<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\User;
use App\Services\Courier\BookingService;
use App\Services\NotificationService;
use App\Services\Points\PointHooks;
use App\Services\Work\WorkCalendar;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The order desk: who holds which order and until when.
 *
 * - Take next: the oldest waiting website order, never picked by hand.
 * - Action timer: runs on ONE order per moderator at a time (their oldest);
 *   when it passes, the order goes back to New and the next timer starts.
 * - No response: the order leaves the Call tab and returns after a delay.
 * - Send to packaging: courier booking runs in the background; the order
 *   reaches the packaging queue only once a consignment exists.
 *
 * Due times are plain columns, so "is it due?" is answered by a WHERE at
 * click time. The cron sweep (desk:tick) only catches what nobody touched.
 */
class DeskService
{
    /** Website orders waiting for a moderator's first action. */
    private const ACTIVE = ['new', 'record_verified'];

    public function __construct(
        private OrderStateMachine $machine,
        private OrderService $orders,
        private WorkCalendar $calendar,
        private NotificationService $notifications,
    ) {}

    // ── Taking and assigning ─────────────────────────────────

    /** Orders anyone may be given: unassigned, still before confirmation. */
    public function waitingQuery()
    {
        return DB::table('orders')->whereNull('moderator_id')
            ->whereIn('status_id', OrderStatus::idsFor(['new', 'record_verified', 'no_answer']));
    }

    /** One person holds at most this many orders waiting for an advance (they do not count in the active limit). */
    public const ADVANCE_CAP = 5;

    /**
     * Orders held for an advance that nobody owns yet. The call to ask for the
     * advance (and check the address, and upsell) is a call like any other, so
     * these are taken from the queue too: after the New and Call orders.
     */
    public function waitingAdvanceQuery()
    {
        return DB::table('orders')->whereNull('moderator_id')->where('status_id', OrderStatus::idFor('hold'))
            ->where('hold_reason_id', $this->advanceReasonId())->whereNull('advance_waived_at')->whereNotNull('advance_required');
    }

    public function advanceReasonId(): ?int
    {
        // Asked many times per request (tabs, pulse, follow-ups); never changes. Kept for the request.
        $app = app();
        if (! $app->bound('desk.advance-reason-id')) {
            $app->instance('desk.advance-reason-id', DB::table('status_reasons')->where('reason_type', 'hold')->where('system_key', 'advance_wait')->value('id'));
        }

        return $app->make('desk.advance-reason-id');
    }

    private function advanceHeldCount(int $userId): int
    {
        return DB::table('orders')->where('moderator_id', $userId)->where('status_id', OrderStatus::idFor('hold'))
            ->where('hold_reason_id', $this->advanceReasonId())->whereNull('advance_waived_at')->count();
    }

    /** Website orders this person still has to act on (the limit counts these). */
    public function activeCount(int $userId): int
    {
        return DB::table('orders')->where('moderator_id', $userId)->where('channel', 'web')
            ->whereIn('status_id', OrderStatus::idsFor(self::ACTIVE))->count();
    }

    /**
     * People who should not be told "a new order is waiting": owners (they
     * watch, they do not work orders), anyone on a break, and anyone whose
     * hands are full (holding as many orders as the limit allows).
     *
     * @return list<int>
     */
    public function notFreeForNewOrders(): array
    {
        $limit = (int) settings('desk.active_limit');
        $full = DB::table('orders')->where('channel', 'web')->whereNotNull('moderator_id')->whereIn('status_id', OrderStatus::idsFor(self::ACTIVE))
            ->groupBy('moderator_id')->havingRaw('COUNT(*) >= ?', [$limit])->pluck('moderator_id')->all();
        $resting = DB::table('users')->whereNotNull('current_break_id')->pluck('id')->all();
        $owners = User::where('is_active', true)->get()->filter(fn (User $u) => $u->isOwner())->pluck('id')->all();

        return array_values(array_unique(array_map('intval', [...$full, ...$resting, ...$owners])));
    }

    public function takeNext(User $user): Order
    {
        if ($user->isOwner()) {
            throw ValidationException::withMessages(['order' => __('Owners watch orders from Order activity; staff take them.')]);
        }
        if ($user->current_break_id) {
            throw ValidationException::withMessages(['order' => __('You are on a break. Press Start work first.')]);
        }
        $limit = (int) settings('desk.active_limit');
        $full = $this->activeCount($user->id) >= $limit;
        $this->sweepExpired(); // timers that ran out free their orders right now

        // Of two people pressing at once, each gets a different order.
        for ($try = 0; $try < 5; $try++) {
            $id = $full ? null : $this->waitingQuery()->orderBy('id')->value('id');
            if (! $id) {
                // Nothing to verify or call (or hands full): an order waiting for its advance, which is outside the limit.
                $id = $this->advanceHeldCount($user->id) < self::ADVANCE_CAP ? $this->waitingAdvanceQuery()->orderBy('id')->value('id') : null;
            }
            if (! $id) {
                throw ValidationException::withMessages(['order' => $full
                    ? ($limit === 1 ? __('Finish the order you have before taking the next one.') : __('You already hold :n orders. Finish one first.', ['n' => $limit]))
                    : __('No order is waiting.')]);
            }
            if ($this->assign($id, $user->id, 'claimed')) {
                return Order::findOrFail($id);
            }
        }

        throw ValidationException::withMessages(['order' => __('Busy: try again.')]);
    }

