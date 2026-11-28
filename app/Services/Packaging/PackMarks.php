<?php

namespace App\Services\Packaging;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Orders\OrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The Today sheet (the A4 sheet's two boxes, on screen): Packed and Label on, one press each after a yes.
 * A tick locks: only the owner or a manager takes it back, on a packer's request or themselves, with a reason.
 * Scanning a label only finds the order; Label on is the sticker really on the box.
 */
class PackMarks
{
    public function __construct(private ScanService $scans, private OrderService $orders, private NotificationService $notifications) {}

    /** Packed: everything is in the box. The same step as the scan checklist, all items at once. */
    public function packed(Order $order, User $by): string
    {
        $key = OrderStatus::map()[$order->status_id]['key'];
        $repack = in_array($key, ['packed', 'ready_for_pickup'], true) && $order->packMark() === 'repack';
        if ($key !== 'ready_for_packaging' && ! $repack) {
            throw ValidationException::withMessages(['order' => __(':no cannot be packed now: it is :s.', ['no' => $order->order_no, 's' => OrderStatus::map()[$order->status_id]['name']])]);
        }
        if (! $order->active_shipment_id || (int) $order->label_version !== (int) $order->current_version) {
            throw ValidationException::withMessages(['order' => __(':no changed: print its new label first.', ['no' => $order->order_no])]);
        }

        return DB::transaction(function () use ($order, $by) {
            if ((int) $order->packer_id !== $by->id) {
                $order->forceFill(['packer_id' => $by->id, 'packaging_started_at' => $order->packaging_started_at ?? now()])->save();
            }
            $items = DB::table('order_items')->where('order_id', $order->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $message = $this->scans->finish($order->refresh(), $items, $by);
            $this->orders->note($order, 'system', __('Packed: ticked on the Today sheet by :n.', ['n' => $by->name]), $by);

            return $message;
        });
    }

    /** Label on: the sticker is on the box. Only after Packed. */
    public function labelOn(Order $order, User $by): string
    {
        if (! $order->packed_at || $order->packMark() === 'repack') {
            throw ValidationException::withMessages(['order' => __('Pack :no first, then put the label on.', ['no' => $order->order_no])]);
        }
        $label = $this->label($order);
        if (! $label) {
            throw ValidationException::withMessages(['order' => __(':no has no label.', ['no' => $order->order_no])]);
        }
        if ($label->applied_at) {
            return __('Label :l is already on.', ['l' => $label->barcode]);
        }
        // On the box means printed: a label printed from a PDF (not by the CRM's printer) is marked printed here.
        DB::table('shipment_labels')->where('id', $label->id)->update(['applied_at' => now(), 'applied_by' => $by->id, 'updated_at' => now()]
            + ($label->printed_at ? [] : ['printed_at' => now(), 'printed_by' => $by->id]));
        $this->orders->note($order, 'system', __('Label :l on the box: ticked by :n.', ['l' => $label->barcode, 'n' => $by->name]), $by);

        return __('Label :l is on.', ['l' => $label->barcode]);
    }

    /** A packer asks to take a tick back; the owner and managers are told. */
    public function ask(Order $order, string $mark, string $reason, User $by): void
    {
        if (DB::table('packing_undo_requests')->where('order_id', $order->id)->where('mark', $mark)->where('status', 'open')->exists()) {
            throw ValidationException::withMessages(['order' => __('Already asked. Waiting for an answer.')]);
        }
        DB::table('packing_undo_requests')->insert(['order_id' => $order->id, 'mark' => $mark, 'reason' => $reason, 'requested_by' => $by->id, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $what = $mark === 'packed' ? __('Packed') : __('Label on');
        $this->orders->note($order, 'system', __(':n asked to undo :w: :r', ['n' => $by->name, 'w' => $what, 'r' => $reason]), $by);
        $this->notifications->send('packing_undo_request', __('Undo :w? :no', ['w' => $what, 'no' => $order->order_no]), $by->name.': '.$reason, [
            'link' => route('packaging.index', ['show' => 'today']), 'subject' => ['order', $order->id], 'user_ids' => $this->managers(),
        ]);
    }

    /** The owner or a manager: approve (the tick goes) or refuse (it stays). */
    public function decide(int $requestId, bool $approve, User $by): string
    {
        $r = DB::table('packing_undo_requests')->where('id', $requestId)->where('status', 'open')->first();
        if (! $r) {
            throw ValidationException::withMessages(['order' => __('This request was already answered.')]);
        }
        $order = Order::findOrFail($r->order_id);
        DB::transaction(function () use ($r, $order, $approve, $by) {
            if ($approve) {
                $this->undo($order, $r->mark, $by);
            }
            DB::table('packing_undo_requests')->where('id', $r->id)->update(['status' => $approve ? 'approved' : 'refused', 'decided_by' => $by->id, 'decided_at' => now(), 'updated_at' => now()]);
            $this->orders->note($order, 'system', $approve ? __('Undo approved by :n.', ['n' => $by->name]) : __('Undo refused by :n: the tick stays.', ['n' => $by->name]), $by);
        });

        return $approve ? __('Undone on :no.', ['no' => $order->order_no]) : __('Refused. The tick stays on :no.', ['no' => $order->order_no]);
    }

    /** Takes a tick back (the owner or a manager, with a reason). Packed back = Label on back too, the box goes back on the shelf. */
    public function undo(Order $order, string $mark, User $by, ?string $why = null): void
    {
        $label = $this->label($order);
        if ($mark === 'label' || $mark === 'packed') {
            if ($label?->applied_at) {
                DB::table('shipment_labels')->where('id', $label->id)->update(['applied_at' => null, 'applied_by' => null, 'updated_at' => now()]);
            }
        }
        if ($mark === 'packed' && $order->packed_at) {
            $key = OrderStatus::map()[$order->status_id]['key'];
            if (! in_array($key, ['packed', 'ready_for_pickup'], true)) {
                throw ValidationException::withMessages(['order' => __(':no is :s: it can no longer be unpacked here.', ['no' => $order->order_no, 's' => OrderStatus::map()[$order->status_id]['name']])]);
            }
            $rfp = OrderStatus::idFor('ready_for_packaging');
            DB::table('order_events')->insert(['order_id' => $order->id, 'from_status_id' => $order->status_id, 'to_status_id' => $rfp, 'source' => 'user', 'user_id' => $by->id, 'created_at' => now()]);
            $order->forceFill(['status_id' => $rfp, 'packed_at' => null, 'packed_version' => null, 'packer_id' => null, 'packaging_started_at' => null])->save();
            $stock = app(\App\Services\Catalog\StockService::class);
            $stock->settleOrder($order->refresh(), [], 'unpacked', $by); // back on the shelf
        }
        if ($why) {
            $this->orders->note($order, 'system', __(':w taken back by :n: :r', ['w' => $mark === 'packed' ? __('Packed') : __('Label on'), 'n' => $by->name, 'r' => $why]), $by);
        }
    }

    private function label(Order $order): ?object
    {
        return DB::table('shipment_labels')->where('order_id', $order->id)->whereNull('voided_at')->orderByDesc('id')->first();
    }

    /** @return list<int> */
    private function managers(): array
    {
        return User::where('is_active', true)->get()->filter(fn ($u) => $u->isOwner() || $u->can('packaging.manage'))->pluck('id')->all();
    }
}
