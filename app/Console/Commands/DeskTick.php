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
        // Each step on its own: one bad order in one step must not stop the others (bookings, breaks...) every minute.
        $released = $this->step('sweepExpired', fn () => $desk->sweepExpired(), 0);
        $this->step('armUntouched', fn () => $desk->armUntouchedAll());
        $assigned = $this->step('autoAssign', fn () => $desk->autoAssign(), 0);
        $this->step('coverAway', fn () => $desk->coverAway()); // a call-back or hold date due while its person is away: a free colleague calls
        $this->step('followUpHolds', fn () => $desk->followUpHolds());
        $this->step('remindPaymentChecks', fn () => $desk->remindPaymentChecks());
        if (Cache::add('woo:sweep', 1, now()->addMinutes(9))) {
            $this->step('wooSweep', fn () => app(\App\Services\Orders\WooOrderSweep::class)->run()); // website orders a webhook did not bring
        }
        $this->step('recoverStale', fn () => $desk->recoverStale()); // a booking that stopped half way: ask the courier before trying again
        $this->step('runBookings', fn () => $desk->runBookings());
        $closed = $this->step('breaksAutoClose', fn () => $breaks->autoClose(), 0);
        $this->step('chatCloseStale', fn () => app(\App\Services\Work\ChatService::class)->closeStale()); // Communication left on by someone who left
        $this->step('courierRetry', fn () => app(\App\Services\Courier\CourierUpdateProcessor::class)->retryPending()); // courier messages not applied yet
        $this->step('trackingRetry', fn () => app(\App\Services\Tracking\TrackingSender::class)->retryStuck()); // ad events left queued
        $this->step('digest', fn () => $this->digest($telegram));

        $this->line("released {$released}, auto-assigned {$assigned}, breaks closed {$closed}");

        return self::SUCCESS;
    }

    /** Runs one step; a failure is written to the log (with the step name) and the tick goes on. */
    private function step(string $name, callable $run, mixed $fallback = null): mixed
    {
        try {
            return $run();
        } catch (\Throwable $e) {
            report($e);
            \Illuminate\Support\Facades\Log::error("desk:tick step {$name} failed: {$e->getMessage()}");
            $this->error("{$name} failed");

            return $fallback;
        }
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

        // Paused: the orders booked since are not in the shop's list yet, so the shop group does not hear of them.
        $hide = app(\App\Services\Packaging\PackagingPause::class)->visibleSql('orders');
        $reached = DB::table('orders')->where('packaging_sent_at', '>', $since)->when($hide !== '', fn ($q) => $q->whereRaw($hide))->count();
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
        $waiting = DB::table('orders')->where('status_id', OrderStatus::idFor('ready_for_packaging'))->when($hide !== '', fn ($q) => $q->whereRaw($hide))->count();
        $telegram->send(config('services.telegram.shop_chat_id'),
            '📦 '.__(':n new for packaging · :w waiting in total', ['n' => $reached, 'w' => $waiting]).($lines ? "\n".implode("\n", $lines) : ''));
    }
}
