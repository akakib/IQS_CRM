<?php

namespace App\Services\Calls;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Calls from a list: a manager fills a list from an imported file and gives
 * it to staff; each caller presses Next call and gets the next number from
 * the shared pool. No answer comes back later (at most three tries); a
 * customer who says not to call again is never in a list again.
 */
class CallListService
{
    public const PURPOSES = ['feedback' => 'Feedback', 'upsell' => 'Sell again', 'confirm' => 'Confirm order', 'other' => 'Other'];

    public const OUTCOMES = [
        'answered' => 'Talked',
        'ordered' => 'Ordered',
        'later' => 'Call later',
        'no_answer' => 'No answer',
        'busy' => 'Busy or off',
        'wrong' => 'Wrong number',
        'do_not_call' => 'Do not call again',
    ];

    /** Outcomes where the customer spoke: how satisfied is a must, the proof is the recording. */
    public const SPOKE = ['answered', 'ordered', 'later', 'do_not_call'];

    public const SATISFACTION = [0 => 'Very unhappy', 25 => 'Unhappy', 50 => 'Okay', 75 => 'Happy', 100 => 'Very happy'];

    public const TOPICS = ['product' => 'Product quality', 'delivery' => 'Delivery', 'packaging' => 'Packaging', 'price' => 'Price', 'staff' => 'Staff'];

    public const PROOF_TYPES = ['mp3', 'm4a', 'aac', 'amr', 'ogg', 'oga', 'opus', 'wav', '3gp', '3gpp', 'mp4', 'webm', 'jpg', 'jpeg', 'png', 'webp', 'heic'];

    /** No answer or busy: tried again after this long, at most this many times. */
    private const RETRY_HOURS = 3;

    private const MAX_ATTEMPTS = 3;

    /** A number taken and left (page closed) goes back to the pool after this long. */
    private const HOLD_MINUTES = 30;

    /**
     * Fills a list with the customers of an import (one row per person), skipping anyone who asked not to be called.
     *
     * @param  array{status?: ?string, from?: ?string, to?: ?string}  $filter
     */
    public function fill(int $listId, int $importId, array $filter): int
    {
        $people = DB::table('customer_past_orders as p')->join('customers as c', 'c.id', '=', 'p.customer_id')
            ->where('p.customer_import_id', $importId)->whereNull('c.deleted_at')->whereNull('c.do_not_call_at')
            ->when($filter['status'] ?? null, fn ($q, $s) => $q->where('p.status', $s))
            ->when($filter['from'] ?? null, fn ($q, $d) => $q->where('p.ordered_at', '>=', $d.' 00:00:00'))
            ->when($filter['to'] ?? null, fn ($q, $d) => $q->where('p.ordered_at', '<=', $d.' 23:59:59'))
            ->groupBy('c.id', 'c.primary_phone', 'c.name')
            ->selectRaw('c.id, c.primary_phone as phone, c.name, MAX(p.ordered_at) as last_at')
            ->orderByDesc('last_at')->get();
        $now = now();
        $added = 0;
        foreach ($people->chunk(500) as $chunk) {
            $added += DB::table('call_list_items')->insertOrIgnore($chunk->map(fn ($p) => [
                'call_list_id' => $listId, 'customer_id' => $p->id, 'phone' => $p->phone, 'name' => mb_substr($p->name, 0, 150),
                'created_at' => $now, 'updated_at' => $now,
            ])->values()->all());
        }

        return $added;
    }

    /** The number this person is on, or the next one from the pool (taken so nobody else gets it). */
    public function next(int $listId, User $user): ?object
    {
        $now = now();
        // Left open too long (page closed, phone died): back to the pool.
        DB::table('call_list_items')->where('call_list_id', $listId)->where('state', 'in_hand')->where('taken_at', '<', $now->copy()->subMinutes(self::HOLD_MINUTES))
            ->update(['state' => 'waiting', 'user_id' => null, 'taken_at' => null, 'updated_at' => $now]);

        $mine = DB::table('call_list_items')->where('call_list_id', $listId)->where('state', 'in_hand')->where('user_id', $user->id)->first();
        if ($mine) {
            return $mine;
        }
        // Call-backs that are due first (a promise to the customer), then the pool, newest buyers first (as filled).
        for ($try = 0; $try < 5; $try++) {
            $id = DB::table('call_list_items')->where('call_list_id', $listId)
                ->where(fn ($q) => $q->where('state', 'waiting')->orWhere(fn ($l) => $l->where('state', 'later')->where('next_at', '<=', $now)))
                ->orderByRaw("CASE WHEN state = 'later' THEN 0 ELSE 1 END")->orderBy('next_at')->orderBy('id')->value('id');
            if (! $id) {
                return null;
            }
            $taken = DB::table('call_list_items')->where('id', $id)->whereIn('state', ['waiting', 'later'])
                ->update(['state' => 'in_hand', 'user_id' => $user->id, 'taken_at' => $now, 'updated_at' => $now]);
            if ($taken) {
                return DB::table('call_list_items')->find($id);
            }
            // Someone else took it in the same moment: try the next one.
        }

        return null;
    }

