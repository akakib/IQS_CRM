<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Services\Orders\DeskService;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderStateMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Order management: the moderator's one screen. Left: my orders by stage.
 * Middle: one order with everything needed to decide. Bottom: the actions
 * for its stage. New orders are never picked from a list, only "Take next".
 *
 * Page load: tab counts (1), list (1), waiting (1), reasons (1) and the open
 * order: order + customer (2), items (1), notes (1), duplicates (1).
 */
class DeskController extends Controller
{
    public const TABS = ['verify', 'call', 'again', 'hold', 'send', 'packaging'];

    private const PER_PAGE = 25;

    public function __construct(private DeskService $desk, private OrderStateMachine $machine, private OrderService $orders) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $s = fn (string $key) => OrderStatus::idFor($key);

        // Time that ran out is enforced here, on every visit, not only by the cron.
        $lost = $this->desk->sweepFor($user->id);
        $now = now()->toDateTimeString();
        $packaging = [$s('ready_for_packaging'), $s('packed'), $s('ready_for_pickup')];

        // tab => [SQL condition, bindings]. Written once, used for the counts and for the list.
        $where = [
            'verify' => ['status_id = ?', [$s('new')]],
            'call' => ['(status_id = ? OR (status_id = ? AND (next_call_at IS NULL OR next_call_at <= ?)))', [$s('record_verified'), $s('no_answer'), $now]],
            'again' => ['(status_id = ? AND next_call_at > ?)', [$s('no_answer'), $now]],
            'hold' => ['status_id = ?', [$s('hold')]],
            'send' => ["(status_id = ? AND booking_state = 'none')", [$s('confirmed')]],
            'packaging' => ["((status_id = ? AND booking_state <> 'none') OR status_id IN (?, ?, ?))", [$s('confirmed'), ...$packaging]],
        ];

        $select = [];
        $bindings = [];
        foreach ($where as $tab => [$sql, $b]) {
            $select[] = "SUM(CASE WHEN {$sql} THEN 1 ELSE 0 END) as n_{$tab}"; // prefixed: "call" is a reserved word in MariaDB
            $bindings = array_merge($bindings, $b);
        }
        $select[] = "SUM(CASE WHEN channel = 'web' AND status_id IN (?, ?) THEN 1 ELSE 0 END) as n_active";
        $select[] = 'SUM(CASE WHEN action_due_at IS NOT NULL THEN 1 ELSE 0 END) as n_timed';
        $bindings = array_merge($bindings, [$s('new'), $s('record_verified')]);
        $counts = (array) DB::table('orders')->where('moderator_id', $user->id)
            ->whereIn('status_id', [$s('new'), $s('record_verified'), $s('no_answer'), $s('hold'), $s('confirmed'), ...$packaging])
            ->selectRaw(implode(', ', $select), $bindings)->first();
        $counts = collect($counts)->mapWithKeys(fn ($n, $k) => [substr($k, 2) => (int) $n])->all();

        // One timer at a time. Arriving without choosing a tab or an order lands on the order whose clock is running.
        $timed = $counts['timed'] ? $this->desk->timedOrder($user->id) : null;
        $toTimed = $timed && ! $request->has('tab') && ! $request->has('order');

        // Default tab: the running order's tab, else the first one with work in it.
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab')
            : ($toTimed ? ($timed->status_id === $s('new') ? 'verify' : 'call') : (collect(self::TABS)->first(fn ($t) => $counts[$t] > 0) ?? 'call'));

        $page = max(1, (int) $request->query('page', 1));
        $rows = DB::table('orders')->where('moderator_id', $user->id)->whereRaw($where[$tab][0], $where[$tab][1])
            ->orderByRaw('action_due_at IS NULL')->orderBy('action_due_at')->orderBy('assigned_at')->orderBy('id')
            ->forPage($page, self::PER_PAGE)
            ->get(['id', 'order_no', 'ship_name', 'ship_thana', 'ship_district', 'grand_total', 'channel', 'status_id', 'created_at', 'assigned_at',
                'action_due_at', 'next_call_at', 'no_response_count', 'booking_state', 'is_duplicate_flag', 'packer_id', 'packed_at']);
        $list = new LengthAwarePaginator($rows, $counts[$tab], self::PER_PAGE, $page, ['path' => route('desk.index'), 'query' => ['tab' => $tab]]);

