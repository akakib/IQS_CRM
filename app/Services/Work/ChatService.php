<?php

namespace App\Services\Work;

use App\Models\ChatChannel;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Chat mode. While it is on, the time counts as work (Team Activity), and
 * the person counts what they answered, per channel. A count taken back or
 * a chat that ended without an order needs a reason. Website orders still
 * come first: being in chat does not stop their notices.
 */
class ChatService
{
    /** Channels this person answers (admins and owners: all active ones). */
    public function channelsFor(User $user): Collection
    {
        $q = ChatChannel::where('is_active', true)->orderBy('sort_order')->orderBy('name');

        return $user->isOwner() ? $q->get() : $q->whereHas('users', fn ($u) => $u->whereKey($user->id))->get();
    }

    public function openSession(int $userId): ?object
    {
        return DB::table('chat_sessions')->where('user_id', $userId)->whereNull('ended_at')->orderByDesc('id')->first();
    }

    public function start(User $user): void
    {
        if ($user->current_break_id) {
            throw ValidationException::withMessages(['chat' => __('You are on a break. Press Start work first.')]);
        }
        if ($this->channelsFor($user)->isEmpty() && ! $user->can('hotline.view')) {
            throw ValidationException::withMessages(['chat' => __('No chat channel is given to you. A manager can add one on the Staff page.')]);
        }
        if (! $this->openSession($user->id)) {
            DB::table('chat_sessions')->insert(['user_id' => $user->id, 'started_at' => now()]);
        }
    }

    public function stop(User $user): void
    {
        DB::table('chat_sessions')->where('user_id', $user->id)->whereNull('ended_at')->update(['ended_at' => now()]);
    }

    /** + (message), − (undo, with a reason) or No order (with a reason). */
    public function record(User $user, int $channelId, string $kind, ?int $reasonId = null): void
    {
        if (! $this->channelsFor($user)->contains('id', $channelId)) {
            throw ValidationException::withMessages(['channel' => __('This channel is not yours.')]);
        }
        if (in_array($kind, ['undo', 'no_order'], true)) {
            $type = $kind === 'undo' ? 'chat_undo' : 'chat_lost';
            if (! $reasonId || ! DB::table('status_reasons')->where('id', $reasonId)->where('reason_type', $type)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['reason_id' => __('Choose a reason.')]);
            }
        }
        if ($kind === 'undo' && ($this->today($user, [$channelId])[$channelId]['messages'] ?? 0) < 1) {
            throw ValidationException::withMessages(['channel' => __('Nothing to take back on this channel today.')]);
        }
        // Counting needs chat mode on: the time and the count go together.
        $this->start($user);
        DB::table('chat_events')->insert(['user_id' => $user->id, 'chat_channel_id' => $channelId, 'kind' => $kind, 'reason_id' => $reasonId, 'created_at' => now()]);
    }

    /**
     * Today's numbers per channel for this person.
     *
     * @return array<int, array{messages: int, no_order: int, orders: int}>
     */
    public function today(User $user, ?array $channelIds = null): array
    {
        $from = today();
        $events = DB::table('chat_events')->where('user_id', $user->id)->where('created_at', '>=', $from)
            ->when($channelIds, fn ($q) => $q->whereIn('chat_channel_id', $channelIds))
            ->groupBy('chat_channel_id', 'kind')->selectRaw('chat_channel_id, kind, COUNT(*) as n')->get();
        $orders = DB::table('orders')->where('created_by', $user->id)->whereNotNull('chat_channel_id')->where('created_at', '>=', $from)
            ->when($channelIds, fn ($q) => $q->whereIn('chat_channel_id', $channelIds))
            ->groupBy('chat_channel_id')->selectRaw('chat_channel_id, COUNT(*) as n')->pluck('n', 'chat_channel_id');
        $out = [];
        foreach ($events as $e) {
            $out[$e->chat_channel_id] ??= ['messages' => 0, 'no_order' => 0, 'orders' => 0];
            match ($e->kind) {
                'message' => $out[$e->chat_channel_id]['messages'] += (int) $e->n,
                'undo' => $out[$e->chat_channel_id]['messages'] -= (int) $e->n,
                'no_order' => $out[$e->chat_channel_id]['no_order'] += (int) $e->n,
                default => null,
            };
        }
        foreach ($orders as $id => $n) {
            $out[$id] ??= ['messages' => 0, 'no_order' => 0, 'orders' => 0];
            $out[$id]['orders'] = (int) $n;
        }

        return $out;
    }
}
