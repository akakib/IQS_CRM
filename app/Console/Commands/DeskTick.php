<?php

namespace App\Console\Commands;

use App\Models\OrderStatus;
use App\Services\Orders\DeskService;
use App\Services\Telegram\TelegramService;
use App\Services\Work\BreakService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The once-a-minute sweep for things nobody clicked: expired action timers,
 * returned No response orders, auto-assign, booking retries, breaks left
 * open, and the packaging digest. Each part is a few indexed queries.
 */
class DeskTick extends Command
{
    protected $signature = 'desk:tick';

    protected $description = 'Order desk housekeeping (timers, auto-assign, booking retries, breaks, digest)';

    public function handle(DeskService $desk, BreakService $breaks, TelegramService $telegram): int
    {
        $released = $desk->sweepExpired();
        $desk->armUntouchedAll();
        $assigned = $desk->autoAssign();
        $desk->followUpHolds();
        $desk->recoverStale(); // a booking that stopped half way: ask the courier before trying again
        $desk->runBookings();
        $closed = $breaks->autoClose();
        $this->digest($telegram);

        $this->line("released {$released}, auto-assigned {$assigned}, breaks closed {$closed}");

        return self::SUCCESS;
    }

    /** One message per interval: how many orders reached packaging, plus exceptions. No customer details. */
    private function digest(TelegramService $telegram): void
    {
        $last = Cache::get('desk:digest_at');
        $since = $last ? Carbon::parse($last) : now()->subMinutes((int) settings('telegram.digest_minutes'));
        if ($last && $since->diffInMinutes(now(), true) < (int) settings('telegram.digest_minutes')) {
            return;
        }
        Cache::forever('desk:digest_at', now()->toIso8601String());

        $reached = DB::table('orders')->where('packaging_sent_at', '>', $since)->count();
        $lines = [];
        $event = fn (string $key) => DB::table('order_events as e')->join('orders as o', 'o.id', '=', 'e.order_id')
            ->where('e.created_at', '>', $since)->where('e.to_status_id', OrderStatus::idFor($key))->whereNotNull('o.packaging_sent_at');

        foreach ($event('cancelled')->pluck('o.order_no') as $no) {
            $lines[] = '❌ '.__(':no cancelled: do not pack', ['no' => $no]);
        }
        foreach ($event('hold')->pluck('o.order_no') as $no) {
            $lines[] = '⏸ '.__(':no on hold', ['no' => $no]);
        }
        $repack = DB::table('orders')->whereNotNull('packed_version')->whereColumn('packed_version', '<', 'current_version')
            ->where('updated_at', '>', $since)->whereIn('status_id', OrderStatus::idsFor(['packed', 'ready_for_pickup']))->pluck('order_no');
        foreach ($repack as $no) {
            $lines[] = '🔴 '.__(':no edited after packaging: repack', ['no' => $no]);
        }

        if ($reached === 0 && $lines === []) {
            return;
        }
        $waiting = DB::table('orders')->where('status_id', OrderStatus::idFor('ready_for_packaging'))->count();
        $telegram->send(config('services.telegram.shop_chat_id'),
            '📦 '.__(':n new for packaging · :w waiting in total', ['n' => $reached, 'w' => $waiting]).($lines ? "\n".implode("\n", $lines) : ''));
    }
}