    /** Gives the number back untouched (Skip): it is not a call and does not count. */
    public function release(object $item, User $user): void
    {
        DB::table('call_list_items')->where('id', $item->id)->where('user_id', $user->id)->where('state', 'in_hand')
            ->update(['state' => $item->attempts ? 'later' : 'waiting', 'user_id' => null, 'taken_at' => null, 'next_at' => $item->attempts ? now() : null, 'updated_at' => now()]);
    }

    /**
     * Records one call: what happened, how satisfied, the proof, and where the number goes next.
     *
     * @param  array{outcome: string, satisfaction?: ?int, topics?: array, note?: ?string, later_at?: ?string, seconds?: ?int}  $data
     */
    public function record(object $item, User $user, array $data, ?UploadedFile $proof): void
    {
        abort_unless($item->state === 'in_hand' && (int) $item->user_id === $user->id, 409, __('This number is no longer with you. Press Next call.'));
        $outcome = $data['outcome'];
        $spoke = in_array($outcome, self::SPOKE, true);
        if ($spoke && ! array_key_exists((int) ($data['satisfaction'] ?? -1), self::SATISFACTION)) {
            throw ValidationException::withMessages(['satisfaction' => __('Pick how satisfied the customer is.')]);
        }
        if (! $proof) {
            throw ValidationException::withMessages(['proof' => $spoke ? __('Add the call recording.') : __('Add a screenshot of the call log.')]);
        }
        if ($outcome === 'later' && empty($data['later_at'])) {
            throw ValidationException::withMessages(['later_at' => __('Pick when to call again.')]);
        }

        $now = now();
        $attempts = $item->attempts + 1;
        $path = $proof->storeAs('call-proofs/'.$item->call_list_id, $item->id.'-'.$attempts.'-'.$now->format('YmdHis').'.'.strtolower($proof->getClientOriginalExtension()), 'local');
        $topics = array_intersect_key(array_filter((array) ($data['topics'] ?? []), fn ($v) => in_array($v, ['good', 'bad'], true)), self::TOPICS);
        $satisfaction = $spoke ? (int) $data['satisfaction'] : null;

        // Where the number goes: tried again later, or finished.
        [$state, $nextAt] = match (true) {
            $outcome === 'later' => ['later', Carbon::parse($data['later_at'])],
            in_array($outcome, ['no_answer', 'busy'], true) && $attempts < self::MAX_ATTEMPTS => ['later', $now->copy()->addHours(self::RETRY_HOURS)],
            default => ['done', null],
        };
        // An order made on this call (from the popup) is linked; else the newest one this person made for the customer while holding it.
        $orderId = $item->order_id ?? ($outcome === 'ordered' && $item->customer_id
            ? DB::table('orders')->where('customer_id', $item->customer_id)->where('created_by', $user->id)->where('created_at', '>=', $item->taken_at)->orderByDesc('id')->value('id')
            : null);

        DB::transaction(function () use ($item, $user, $data, $outcome, $satisfaction, $topics, $path, $state, $nextAt, $attempts, $orderId, $now) {
            DB::table('customer_calls')->insert([
                'order_id' => $orderId, 'phone' => $item->phone, 'direction' => 'out', 'user_id' => $user->id,
                'started_at' => $item->taken_at ?? $now, 'ended_at' => $now,
                'outcome' => match ($outcome) { 'no_answer' => 'no_answer', 'busy' => 'busy', 'wrong' => 'wrong', default => 'answered' },
                'duration_seconds' => isset($data['seconds']) ? min(7200, max(0, (int) $data['seconds'])) : null,
                'note' => mb_substr(trim(__(self::OUTCOMES[$outcome]).(empty($data['note']) ? '' : ': '.$data['note'])), 0, 500),
                'satisfaction' => $satisfaction, 'proof_path' => $path, 'call_list_item_id' => $item->id,
            ]);
            DB::table('call_list_items')->where('id', $item->id)->update([
                'state' => $state, 'next_at' => $nextAt, 'attempts' => $attempts, 'outcome' => $outcome,
                'satisfaction' => $satisfaction ?? $item->satisfaction, 'topics' => $topics ? json_encode($topics) : $item->topics,
                'note' => empty($data['note']) ? $item->note : mb_substr($data['note'], 0, 500), 'order_id' => $orderId,
                'user_id' => $user->id, 'taken_at' => null, 'done_at' => $state === 'done' ? $now : null, 'updated_at' => $now,
            ]);
            if ($outcome === 'do_not_call' && $item->customer_id) {
                DB::table('customers')->where('id', $item->customer_id)->update(['do_not_call_at' => $now]);
                // Out of every other list too.
                DB::table('call_list_items')->where('customer_id', $item->customer_id)->where('id', '!=', $item->id)->whereIn('state', ['waiting', 'later'])
                    ->update(['state' => 'done', 'outcome' => 'do_not_call', 'done_at' => $now, 'updated_at' => $now]);
            }
        });
    }

