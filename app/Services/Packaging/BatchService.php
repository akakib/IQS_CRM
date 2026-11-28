<?php

namespace App\Services\Packaging;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\User;
use App\Services\Orders\OrderStateMachine;
use App\Services\Telegram\TelegramService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Batches: booked orders released together to the shop. The bot posts one
 * consolidated pick list sorted by shelf (no customer details), with
 * Picked / Item missing buttons tied to the packer's Telegram account.
 */
class BatchService
{
    public function __construct(private TelegramService $telegram, private OrderStateMachine $machine) {}

    /**
     * The next batch to print, by paper: the oldest orders waiting (not in a batch yet), one by one, until the
     * batch list would pass Settings > Packaging lines (an order = 1 line + 1 per item) or orders. An order is
     * never split; one order bigger than the limit goes alone. Oldest first: a big order never waits behind small ones.
     *
     * @return array{ids: list<int>, lines: int, waiting: int}
     */
    public function nextUp(?User $for = null): array
    {
        $hide = $for && app(PackagingPause::class)->canControl($for) ? '' : app(PackagingPause::class)->visibleSql('o');
        $rows = DB::table('orders as o')->where('o.status_id', OrderStatus::idFor('ready_for_packaging'))->whereNull('o.batch_id')->whereNotNull('o.active_shipment_id')
            ->whereNull('o.packer_id') // a packer already started it: it is in hand, not in a batch
            ->when($hide !== '', fn ($q) => $q->whereRaw($hide))
            ->orderBy('o.packaging_sent_at')->orderBy('o.id')->limit(300)
            ->get(['o.id', DB::raw('(SELECT COUNT(*) FROM order_items i WHERE i.order_id = o.id) as items')]);
        $maxLines = max(5, (int) settings('packaging.batch_lines'));
        $maxOrders = max(1, (int) settings('packaging.batch_orders'));
        $ids = [];
        $lines = 0;
        foreach ($rows as $r) {
            $need = 1 + (int) $r->items;
            if ($ids && ($lines + $need > $maxLines || count($ids) >= $maxOrders)) {
                break;
            }
            $ids[] = (int) $r->id;
            $lines += $need;
        }

        return ['ids' => $ids, 'lines' => $lines, 'waiting' => $rows->count()];
    }

    /** Makes the next batch (nextUp) and numbers it B + date + count of the day. Null when nothing waits. */
    public function makeNext(User $by): ?int
    {
        // One at a time: two people pressing together never put an order in two batches, nor take the same number.
        return \Illuminate\Support\Facades\Cache::lock('packaging-next-batch', 15)->block(10, fn () => DB::transaction(function () use ($by) {
            $ids = $this->nextUp($by)['ids'];
            if (! $ids) {
                return null;
            }
            $n = DB::table('batches')->whereDate('created_at', today())->count() + 1;
            $id = DB::table('batches')->insertGetId([
                'batch_no' => 'B'.now()->format('dmy').'-'.str_pad((string) $n, 2, '0', STR_PAD_LEFT), 'pickup_date' => today(), 'status' => 'released',
                'released_by' => $by->id, 'released_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $taken = DB::table('orders')->whereIn('id', $ids)->whereNull('batch_id')->update(['batch_id' => $id, 'updated_at' => now()]);
            if ($taken === 0) {
                DB::table('batches')->where('id', $id)->delete();

                return null;
            }

            return $id;
        }));
    }

    /** @param list<int>|null $orderIds null = every booked order not yet in a batch */
    public function release(?array $orderIds, User $by): ?int
    {
        $hide = app(PackagingPause::class)->visibleSql('orders'); // paused: what came since stays out of the shop's pick list
        $ids = Order::where('status_id', OrderStatus::idFor('ready_for_packaging'))->whereNull('batch_id')
            ->when($orderIds !== null, fn ($q) => $q->whereIn('id', $orderIds))->when($hide !== '', fn ($q) => $q->whereRaw($hide))->pluck('id');
        if ($ids->isEmpty()) {
            return null;
        }

        $batchId = DB::transaction(function () use ($ids, $by) {
            $n = DB::table('batches')->whereDate('created_at', today())->count() + 1;
            $id = DB::table('batches')->insertGetId([
                'batch_no' => 'B'.now()->format('ymd').'-'.$n, 'pickup_date' => today(), 'status' => 'released',
                'released_by' => $by->id, 'released_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('orders')->whereIn('id', $ids)->update(['batch_id' => $id, 'updated_at' => now()]);

            return $id;
        });

        $batch = DB::table('batches')->find($batchId);
        $lines = $this->pickList($batchId);
        $text = "📦 <b>{$batch->batch_no}</b> · ".$ids->count().' '.__('orders')."\n"
            .$lines->map(fn ($l) => ($l->shelf_code ? "[{$l->shelf_code}] " : '').e($l->name).' × '.$this->qty($l))->join("\n");
        $messageId = $this->telegram->send(config('services.telegram.shop_chat_id'), $text, [[
            ['text' => '✅ '.__('Picked'), 'callback_data' => "picked:{$batchId}"],
            ['text' => '⚠️ '.__('Item missing'), 'web_app' => ['url' => route('tg.app', ['batch' => $batchId])]],
        ]]);
        DB::table('batches')->where('id', $batchId)->update(['telegram_message_id' => $messageId]);

        return $batchId;
    }

    /** One line per variant: total quantity across the batch, sorted by shelf. */
    public function pickList(int $batchId): Collection
    {
        return DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')
            ->join('product_variants as v', 'v.id', '=', 'i.variant_id')->leftJoin('products as p', 'p.id', '=', 'v.product_id')
            ->where('o.batch_id', $batchId)->where('o.stock_issue_flag', false)
            ->groupBy('i.variant_id', 'i.name_snapshot', 'i.sku_snapshot', 'i.unit', 'v.shelf_code', 'p.image_url')
            ->orderByRaw('v.shelf_code is null, v.shelf_code')->orderBy('i.name_snapshot')
            ->get(['i.variant_id', 'i.name_snapshot as name', 'i.sku_snapshot as sku', 'i.unit', 'v.shelf_code', 'p.image_url',
                DB::raw('SUM(i.qty) as qty'), DB::raw('COUNT(DISTINCT o.id) as orders')]);
    }

    public function markPicked(int $batchId, ?User $by): void
    {
        DB::table('batches')->where('id', $batchId)->where('status', 'released')
            ->update(['status' => 'picked', 'picked_by' => $by?->id, 'picked_at' => now(), 'updated_at' => now()]);
    }

    /** Packaging finished: packed orders wait on the pickup shelf. */
    public function done(int $batchId, User $by): int
    {
        $packed = Order::where('batch_id', $batchId)->where('status_id', OrderStatus::idFor('packed'))->get();
        foreach ($packed as $order) {
            $this->machine->transition($order, 'ready_for_pickup', $by, 'scan');
        }
        DB::table('batches')->where('id', $batchId)->update(['status' => 'done', 'done_at' => now(), 'updated_at' => now()]);

        return $packed->count();
    }

    private function qty(object $line): string
    {
        return \App\Support\Units::qty($line->qty, $line->unit);
    }
}
