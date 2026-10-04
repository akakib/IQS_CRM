<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sends one queued Telegram/SMS copy of a notification. Real sending comes
 * with the Telegram bot (Step 4) and an SMS gateway; until then the channel
 * is a stub that only writes to the log and marks the delivery sent.
 */
class SendNotificationDelivery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public int $deliveryId) {}

    public function handle(): void
    {
        $delivery = DB::table('notification_deliveries as d')
            ->join('app_notifications as n', 'n.id', '=', 'd.notification_id')
            ->join('users as u', 'u.id', '=', 'n.user_id')
            ->where('d.id', $this->deliveryId)->where('d.status', 'queued')
            ->first(['d.id', 'd.channel', 'n.title', 'n.body', 'u.id as user_id', 'u.phone', 'u.telegram_user_id']);

        if (! $delivery) {
            return;
        }

        $address = $delivery->channel === 'telegram' ? $delivery->telegram_user_id : $delivery->phone;
        if (! $address) {
            DB::table('notification_deliveries')->where('id', $delivery->id)
                ->update(['status' => 'failed', 'error' => "No {$delivery->channel} address on the staff profile."]);

            return;
        }

        Log::info("[notification stub] {$delivery->channel} to user {$delivery->user_id}: {$delivery->title}");
        DB::table('notification_deliveries')->where('id', $delivery->id)->update(['status' => 'sent', 'sent_at' => now()]);
    }
}
