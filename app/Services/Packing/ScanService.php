<?php

namespace App\Services\Packing;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderStateMachine;
use Illuminate\Support\Facades\DB;

/**
 * Packing and handover scans (DB_DESIGN 3.5). Every scan is logged, good or
 * bad. Packers see items only, never customer contact details.
 *
 * @phpstan-type Result array{ok: bool, level: string, result: string, message: string, order?: array}
 */
class ScanService
{
    public function __construct(private OrderStateMachine $machine, private OrderService $orders) {}

    /** @return array{ok: bool, level: string, result: string, message: string, order?: array} */
    public function pack(string $code, User $by): array
    {
        [$label, $order, $early] = $this->lookup($code);
        if ($early) {
            return $this->log('packing', null, $code, $order, $label, $early, $by);
        }

        $key = OrderStatus::map()[$order->status_id]['key'];
        $mark = $order->packMark();
        $isCurrent = (int) $label->order_version === (int) $order->current_version;

        if ($order->stock_issue_flag) {
            return $this->log('packing', null, $code, $order, $label, $this->fail('blocked', __('Skip: an item was reported missing. Admin is deciding.')), $by);
        }

        // Re-scan of an edited, already packed order: the box was fixed and the NEW label applied.
        if (in_array($key, ['packed', 'ready_for_pickup'], true)) {
            if ($mark === 'repack' && $isCurrent) {
                $order->forceFill(['packed_version' => $order->current_version])->save();
                $this->orders->note($order, 'system', __('Repacked and new label scanned by :n.', ['n' => $by->name]), $by);

                return $this->log('packing', null, $code, $order, $label, ['ok' => true, 'level' => 'edited', 'result' => 'repack_done', 'message' => __('Repack done. Edited order.')], $by);
            }
            if ($mark === 'repack') {
                return $this->log('packing', null, $code, $order, $label, $this->fail('blocked', __('Edited after packing: repack. :d Print and scan the NEW label.', ['d' => $this->diff($order)])), $by);
            }

            return $this->log('packing', null, $code, $order, $label, $this->fail('duplicate', __('Already packed.'), 'warn'), $by);
        }

        if ($key !== 'ready_for_packaging') {
            return $this->log('packing', null, $code, $order, $label, $this->fail('blocked', __('Do not pack: order is :s.', ['s' => OrderStatus::map()[$order->status_id]['name']])), $by);
        }
        if (! $isCurrent) {
            return $this->log('packing', null, $code, $order, $label, $this->fail('blocked', __('Old label: the order changed. Print the new label first.')), $by);
        }

        $this->machine->transition($order, 'packed', $by, 'scan');
        $order->refresh()->forceFill(['packed_version' => $order->current_version])->save();

        return $this->log('packing', null, $code, $order, $label, ['ok' => true, 'level' => 'ok', 'result' => 'ok', 'message' => __('Packed.')], $by);
    }