    /** @return bool false when someone else got the order first */
    public function assign(int $orderId, int $userId, string $how, ?int $by = null): bool
    {
        $won = DB::table('orders')->where('id', $orderId)->whereNull('moderator_id')->update([
            'moderator_id' => $userId, 'assigned_at' => now(), 'queue_since' => null, 'action_due_at' => null,
            'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now(),
        ]);
        if (! $won) {
            return false;
        }

        DB::table('order_assignments')->insert([
            'order_id' => $orderId, 'user_id' => $userId, 'role' => 'moderator', 'how' => $how, 'assigned_by' => $by, 'started_at' => now(),
        ]);
        $name = DB::table('users')->where('id', $userId)->value('name');
        $this->systemNote($orderId, $how === 'auto' ? __('Given to :n automatically (nobody took it).', ['n' => $name]) : __(':n took the order.', ['n' => $name]), $how === 'auto' ? null : $userId);
        if ($how !== 'auto') {
            $this->armTimer($userId, $orderId); // they took it and it opens in front of them
        }

        return true;
    }

    /**
     * Start the action timer on the order this person has in front of them.
     * One timer at a time: nothing happens while another one is running, so
     * a clock never starts on an order they are not looking at. Chat orders
     * never get a timer.
     */
    public function armTimer(int $userId, int $orderId): bool
    {
        $id = $this->timerCandidates($userId)?->where('id', $orderId)->value('id');

        return $id ? $this->startTimer($userId, (int) $id) : false;
    }

    /**
     * Safety net for orders nobody opens: when this person has no timer
     * running and their oldest waiting order has sat untouched for the
     * configured minutes, its timer starts by itself.
     */
    public function armUntouched(int $userId): bool
    {
        $since = now()->subMinutes((int) settings('desk.untouched_start_minutes'));
        $id = $this->timerCandidates($userId)
            ?->whereRaw('COALESCE(next_call_at, assigned_at) <= ?', [$since])
            ->orderBy('assigned_at')->orderBy('id')->value('id');

        return $id ? $this->startTimer($userId, (int) $id) : false;
    }

    /** Orders of this person that may get the timer now; null while on a break or while a timer is already running. */
    private function timerCandidates(int $userId): ?\Illuminate\Database\Query\Builder
    {
        $user = DB::table('users')->where('id', $userId)->first(['id', 'current_break_id']);
        if (! $user || $user->current_break_id) {
            return null;
        }
        $mine = DB::table('orders')->where('moderator_id', $userId)->where('channel', 'web');
        if ((clone $mine)->whereNotNull('action_due_at')->exists()) {
            return null;
        }

        return $mine->where(fn ($q) => $q->whereIn('status_id', OrderStatus::idsFor(self::ACTIVE))
            ->orWhere(fn ($q) => $q->where('status_id', OrderStatus::idFor('no_answer'))->where('next_call_at', '<=', now())));
    }

    private function startTimer(int $userId, int $orderId): bool
    {
        return (bool) DB::table('orders')->where('id', $orderId)->whereNull('action_due_at')->update([
            'action_due_at' => $this->calendar->deadline($userId, (int) settings('desk.action_timer_minutes')),
        ]);
    }

    /** The order whose timer is running for this person, if any. */
    public function timedOrder(int $userId): ?object
    {
        return DB::table('orders')->where('moderator_id', $userId)->whereNotNull('action_due_at')->orderBy('action_due_at')
            ->first(['id', 'order_no', 'action_due_at', 'status_id', 'timer_extended_at']);
    }

    /**
     * Back to the New list. "timeout" counts against the moderator (KPI and
     * points); "break" does not.
     */
    public function release(int $orderId, string $reason): void
    {
        $order = DB::table('orders')->where('id', $orderId)->first(['id', 'moderator_id', 'order_no']);
        if (! $order || ! $order->moderator_id) {
            return;
        }
        $userId = (int) $order->moderator_id;

        $freed = DB::table('orders')->where('id', $orderId)->where('moderator_id', $userId)->update([
            'moderator_id' => null, 'assigned_at' => null, 'action_due_at' => null, 'queue_since' => now(),
            'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now(),
        ]);
        if (! $freed) {
            return;
        }
        DB::table('order_assignments')->where('order_id', $orderId)->where('user_id', $userId)->whereNull('ended_at')
            ->update(['ended_at' => now(), 'ended_reason' => $reason]);

        $name = DB::table('users')->where('id', $userId)->value('name');
        $this->systemNote($orderId, $reason === 'timeout'
            ? __('No action in time: taken back from :n, waiting in New again.', ['n' => $name])
            : __(':n went on a break: waiting in New again.', ['n' => $name]), null, ['released_from' => $userId, 'reason' => $reason]);

        if ($reason === 'timeout') {
            $today = DB::table('order_assignments')->where('user_id', $userId)->where('ended_reason', 'timeout')
                ->where('ended_at', '>=', now()->startOfDay())->count();
            app(PointHooks::class)->timerMissed(Order::find($orderId), $userId, $today);
        }
    }

