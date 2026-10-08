<?php

namespace App\Services\Orders;

use App\Models\OrderStatus;
use App\Models\User;
use App\Support\Permissions\Mask;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Orders for Excel or print: by the date they came in, stage and person,
 * with the same stage rules as the Order activity board. Three queries:
 * the totals, the orders (people and parcel joined), their items.
 */
class OrderExport
{
    /** One file or page holds at most this many orders. */
    public const MAX_ROWS = 5000;

    /** Sort choices for the panel: key => label. */
    public static function sorts(): array
    {
        return ['placed' => __('Placed (oldest first)'), 'order_no' => __('Order no'), 'status' => __('Status'),
            'moderator' => __('Moderator'), 'area' => __('Area (district, thana)'), 'total' => __('Total (highest first)')];
    }

    /**
     * @param  array{from: string, to: string, stages: list<string>, staff: ?string, handover?: ?int}  $f
     *                                                                                                      handover: only the parcels handed over in that pickup (dates and visibility do not apply:
     *                                                                                                      whoever runs the handover may list its parcels; masked fields stay masked)
     */
    public function query(array $f, User $user)
    {
        if (! empty($f['handover'])) {
            return DB::table('orders as o')->whereIn('o.id', DB::table('scan_logs')->select('order_id')
                ->where('handover_session_id', (int) $f['handover'])->where('result', 'ok'));
        }
        $stages = OrderStages::all();
        $chosen = array_values(array_intersect_key($stages, array_flip($f['stages'])));

        return DB::table('orders as o')
            ->where('o.created_at', '>=', Carbon::parse($f['from'])->startOfDay())
            ->where('o.created_at', '<=', Carbon::parse($f['to'])->endOfDay())
            ->when($f['staff'] === 'none', fn ($q) => $q->whereNull('o.moderator_id'))
            ->when($f['staff'] && $f['staff'] !== 'none', fn ($q) => $q->where('o.moderator_id', (int) $f['staff']))
            // "All" = no stage chosen; otherwise any of the chosen stages.
            ->when($chosen, fn ($q) => $q->where(function ($q) use ($chosen) {
                foreach ($chosen as $stage) {
                    $q->orWhereRaw('('.$stage['sql'].')', $stage['bind']);
                }
            }))
            // Same visibility as everywhere: someone who sees only their own orders exports only those.
            ->when($user->permissionScope('orders.view') !== 'all', fn ($q) => $q->where(fn ($q) => $q->where('o.moderator_id', $user->id)
                ->orWhere(fn ($q) => $q->whereNull('o.moderator_id')->whereIn('o.status_id', OrderStatus::idsFor(['new', 'record_verified'])))));
    }

    /** @return array{orders: int, total: float, cod: float} */
    public function totals(array $f, User $user): array
    {
        $t = $this->query($f, $user)->selectRaw('COUNT(*) as n, COALESCE(SUM(o.grand_total), 0) as total, COALESCE(SUM(o.cod_amount), 0) as cod')->first();

        return ['orders' => (int) $t->n, 'total' => (float) $t->total, 'cod' => (float) $t->cod];
    }

    /** @return Collection<int, array<string, string|float|null>> one row per order, oldest first */
    public function rows(array $f, User $user): Collection
    {
        $orders = $this->query($f, $user)
            ->leftJoin('users as m', 'm.id', '=', 'o.moderator_id')
            ->leftJoin('shipments as sh', 'sh.id', '=', 'o.active_shipment_id')
            ->tap(fn ($q) => match (! empty($f['handover']) ? 'handover' : ($f['sort'] ?? 'placed')) {
                // A pickup list: in the order the parcels were scanned out.
                'handover' => $q->orderByRaw("(SELECT MIN(l.id) FROM scan_logs l WHERE l.order_id = o.id AND l.result = 'ok' AND l.handover_session_id = ?)", [(int) $f['handover']]),
                'order_no' => $q->orderBy('o.id'),
                'status' => $q->orderBy('o.status_id'),
                'moderator' => $q->orderByRaw('m.name IS NULL')->orderBy('m.name'),
                'area' => $q->orderBy('o.ship_district')->orderBy('o.ship_thana'),
                'total' => $q->orderByDesc('o.grand_total'),
                default => null,
            })
            ->orderBy('o.created_at')->orderBy('o.id')->limit(self::MAX_ROWS)
            ->get(['o.id', 'o.order_no', 'o.created_at', 'o.channel', 'o.ship_name', 'o.ship_phone', 'o.ship_address', 'o.ship_thana', 'o.ship_district',
                'o.grand_total', 'o.cod_amount', 'o.status_id', 'm.name as moderator', 'sh.consignment_id']);

        $items = DB::table('order_items')->whereIn('order_id', $orders->pluck('id'))->orderBy('id')
            ->get(['order_id', 'name_snapshot', 'qty', 'unit'])->groupBy('order_id');
        $statuses = OrderStatus::map();
        $contact = fn (?string $v) => Mask::value($v, 'customer_contact', $user);

        return $orders->map(fn ($o) => [
            'order_no' => $o->order_no,
            'placed' => Carbon::parse($o->created_at)->format('d M Y, g:i A'),
            'placed_at' => Carbon::parse($o->created_at)->getTimestamp(),
            'channel' => \App\Models\Order::channelLabel($o->channel),
            'customer' => $o->ship_name,
            'phone' => $contact($o->ship_phone),
            'address' => $contact(trim(implode(', ', array_filter([$o->ship_address, $o->ship_thana, $o->ship_district])))),
            'items' => ($items[$o->id] ?? collect())->map(fn ($i) => html_entity_decode($i->name_snapshot, ENT_QUOTES | ENT_HTML5, 'UTF-8').' '.\App\Support\Units::qty($i->qty, $i->unit))->join('; '), // website names can carry &amp;
            'total' => (float) $o->grand_total,
            'cod' => (float) $o->cod_amount,
            'status' => $statuses[$o->status_id]['name'] ?? '',
            'moderator' => $o->moderator ?? '',
            'cn' => $o->consignment_id ?? '',
        ]);
    }

    /** "On hold, Packaging" or "All stages" */
    public function stageNames(array $keys): string
    {
        $all = OrderStages::all();

        return $keys ? collect($keys)->map(fn ($k) => $all[$k]['label'] ?? $k)->join(', ') : __('All stages');
    }
}
