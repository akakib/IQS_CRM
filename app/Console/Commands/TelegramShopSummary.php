<?php

namespace App\Console\Commands;

use App\Models\OrderStatus;
use App\Services\Telegram\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** End-of-day report to the shop Telegram group: what was packed, handed over and is still waiting. */
class TelegramShopSummary extends Command
{
    protected $signature = 'telegram:shop-summary';

    protected $description = 'Post the end-of-day packaging summary to the shop Telegram group';

    public function handle(TelegramService $telegram): int
    {
        $count = fn (string $key) => DB::table('order_events')->where('to_status_id', OrderStatus::idFor($key))->whereDate('created_at', today())->count();
        $waiting = DB::table('orders')->whereIn('status_id', OrderStatus::idsFor(['ready_for_packaging', 'packed', 'ready_for_pickup']))->count();
        $missing = DB::table('stock_issue_reports')->where('status', 'open')->count();

        $text = '🧾 <b>'.__('End of day :d', ['d' => today()->format('d M')])."</b>\n"
            .__('Batches: :n', ['n' => DB::table('batches')->whereDate('released_at', today())->count()])."\n"
            .__('Packed: :n', ['n' => $count('packed')])."\n"
            .__('Handed over: :n', ['n' => $count('handed_over')])."\n"
            .__('Still in the shop: :n', ['n' => $waiting])
            .($missing ? "\n⚠️ ".__('Missing-item reports open: :n', ['n' => $missing]) : '');

        $telegram->send(config('services.telegram.shop_chat_id'), $text);
        $this->info('Sent.');

        return self::SUCCESS;
    }
}