    /** How many times this person took extra time today. */
    public function extensionsToday(int $userId): int
    {
        return DB::table('orders')->where('timer_extended_by', $userId)->where('timer_extended_at', '>=', now()->startOfDay())->count();
    }

    /**
     * Extra minutes on a running timer: once per order, a limited number of
     * times a day, and only before the time is up. Recorded and scored.
     */
    public function extend(Order $order, User $user): void
    {
        $limit = (int) settings('desk.extend_daily_limit');
        $used = $this->extensionsToday($user->id);
        $fail = match (true) {
            $order->moderator_id !== $user->id => __('This order is not with you.'),
            ! $order->action_due_at => __('No timer is running on this order.'),
            $order->action_due_at->isPast() => __('Time is already up on this order.'),
            $order->timer_extended_at !== null => __('Extra time was already taken on this order.'),
            $used >= $limit => __('You have used your :n extra-time requests for today.', ['n' => $limit]),
            default => null,
        };
        if ($fail) {
            throw ValidationException::withMessages(['order' => $fail]);
        }

        $minutes = (int) settings('desk.extend_minutes');
        // The WHERE repeats the checks, so two clicks cannot both add time.
        $done = DB::table('orders')->where('id', $order->id)->where('moderator_id', $user->id)->whereNull('timer_extended_at')
            ->where('action_due_at', '>', now())
            ->update(['action_due_at' => $order->action_due_at->copy()->addMinutes($minutes), 'timer_extended_at' => now(), 'timer_extended_by' => $user->id]);
        if (! $done) {
            return;
        }
        $this->systemNote($order->id, __(':n took :m more minutes.', ['n' => $user->name, 'm' => $minutes]), $user->id);
        app(PointHooks::class)->timerExtended($order, $user->id, $used + 1);
    }

    /**
     * Release every order whose time is up and say which of them were this
     * person's (the desk shows that as a message).
     *
     * @return list<string> order numbers taken back from this person
     */
    public function sweepFor(int $userId): array
    {
        $mine = DB::table('orders')->where('moderator_id', $userId)->whereNotNull('action_due_at')->where('action_due_at', '<=', now())->pluck('order_no')->all();
        $this->sweepExpired();

        return $mine;
    }

    /** @return int orders released because their timer ran out */
    public function sweepExpired(): int
    {
        $ids = DB::table('orders')->whereNotNull('action_due_at')->where('action_due_at', '<=', now())
            ->whereNotNull('moderator_id')->orderBy('action_due_at')->limit(100)->pluck('id');
        foreach ($ids as $id) {
            $this->release($id, 'timeout');
        }

        return $ids->count();
    }

    /** Cron safety net: start the timer for everyone whose waiting order has sat untouched too long. */
    public function armUntouchedAll(): void
    {
        $since = now()->subMinutes((int) settings('desk.untouched_start_minutes'));
        $userIds = DB::table('orders')->where('channel', 'web')->whereNotNull('moderator_id')->whereNull('action_due_at')
            ->where(fn ($q) => $q->whereIn('status_id', OrderStatus::idsFor(self::ACTIVE))
                ->orWhere(fn ($q) => $q->where('status_id', OrderStatus::idFor('no_answer'))->where('next_call_at', '<=', now())))
            ->whereRaw('COALESCE(next_call_at, assigned_at) <= ?', [$since])
            ->distinct()->limit(200)->pluck('moderator_id');
        foreach ($userIds as $userId) {
            $this->armUntouched((int) $userId);
        }
    }

