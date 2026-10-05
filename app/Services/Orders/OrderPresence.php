<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Who has an order open right now, and who is editing it. Each open screen
 * checks in every few seconds; an entry not refreshed for STALE seconds
 * (tab closed, network gone) no longer counts.
 *
 * Editing is one person at a time: the first one to open the edit form holds
 * it, the others see who and wait. A manager can take over. While someone
 * other than the order's moderator edits it, the moderator's timer is held
 * (pushed back by the time spent), so they do not lose time or points.
 */
class OrderPresence
{
    public const STALE = 25;

    private const TTL = 120;

    /**
     * @param  'view'|'edit'|'leave'  $mode
     * @return array{others: list<array{id: int, name: string, mode: string}>, editor: ?array{id: int, name: string}, editing: bool}
     */
    public function checkIn(Order $order, User $user, string $mode, bool $takeOver = false): array
    {
        return Cache::lock('order-presence:'.$order->id, 5)->block(3, function () use ($order, $user, $mode, $takeOver) {
            $now = now()->getTimestamp();
            $all = $this->live($order->id, $now);
            $mine = $all[$user->id] ?? null;
            $editor = $this->editor($all, $user->id);

            if ($mode === 'leave') {
                unset($all[$user->id]);
            } else {
                $wantsEdit = $mode === 'edit';
                if ($wantsEdit && $editor && $takeOver) {
                    $all[$editor['id']]['mode'] = 'view'; // taken over: the other person now waits
                    $editor = null;
                }
                $editing = $wantsEdit && ! $editor;
                // Holding the moderator's timer while someone else edits their order.
                if ($editing && ($mine['mode'] ?? null) === 'edit' && $order->moderator_id && $order->moderator_id !== $user->id) {
                    $this->holdTimer($order, $now - (int) $mine['at']);
                }
                $all[$user->id] = [
                    'id' => $user->id, 'name' => $user->name, 'mode' => $editing ? 'edit' : 'view',
                    'since' => ($editing && ($mine['mode'] ?? null) === 'edit') ? $mine['since'] : $now, 'at' => $now,
                ];
            }
            Cache::put($this->key($order->id), $all, self::TTL);

            $editor = $this->editor($all, $user->id);

            return [
                'others' => array_values(array_map(fn ($e) => ['id' => $e['id'], 'name' => $e['name'], 'mode' => $e['mode']],
                    array_filter($all, fn ($e) => $e['id'] !== $user->id))),
                'editor' => $editor ? ['id' => $editor['id'], 'name' => $editor['name']] : null,
                'editing' => ($all[$user->id]['mode'] ?? null) === 'edit',
            ];
        });
    }

    /** Someone other than this user editing the order right now, if any. */
    public function editorOtherThan(int $orderId, int $userId): ?array
    {
        return $this->editor($this->live($orderId, now()->getTimestamp()), $userId);
    }

    private function editor(array $all, int $exceptUserId): ?array
    {
        $editors = array_filter($all, fn ($e) => $e['mode'] === 'edit' && $e['id'] !== $exceptUserId);
        uasort($editors, fn ($a, $b) => $a['since'] <=> $b['since']);

        return $editors ? reset($editors) : null;
    }

    private function live(int $orderId, int $now): array
    {
        return array_filter((array) Cache::get($this->key($orderId), []), fn ($e) => $now - (int) $e['at'] <= self::STALE);
    }

    private function holdTimer(Order $order, int $seconds): void
    {
        $seconds = max(0, min($seconds, self::STALE));
        $due = DB::table('orders')->where('id', $order->id)->value('action_due_at');
        if ($seconds > 0 && $due && now()->lt($due)) {
            DB::table('orders')->where('id', $order->id)->where('action_due_at', $due) // unchanged since read
                ->update(['action_due_at' => \Illuminate\Support\Carbon::parse($due)->addSeconds($seconds)]);
        }
    }

    private function key(int $orderId): string
    {
        return 'order-presence:list:'.$orderId;
    }
}
