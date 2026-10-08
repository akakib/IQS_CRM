<?php

namespace App\Http\Controllers;

use App\Models\ChatChannel;
use App\Services\Work\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** The Chat button beside Break (JSON for the popup), and the admin's list of channels. */
class ChatController extends Controller
{
    public function __construct(private ChatService $chat) {}

    /** What the popup shows: today's numbers per channel, whether chat mode is on, the reasons. */
    public function state(Request $request): JsonResponse
    {
        $user = $request->user();
        $channels = $this->chat->channelsFor($user);
        $today = $this->chat->today($user, $channels->pluck('id')->all());
        $session = $this->chat->openSession($user->id);
        $reasons = DB::table('status_reasons')->whereIn('reason_type', ['chat_lost', 'chat_undo'])->where('is_active', true)
            ->orderBy('sort_order')->get(['id', 'reason_type', 'label_en'])->groupBy('reason_type')
            ->map(fn ($r) => $r->map(fn ($x) => ['id' => $x->id, 'label' => __($x->label_en)])->values());

        return response()->json([
            'date' => now()->format('l, d M'),
            'on' => (bool) $session,
            'since' => $session ? \Illuminate\Support\Carbon::parse($session->started_at)->format('g:i A') : null,
            'channels' => $channels->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'type' => ChatChannel::TYPES[$c->type] ?? $c->type,
                'order_url' => route('orders.create', ['chat_channel' => $c->id]),
            ] + ($today[$c->id] ?? ['messages' => 0, 'no_order' => 0, 'orders' => 0]))->values(),
            'reasons' => ['lost' => $reasons['chat_lost'] ?? [], 'undo' => $reasons['chat_undo'] ?? []],
            // Rider calls: known riders, most recent first, for the name box.
            'riders' => $user->can('hotline.view') ? DB::table('riders')->orderByDesc('last_used_at')->limit(100)->pluck('name') : [],
        ]);
    }

    public function start(Request $request): JsonResponse
    {
        $this->chat->start($request->user());

        return $this->state($request);
    }

    public function stop(Request $request): JsonResponse
    {
        $this->chat->stop($request->user());

        return $this->state($request);
    }

    public function record(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channel_id' => ['required', 'integer'], 'kind' => ['required', Rule::in(['message', 'undo', 'no_order'])],
            'reason_id' => ['nullable', 'integer'],
        ]);
        $this->chat->record($request->user(), (int) $data['channel_id'], $data['kind'], $data['reason_id'] ?? null);

        return $this->state($request);
    }

    // ── Settings > Chat channels ────────────────────────────

    public function index(): View
    {
        return view('settings.chat-channels', [
            'channels' => ChatChannel::with(['users' => fn ($q) => $q->orderBy('name')->select('users.id', 'users.name')])->orderBy('sort_order')->orderBy('name')->get(),
            'types' => ChatChannel::TYPES,
            'staff' => request()->user()->can('staff.edit') ? \App\Models\User::where('is_active', true)->orderBy('name')->get(['id', 'name']) : collect(),
        ]);
    }

    /** Who handles this channel: the same list as the ticks on each person's Staff page. */
    public function people(Request $request, ChatChannel $channel): RedirectResponse
    {
        $data = $request->validate(['users' => ['nullable', 'array'], 'users.*' => ['integer']]);
        $channel->users()->sync(\App\Models\User::where('is_active', true)->whereIn('id', $data['users'] ?? [])->pluck('id'));

        return redirect()->route('settings.chat-channels')->with('success', __('Access saved for :c.', ['c' => $channel->name]));
    }

    public function save(Request $request, ?ChatChannel $channel = null): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'], 'type' => ['required', Rule::in(array_keys(ChatChannel::TYPES))],
            'is_active' => ['nullable', 'boolean'], 'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);
        $data['is_active'] = $request->boolean('is_active', ! $channel);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $channel ? $channel->update($data) : ChatChannel::create($data);

        return redirect()->route('settings.chat-channels')->with('success', $channel ? __('Channel saved.') : __('Channel added. Give it to people on the Staff page.'));
    }
}