    /**
     * Orders nobody took in time go to the active moderator with the fewest
     * waiting orders. Nobody active: they stay in New and managers are told.
     *
     * @return int orders assigned
     */
    public function autoAssign(): int
    {
        $due = $this->waitingQuery()->where('channel', 'web')
            ->where('queue_since', '<=', now()->subMinutes((int) settings('desk.auto_assign_minutes')))
            ->orderBy('id')->limit(50)->pluck('order_no', 'id');

        $limit = (int) settings('desk.active_limit');
        $load = [];
        foreach ($this->activeModerators() as $userId) {
            $count = $this->activeCount($userId);
            if ($count < $limit) {
                $load[$userId] = $count;
            }
        }

        $assigned = 0;
        foreach ($due as $orderId => $orderNo) {
            if ($load === []) {
                break;
            }
            asort($load);
            $userId = array_key_first($load);
            if ($this->assign($orderId, $userId, 'auto')) {
                $assigned++;
                $this->notifications->send('order_assigned', __('Order :no was given to you', ['no' => $orderNo]), null, [
                    'link' => route('desk.index', ['order' => $orderId]), 'subject' => ['order', $orderId], 'user_ids' => [$userId],
                ]);
                if (++$load[$userId] >= $limit) {
                    unset($load[$userId]);
                }
            }
        }

        // Advance holds nobody took in an hour: same idea, outside the active limit, up to the cap each.
        $advance = $this->waitingAdvanceQuery()->where('queue_since', '<=', now()->subMinutes(60))->orderBy('id')->limit(50)->pluck('order_no', 'id');
        $room = [];
        foreach ($this->activeModerators() as $userId) {
            if (($n = $this->advanceHeldCount($userId)) < self::ADVANCE_CAP) {
                $room[$userId] = $n;
            }
        }
        foreach ($advance as $orderId => $orderNo) {
            if ($room === []) {
                break;
            }
            asort($room);
            $userId = array_key_first($room);
            if ($this->assign($orderId, $userId, 'auto')) {
                $this->notifications->send('order_assigned', __('Order :no was given to you (ask for the advance)', ['no' => $orderNo]), null, [
                    'link' => route('desk.index', ['tab' => 'call', 'order' => $orderId]), 'subject' => ['order', $orderId], 'user_ids' => [$userId],
                ]);
                if (++$room[$userId] >= self::ADVANCE_CAP) {
                    unset($room[$userId]);
                }
            }
        }

        // Managers are told when orders wait and nobody is free, at most once every 30 minutes (this runs every minute).
        $left = $due->count() - $assigned + $this->waitingAdvanceQuery()->where('queue_since', '<=', now()->subMinutes(60))->count();
        if ($left > 0 && Cache::add('desk:unassigned-alert', 1, now()->addMinutes(30))) {
            $this->notifications->send('orders_unassigned', trans_choice('{1} 1 order is waiting and nobody is free to take it|[2,*] :n orders are waiting and nobody is free to take them', $left, ['n' => $left]), null, [
                'link' => route('orders.activity'), 'user_ids' => $this->managerIds(),
            ]);
        }

        return $assigned;
    }

    /** @return list<int> moderators at work right now: seen recently, not on a break, inside their shift */
    public function activeModerators(): array
    {
        $users = User::where('is_active', true)->whereNull('current_break_id')
            ->where('last_seen_at', '>=', now()->subMinutes((int) settings('desk.active_window_minutes')))->get();
        $this->calendar->preload($users->pluck('id')->all());

        return $users->filter(fn (User $u) => ! $u->isOwner() && $u->can('orders.take') && $this->calendar->isWorking($u->id))
            ->pluck('id')->all();
    }

    // ── Moderator actions ────────────────────────────────────

    /**
     * The customer did not pick up. The order returns to this moderator's
     * Call tab after the next delay; when no delay is left it is cancelled.
     */
    public function noResponse(Order $order, User $user, ?string $note = null): string
    {
        // One try per wait: pressing again before the return time must not burn the remaining tries and cancel the order.
        if ($order->next_call_at && $order->next_call_at->isFuture() && OrderStatus::map()[$order->status_id]['key'] === 'no_answer') {
            throw ValidationException::withMessages(['order' => __('Already marked No response. Call again at :t.', ['t' => $order->next_call_at->format('g:i A')])]);
        }
        $delays = array_values(array_filter(array_map('intval', explode(',', (string) settings('desk.no_response_returns')))));
        $try = (int) $order->no_response_count + 1;
        $this->orders->note($order, 'call', trim(__('Called: no response (try :n)', ['n' => $try]).($note ? ' · '.$note : '')), $user, ['outcome' => 'no_answer']);

        if ($try > count($delays)) {
            $reason = DB::table('status_reasons')->where('reason_type', 'cancel')->where('system_key', 'unreachable')->value('id');
            DB::table('orders')->where('id', $order->id)->update(['no_response_count' => $try]);
            $this->machine->transition($order, 'cancelled', $user, 'system', $reason, __('No response :n times', ['n' => $try]));

            return 'cancelled';
        }

        // Return time first: the status change below restarts timers and must see it.
        $returnAt = $this->calendar->nextWorkingMoment($user->id, now()->addMinutes($delays[$try - 1]));
        DB::table('orders')->where('id', $order->id)->update(['no_response_count' => $try, 'next_call_at' => $returnAt]);
        $this->machine->transition($order, 'no_answer', $user);

        if ($try === 1 && settings('desk.sms_after_no_response')) {
            // No SMS gateway yet: the request is recorded so it can be sent once one is connected.
            $this->systemNote($order->id, __('Customer message "please call back" is waiting for an SMS gateway.'), null);
        }

        return 'no_answer';
    }