        // The open order: the one asked for (if it is mine), else the first in the list.
        // (An order just taken back is not reopened, even if the address still names it.)
        $openId = ($lost ? 0 : (int) $request->query('order')) ?: ($toTimed ? $timed->id : ($rows->first()->id ?? 0));
        $order = $openId ? Order::with(['customer:id,name,orders_count,delivered_count,returned_count,risk_level', 'holdReason:id,label_en', 'packer:id,name'])->find($openId) : null;
        if ($order && $order->moderator_id !== $user->id && $user->permissionScope('orders.view') !== 'all') {
            $order = null;
        }

        // No timer running: it starts on the order now open in front of them (never on one they cannot see),
        // or, as a safety net, on an order that has sat untouched too long.
        if (! $timed) {
            if ($order && $order->moderator_id === $user->id && $this->desk->armTimer($user->id, $order->id)) {
                $order->refresh();
                if ($row = $rows->firstWhere('id', $order->id)) {
                    $row->action_due_at = $order->action_due_at;
                }
                $counts['timed'] = 1;
            } elseif ($this->desk->armUntouched($user->id)) {
                $counts['timed'] = 1;
            }
            $timed = $counts['timed'] ? $this->desk->timedOrder($user->id) : null;
        }
        // The open order needs the clock but it is running on another one: finish that one first.
        $openNeedsTimer = $order && $order->moderator_id === $user->id && $order->channel === 'web' && ! $order->action_due_at
            && (in_array($order->status_id, [$s('new'), $s('record_verified')], true) || ($order->status_id === $s('no_answer') && (! $order->next_call_at || $order->next_call_at->isPast())));

