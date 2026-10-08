<?php

namespace App\Services\Packaging;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Packaging and handover scans (DB_DESIGN 3.5). Every scan is logged, good or
 * bad. Packers see items only, never customer contact details.
 *
 * @phpstan-type Result array{ok: bool, level: string, result: string, message: string, order?: array}
 */
class ScanService
{
    /** True while a parcel is being handed over by a tick instead of a label scan (written to the log). */
    private bool $byHand = false;

    public function __construct(private OrderStateMachine $machine, private OrderService $orders) {}

    /**
     * Packaging scan, step 1: scanning the label opens the order and makes the
     * scanner its packer. Nothing is packed yet; step 2 is finish().
     *
     * @return array{ok: bool, level: string, result: string, message: string, order?: array, checklist?: array}
     */
    public function open(string $code, User $by): array
    {
        [$label, $order, $early] = $this->lookup($code);
        if ($early) {
            return $this->log('packaging', null, $code, $order, $label, $early, $by);
        }

        $key = OrderStatus::map()[$order->status_id]['key'];
        $mark = $order->packMark();
        $isCurrent = (int) $label->order_version === (int) $order->current_version;

        if ($order->stock_issue_flag) {
            return $this->log('packaging', null, $code, $order, $label, $this->fail('blocked', __('Skip: an item was reported missing. Admin is deciding.')), $by);
        }

        $repack = false;
        if (in_array($key, ['packed', 'ready_for_pickup'], true)) {
            if ($mark !== 'repack') {
                return $this->log('packaging', null, $code, $order, $label, $this->fail('duplicate', __('Already packed.'), 'warn'), $by);
            }
            if (! $isCurrent) {
                return $this->log('packaging', null, $code, $order, $label, $this->fail('blocked', __('Edited after packaging: repack. :d Print and scan the NEW label.', ['d' => $this->diff($order)])), $by);
            }
            $repack = true; // new label on an edited order: fix the box, tick the items again
        } elseif ($key !== 'ready_for_packaging') {
            return $this->log('packaging', null, $code, $order, $label, $this->fail('blocked', __('Do not pack: order is :s.', ['s' => OrderStatus::map()[$order->status_id]['name']])), $by);
        } elseif (! $isCurrent) {
            return $this->log('packaging', null, $code, $order, $label, $this->fail('blocked', __('Old label: the order changed. Print the new label first.')), $by);
        }

        if ((int) $order->packer_id !== $by->id) {
            $previous = $order->packer_id ? DB::table('users')->where('id', $order->packer_id)->value('name') : null;
            $order->forceFill(['packer_id' => $by->id, 'packaging_started_at' => now()])->save();
            $this->orders->note($order, 'system', $previous
                ? __('Packaging taken over by :n (was :p).', ['n' => $by->name, 'p' => $previous])
                : __('Packaging started by :n.', ['n' => $by->name]), $by);
        }

        $r = $this->log('packaging', null, $code, $order, $label, [
            'ok' => true, 'level' => $repack ? 'edited' : 'ok', 'result' => 'ok',
            'message' => $repack ? __('Repack: fix the box, then tick every item.') : __('Yours. Tick every item, then press Packed.'),
        ], $by);
        $r['checklist'] = $this->checklist($order, $repack);

        return $r;
    }

    /** @return array{id: int, order_no: string, repack: bool, diff: ?string, moderator: ?string, items: list<array>} what the packer ticks (no customer details, no prices) */
    public function checklist(Order $order, bool $repack): array
    {
        return [
            'id' => $order->id,
            'order_no' => $order->order_no,
            'repack' => $repack,
            'diff' => $repack ? $this->diff($order) : null,
            'moderator' => $order->moderator_id ? DB::table('users')->where('id', $order->moderator_id)->value('name') : null,
            'items' => DB::table('order_items as i')->join('product_variants as v', 'v.id', '=', 'i.variant_id')
                ->where('i.order_id', $order->id)->orderBy('v.shelf_code')->orderBy('i.id')
                ->get(['i.id', 'i.name_snapshot as name', 'i.qty', 'i.unit', 'v.shelf_code as shelf'])
                ->map(fn ($i) => ['id' => $i->id, 'name' => $i->name, 'qty' => \App\Support\Units::qty($i->qty, $i->unit), 'shelf' => $i->shelf])->all(),
        ];
    }