    /** Minutes to the next try after a temporary failure (1st, 2nd); the 3rd failure stops. */
    private const RETRY_MINUTES = [1, 5];

    /** A booking that has not finished after this long probably died half way. */
    private const STALE_MINUTES = 5;

    /**
     * Book what is waiting, after the response: new confirms whose own request
     * died, retries whose time came, and half-finished bookings to check.
     * Called by the desk pulse, the desk and packaging pages and cron, so
     * booking never depends on one browser staying open.
     */
    public function bookDue(): void
    {
        $confirmed = OrderStatus::idFor('confirmed');
        $due = DB::table('orders')->where('booking_state', 'queued')->where('status_id', $confirmed)
            ->where(fn ($q) => $q->where(fn ($q) => $q->whereNull('booking_claim')->where(fn ($q) => $q->whereNull('book_after')->orWhere('book_after', '<=', now())))
                ->orWhere('booking_claimed_at', '<', now()->subMinutes(self::STALE_MINUTES)))
            ->exists();
        if ($due) {
            app()->terminating(fn () => Cache::lock('desk:book-due', 120)->get(function () {
                $this->recoverStale();
                $this->runBookings();
            }));
        }
    }

    /**
     * A booking that started but never finished (the request was killed after
     * sending): it may or may not be at the courier. Ask the courier by our
     * order number. Not there: try again. There, or the courier cannot tell:
     * stop and let a person check, never book a second parcel blindly.
     */
    public function recoverStale(): void
    {
        $rows = DB::table('orders')->where('booking_state', 'queued')->where('status_id', OrderStatus::idFor('confirmed'))
            ->where('booking_claimed_at', '<', now()->subMinutes(self::STALE_MINUTES))->limit(10)->get(['id', 'order_no', 'moderator_id', 'booking_claim']);
        foreach ($rows as $row) {
            $booked = app(\App\Services\Courier\CourierManager::class)->driver()->invoiceBooked(BookingService::invoiceFor(Order::find($row->id)));
            $release = DB::table('orders')->where('id', $row->id)->where('booking_claim', $row->booking_claim);
            if ($booked === false) {
                $release->update(['booking_claim' => null, 'booking_claimed_at' => null, 'book_after' => null, 'updated_at' => now()]);

                continue;
            }
            $error = __('The booking stopped half way and :no may already be at the courier. Check it in the courier panel before booking again.', ['no' => $row->order_no]);
            $release->update(['booking_claim' => null, 'booking_claimed_at' => null, 'booking_state' => 'failed', 'booking_error' => mb_substr($error, 0, 255), 'updated_at' => now()]);
            $this->failedNotice($row, $error);
        }
    }

    /** One button: book the courier in the background, then the order shows up for the packers. */
    public function sendToPackaging(Order $order, User $user): void
    {
        if (OrderStatus::map()[$order->status_id]['key'] !== 'confirmed') {
            throw ValidationException::withMessages(['order' => __('Only a confirmed order can go to packaging.')]);
        }
        // Only one click wins: the state must still be "not sent" (or "failed" for a retry) at the moment of the update.
        $queued = DB::table('orders')->where('id', $order->id)->whereIn('booking_state', ['none', 'failed'])->update([
            'booking_state' => 'queued', 'booking_attempts' => 0, 'booking_error' => null, 'book_after' => null, 'booking_claim' => null, 'booking_claimed_at' => null,
            'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now(),
        ]);
        if (! $queued) {
            return; // already on its way
        }
        $this->orders->note($order, 'courier', __('Sent to packaging by :n. Booking the courier…', ['n' => $user->name]), $user);

        $orderId = $order->id;
        $userId = $user->id;
        // After the response is sent: the moderator does not wait for the courier.
        app()->terminating(fn () => $this->runBookings([$orderId], $userId));
    }

