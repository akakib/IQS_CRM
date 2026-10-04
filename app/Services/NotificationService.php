<?php

namespace App\Services;

use App\Jobs\SendNotificationDelivery;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY way any module raises a notification. Recipients come from the
 * alert matrix (notification_rules); nothing calls Telegram/SMS directly.
 *
 *   app(NotificationService::class)->send('delivery_issue', 'Rider: customer not answering', null, [
 *       'link' => route('orders.show', $order), 'subject' => ['order', $order->id],
 *       'order_owner_id' => $order->owner_id, 'group_key' => 'delivery_issue:'.$order->id,
 *   ]);
 */
class NotificationService
{
    /**
     * @param  array{link?: string, subject?: array{0: string, 1: int}, group_key?: string, priority?: string,
     *               order_owner_id?: int|null, user_ids?: list<int>}  $opts
     * @return int number of people notified
     */
    public function send(string $typeKey, string $title, ?string $body = null, array $opts = []): int
    {
        $type = DB::table('notification_types')->where('system_key', $typeKey)->where('is_active', true)->first();
        if (! $type) {
            return 0;
        }

        $priority = $opts['priority'] ?? $type->default_priority;
        $channels = $this->recipients($type->id, $opts);
        if ($channels === []) {
            return 0;
        }

        // Muted (non-urgent only) and inactive staff are skipped.
        $userIds = DB::table('users')->whereIn('id', array_keys($channels))->where('is_active', true)->pluck('id')->all();
        if ($priority !== 'urgent') {
            $muted = DB::table('user_notification_prefs')->where('type_id', $type->id)
                ->whereIn('user_id', $userIds)->where('mute_until', '>', now())->pluck('user_id')->all();
            $userIds = array_diff($userIds, $muted);
        }

        $now = now();
        [$subjectType, $subjectId] = $opts['subject'] ?? [null, null];
        $groupKey = $opts['group_key'] ?? null;

        foreach ($userIds as $userId) {
            $id = null;

            if ($groupKey) {
                $existing = DB::table('app_notifications')
                    ->where('user_id', $userId)->where('group_key', $groupKey)->whereNull('read_at')
                    ->where('created_at', '>=', $now->copy()->subHours((int) config('notifications.group_window_hours', 12)))
                    ->value('id');
                if ($existing) {
                    DB::table('app_notifications')->where('id', $existing)->update([
                        'group_count' => DB::raw('group_count + 1'),
                        'title' => $title, 'body' => $body, 'priority' => $priority, 'updated_at' => $now,
                    ]);
                    $id = $existing;
                }
            }

            $id ??= DB::table('app_notifications')->insertGetId([
                'user_id' => $userId,
                'type_id' => $type->id,
                'priority' => $priority,
                'title' => mb_substr($title, 0, 200),
                'body' => $body === null ? null : mb_substr($body, 0, 500),
                'link_url' => $opts['link'] ?? null,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'group_key' => $groupKey,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach (['telegram', 'sms'] as $channel) {
                if ($channels[$userId][$channel] ?? false) {
                    $deliveryId = DB::table('notification_deliveries')->insertGetId([
                        'notification_id' => $id, 'channel' => $channel, 'status' => 'queued', 'created_at' => $now,
                    ]);
                    SendNotificationDelivery::dispatch($deliveryId);
                }
            }
        }

        return count($userIds);
    }

    /** The underlying task is done: urgent items stop being highlighted. */
    public function markActed(string $subjectType, int $subjectId, ?string $typeKey = null): void
    {
        DB::table('app_notifications')
            ->where('subject_type', $subjectType)->where('subject_id', $subjectId)->whereNull('acted_at')
            ->when($typeKey, fn ($q) => $q->whereIn('type_id', DB::table('notification_types')->where('system_key', $typeKey)->select('id')))
            ->update(['acted_at' => now(), 'updated_at' => now()]);
    }

    /** @return array<int, array{in_app: bool, telegram: bool, sms: bool}> user id => channels */
    private function recipients(int $typeId, array $opts): array
    {
        $rules = DB::table('notification_rules')->where('type_id', $typeId)->where('is_active', true)->get();
        $out = [];
        $add = function (iterable $ids, object $rule) use (&$out) {
            foreach ($ids as $id) {
                $id = (int) $id;
                $out[$id] = [
                    'in_app' => ($out[$id]['in_app'] ?? false) || (bool) $rule->channel_in_app,
                    'telegram' => ($out[$id]['telegram'] ?? false) || (bool) $rule->channel_telegram,
                    'sms' => ($out[$id]['sms'] ?? false) || (bool) $rule->channel_sms,
                ];
            }
        };

        foreach ($rules as $rule) {
            match ($rule->target) {
                'role' => $add($this->usersWithRole(fn ($q) => $q->where('r.id', $rule->role_id)), $rule),
                'user' => $add(array_filter([$rule->user_id]), $rule),
                'order_owner' => $add(array_filter([$opts['order_owner_id'] ?? null]), $rule),
                'actor_manager' => $add($this->usersWithRole(fn ($q) => $q->where('r.system_key', 'manager')), $rule),
            };
        }

        // Explicit recipients from the caller always get the in-app copy.
        foreach ($opts['user_ids'] ?? [] as $id) {
            $out[(int) $id]['in_app'] = true;
        }

        return $out;
    }

    /** @return list<int> */
    private function usersWithRole(callable $where): array
    {
        $now = now();

        return $where(DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')->whereNull('r.deleted_at'))
            ->where(fn ($q) => $q->whereNull('ur.starts_at')->orWhere('ur.starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ur.expires_at')->orWhere('ur.expires_at', '>', $now))
            ->distinct()->pluck('ur.user_id')->all();
    }
}