    /**
     * Packaging, step 2: every item was ticked. The server checks the list
     * itself, so a half-ticked order can never become Packed.
     *
     * @param  list<int>  $tickedItemIds
     */
    public function finish(Order $order, array $tickedItemIds, User $by): string
    {
        if ((int) $order->packer_id !== $by->id) {
            throw ValidationException::withMessages(['order' => __('Scan the label first: this order is not with you.')]);
        }
        $itemIds = DB::table('order_items')->where('order_id', $order->id)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $ticked = collect($tickedItemIds)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
        if ($itemIds !== $ticked) {
            throw ValidationException::withMessages(['items' => __('Tick every item first.')]);
        }

        $key = OrderStatus::map()[$order->status_id]['key'];
        if ($key === 'ready_for_packaging') {
            $this->machine->transition($order, 'packed', $by, 'scan');
            $order->refresh()->forceFill(['packed_version' => $order->current_version])->save();
            $stock = app(\App\Services\Catalog\StockService::class);
            $stock->settleOrder($order, $stock->itemsOf($order), 'unpacked', $by); // off the shelf

            return __(':no packed.', ['no' => $order->order_no]);
        }
        if (in_array($key, ['packed', 'ready_for_pickup'], true) && $order->packMark() === 'repack' && (int) $order->label_version === (int) $order->current_version) {
            $order->forceFill(['packed_version' => $order->current_version, 'packed_at' => now()])->save();
            $stock = app(\App\Services\Catalog\StockService::class);
            $stock->settleOrder($order, $stock->itemsOf($order), 'unpacked', $by); // only what the edit changed
            $this->orders->note($order, 'system', __('Repacked and new label scanned by :n.', ['n' => $by->name]), $by);

            return __(':no repacked.', ['no' => $order->order_no]);
        }

        throw ValidationException::withMessages(['order' => __('Do not pack: order is :s.', ['s' => OrderStatus::map()[$order->status_id]['name']])]);
    }

    /** A packer cannot finish the order (item missing, damaged): Hold with a reason; the moderator and managers are told. */
    public function hold(Order $order, int $reasonId, ?string $note, User $by): void
    {
        $this->machine->transition($order, 'hold', $by, 'scan', $reasonId, $note);
        $order->forceFill(['packer_id' => null, 'packaging_started_at' => null])->save();

        $reason = DB::table('status_reasons')->where('id', $reasonId)->value('label_en');
        app(\App\Services\NotificationService::class)->send('order_held_by_packer', __(':no held by packer :n', ['no' => $order->order_no, 'n' => $by->name]), trim($reason.($note ? ' · '.$note : '')), [
            'link' => route('orders.show', $order), 'subject' => ['order', $order->id],
            'user_ids' => array_filter(array_merge([$order->moderator_id], app(\App\Services\Orders\DeskService::class)->managerIds())),
        ]);
    }

    /** @return array{ok: bool, level: string, result: string, message: string, order?: array} */
    public function handover(int $sessionId, string $code, User $by, bool $byHand = false): array
    {
        $this->byHand = $byHand;
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
            return $this->log('handover', $sessionId, $code, $order, $label, $this->fail('blocked', __('Edited after packaging: repack first. :d', ['d' => $this->diff($order)])), $by);
        }
        if ((int) $label->order_version < (int) $order->label_version || (int) $order->label_version < (int) $order->current_version) {
            return $this->log('handover', $sessionId, $code, $order, $label, $this->fail('blocked', __('New label needed (address or COD changed).'), 'orange'), $by);
        }
        $pendingCod = DB::table('order_amendments')->where('order_id', $order->id)->where('courier_action', 'update_cod')->whereNull('courier_action_done_at')->exists();
        if ($pendingCod) {
            return $this->log('handover', $sessionId, $code, $order, $label, $this->fail('blocked', __('COD changed to ৳:b but not updated at the courier yet. Keep the parcel; ask the moderator or admin to update it, then scan again.', ['b' => number_format((float) $order->cod_amount)]), 'cod'), $by); // own colour on the screen: not a wrong parcel, a cash amount to fix
        }

        if ($key === 'packed') {
            $this->machine->transition($order, 'ready_for_pickup', $by, 'scan');
        }
        $this->machine->transition($order, 'handed_over', $by, 'scan', null, $byHand ? __('ticked by hand, label not scanned') : null);