    /** @return array{ok: bool, level: string, result: string, message: string, order?: array} */
    public function handover(int $sessionId, string $code, User $by): array
    {
        [$label, $order, $early] = $this->lookup($code);
        if ($early) {
            return $this->log('handover', $sessionId, $code, $order, $label, $early, $by);
        }

        if (DB::table('scan_logs')->where('handover_session_id', $sessionId)->where('order_id', $order->id)->whereIn('result', ['ok', 'relabel_done'])->exists()) {
            return $this->log('handover', $sessionId, $code, $order, $label, $this->fail('duplicate', __('Scanned twice: this parcel is already in this handover.'), 'warn'), $by);
        }

        $key = OrderStatus::map()[$order->status_id]['key'];
        if (! in_array($key, ['packed', 'ready_for_pickup'], true)) {
            return $this->log('handover', $sessionId, $code, $order, $label, $this->fail('blocked', __('Do not hand over: order is :s.', ['s' => OrderStatus::map()[$order->status_id]['name']])), $by);
        }
        if ($order->packMark() === 'repack') {
            return $this->log('handover', $sessionId, $code, $order, $label, $this->fail('blocked', __('Edited after packing: repack first. :d', ['d' => $this->diff($order)])), $by);
        }
        if ((int) $label->order_version < (int) $order->label_version || (int) $order->label_version < (int) $order->current_version) {
            return $this->log('handover', $sessionId, $code, $order, $label, $this->fail('blocked', __('New label needed (address or COD changed).'), 'orange'), $by);
        }
        $pendingCod = DB::table('order_amendments')->where('order_id', $order->id)->where('courier_action', 'update_cod')->whereNull('courier_action_done_at')->exists();
        if ($pendingCod) {
            return $this->log('handover', $sessionId, $code, $order, $label, $this->fail('blocked', __('COD not yet updated at Steadfast. Do not hand over.')), $by);
        }

        if ($key === 'packed') {
            $this->machine->transition($order, 'ready_for_pickup', $by, 'scan');
        }
        $this->machine->transition($order, 'handed_over', $by, 'scan');

        return $this->log('handover', $sessionId, $code, $order->refresh(), $label, [
            'ok' => true, 'level' => $order->edited_after_pack ? 'edited' : 'ok', 'result' => 'ok',
            'message' => $order->edited_after_pack ? __('Handed over (edited order).') : __('Handed over.'),
        ], $by);
    }

    /** @return array{0: ?object, 1: ?Order, 2: ?array} label, order, early failure */
    private function lookup(string $code): array
    {
        $code = strtoupper(trim($code));
        $label = DB::table('shipment_labels')->where('barcode', $code)->first();
        if (! $label) {
            return [null, null, $this->fail('unknown', __('Unknown label.'))];
        }
        $order = Order::with('items:id,order_id,name_snapshot,qty,unit')->find($label->order_id);
        if ($label->voided_at) {
            return [$label, $order, $this->fail('blocked', __('Old label: replace it. :r', ['r' => (string) $label->void_reason]))];
        }

        return [$label, $order, null];
    }

    /** What changed between the packed box and the current order. */
    private function diff(Order $order): string
    {
        $packed = DB::table('order_versions')->where('order_id', $order->id)->where('version_no', $order->packed_version)->value('items_snapshot');
        $old = collect(json_decode((string) $packed, true) ?: [])->keyBy('variant_id');
        $now = $order->items()->get()->keyBy('variant_id');
        $parts = [];
        foreach ($now as $id => $i) {
            $was = (float) ($old[$id]['qty'] ?? 0);
            if ($was !== (float) $i->qty) {
                $parts[] = $i->name_snapshot.' ×'.($was + 0).' → ×'.((float) $i->qty + 0);
            }
        }
        foreach ($old as $id => $o) {
            if (! isset($now[$id])) {
                $parts[] = __('remove :i', ['i' => $o['name_snapshot']]);
            }
        }

        return implode(', ', $parts);
    }

    private function fail(string $result, string $message, string $level = 'red'): array
    {
        return ['ok' => false, 'level' => $level, 'result' => $result, 'message' => $message];
    }

    private function log(string $station, ?int $sessionId, string $code, ?Order $order, ?object $label, array $r, User $by): array
    {
        DB::table('scan_logs')->insert([
            'station' => $station, 'handover_session_id' => $sessionId, 'code' => mb_substr(strtoupper(trim($code)), 0, 80),
            'order_id' => $order?->id, 'label_id' => $label?->id, 'result' => $r['result'], 'message' => mb_substr($r['message'], 0, 255),
            'user_id' => $by->id, 'created_at' => now(),
        ]);

        if ($order) {
            $r['order'] = [
                'order_no' => $order->order_no,
                'items' => $order->items->map(fn ($i) => $i->name_snapshot.' ×'.rtrim(rtrim((string) $i->qty, '0'), '.').($i->unit === 'g' ? ' g' : ''))->all(),
                'cod' => (float) $order->cod_amount,
            ];
        }

        return $r;
    }
}