    /**
     * Book queued orders. Each order is claimed first (one UPDATE that only
     * succeeds while nobody else holds it), so two requests running at the same
     * moment can never send the same order twice.
     *
     * Failures: keys refused = wait, one alert, no order is blamed; the courier
     * refused the parcel's data = Booking failed at once (retrying cannot help);
     * anything else = try again after 1 and 5 minutes, then Booking failed.
     *
     * @param  list<int>|null  $orderIds  null = every order that is due
     */
    public function runBookings(?array $orderIds = null, ?int $byUserId = null): void
    {
        $ids = DB::table('orders')->where('booking_state', 'queued')->where('status_id', OrderStatus::idFor('confirmed'))
            ->whereNull('booking_claim')
            ->where(fn ($q) => $q->whereNull('book_after')->orWhere('book_after', '<=', now()))
            ->when($orderIds !== null, fn ($q) => $q->whereIn('id', $orderIds))
            ->orderBy('id')->limit(50)->pluck('id')->all();
        if (! $ids) {
            return;
        }
        $token = (string) Str::uuid();
        DB::table('orders')->whereIn('id', $ids)->where('booking_state', 'queued')->whereNull('booking_claim')
            ->update(['booking_claim' => $token, 'booking_claimed_at' => now()]);
        $rows = DB::table('orders')->where('booking_claim', $token)->get(['id', 'order_no', 'moderator_id', 'booking_attempts']);
        if ($rows->isEmpty()) {
            return; // another request took them first
        }

        $by = $byUserId ? User::find($byUserId) : null;
        try {
            $result = app(BookingService::class)->book($rows->pluck('id')->all(), $by);
        } finally {
            // Booked or not, this request is done with them (booked ones are no longer queued).
            DB::table('orders')->where('booking_claim', $token)->update(['booking_claim' => null, 'booking_claimed_at' => null]);
        }

        foreach ($rows as $row) {
            if (! isset($result['failed'][$row->order_no])) {
                continue; // booked: BookingService cleared the state
            }
            $error = (string) $result['failed'][$row->order_no];
            $kind = $result['kind'][$row->order_no] ?? 'temporary';
            $order = DB::table('orders')->where('id', $row->id)->where('booking_state', 'queued');

            if ($kind === 'auth') {
                $order->update(['book_after' => now()->addMinutes(5), 'booking_error' => mb_substr($error, 0, 255), 'updated_at' => now()]);
                if (Cache::add('courier:auth-alert', 1, now()->addMinutes(30))) {
                    $this->notifications->send('booking_failed', __('The courier refused the API keys'),
                        __('No order can be booked until the keys are fixed in Settings > Courier accounts. Waiting orders are booked by themselves after that.'), [
                            'link' => route('settings.couriers'), 'user_ids' => $this->managerIds(),
                        ]);
                }

                continue;
            }

            $attempts = $row->booking_attempts + 1;
            $failed = $kind === 'rejected' || $attempts > count(self::RETRY_MINUTES);
            $order->update([
                'booking_attempts' => $attempts, 'booking_state' => $failed ? 'failed' : 'queued',
                'book_after' => $failed ? null : now()->addMinutes(self::RETRY_MINUTES[$attempts - 1]),
                'booking_error' => mb_substr($error, 0, 255), 'updated_at' => now(),
            ]);
            if ($failed) {
                $this->failedNotice($row, $kind === 'rejected'
                    ? __('The courier refused this parcel: :e', ['e' => $error])
                    : __('Courier booking failed :n times: :e', ['n' => $attempts, 'e' => $error]));
            }
        }
    }

    /**
     * May this person take this booked order back? Waiting for a packer: its own
     * moderator (or a manager). Once a packer started or packed it: managers
     * only, because someone else's work is stopped. With the courier: never.
     */
    public function canTakeBack(Order $order, User $user): bool
    {
        if (! in_array(OrderStatus::map()[$order->status_id]['key'], ['ready_for_packaging', 'packed', 'ready_for_pickup'], true) || $order->taken_back_at) {
            return false;
        }
        if ($user->can('orders.approve')) {
            return true;
        }

        return ! $order->packer_id && ! $order->packed_at && $order->moderator_id === $user->id && $user->can('orders.edit');
    }

    /**
     * Booked by mistake: back to the Call tab. The label is voided so it never
     * scans, the parcel is deleted at the courier by hand (same "Deleted" step
     * as a cancel), and a started or packed box is opened and put back. Until
     * Deleted is pressed the order cannot be confirmed again (one order, one parcel).
     */
    public function takeBack(Order $order, User $by, string $why): void
    {
        DB::transaction(function () use ($order, $by, $why) {
            $fresh = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $key = OrderStatus::map()[$fresh->status_id]['key'];
            if (in_array(OrderStatus::map()[$fresh->status_id]['group'], ['courier', 'final'], true)) {
                throw ValidationException::withMessages(['order' => __('The courier already has this parcel: use Cancel and ask the courier to return it.')]);
            }
            if (! $this->canTakeBack($fresh, $by)) {
                throw ValidationException::withMessages(['order' => $key === 'ready_for_packaging' || in_array($key, ['packed', 'ready_for_pickup'], true)
                    ? __('A packer already started this order: ask a manager to take it back.')
                    : __('Only a booked order that is not with the courier yet can be taken back.')]);
            }
            $touched = $fresh->packer_id || $fresh->packed_at;
            $packerId = $fresh->packer_id;
            $cn = $fresh->active_shipment_id ? DB::table('shipments')->where('id', $fresh->active_shipment_id)->value('consignment_id') : null;

            DB::table('shipment_labels')->where('order_id', $fresh->id)->whereNull('voided_at')
                ->update(['voided_at' => now(), 'void_reason' => __('Taken back (booked by mistake)'), 'updated_at' => now()]);
            $fresh->forceFill([
                'taken_back_at' => now(), 'booking_state' => 'none', 'packaging_sent_at' => null,
                'packer_id' => null, 'packaging_started_at' => null, 'packed_at' => null,
                'unpack_needed_at' => $touched ? now() : null, 'unpack_packer_id' => $touched ? $packerId : null,
            ])->save();
            app(OrderStateMachine::class)->transition($fresh, 'record_verified', $by, 'system', null, __('Booked by mistake: :w', ['w' => $why]));

            $this->notifications->send('courier_cancel_needed', __('Delete :no at the courier', ['no' => $fresh->order_no]),
                __('Taken back by :n (booked by mistake, CN :cn). Delete it in the courier panel, then press "Deleted" on the order.', ['n' => $by->name, 'cn' => $cn ?? '-']), [
                    'link' => route('orders.show', $fresh), 'subject' => ['order', $fresh->id], 'priority' => 'urgent',
                    'user_ids' => array_values(array_unique(array_filter([$fresh->moderator_id, $by->id, ...$this->managerIds()]))),
                ]);
            if ($touched && $packerId) {
                $this->notifications->send('courier_cancel_needed', __('Stop: :no was taken back', ['no' => $fresh->order_no]),
                    __('Do not pack or send it. Open the box, put the items back on the shelf, then press Unpacked on the Packaging page.'), [
                        'link' => route('packaging.index'), 'subject' => ['order', $fresh->id], 'priority' => 'urgent', 'user_ids' => [$packerId],
                    ]);
            }
        });
    }