        return $this->log('handover', $sessionId, $code, $order->refresh(), $label, [
            'ok' => true, 'level' => $order->edited_after_pack ? 'edited' : 'ok', 'result' => 'ok',
            'message' => $byHand ? __('Handed over (by hand).') : ($order->edited_after_pack ? __('Handed over (edited order).') : __('Handed over.')),
        ], $by);
    }

    /**
     * Handover without a scanner: the same checks as a scan, run against the
     * order's current label. What is lost is the proof that the parcel in
     * hand carries that label, so every such handover is marked "by hand".
     *
     * @return array{ok: bool, level: string, result: string, message: string, order?: array}
     */
    public function handoverByHand(int $sessionId, Order $order, User $by): array
    {
        $barcode = DB::table('shipment_labels')->where('order_id', $order->id)->whereNull('voided_at')->orderByDesc('id')->value('barcode');
        if (! $barcode) {
            return ['ok' => false, 'level' => 'red', 'result' => 'blocked', 'message' => __('No label: book the courier first.'), 'order' => ['order_no' => $order->order_no]];
        }
        try {
            return $this->handover($sessionId, $barcode, $by, true);
        } finally {
            $this->byHand = false;
        }
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
        if ($label->voided_at && $order && ($order->unpack_needed_at || $order->taken_back_at || OrderStatus::map()[$order->status_id]['key'] === 'cancelled')) {
            return [$label, $order, $this->fail('blocked', __('Do not pack: :r. Put the items back on the shelf.', ['r' => (string) $label->void_reason]))];
        }
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

    /**
     * A scan refused this order: say so in the order's history (when, where,
     * why, who packed it), once per handover and reason; scanning it again
     * only counts up. The first stop at a handover also tells the person
     * responsible for the order and the managers, while the rider is there.
     */
    public function noteStop(string $station, ?int $sessionId, int $orderId, string $message, ?int $byId, \Illuminate\Support\Carbon $at, bool $notify = true): void
    {
        $key = $station.':'.($sessionId ?? $at->toDateString()).':'.md5($message);
        $note = DB::table('order_notes')->where('order_id', $orderId)->where('note_type', 'scan')->where('meta->key', $key)->first(['id', 'meta']);
        if ($note) {
            $meta = json_decode((string) $note->meta, true) ?: [];
            $meta['times'] = ($meta['times'] ?? 1) + 1;
            DB::table('order_notes')->where('id', $note->id)->update([
                'body' => $meta['text'].' · '.__('Scanned :n times', ['n' => $meta['times']]), 'meta' => json_encode($meta),
            ]);

            return;
        }

        $order = DB::table('orders')->where('id', $orderId)->first(['id', 'order_no', 'moderator_id', 'packer_id', 'packed_at']);
        $session = $sessionId ? DB::table('handover_sessions')->where('id', $sessionId)->first(['pickup_date', 'rider_name']) : null;
        $packer = $order->packer_id ? DB::table('users')->where('id', $order->packer_id)->value('name') : null;
        $text = implode(' · ', array_filter([
            ($station === 'handover' ? __('Stopped at handover') : __('Stopped at packaging')).': '.$message,
            $session ? trim(__('Handover of :d', ['d' => \Illuminate\Support\Carbon::parse($session->pickup_date)->format('d M')]).($session->rider_name ? ', '.__('rider :r', ['r' => $session->rider_name]) : '')) : null,
            // A packing time after the stop belongs to a later repack: then only the packer's name.
            $packer && $order->packed_at && \Illuminate\Support\Carbon::parse($order->packed_at)->lte($at) ? __('Packed by :p at :t', ['p' => $packer, 't' => \Illuminate\Support\Carbon::parse($order->packed_at)->format('g:i A')]) : ($packer ? __('Packer: :p', ['p' => $packer]) : null),
        ]));
        DB::table('order_notes')->insert([
            'order_id' => $orderId, 'note_type' => 'scan', 'body' => $text, 'user_id' => $byId, 'created_at' => $at,
            'meta' => json_encode(['key' => $key, 'text' => $text, 'times' => 1, 'station' => $station, 'handover_session_id' => $sessionId]),
        ]);

        if ($notify && $station === 'handover') {
            app(\App\Services\NotificationService::class)->send('scan_stopped', __(':no stopped at handover', ['no' => $order->order_no]), $message, [
                'link' => route('orders.show', $orderId), 'subject' => ['order', $orderId],
                'user_ids' => array_values(array_unique(array_filter([$order->moderator_id, ...app(\App\Services\Orders\DeskService::class)->managerIds()]))),
            ]);
        }
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
            'user_id' => $by->id, 'manual' => $this->byHand, 'created_at' => now(),
        ]);
        if ($order && $r['result'] === 'blocked') {
            $this->noteStop($station, $sessionId, $order->id, $r['message'], $by->id, now());
        }

        if ($order) {
            $r['order'] = [
                'order_no' => $order->order_no,
                'items' => $order->items->map(fn ($i) => $i->name_snapshot.' '.\App\Support\Units::qty($i->qty, $i->unit))->all(),
            ];
        }

        return $r;
    }
}
