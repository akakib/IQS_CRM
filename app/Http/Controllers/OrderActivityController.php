<?php

namespace App\Http\Controllers;

use App\Models\OrderStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Order activity: the admin's board. Every order still moving, one column
 * per stage, a card per order with who holds it and what they are doing now.
 * Staff do the work in Order management; here the admin watches, opens any
 * order and can hand it to someone else.
 *
 * Queries: one for all column counts, one per column for its first cards
 * (users joined, no lazy loading), one for the staff filter.
 */
class OrderActivityController extends Controller
{
    private const PER_COLUMN = 20;

    public function index(Request $request): View|Response
    {
        $filters = $this->filters($request);
        $columns = $this->columns();
        $base = $this->base($filters);

        // Load more in one column: just its next cards.
        if ($request->filled('column') && isset($columns[$request->query('column')])) {
            $key = $request->query('column');
            $page = max(2, (int) $request->query('page', 2));

            return response()->view('orders.activity._cards', [
                'cards' => $this->cards($base, $columns[$key], $page), 'column' => $key, 'statuses' => OrderStatus::map(),
                'more' => $this->countIn($base, $columns[$key]) > $page * self::PER_COLUMN, 'page' => $page,
            ]);
        }

        $select = [];
        $bindings = [];
        foreach ($columns as $key => $col) {
            $select[] = "SUM(CASE WHEN {$col['sql']} THEN 1 ELSE 0 END) as n_{$key}";
            $bindings = [...$bindings, ...$col['bind']];
        }
        $counts = collect((array) (clone $base)->selectRaw(implode(', ', $select), $bindings)->first())
            ->mapWithKeys(fn ($n, $k) => [substr($k, 2) => (int) $n])->all();

        $data = [
            'filters' => $filters,
            'columns' => $columns,
            'counts' => $counts,
            'cards' => collect($columns)->map(fn ($col, $key) => $counts[$key] ? $this->cards($base, $col, 1) : collect())->all(),
            'statuses' => OrderStatus::map(),
            'perColumn' => self::PER_COLUMN,
            'staff' => DB::table('users')->where('is_active', true)
                ->whereIn('id', DB::table('order_assignments')->select('user_id')->where('started_at', '>=', now()->subDays(60)))
                ->orderBy('name')->get(['id', 'name', 'photo_path']),
            'refreshedAt' => now(),
        ];

        // The 30-second refresh and the Refresh button swap only the board.
        return $request->header('X-Board') ? response()->view('orders.activity._board', $data) : view('orders.activity.index', $data);
    }