        $waiting = $this->desk->waitingQuery()->selectRaw('COUNT(*) as n, MIN(created_at) as oldest')->first();
        // Packer-only hold reasons (item not found on the shelf) are not offered to moderators.
        $reasons = DB::table('status_reasons')->whereIn('reason_type', ['hold', 'cancel'])->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('system_key')->orWhereNotIn('system_key', ['item_not_found', 'item_damaged']))
            ->orderBy('sort_order')->get(['id', 'reason_type', 'label_en'])->groupBy('reason_type');

        return view('desk.index', [
            'tab' => $tab,
            'counts' => $counts,
            'list' => $list,
            'order' => $order,
            'detail' => $order ? $this->detail($order) : null,
            // The timer runs on one order at a time. If the open order is not that one, point to it.
            'timed' => $timed && (! $order || $order->id !== $timed->id) ? $timed : null,
            'blocked' => $timed && $openNeedsTimer && $order->id !== $timed->id,
            'lost' => $lost,
            'extendMinutes' => (int) settings('desk.extend_minutes'),
            'extendsLeft' => $counts['timed'] ? max(0, (int) settings('desk.extend_daily_limit') - $this->desk->extensionsToday($user->id)) : 0,
            'statuses' => OrderStatus::map(),
            'waiting' => (int) $waiting->n,
            'oldestWaiting' => $waiting->oldest,
            'limit' => (int) settings('desk.active_limit'),
            'canTake' => $user->can('orders.take') && ! $user->isOwner(), // owners watch, staff take
            // Order activity popup: the admin can hand the order to someone else.
            'reassign' => $request->boolean('embed') && $order && $user->can('orders.reassign') ? [
                'staff' => \App\Models\User::where('is_active', true)->orderBy('name')->get(['id', 'name', 'photo_path'])
                    ->filter(fn ($u) => $u->id !== $order->moderator_id && $u->can('orders.take') && ! $u->isOwner())->values(),
                'reasons' => \App\Models\StatusReason::options('reassign'),
                'holder' => $order->moderator_id ? DB::table('users')->where('id', $order->moderator_id)->first(['id', 'name', 'photo_path']) : null,
            ] : null,
            'reasons' => ['hold' => ($reasons['hold'] ?? collect())->pluck('label_en', 'id')->all(), 'cancel' => ($reasons['cancel'] ?? collect())->pluck('label_en', 'id')->all()],
            'returns' => array_values(array_filter(array_map('intval', explode(',', (string) settings('desk.no_response_returns'))))),
        ]);
    }

    /**
     * Voice alerts ask this every 30 seconds: what this moderator has now.
     * Also a cron stand-in while someone is at work: the untouched-order timer
     * for them, and (at most once a minute for everyone) handing out orders
     * nobody took. Not counted as presence (see TrackPresence).
     */
    public function pulse(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->current_break_id) {
            if (Cache::add('desk:auto-assign', 1, 60)) {
                $this->desk->autoAssign();
            }
            if (! DB::table('orders')->where('moderator_id', $user->id)->whereNotNull('action_due_at')->exists()) {
                $this->desk->armUntouched($user->id);
            }
        }

        $s = fn (string $key) => OrderStatus::idFor($key);
        $rows = DB::table('orders')->where('moderator_id', $user->id)->where('channel', 'web')
            ->whereIn('status_id', [$s('new'), $s('record_verified'), $s('no_answer')])
            ->get(['id', 'order_no', 'status_id', 'next_call_at', 'action_due_at']);
        $timed = $rows->whereNotNull('action_due_at')->sortBy('action_due_at')->first();
        $active = $rows->whereIn('status_id', [$s('new'), $s('record_verified')]);

        return response()->json([
            'on_break' => (bool) $user->current_break_id,
            'mine' => $active->map(fn ($o) => ['id' => $o->id, 'no' => $o->order_no])->values(),
            'returned' => $rows->where('status_id', $s('no_answer'))->filter(fn ($o) => $o->next_call_at && $o->next_call_at <= now()->toDateTimeString())
                ->map(fn ($o) => ['id' => $o->id, 'no' => $o->order_no])->values(),
            'timed' => $timed ? ['id' => $timed->id, 'no' => $timed->order_no, 'due' => $timed->action_due_at,
                'left' => (int) max(0, now()->diffInSeconds(\Illuminate\Support\Carbon::parse($timed->action_due_at), false))] : null,
            'waiting' => (int) $this->desk->waitingQuery()->count(),
            'can_take' => $active->count() < (int) settings('desk.active_limit'),
        ]);
    }

    public function takeNext(Request $request): RedirectResponse
    {
        // A double click must not hand out two orders: one Take next per person every 3 seconds.
        if (! Cache::add('desk:take:'.$request->user()->id, 1, 3)) {
            return redirect()->route('desk.index');
        }
        $order = $this->desk->takeNext($request->user());
        $tab =OrderStatus::map()[$order->status_id]['key'] === 'new' ? 'verify' : 'call';

        return redirect()->route('desk.index', ['tab' => $tab, 'order' => $order->id])->with('success', __(':no is yours.', ['no' => $order->order_no]));
    }

    public function act(Order $order, Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if($order->moderator_id !== $user->id && $user->permissionScope('orders.view') !== 'all', 403);

        $data = $request->validate([
            'action' => ['required', Rule::in(['verify', 'confirm', 'no_response', 'hold', 'cancel', 'resume', 'send', 'back_to_packaging'])],
            'reason_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
            'hold_expected_date' => ['nullable', 'date', 'after_or_equal:today'],
            'lock_version' => ['required', 'integer'],
            'tab' => ['nullable', Rule::in(self::TABS)],
            'embed' => ['nullable', 'boolean'],
        ]);
        // Opened from Order activity: stay on this order in the popup.
        $embedded = $request->boolean('embed') ? ['embed' => 1, 'order' => $order->id] : null;
        // Time already up: the action does not count; the order goes back to New.
        if ($order->moderator_id === $user->id && $order->action_due_at && $order->action_due_at->isPast()) {
            $this->desk->release($order->id, 'timeout');

            return redirect()->route('desk.index', $embedded ?? array_filter(['tab' => $data['tab'] ?? null]))
                ->with('error', __('Time ran out on :no. It went back to New.', ['no' => $order->order_no]));
        }
        if ($order->lock_version !== (int) $data['lock_version']) {
            throw ValidationException::withMessages(['order' => __('This order just changed. Look again before acting.')]);
        }
        // One order at a time: while the clock runs on another order, a waiting order cannot be worked on.
        $other = $order->moderator_id === $user->id && $order->channel === 'web' && ! $order->action_due_at
            && in_array(OrderStatus::map()[$order->status_id]['key'], ['new', 'record_verified', 'no_answer'], true)
            ? $this->desk->timedOrder($user->id) : null;
        if ($other) {
            throw ValidationException::withMessages(['order' => __('Your timer is running on :no. Finish that one first.', ['no' => $other->order_no])]);
        }
        $note = $data['note'] ?? null;
        $reason = $data['reason_id'] ?? null;
        $next = ['tab' => $data['tab'] ?? null];

        switch ($data['action']) {
            case 'verify':
                $this->machine->transition($order, 'record_verified', $user, 'user', null, $note);
                $next = ['tab' => 'call', 'order' => $order->id];
                $message = __('Record checked. Call the customer.');
                break;
            case 'confirm':
                if ($order->channel === 'web') {
                    $this->orders->note($order, 'call', trim(__('Called: customer confirmed').($note ? ' · '.$note : '')), $user, ['outcome' => 'confirmed']);
                }
                $this->machine->transition($order, 'confirmed', $user);
                $next = ['tab' => 'send', 'order' => $order->id];
                $message = __('Confirmed. Send it to packaging.');
                break;
            case 'no_response':
                $message = $this->desk->noResponse($order, $user, $note) === 'cancelled'
                    ? __(':no cancelled: customer could not be reached.', ['no' => $order->order_no])
                    : __('No response saved. It will come back to your Call tab.');
                break;
            case 'hold':
                $this->machine->transition($order, 'hold', $user, 'user', $reason, $note);
                if (! empty($data['hold_expected_date'])) {
                    $order->forceFill(['hold_expected_date' => $data['hold_expected_date']])->save();
                }
                $message = __(':no is on hold.', ['no' => $order->order_no]);
                break;
            case 'cancel':
                $this->machine->transition($order, 'cancelled', $user, 'user', $reason, $note);
                $message = __(':no cancelled.', ['no' => $order->order_no]);
                break;
            case 'resume':
                $this->machine->transition($order, 'record_verified', $user, 'user', null, $note);
                $next = ['tab' => 'call', 'order' => $order->id];
                $message = __('Back in your Call tab.');
                break;
            case 'back_to_packaging':
                $this->machine->transition($order, 'ready_for_packaging', $user, 'user', null, $note);
                $message = __(':no is back in the packaging queue.', ['no' => $order->order_no]);
                break;
            default: // send
                $this->desk->sendToPackaging($order, $user);
                $message = __(':no sent to packaging. Booking the courier…', ['no' => $order->order_no]);
        }

        return redirect()->route('desk.index', $embedded ?? array_filter($next))->with('success', $message);
    }

    /** "+5 min" on the running timer. */
    public function extend(Order $order, Request $request): RedirectResponse
    {
        $this->desk->extend($order, $request->user());

        return back()->with('success', __(':m more minutes on :no.', ['m' => settings('desk.extend_minutes'), 'no' => $order->order_no]));
    }

    /** @return array<string, mixed> everything the detail pane shows beyond the order row */
    private function detail(Order $order): array
    {
        $items = DB::table('order_items as i')->join('product_variants as v', 'v.id', '=', 'i.variant_id')
            ->where('i.order_id', $order->id)->orderBy('i.id')
            ->get(['i.name_snapshot', 'i.qty', 'i.unit', 'i.line_total', 'v.availability_status', 'v.expected_restock_date']);

        $notes = DB::table('order_notes as n')->leftJoin('users as u', 'u.id', '=', 'n.user_id')
            ->where('n.order_id', $order->id)->orderByDesc('n.id')->limit(30)
            ->get(['n.note_type', 'n.body', 'n.created_at', 'n.meta', 'u.name as user']);

        // Same customer, another order still open or placed in the last 7 days.
        $duplicates = DB::table('orders')->where('customer_id', $order->customer_id)->where('id', '!=', $order->id)
            ->where('created_at', '>=', now()->subDays(7))->orderByDesc('id')->limit(5)->get(['id', 'order_no', 'status_id', 'grand_total', 'created_at']);

        $key = OrderStatus::map()[$order->status_id]['key'];
        $heldBy = null;
        if ($key === 'hold') {
            $e = DB::table('order_events as e')->leftJoin('users as u', 'u.id', '=', 'e.user_id')
                ->where('e.order_id', $order->id)->where('e.to_status_id', $order->status_id)->orderByDesc('e.id')->first(['e.source', 'u.name']);
            $heldBy = $e ? ($e->name ? $e->name.($e->source === 'scan' ? ' ('.__('packer').')' : '') : __('System')) : null;
        }

        return [
            'items' => $items,
            'notes' => $notes,
            'duplicates' => $duplicates,
            'heldBy' => $heldBy,
            'consignment' => $order->active_shipment_id ? DB::table('shipments')->where('id', $order->active_shipment_id)->value('consignment_id') : null,
        ];
    }
}
