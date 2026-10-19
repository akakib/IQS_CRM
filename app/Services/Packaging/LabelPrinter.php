<?php

namespace App\Services\Packaging;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\User;
use App\Services\Courier\BookingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one way labels are printed (Courier booking, the packaging list, Print new labels): the picked orders
 * get a current label first (an order edited since its label was issued gets the new one), every print shares
 * a print_run so the A4 packing sheet lists the same orders in the same order, and printed_at is stamped.
 */
class LabelPrinter
{
    public function __construct(private BookingService $booking, private PackagingPause $pause) {}

    /** @return array{orders: Collection, labels: Collection, run: string}|null null = nothing to print */
    public function picked(Collection $ids, User $by, bool $respectPause = true): ?array
    {
        $ids = $ids->map(fn ($v) => (int) $v)->filter()->unique()->take(100)->values();
        $hide = $respectPause && ! $this->pause->canControl($by) ? $this->pause->visibleSql('orders') : '';
        $found = Order::whereIn('id', $ids)->whereIn('status_id', self::queueStatuses())->whereNotNull('active_shipment_id')
            ->when($hide !== '', fn ($q) => $q->whereRaw($hide))->get();
        foreach ($found->filter(fn ($o) => (int) $o->label_version !== (int) $o->current_version) as $o) {
            $this->booking->issueLabel($o, $o->active_shipment_id, null, __('Order edited (version :v)', ['v' => $o->current_version]));
        }

        return $this->print($ids->intersect($found->pluck('id'))->values(), $by, false);
    }

    /** Every label in the queue not printed yet, oldest first. */
    public function fresh(User $by): ?array
    {
        $ids = DB::table('shipment_labels as l')->join('orders as o', 'o.id', '=', 'l.order_id')
            ->whereNull('l.voided_at')->whereNull('l.printed_at')->whereIn('o.status_id', self::queueStatuses())
            ->orderBy('o.packaging_sent_at')->limit(100)->pluck('o.id');

        return $this->print($ids, $by, true);
    }

    /** @return list<int> */
    public static function queueStatuses(): array
    {
        return [OrderStatus::idFor('ready_for_packaging'), ...OrderStatus::idsFor(['packed', 'ready_for_pickup'])];
    }

    private function print(Collection $ids, User $by, bool $unprintedOnly): ?array
    {
        if ($ids->isEmpty()) {
            return null;
        }
        $orders = Order::whereIn('id', $ids)->with('items:id,order_id,variant_id,name_snapshot,qty,unit', 'items.variant:id,size_qty,size_unit')->get()
            ->sortBy(fn ($o) => $ids->search($o->id))->values();
        $labels = DB::table('shipment_labels as l')->join('shipments as s', 's.id', '=', 'l.shipment_id')
            ->whereIn('l.order_id', $ids)->whereNull('l.voided_at')
            ->get(['l.order_id', 'l.barcode', 'l.cod_on_label', 's.consignment_id', 's.courier'])->keyBy('order_id');
        $run = now()->format('ymdHis').substr((string) $by->id, -4);
        DB::table('shipment_labels')->whereIn('order_id', $ids)->whereNull('voided_at')
            ->when($unprintedOnly, fn ($q) => $q->whereNull('printed_at'))
            ->update(['printed_at' => now(), 'printed_by' => $by->id, 'print_run' => $run]);

        return ['orders' => $orders, 'labels' => $labels, 'run' => $run];
    }
}
