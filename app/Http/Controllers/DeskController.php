<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Services\Orders\DeskService;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderStateMachine;
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
    public const TABS = ['verify', 'call', 'again', 'hold', 'send', 'packing'];

    private const PER_PAGE = 25;

    public function __construct(private DeskService $desk, private OrderStateMachine $machine, private OrderService $orders) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $s = fn (string $key) => OrderStatus::idFor($key);
        $now = now()->toDateTimeString();
        $packing = [$s('ready_for_packaging'), $s('packed'), $s('ready_for_pickup')];

        // tab => [SQL condition, bindings]. Written once, used for the counts and for the list.
        $where = [
            'verify' => ['status_id = ?', [$s('new')]],
            'call' => ['(status_id = ? OR (status_id = ? AND (next_call_at IS NULL OR next_call_at <= ?)))', [$s('record_verified'), $s('no_answer'), $now]],
            'again' => ['(status_id = ? AND next_call_at > ?)', [$s('no_answer'), $now]],
            'hold' => ['status_id = ?', [$s('hold')]],
            'send' => ["(status_id = ? AND booking_state = 'none')", [$s('confirmed')]],
            'packing' => ["((status_id = ? AND booking_state <> 'none') OR status_id IN (?, ?, ?))", [$s('confirmed'), ...$packing]],
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
            ->whereIn('status_id', [$s('new'), $s('record_verified'), $s('no_answer'), $s('hold'), $s('confirmed'), ...$packing])
            ->selectRaw(implode(', ', $select), $bindings)->first();
        $counts = collect($counts)->mapWithKeys(fn ($n, $k) => [substr($k, 2) => (int) $n])->all();

        // Work waiting but no timer running (first visit of the day, or after a break): start it now.
        if (! $counts['timed'] && $counts['verify'] + $counts['call'] > 0) {
            $this->desk->armTimer($user->id);
        }

        // Default tab: the first one with work in it.
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab')
            : (collect(self::TABS)->first(fn ($t) => $counts[$t] > 0) ?? 'call');

        $page = max(1, (int) $request->query('page', 1));
        $rows = DB::table('orders')->where('moderator_id', $user->id)->whereRaw($where[$tab][0], $where[$tab][1])
            ->orderByRaw('action_due_at IS NULL')->orderBy('action_due_at')->orderBy('assigned_at')->orderBy('id')
            ->forPage($page, self::PER_PAGE)
            ->get(['id', 'order_no', 'ship_name', 'ship_thana', 'ship_district', 'grand_total', 'channel', 'status_id', 'created_at', 'assigned_at',
                'action_due_at', 'next_call_at', 'no_response_count', 'booking_state', 'is_duplicate_flag', 'packer_id', 'packed_at']);
        $list = new LengthAwarePaginator($rows, $counts[$tab], self::PER_PAGE, $page, ['path' => route('desk.index'), 'query' => ['tab' => $tab]]);

        // The open order: the one asked for (if it is mine), else the first in the list.
        $openId = (int) $request->query('order') ?: ($rows->first()->id ?? 0);
        $order = $openId ? Order::with(['customer:id,name,orders_count,delivered_count,returned_count,risk_level', 'holdReason:id,label_en', 'packer:id,name'])->find($openId) : null;
        if ($order && $order->moderator_id !== $user->id && $user->permissionScope('orders.view') !== 'all') {
            $order = null;
        }

        $waiting = $this->desk->waitingQuery()->selectRaw('COUNT(*) as n, MIN(created_at) as oldest')->first();
        $reasons = DB::table('status_reasons')->whereIn('reason_type', ['hold', 'cancel'])->where('is_active', true)
            ->orderBy('sort_order')->get(['id', 'reason_type', 'label_en'])->groupBy('reason_type');

        return view('desk.index', [
            'tab' => $tab,
            'counts' => $counts,
            'list' => $list,
            'order' => $order,
            'detail' => $order ? $this->detail($order) : null,
            // The timer runs on one order at a time. If the open order is not that one, point to it.
            'timed' => $order && ! $order->action_due_at
                ? DB::table('orders')->where('moderator_id', $user->id)->whereNotNull('action_due_at')->orderBy('action_due_at')->first(['id', 'order_no', 'action_due_at', 'status_id'])
                : null,
            'statuses' => OrderStatus::map(),
            'waiting' => (int) $waiting->n,
            'oldestWaiting' => $waiting->oldest,
            'limit' => (int) settings('desk.active_limit'),
            'canTake' => $user->can('orders.take'),
            'reasons' => ['hold' => ($reasons['hold'] ?? collect())->pluck('label_en', 'id')->all(), 'cancel' => ($reasons['cancel'] ?? collect())->pluck('label_en', 'id')->all()],
            'returns' => array_values(array_filter(array_map('intval', explode(',', (string) settings('desk.no_response_returns'))))),
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
            'action' => ['required', Rule::in(['verify', 'confirm', 'no_response', 'hold', 'cancel', 'resume', 'send', 'back_to_packing'])],
            'reason_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
            'hold_expected_date' => ['nullable', 'date', 'after_or_equal:today'],
            'lock_version' => ['required', 'integer'],
            'tab' => ['nullable', Rule::in(self::TABS)],
        ]);
        if ($order->lock_version !== (int) $data['lock_version']) {
            throw ValidationException::withMessages(['order' => __('This order just changed. Look again before acting.')]);
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
                $message = __('Confirmed. Send it to packing.');
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
            case 'back_to_packing':
                $this->machine->transition($order, 'ready_for_packaging', $user, 'user', null, $note);
                $message = __(':no is back in the packing queue.', ['no' => $order->order_no]);
                break;
            default: // send
                $this->desk->sendToPacking($order, $user);
                $message = __(':no sent to packing. Booking the courier…', ['no' => $order->order_no]);
        }

        return redirect()->route('desk.index', array_filter($next))->with('success', $message);
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
