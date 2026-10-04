<?php

namespace App\Jobs;

use App\Services\Telegram\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sends one queued Telegram/SMS copy of a notification. Telegram goes
 * through the bot (fake mode without a token). SMS is still a stub until a
 * gateway is chosen: it is logged and marked sent.
 */
class SendNotificationDelivery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public int $deliveryId) {}

    public function handle(TelegramService $telegram): void
    {
        $delivery = DB::table('notification_deliveries as d')
            ->join('app_notifications as n', 'n.id', '=', 'd.notification_id')
            ->join('users as u', 'u.id', '=', 'n.user_id')
            ->where('d.id', $this->deliveryId)->where('d.status', 'queued')
            ->first(['d.id', 'd.channel', 'n.title', 'n.body', 'n.link_url', 'n.priority', 'u.id as user_id', 'u.phone', 'u.telegram_user_id']);

        if (! $delivery) {
            return;
        }

        $address = $delivery->channel === 'telegram' ? $delivery->telegram_user_id : $delivery->phone;
        if (! $address) {
            DB::table('notification_deliveries')->where('id', $delivery->id)
                ->update(['status' => 'failed', 'error' => "No {$delivery->channel} address on the staff profile."]);

            return;
        }

        if ($delivery->channel === 'telegram') {
            $text = ($delivery->priority === 'urgent' ? '🔴 ' : '').'<b>'.e($delivery->title).'</b>'.($delivery->body ? "\n".e($delivery->body) : '');
            $buttons = $delivery->link_url ? [[['text' => __('Open'), 'url' => $delivery->link_url]]] : [];
            $sent = $telegram->send($address, $text, $buttons);
            DB::table('notification_deliveries')->where('id', $delivery->id)->update($sent
                ? ['status' => 'sent', 'sent_at' => now()]
                : ['status' => 'failed', 'error' => 'Telegram did not accept the message.']);

            return;
        }

        Log::info("[notification stub] sms to user {$delivery->user_id}: {$delivery->title}");
        DB::table('notification_deliveries')->where('id', $delivery->id)->update(['status' => 'sent', 'sent_at' => now()]);
    }
}