    /** The packer opened the box and put the items back. */
    public function markUnpacked(Order $order, User $by): void
    {
        $done = DB::table('orders')->where('id', $order->id)->whereNotNull('unpack_needed_at')->update(['unpack_needed_at' => null, 'unpack_packer_id' => null]);
        if ($done) {
            $this->systemNote($order->id, __('Box opened and items put back on the shelf by :n.', ['n' => $by->name]), $by->id);
        }
    }

    /**
     * Follow-up on holds, from cron and (throttled) from the desk pulse:
     *  - a hold whose announced date is reached: the moderator is told once;
     *  - an advance not paid by half the wait: the moderator (or the managers) reminded once;
     *  - an advance not paid by the full wait: cancelled, "No advance payment".
     * Each step is marked on the order, so running this every minute changes nothing twice.
     *
     * @return array{dated: int, reminded: int, cancelled: int}
     */
    public function followUpHolds(): array
    {
        $hold = OrderStatus::idFor('hold');
        $out = ['dated' => 0, 'reminded' => 0, 'cancelled' => 0];

        foreach (DB::table('orders')->where('status_id', $hold)->whereNotNull('hold_expected_date')->where('hold_expected_date', '<=', today())
            ->whereNull('hold_date_notified_at')->limit(100)->get(['id', 'order_no', 'moderator_id', 'hold_expected_date']) as $o) {
            DB::table('orders')->where('id', $o->id)->update(['hold_date_notified_at' => now()]);
            $this->notifications->send('hold_date_reached', __('Hold date reached: :no', ['no' => $o->order_no]),
                __('The customer said :d. Call them.', ['d' => \Illuminate\Support\Carbon::parse($o->hold_expected_date)->format('d M')]), [
                    'link' => route('desk.index', ['tab' => 'hold', 'order' => $o->id]), 'subject' => ['order', $o->id],
                    'user_ids' => array_filter([$o->moderator_id]) ?: $this->managerIds(),
                ]);
            $out['dated']++;
        }

        $days = max(1, (int) settings('advance.wait_days'));
        $advance = DB::table('orders')->where('status_id', $hold)->where('hold_reason_id', $this->advanceReasonId())
            ->whereNull('advance_waived_at')->whereNotNull('advance_hold_since');
        // A date the customer gave moves the clock: no reminder (the date notice covers it) and no cancel before the day after it.
        $dated = fn ($q) => $q->whereNull('hold_expected_date');
        foreach ((clone $advance)->where($dated)->whereNull('advance_reminded_at')->where('advance_hold_since', '<=', now()->subHours($days * 12))
            ->limit(100)->get(['id', 'order_no', 'moderator_id', 'advance_required', 'advance_verified']) as $o) {
            DB::table('orders')->where('id', $o->id)->update(['advance_reminded_at' => now()]);
            $this->notifications->send('hold_date_reached', __('Advance still missing: :no', ['no' => $o->order_no]),
                __('৳:a not received yet. It is cancelled by itself after :d days.', ['a' => number_format((float) $o->advance_required - (float) $o->advance_verified), 'd' => $days]), [
                    'link' => route('desk.index', ['tab' => 'call', 'order' => $o->id]), 'subject' => ['order', $o->id],
                    'user_ids' => array_filter([$o->moderator_id]) ?: $this->managerIds(),
                ]);
            $out['reminded']++;
        }
        $reason = DB::table('status_reasons')->where('reason_type', 'cancel')->where('system_key', 'no_advance')->value('id');
        foreach ((clone $advance)->where('advance_hold_since', '<=', now()->subDays($days))
            ->where(fn ($q) => $q->whereNull('hold_expected_date')->orWhere('hold_expected_date', '<', today()->subDay()))->limit(100)->pluck('id') as $id) {
            $order = Order::find($id);
            if (! $order || app(OrderStateMachine::class)->advanceSettled($order)) {
                continue;
            }
            try {
                app(OrderStateMachine::class)->transition($order, 'cancelled', null, 'system', $reason, __('No advance in :d days', ['d' => $days]));
                $out['cancelled']++;
            } catch (\Throwable $e) {
                Log::warning("No-advance cancel of order {$id} failed: {$e->getMessage()}");
            }
        }

        return $out;
    }