    /** An order made in the popup during this call: linked at once. */
    public function linkOrder(int $itemId, int $orderId, User $user): void
    {
        DB::table('call_list_items')->where('id', $itemId)->where('user_id', $user->id)->where('state', 'in_hand')->update(['order_id' => $orderId, 'updated_at' => now()]);
    }

    /**
     * Progress of a list: overall, today per caller, and what the calls brought.
     *
     * @return array{total: int, done: int, waiting: int, later: int, in_hand: int, people: Collection, outcomes: Collection, satisfaction: ?int, orders: int, sold: float, unhappy: Collection}
     */
    public function progress(object $list): array
    {
        $states = DB::table('call_list_items')->where('call_list_id', $list->id)->groupBy('state')->selectRaw('state, COUNT(*) as n')->pluck('n', 'state');
        $calls = DB::table('customer_calls as cc')->join('call_list_items as i', 'i.id', '=', 'cc.call_list_item_id')->where('i.call_list_id', $list->id);
        $today = (clone $calls)->where('cc.started_at', '>=', now()->startOfDay())->groupBy('cc.user_id')->selectRaw('cc.user_id, COUNT(*) as n')->pluck('n', 'cc.user_id');
        $all = (clone $calls)->groupBy('cc.user_id')->selectRaw('cc.user_id, COUNT(*) as n')->pluck('n', 'cc.user_id');
        $people = DB::table('call_list_user as lu')->join('users as u', 'u.id', '=', 'lu.user_id')->where('lu.call_list_id', $list->id)->orderBy('u.name')
            ->get(['u.id', 'u.name'])->each(function ($p) use ($today, $all, $list) {
                $p->today = (int) ($today[$p->id] ?? 0);
                $p->all = (int) ($all[$p->id] ?? 0);
                $p->target = (int) $list->daily_target;
            });
        $orders = DB::table('call_list_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')->where('i.call_list_id', $list->id);

        return [
            'total' => (int) $states->sum(),
            'done' => (int) ($states['done'] ?? 0),
            'waiting' => (int) ($states['waiting'] ?? 0),
            'later' => (int) ($states['later'] ?? 0),
            'in_hand' => (int) ($states['in_hand'] ?? 0),
            'people' => $people,
            'outcomes' => DB::table('call_list_items')->where('call_list_id', $list->id)->whereNotNull('outcome')->groupBy('outcome')->selectRaw('outcome, COUNT(*) as n')->pluck('n', 'outcome'),
            'satisfaction' => ($s = DB::table('call_list_items')->where('call_list_id', $list->id)->whereNotNull('satisfaction')->avg('satisfaction')) === null ? null : (int) round($s),
            'orders' => (clone $orders)->count(),
            'sold' => (float) (clone $orders)->sum('o.grand_total'),
            'unhappy' => DB::table('call_list_items as i')->leftJoin('users as u', 'u.id', '=', 'i.user_id')->where('i.call_list_id', $list->id)
                ->where('i.satisfaction', '<', 50)->orderByDesc('i.updated_at')->limit(20)->get(['i.id', 'i.name', 'i.customer_id', 'i.satisfaction', 'i.note', 'i.topics', 'u.name as by']),
        ];
    }

    /** Lists given to this person, with what is left and today's count. */
    public function mine(User $user): Collection
    {
        $lists = DB::table('call_lists as l')->join('call_list_user as lu', 'lu.call_list_id', '=', 'l.id')
            ->where('lu.user_id', $user->id)->where('l.is_active', true)->orderByDesc('l.id')->get(['l.*']);
        $ids = $lists->pluck('id');
        $left = DB::table('call_list_items')->whereIn('call_list_id', $ids)
            ->where(fn ($q) => $q->where('state', 'waiting')->orWhere(fn ($l) => $l->where('state', 'later')->where('next_at', '<=', now()))->orWhere(fn ($h) => $h->where('state', 'in_hand')->where('user_id', $user->id)))
            ->groupBy('call_list_id')->selectRaw('call_list_id, COUNT(*) as n')->pluck('n', 'call_list_id');
        $today = DB::table('customer_calls as cc')->join('call_list_items as i', 'i.id', '=', 'cc.call_list_item_id')
            ->whereIn('i.call_list_id', $ids)->where('cc.user_id', $user->id)->where('cc.started_at', '>=', now()->startOfDay())
            ->groupBy('i.call_list_id')->selectRaw('i.call_list_id, COUNT(*) as n')->pluck('n', 'i.call_list_id');

        return $lists->each(function ($l) use ($left, $today) {
            $l->left = (int) ($left[$l->id] ?? 0);
            $l->today = (int) ($today[$l->id] ?? 0);
        });
    }

    /** Proof files are private: only the caller who made the call and managers may open them. */
    public function proofPath(int $callId, User $user): ?string
    {
        $call = DB::table('customer_calls')->find($callId, ['user_id', 'proof_path']);
        if (! $call?->proof_path || ($call->user_id !== $user->id && ! $user->can('calls.manage'))) {
            return null;
        }

        return Storage::disk('local')->exists($call->proof_path) ? $call->proof_path : null;
    }
}
