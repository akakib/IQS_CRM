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

    /** @param list<int>|null $orderIds null = every booked order not yet in a batch */
    public function release(?array $orderIds, User $by): ?int
    {
        $ids = Order::where('status_id', OrderStatus::idFor('ready_for_packaging'))->whereNull('batch_id')
            ->when($orderIds !== null, fn ($q) => $q->whereIn('id', $orderIds))->pluck('id');
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
            ->join('product_variants as v', 'v.id', '=', 'i.variant_id')
            ->where('o.batch_id', $batchId)->where('o.stock_issue_flag', false)
            ->groupBy('i.variant_id', 'i.name_snapshot', 'i.sku_snapshot', 'i.unit', 'v.shelf_code')
            ->orderByRaw('v.shelf_code is null, v.shelf_code')->orderBy('i.name_snapshot')
            ->get(['i.variant_id', 'i.name_snapshot as name', 'i.sku_snapshot as sku', 'i.unit', 'v.shelf_code',
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
        $q = rtrim(rtrim(number_format((float) $line->qty, 3, '.', ''), '0'), '.');

        return $line->unit === 'g' ? "{$q} g" : $q;
    }
}