    /** Booking failed: a note on the order and a notice to its moderator and the managers. */
    private function failedNotice(object $row, string $message): void
    {
        $this->systemNote($row->id, $message, null);
        $this->notifications->send('booking_failed', __('Booking failed: :no', ['no' => $row->order_no]), $message, [
            'link' => route('desk.index', ['tab' => 'send', 'order' => $row->id]), 'subject' => ['order', $row->id],
            'user_ids' => array_filter(array_merge([$row->moderator_id], $this->managerIds())),
        ]);
    }

    // ── Reactions to status changes (registered in AppServiceProvider) ──

    public function onTransition(Order $order, array $from, array $to, ?User $actor): void
    {
        $set = [];
        if ($order->action_due_at !== null) {
            $set['action_due_at'] = null; // an action was taken: this timer is done
        }
        if (in_array($to['key'], ['no_answer', 'hold'], true) && ! $order->had_setback) {
            $set['had_setback'] = true;
        }
        if ($to['key'] !== 'no_answer' && $order->next_call_at !== null) {
            $set['next_call_at'] = null;
        }
        if ($to['key'] === 'packed') {
            $set['packed_at'] = now();
        }
        // Confirmed = book the courier right away, after the response (nobody waits for the courier).
        if ($to['key'] === 'confirmed') {
            $set += ['booking_state' => 'queued', 'booking_attempts' => 0, 'booking_error' => null, 'book_after' => null];
            $orderId = $order->id;
            $actorId = $actor?->id;
            app()->terminating(fn () => $this->runBookings([$orderId], $actorId));
        } elseif ($from['key'] === 'confirmed' && $to['key'] !== 'ready_for_packaging' && $order->booking_state !== 'none') {
            $set += ['booking_state' => 'none', 'book_after' => null]; // held or cancelled before the courier was booked
        }
        if ($set) {
            DB::table('orders')->where('id', $order->id)->update($set);
        }
        // Cancelled after booking: the old label must never scan again, and someone deletes the booking at the courier by hand.
        if ($to['key'] === 'cancelled' && $order->active_shipment_id
            && DB::table('shipments')->where('id', $order->active_shipment_id)->whereNull('cancelled_at')->whereNull('final_at')->exists()) {
            DB::table('shipment_labels')->where('order_id', $order->id)->whereNull('voided_at')
                ->update(['voided_at' => now(), 'void_reason' => __('Order cancelled'), 'updated_at' => now()]);
            $cn = DB::table('shipments')->where('id', $order->active_shipment_id)->value('consignment_id');
            $this->notifications->send('courier_cancel_needed', __('Delete :no at the courier', ['no' => $order->order_no]),
                __('Cancelled after booking (CN :cn). Delete it in the courier panel, then press "Deleted" on the order.', ['cn' => $cn ?? '-'])
                    .($order->packed_at ? ' '.__('It was packed: open the box and put the items back.') : ''), [
                    'link' => route('orders.show', $order), 'subject' => ['order', $order->id], 'priority' => 'urgent',
                    'user_ids' => array_values(array_unique(array_filter([$order->moderator_id, $actor?->id, $order->packer_id, ...$this->managerIds()]))),
                ]);
        }
        if ($to['key'] === 'cancelled' && ($order->packer_id || $order->packed_at)) {
            DB::table('orders')->where('id', $order->id)->update(['unpack_needed_at' => now(), 'unpack_packer_id' => $order->packer_id, 'packer_id' => null, 'packaging_started_at' => null]);
        }
        if ($to['final']) {
            DB::table('order_assignments')->where('order_id', $order->id)->whereNull('ended_at')->update(['ended_at' => now(), 'ended_reason' => 'finished']);
        }
        // The next timer is not started here: it starts when the moderator opens their next order.
    }

    /** @return list<int> */
    public function managerIds(): array
    {
        return DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->whereIn('r.system_key', ['manager', 'owner'])->whereNull('r.deleted_at')->distinct()->pluck('ur.user_id')->all();
    }

    private function systemNote(int $orderId, string $body, ?int $userId, array $meta = []): void
    {
        DB::table('order_notes')->insert([
            'order_id' => $orderId, 'note_type' => 'assignment', 'body' => $body, 'meta' => $meta ? json_encode($meta) : null,
            'status_at_time_id' => DB::table('orders')->where('id', $orderId)->value('status_id'), 'user_id' => $userId, 'created_at' => now(),
        ]);
    }
}