    /** @return array{from: ?string, to: ?string, staff: ?string} */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
            'staff' => ['nullable', 'regex:/^(none|\d+)$/'],
        ]);
        $hasRange = $request->has('from') || $request->has('to');

        return [
            // Default: the last 30 days (the board is for orders still moving).
            'from' => $hasRange ? ($data['from'] ?? null) : today()->subDays(29)->toDateString(),
            'to' => $hasRange ? ($data['to'] ?? null) : today()->toDateString(),
            'staff' => $data['staff'] ?? null,
        ];
    }

    private function base(array $f)
    {
        return DB::table('orders as o')
            ->when($f['from'], fn ($q, $d) => $q->where('o.created_at', '>=', Carbon::parse($d)->startOfDay()))
            ->when($f['to'], fn ($q, $d) => $q->where('o.created_at', '<=', Carbon::parse($d)->endOfDay()))
            ->when($f['staff'] === 'none', fn ($q) => $q->whereNull('o.moderator_id'))
            ->when($f['staff'] && $f['staff'] !== 'none', fn ($q) => $q->where('o.moderator_id', (int) $f['staff']));
    }

    /** Stage columns, left to right. Each is a condition on the order row. */
    private function columns(): array
    {
        $s = fn (string $key) => OrderStatus::idFor($key);
        $now = now()->toDateTimeString();

        return [
            // Cancelled after booking, still to delete at the courier (by hand): first, it costs money if a rider takes it.
            'courier_cancel' => ['label' => __('Delete at courier'), 'sql' => "o.status_id = ? AND EXISTS (SELECT 1 FROM shipments sc WHERE sc.id = o.active_shipment_id AND sc.cancelled_at IS NULL AND sc.final_at IS NULL)", 'bind' => [$s('cancelled')], 'tone' => 'red'],
            // Anything waiting with nobody on it (also an order taken back after a missed timer) is "nobody took", like the Control room counts it.
            'waiting' => ['label' => __('New, nobody took'), 'sql' => 'o.status_id IN (?, ?, ?) AND o.moderator_id IS NULL', 'bind' => [$s('new'), $s('record_verified'), $s('no_answer')], 'tone' => 'gray'],
            'verify' => ['label' => __('Verify'), 'sql' => 'o.status_id = ? AND o.moderator_id IS NOT NULL', 'bind' => [$s('new')], 'tone' => 'blue'],
            'call' => ['label' => __('Call'), 'sql' => 'o.moderator_id IS NOT NULL AND (o.status_id = ? OR (o.status_id = ? AND (o.next_call_at IS NULL OR o.next_call_at <= ?)))', 'bind' => [$s('record_verified'), $s('no_answer'), $now], 'tone' => 'amber'],
            'again' => ['label' => __('Call again'), 'sql' => 'o.moderator_id IS NOT NULL AND o.status_id = ? AND o.next_call_at > ?', 'bind' => [$s('no_answer'), $now], 'tone' => 'orange'],
            'hold' => ['label' => __('On hold'), 'sql' => 'o.status_id = ?', 'bind' => [$s('hold')], 'tone' => 'red'],
            'send' => ['label' => __('To send'), 'sql' => "o.status_id = ? AND o.booking_state IN ('none', 'failed')", 'bind' => [$s('confirmed')], 'tone' => 'green'],
            'packaging' => ['label' => __('Packaging'), 'sql' => "((o.status_id = ? AND o.booking_state = 'queued') OR o.status_id = ?)", 'bind' => [$s('confirmed'), $s('ready_for_packaging')], 'tone' => 'purple'],
            'packed' => ['label' => __('Packed'), 'sql' => 'o.status_id IN (?, ?)', 'bind' => [$s('packed'), $s('ready_for_pickup')], 'tone' => 'purple'],
            'shipped' => ['label' => __('With the courier'), 'sql' => 'o.status_id IN (?, ?)', 'bind' => [$s('handed_over'), $s('in_transit')], 'tone' => 'teal'],
        ];
    }

    private function countIn($base, array $col): int
    {
        return (clone $base)->whereRaw($col['sql'], $col['bind'])->count();
    }

    private function cards($base, array $col, int $page)
    {
        return (clone $base)->whereRaw($col['sql'], $col['bind'])
            ->leftJoin('users as m', 'm.id', '=', 'o.moderator_id')
            ->leftJoin('users as p', 'p.id', '=', 'o.packer_id')
            ->leftJoin('status_reasons as hr', 'hr.id', '=', 'o.hold_reason_id')
            // Oldest first: what has waited longest is what needs a look.
            ->orderByRaw('COALESCE(o.queue_since, o.assigned_at, o.created_at)')->orderBy('o.id')
            ->forPage($page, self::PER_COLUMN)
            ->get(['o.id', 'o.order_no', 'o.ship_name', 'o.ship_phone', 'o.grand_total', 'o.channel', 'o.status_id', 'o.created_at', 'o.queue_since',
                'o.assigned_at', 'o.action_due_at', 'o.next_call_at', 'o.no_response_count', 'o.booking_state', 'o.booking_error',
                'o.hold_expected_date', 'o.packed_at', 'o.packaging_started_at', 'o.edited_after_pack', 'o.packed_version', 'o.current_version',
                'o.moderator_id', 'm.name as moderator', 'm.photo_path as moderator_photo', 'm.current_break_id as moderator_on_break',
                'p.name as packer', 'p.photo_path as packer_photo', 'hr.label_en as hold_reason',
                DB::raw("EXISTS (SELECT 1 FROM order_amendments ca WHERE ca.order_id = o.id AND ca.courier_action = 'update_cod' AND ca.courier_action_done_at IS NULL) as cod_pending")]);
    }
}
