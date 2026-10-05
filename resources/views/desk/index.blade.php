@php
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Js;

    $tabLabels = ['verify' => __('Verify'), 'call' => __('Call'), 'again' => __('Call again'), 'hold' => __('On hold'), 'send' => __('To send'), 'packaging' => __('Packaging')];
    $url = fn (array $q) => route('desk.index', $q);
    $key = $order ? $statuses[$order->status_id]['key'] : null;
    $isMine = $order && $order->moderator_id === auth()->id();
    $atLimit = $counts['active'] >= $limit;
    $age = fn ($t) => $t ? Carbon::parse($t)->diffForHumans(now(), ['short' => true, 'syntax' => Carbon::DIFF_ABSOLUTE, 'parts' => 1]) : '';
    $money = fn ($v) => '৳'.number_format((float) $v);
    $secondsLeft = fn ($t) => $t ? max(0, (int) now()->diffInSeconds(Carbon::parse($t), false)) : null;
    $noteIcon = ['status' => '●', 'call' => '☎', 'chat' => '✉', 'payment' => '৳', 'assignment' => '👤', 'amendment' => '✎', 'courier' => '🚚', 'verification' => '✓', 'rider' => '🛵'];
    $stock = ['in_stock' => ['green', __('In stock')], 'backorder' => ['amber', __('Pre-order')], 'out_of_stock' => ['red', __('Out of stock')]];
    $guide = [
        'new' => __('Check the record: name, phone, address and items. Then call.'),
        'record_verified' => __('Call the customer and confirm the order.'),
        'no_answer' => __('No response before. Call again.'),
        'hold' => __('On hold. Resume it when the reason is solved.'),
        'confirmed' => __('Confirmed. Send it to packaging: the courier is booked for you.'),
    ];
    $input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:outline-none';
    $showDetailOnPhone = request()->filled('order') && $order && ! $lost;
    // The one order whose timer is running: the open one, or another of mine.
    $armed = $order && $order->action_due_at && $isMine ? $order : $timed;
    $armedSeconds = $armed ? $secondsLeft($armed->action_due_at) : 0;
    $armedIsOpen = $armed && $order && $armed->id === $order->id;
    $canExtend = $armed && ! $armed->timer_extended_at && $extendsLeft > 0;
    // embed=1: one order inside the Order activity popup (no list, no tabs, no header).
    $embed = request()->boolean('embed');
@endphp

<x-dynamic-component :component="$embed ? 'layouts.embed' : 'layouts.app'" :heading="__('Order management')">
<div x-data="{
        reasonMode: null, reason: null,
        openReason(mode) { this.reasonMode = mode; this.reason = null },
        keys(e) {
            if (e.ctrlKey || e.metaKey || e.altKey || ['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName)) return;
            const k = e.key.toLowerCase();
            if (this.reasonMode) {
                if (k === 'escape') { this.reasonMode = null; return }
                const pick = this.$root.querySelector(`[data-reason='${this.reasonMode}-${k}']`);
                if (pick) { pick.click(); e.preventDefault() }
                return;
            }
            if (k === 'j' || k === 'k') {
                const rows = [...this.$root.querySelectorAll('[data-row]')];
                const i = rows.findIndex(r => r.dataset.current === '1');
                const next = rows[k === 'j' ? i + 1 : i - 1];
                if (next) { window.location = next.href; e.preventDefault() }
                return;
            }
            const btn = this.$root.querySelector(`[data-key='${k}']:not([disabled])`);
            if (btn) { btn.click(); e.preventDefault() }
        },
    }" @keydown.window="keys($event)">

    {{-- New orders: nobody picks; "Take next" always gives the oldest one. --}}
    @unless ($embed)
    <div @class(['mb-4 flex-col gap-3 rounded-xl border border-gray-200 bg-white p-4 sm:flex-row sm:items-center sm:justify-between', 'flex' => ! $showDetailOnPhone, 'hidden lg:flex' => $showDetailOnPhone])>
        <div class="flex items-center gap-4">
            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl text-lg font-bold tabular-nums {{ $waiting ? 'bg-primary text-white' : 'bg-gray-100 text-gray-400' }}">{{ $waiting }}</div>
            <div>
                <p class="text-sm font-semibold text-gray-800">{{ trans_choice('{0} No new orders|{1} New order waiting|[2,*] New orders waiting', $waiting) }}</p>
                <p class="text-xs text-gray-500">
                    @if ($waiting) {{ __('Oldest: :t ago', ['t' => $age($oldestWaiting)]) }} · @endif
                    {{ $limit === 1 ? ($counts['active'] ? __('One order at a time: finish yours to take the next') : __('One order at a time')) : __('You hold :a of :l', ['a' => $counts['active'], 'l' => $limit]) }}
                    @if ($counts['again']) · {{ __(':n waiting on No response', ['n' => $counts['again']]) }} @endif
                </p>
            </div>
        </div>
        @if ($canTake)
            <form method="POST" action="{{ route('desk.next') }}">
                @csrf
                <x-button class="w-full sm:w-auto" data-key="t" :disabled="! $waiting || $atLimit">
                    {{ $atLimit ? ($limit === 1 ? __('Finish this order first') : __('Finish one first')) : __('Take next') }} <kbd class="rounded bg-white/20 px-1.5 text-[11px]">T</kbd>
                </x-button>
            </form>
        @endif
    </div>

    <div @class(['hidden lg:block' => $showDetailOnPhone])>
        <x-tabs :tabs="collect($tabLabels)->map(fn ($label, $t) => [$label, $url(['tab' => $t]), $counts[$t]])->all()" :active="$tab" />
    </div>
    @endunless


    @if ($notYours ?? null)
        <div class="mb-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ __(':no is no longer yours: it was finished or given to someone else.', ['no' => $notYours]) }}</div>
    @endif
    {{-- A new order came to me while this page was open (given automatically, or a call-again came back). --}}
    @php
        $busy = $order && $isMine && in_array($key ?? '', ['new', 'record_verified', 'no_answer'], true);
    @endphp
    <div x-data="{ fresh: null }" x-cloak x-show="fresh"
        @desk-new-order.window="
            const o = $event.detail;
            const typing = ['INPUT', 'TEXTAREA'].includes(document.activeElement?.tagName) && document.activeElement.value;
            if (!@js($busy) && !typing) { window.location.href = @js(route('desk.index')) + '?tab=' + o.tab + '&order=' + o.id } else { fresh = o }"
        class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-green-300 bg-green-50 px-4 py-3 text-sm text-green-900">
        <p><b x-text="fresh?.no"></b> {{ __('is yours now. Open it when you finish this one.') }}</p>
        <span class="flex shrink-0 items-center gap-2">
            <a :href="fresh ? @js(route('desk.index')) + '?tab=' + fresh.tab + '&order=' + fresh.id : '#'" class="rounded-lg bg-primary px-3 py-1.5 text-xs font-semibold text-white hover:bg-primary-dark">{{ __('Open') }}</a>
            <button type="button" @click="fresh = null" class="text-green-700 hover:text-green-900" aria-label="{{ __('Close') }}">&times;</button>
        </span>
    </div>

    @if ($lost)
        <div class="mb-3 flex items-start justify-between gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" x-data="{ show: true }" x-show="show">
            <p>{{ trans_choice('{1} Time ran out on :list. It went back to New and anyone can take it.|[2,*] Time ran out on :list. They went back to New and anyone can take them.', count($lost), ['list' => implode(', ', $lost)]) }}</p>
            <button type="button" @click="show = false" class="shrink-0 text-red-400 hover:text-red-700" aria-label="{{ __('Close') }}">&times;</button>
        </div>
    @endif

    {{-- Timer watch. Quiet until 2 minutes are left, red in the last 30 seconds; when the time is up the page
         reloads by itself (same tab, same page) and the server takes the order back. --}}
    @if ($armed && $armedSeconds > 0)
        <div x-data="{
                start: Date.now(), base: {{ $armedSeconds }}, now: Date.now(), leaving: false,
                init() { setInterval(() => { this.now = Date.now(); if (this.left === 0 && !this.leaving) { this.leaving = true; setTimeout(() => window.location.reload(), 1200) } }, 1000) },
                get left() { return Math.max(0, this.base - Math.floor((this.now - this.start) / 1000)) },
                get clock() { return Math.floor(this.left / 60) + ':' + String(this.left % 60).padStart(2, '0') },
            }" class="sticky top-[68px] z-20">
            @unless ($armedIsOpen)
                <a x-show="left > 120" href="{{ $url(['tab' => $statuses[$armed->status_id]['key'] === 'new' ? 'verify' : 'call', 'order' => $armed->id]) }}"
                    class="mb-3 flex items-center justify-between gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm text-amber-900 shadow-sm hover:bg-amber-100">
                    <span class="min-w-0"><b>{{ __('Timer running: :no', ['no' => $armed->order_no]) }}</b> <span class="hidden sm:inline">{{ __('Finish that one first.') }}</span></span>
                    <span class="flex shrink-0 items-center gap-2">
                        <span class="rounded-full bg-white px-2.5 py-0.5 text-sm font-bold tabular-nums" x-text="clock"></span>
                        <span class="rounded-lg bg-primary px-2.5 py-1 text-xs font-semibold text-white">{{ __('Open') }}</span>
                    </span>
                </a>
            @endunless
            <div x-show="left <= 120" x-cloak class="mb-3 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-xl border px-4 py-3 text-sm shadow-sm"
                :class="left <= 30 ? 'border-red-300 bg-red-600 text-white' : 'border-red-200 bg-red-50 text-red-800'">
                <p class="min-w-0 flex-1">
                    <template x-if="left > 0"><span><b class="tabular-nums" x-text="clock"></b> {{ __('left on :no. Act now, or it goes back to New.', ['no' => $armed->order_no]) }}</span></template>
                    <template x-if="left === 0"><span>{{ __('Time is up on :no. Taking it back…', ['no' => $armed->order_no]) }}</span></template>
                </p>
                <div class="flex shrink-0 items-center gap-2" x-show="left > 0">
                    @unless ($armedIsOpen)
                        <a href="{{ $url(['tab' => $statuses[$armed->status_id]['key'] === 'new' ? 'verify' : 'call', 'order' => $armed->id]) }}" class="rounded-lg border border-current px-3 py-1.5 text-xs font-semibold">{{ __('Open it') }}</a>
                    @endunless
                    @if ($canExtend)
                        <form method="POST" action="{{ route('desk.extend', $armed->id) }}">
                            @csrf
                            <button type="submit" class="rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-red-700 shadow-sm hover:bg-red-50">{{ __('+:m min', ['m' => $extendMinutes]) }}</button>
                        </form>
                    @endif
                </div>
                @if ($canExtend)
                    <p class="w-full text-[11px] opacity-80" x-show="left > 0">{{ __('Extra time: once per order, :n left today. It is recorded.', ['n' => $extendsLeft]) }}</p>
                @elseif ($armed->timer_extended_at)
                    <p class="w-full text-[11px] opacity-80" x-show="left > 0">{{ __('Extra time was already used on this order.') }}</p>
                @endif
            </div>
        </div>
    @endif

    <div @class(['grid gap-4', 'lg:grid-cols-[300px_minmax(0,1fr)]' => ! $embed])>
        {{-- My orders in this stage --}}
        @unless ($embed)
        <aside @class(['lg:block', 'hidden' => $showDetailOnPhone])>
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <p class="border-b border-gray-100 px-4 py-2.5 text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ $tabLabels[$tab] }} · {{ __('oldest first') }}</p>
                @forelse ($list as $row)
                    @php $current = $order && $order->id === $row->id; @endphp
                    <a href="{{ $url(['tab' => $tab, 'page' => $list->currentPage() > 1 ? $list->currentPage() : null, 'order' => $row->id]) }}" data-row data-current="{{ $current ? 1 : 0 }}"
                        @class(['flex items-center justify-between gap-2 border-b border-l-4 border-b-gray-100 px-4 py-3 last:border-b-0 hover:bg-gray-50',
                            'border-l-primary bg-primary-soft/60' => $current, 'border-l-transparent' => ! $current])>
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-medium text-gray-800">{{ $row->ship_name }}</span>
                            <span class="block truncate font-mono text-xs text-gray-500">{{ $row->order_no }} · {{ $money($row->grand_total) }}@if ($row->channel !== 'web') · {{ $row->channel }}@endif</span>
                        </span>
                        <span class="shrink-0 text-right">
                            @if ($row->action_due_at)
                                <x-countdown :seconds="$secondsLeft($row->action_due_at)" />
                            @elseif ($tab === 'again')
                                <span class="text-xs text-amber-700">{{ Carbon::parse($row->next_call_at)->isToday() ? Carbon::parse($row->next_call_at)->format('g:i A') : Carbon::parse($row->next_call_at)->format('d M, g A') }}</span>
                            @elseif ($tab === 'packaging')
                                <span class="flex flex-col items-end gap-1">
                                    <x-badge :color="$statuses[$row->status_id]['color']">{{ $row->booking_state === 'failed' ? __('Booking failed') : ($row->booking_state === 'queued' ? __('Booking…') : __($statuses[$row->status_id]['name'])) }}</x-badge>
                                    @if ($row->packer_name)
                                        <span class="flex items-center gap-1 text-[11px] text-purple-800" title="{{ __('Packaging by :n', ['n' => $row->packer_name]) }}"><x-avatar :name="$row->packer_name" :photo="$row->packer_photo" size="xs" /> {{ $row->packer_name }}</span>
                                    @elseif ($row->booking_state !== 'queued' && $row->booking_state !== 'failed')
                                        <span class="text-[11px] text-gray-400">{{ __('No packer yet') }}</span>
                                    @endif
                                </span>
                            @else
                                <span class="text-xs text-gray-400">{{ $age($row->assigned_at ?? $row->created_at) }}</span>
                            @endif
                            @if ($row->no_response_count && $tab !== 'packaging')<span class="mt-0.5 block text-[11px] text-amber-700">{{ __('Try :n', ['n' => $row->no_response_count + 1]) }}</span>@endif
                        </span>
                    </a>
                @empty
                    <p class="px-4 py-10 text-center text-sm text-gray-400">{{ __('Nothing here.') }}</p>
                @endforelse
            </div>
            @if ($list->hasPages())<div class="mt-3">{{ $list->links() }}</div>@endif
            <p class="mt-3 hidden text-[11px] text-gray-400 lg:block">{{ __('Keys: J / K next and previous order · the letter on a button presses it · T take next') }}</p>
        </aside>
        @endunless

        {{-- The open order --}}
        <section @class(['min-w-0', 'hidden lg:block' => ! $showDetailOnPhone && ! $embed])>
            @if (! $order)
                <div class="rounded-xl border border-dashed border-gray-300 bg-white p-12 text-center">
                    <p class="text-sm font-medium text-gray-700">{{ __('This list is empty') }}</p>
                    <p class="mt-1 text-sm text-gray-500">{{ $waiting && $canTake ? __('Press Take next to get the oldest new order.') : __('New orders will show at the top when they arrive.') }}</p>
                </div>
            @else
                @unless ($embed)
                <a href="{{ $url(['tab' => $tab]) }}" class="mb-3 inline-flex items-center gap-1 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-primary lg:hidden">&larr; {{ __('My orders') }} · {{ $tabLabels[$tab] }} ({{ $counts[$tab] }})</a>
                @endunless
                @if ($embed && $reassign)
                    @include('desk._reassign', ['order' => $order, 'reassign' => $reassign])
                @endif
                <x-order-presence :order="$order" mode="view" />

                <div class="rounded-xl border border-gray-200 bg-white">
                    <div class="p-5">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2 text-xs text-gray-500">
                                    <a href="{{ route('orders.show', $order) }}" class="font-mono font-medium text-primary hover:underline">{{ $order->order_no }}</a>
                                    <span>{{ ucfirst($order->channel) }}</span>
                                    <span>{{ __(':t ago', ['t' => $age($order->created_at)]) }}</span>
                                    <x-order-status :order="$order" :statuses="$statuses" />
                                    {{-- Who has it (and who packs it): shown to managers and whenever it is not the viewer's own order. --}}
                                    @if (! $embed && ($order->moderator_id !== auth()->id() || auth()->user()->can('orders.reassign')))
                                        <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 py-0.5 pl-0.5 pr-2 text-xs text-gray-700" title="{{ __('Assigned to') }}">
                                            @if ($order->moderator)<x-avatar :name="$order->moderator->name" :photo="$order->moderator->photo_path" size="xs" /> {{ $order->moderator_id === auth()->id() ? __('You') : $order->moderator->name }}@else<span class="px-1.5">{{ __('Nobody') }}</span>@endif
                                        </span>
                                    @endif
                                    @if ($order->packer)
                                            <span class="inline-flex items-center gap-1 rounded-full bg-purple-50 py-0.5 pl-0.5 pr-2 text-xs text-purple-800" title="{{ __('Packaging') }}">
                                                <x-avatar :name="$order->packer->name" :photo="$order->packer->photo_path" size="xs" /> {{ $order->packer->name }}
                                            </span>
                                        @endif
                                    @if ($order->action_due_at)<x-countdown :seconds="$secondsLeft($order->action_due_at)" />@endif
                                    @if ($order->no_response_count)<x-badge color="amber">{{ __('No response ×:n', ['n' => $order->no_response_count]) }}</x-badge>@endif
                                    {{-- Wrong item, address or phone: fix it in the popup without leaving this page. --}}
                                    @if (($statuses[$order->status_id]['edit_policy'] ?? 'locked') !== 'locked')
                                        <button type="button" @click="$dispatch('open-order-edit')" class="lockable inline-flex items-center gap-1 rounded-lg border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:border-primary hover:text-primary">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.86 4.49l2.65 2.65M4 20l1-4L16.5 4.5a1.87 1.87 0 012.65 2.65L7.65 18.65 4 20z"/></svg>
                                            {{ __('Edit order') }}
                                        </button>
                                    @endif
                                </div>
                                <h2 class="mt-1 text-2xl font-semibold text-gray-900">{{ $order->ship_name }}</h2>
                                <p class="text-sm text-gray-600">{{ collect([$order->ship_address, $order->ship_thana, $order->ship_district])->filter()->join(', ') }}</p>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="text-xl font-semibold tabular-nums text-gray-900 sm:text-2xl">{{ $money($order->grand_total) }}</p>
                                <p class="text-xs text-gray-500">{{ __('COD :c · delivery :d', ['c' => $money($order->cod_amount), 'd' => $money($order->delivery_charge)]) }}</p>
                            </div>
                        </div>

                        @if (isset($guide[$key]) || $order->customer_note || $order->holdReason)
                            <div class="mt-4 space-y-1 rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-700">
                                @if ($key === 'hold')
                                    <p><b>{{ $order->holdReason->label_en ?? __('On hold') }}</b>@if ($order->hold_expected_date) · {{ __('until :d', ['d' => $order->hold_expected_date->format('d M')]) }}@endif
                                        @if ($detail['heldBy']) · {{ __('held by :n', ['n' => $detail['heldBy']]) }}@endif</p>
                                @elseif ($key === 'no_answer' && $order->next_call_at && $order->next_call_at->isFuture())
                                    <p>{{ __('Comes back to your Call tab at :t.', ['t' => $order->next_call_at->isToday() ? $order->next_call_at->format('g:i A') : $order->next_call_at->format('d M, g:i A')]) }}</p>
                                @elseif (isset($guide[$key]))
                                    <p>{{ $guide[$key] }}</p>
                                @endif
                                @if ($order->customer_note)<p class="text-amber-800">{{ __('Customer note') }}: {{ $order->customer_note }}</p>@endif
                            </div>
                        @endif

                        <div class="mt-4 grid gap-3 sm:grid-cols-2 2xl:grid-cols-3">
                            <div class="min-w-0 rounded-lg border border-gray-200 p-4 sm:col-span-2 2xl:col-span-1">
                                <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ __('Phone') }}</p>
                                <x-phone-dial :phone="$order->ship_phone" :alt="$order->ship_alt_phone" />
                            </div>
                            <div class="rounded-lg border border-gray-200 p-4">
                                <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ __('Customer history') }}</p>
                                @php $c = $order->customer; @endphp
                                @if ($c && $c->orders_count > 1)
                                    <p class="text-base font-semibold text-gray-900">{{ __(':d delivered · :r returned', ['d' => $c->delivered_count, 'r' => $c->returned_count]) }}</p>
                                    <p class="mt-1 text-xs {{ $c->returned_count > $c->delivered_count ? 'text-red-600' : 'text-green-800' }}">{{ __(':n orders in total', ['n' => $c->orders_count]) }}@if ($c->risk_level && $c->risk_level !== 'normal') · {{ __('risk: :r', ['r' => $c->risk_level]) }}@endif</p>
                                @else
                                    <p class="text-base font-semibold text-gray-900">{{ __('New customer') }}</p>
                                    <p class="mt-1 text-xs text-gray-500">{{ __('First order with us') }}</p>
                                @endif
                            </div>
                            <div class="rounded-lg border border-gray-200 p-4">
                                <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ __('Duplicate check') }}</p>
                                @forelse ($detail['duplicates'] as $d)
                                    <a href="{{ route('orders.show', $d->id) }}" class="block text-sm text-gray-800 hover:text-primary">
                                        <span class="font-mono">{{ $d->order_no }}</span> · {{ $money($d->grand_total) }} · <span style="color: {{ $statuses[$d->status_id]['color'] }}">{{ __($statuses[$d->status_id]['name']) }}</span>
                                    </a>
                                @empty
                                    <p class="text-base font-semibold text-gray-900">{{ __('None') }}</p>
                                @endforelse
                                <p class="mt-1 text-xs text-gray-500">{{ __('Same customer, last 7 days') }}</p>
                            </div>
                        </div>

                        <div class="mt-3 divide-y divide-gray-100 rounded-lg border border-gray-200">
                            @foreach ($detail['items'] as $i)
                                <div class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm">
                                    <span class="min-w-0 text-gray-800">{{ $i->name_snapshot }} <b class="whitespace-nowrap">{{ \App\Support\Units::qty($i->qty, $i->unit) }}</b></span>
                                    <span class="flex shrink-0 items-center gap-3">
                                        <span class="tabular-nums text-gray-600">{{ $money($i->line_total) }}</span>
                                        <x-badge :color="$stock[$i->availability_status][0] ?? 'gray'">{{ $stock[$i->availability_status][1] ?? $i->availability_status }}</x-badge>
                                    </span>
                                </div>
                            @endforeach
                        </div>

                        @if ($tab === 'packaging' || in_array($key, ['ready_for_packaging', 'packed', 'ready_for_pickup'], true))
                            <div class="mt-3 rounded-lg border border-gray-200 px-4 py-3 text-sm">
                                @if ($order->booking_state === 'queued')
                                    <p class="text-gray-700">{{ __('Booking the courier… this takes a few seconds. Reload to see the CN.') }}</p>
                                @elseif ($order->booking_state === 'failed')
                                    <p class="font-medium text-red-700">{{ __('Courier booking failed: :e', ['e' => $order->booking_error]) }}</p>
                                @else
                                    <p class="text-gray-700">{{ __('CN :cn', ['cn' => $detail['consignment'] ?? '-']) }} · {{ __('in the packaging queue since :t', ['t' => $order->packaging_sent_at?->format('g:i A') ?? '-']) }}</p>
                                    <p class="mt-2 flex items-center gap-2 font-medium text-gray-900">
                                        @if ($order->packer)<x-avatar :name="$order->packer->name" :photo="$order->packer->photo_path" size="sm" />@endif
                                        <span>
                                        @if ($order->packed_at) {{ __('Packed by :n at :t', ['n' => $order->packer->name ?? '-', 't' => $order->packed_at->format('g:i A')]) }}
                                        @elseif (in_array($key, ['packed', 'ready_for_pickup'], true)) {{ __('Packed') }}
                                        @elseif ($order->packer_id) {{ __(':n is packing it, since :t', ['n' => $order->packer->name ?? '-', 't' => $order->packaging_started_at?->format('g:i A')]) }}
                                        @else <span class="text-gray-600">{{ __('Waiting for a packer: the first packer to scan its label takes it.') }}</span> @endif
                                        </span>
                                    </p>
                                @endif
                            </div>
                        @endif

                        {{-- Short histories start open. Once the user opens or closes it, that choice sticks (adding a note must not fold it away). --}}
                        <details class="mt-3 rounded-lg border border-gray-200" @if ($detail['notes']->count() <= 4) open @endif
                            x-data="{
                                count: {{ $detail['notes']->count() }}, saving: false, failed: false,
                                async addNote(form) {
                                    if (this.saving) return;
                                    this.saving = true; this.failed = false;
                                    try {
                                        const r = await fetch(form.action, { method: 'POST', headers: { Accept: 'application/json' }, body: new FormData(form) });
                                        if (!r.ok) throw new Error();
                                        const box = document.createElement('div');
                                        box.innerHTML = (await r.json()).html;
                                        const li = box.querySelector('li');
                                        const list = this.$refs.timeline;
                                        if (list.children.length) li.insertAdjacentHTML('afterbegin', '<span class=&quot;absolute left-[11px] top-6 -bottom-0 w-px bg-gray-200&quot; aria-hidden=&quot;true&quot;></span>');
                                        li.classList.add('note-in');
                                        list.prepend(li);
                                        this.count++;
                                        form.reset();
                                    } catch (e) { this.failed = true }
                                    this.saving = false;
                                },
                            }" x-init="const s = localStorage.getItem('iqs_desk_history'); if (s !== null) $el.open = s === '1'">
                            <summary @click="localStorage.setItem('iqs_desk_history', $el.parentElement.open ? '0' : '1')" class="cursor-pointer px-4 py-2.5 text-sm font-medium text-gray-700">{{ __('History') }} <span class="text-gray-400">(<span x-text="count">{{ $detail['notes']->count() }}</span>)</span></summary>
                            <div class="space-y-3 border-t border-gray-100 px-4 py-3">
                                <form method="POST" action="{{ route('orders.notes', $order) }}" class="flex gap-2" @submit.prevent="addNote($el)">
                                    @csrf
                                    <input type="hidden" name="type" value="manual">
                                    <input name="body" required maxlength="2000" placeholder="{{ __('Add a note') }}" class="{{ $input }}">
                                    <x-button size="sm" variant="secondary">{{ __('Add') }}</x-button>
                                </form>
                                <p x-show="failed" x-cloak class="text-sm text-red-600">{{ __('The note was not saved. Try again.') }}</p>
                                <x-timeline :entries="$detail['notes']" class="pt-2" x-ref="timeline" />
                            </div>
                        </details>
                    </div>

                    {{-- Actions for this stage --}}
                    @if ($isMine || auth()->user()->permissionScope('orders.view') === 'all')
                        @php
                            $actions = match (true) {
                                $key === 'new' => [['verify', __('Record OK, call next'), 'v', 'primary'], ['hold', __('Hold'), 'h', 'secondary'], ['cancel', __('Cancel'), 'x', 'danger-outline']],
                                // Waiting for the next try: no second "No response" until that time comes (the customer may still call back, so the rest stay).
                                $key === 'no_answer' && $order->next_call_at && $order->next_call_at->isFuture() => [['confirm', $order->channel === 'web' ? __('Call verified') : __('Confirm'), 'v', 'primary'], ['hold', __('Hold'), 'h', 'secondary'], ['cancel', __('Cancel'), 'x', 'danger-outline']],
                                in_array($key, ['record_verified', 'no_answer'], true) => [['confirm', $order->channel === 'web' ? __('Call verified') : __('Confirm'), 'v', 'primary'], ['no_response', __('No response'), 'n', 'secondary'], ['hold', __('Hold'), 'h', 'secondary'], ['cancel', __('Cancel'), 'x', 'danger-outline']],
                                $key === 'hold' => [$detail['consignment'] ? ['back_to_packaging', __('Back to packaging'), 'p', 'primary'] : ['resume', __('Resume, call next'), 'r', 'primary'], ['cancel', __('Cancel'), 'x', 'danger-outline']],
                                $key === 'confirmed' && $order->booking_state === 'none' => [['send', __('Send to packaging'), 'p', 'primary'], ['hold', __('Hold'), 'h', 'secondary'], ['cancel', __('Cancel'), 'x', 'danger-outline']],
                                $key === 'confirmed' && $order->booking_state === 'failed' => [['send', __('Try booking again'), 'p', 'primary'], ['cancel', __('Cancel'), 'x', 'danger-outline']],
                                default => [],
                            };
                            // The clock is running on another order: this one waits its turn.
                            if ($blocked) {
                                $actions = [];
                            }
                        @endphp
                        @if ($blocked && $timed)
                            <div class="mx-5 mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                                <p>{{ __('Your timer is running on :no. Finish that one first, then this order opens for work.', ['no' => $timed->order_no]) }}</p>
                                <a href="{{ $url(['tab' => $statuses[$timed->status_id]['key'] === 'new' ? 'verify' : 'call', 'order' => $timed->id]) }}" class="shrink-0 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-white hover:bg-primary-dark">{{ __('Open :no', ['no' => $timed->order_no]) }}</a>
                            </div>
                        @endif
                        @if ($actions)
                            {{-- The form holds only the note; the buttons below belong to it through form="desk-act",
                                 so the bar can stick to the bottom of the card without covering the order on a phone. --}}
                            <form id="desk-act" method="POST" action="{{ route('desk.act', $order) }}" class="px-5 pb-4">
                                @csrf
                                <input type="hidden" name="lock_version" value="{{ $order->lock_version }}">
                                <input type="hidden" name="tab" value="{{ $tab }}">
                            @if ($embed)<input type="hidden" name="embed" value="1">@endif
                                @if ($embed)<input type="hidden" name="embed" value="1">@endif
                                @if (in_array($key, ['record_verified', 'no_answer'], true))
                                    <input name="note" maxlength="500" placeholder="{{ __('Call note (optional)') }}" class="{{ $input }}">
                                @endif
                                @error('order')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                                @error('status')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                            </form>
                            <div class="lockable sticky bottom-0 z-10 flex flex-wrap gap-2 rounded-b-xl border-t border-gray-200 bg-white p-3 sm:p-4">
                                @foreach ($actions as [$action, $label, $k, $variant])
                                    @if (in_array($action, ['hold', 'cancel'], true))
                                        <x-button type="button" :variant="$variant" data-key="{{ $k }}" @click="openReason('{{ $action }}')" class="flex-1 whitespace-nowrap py-2.5 sm:py-3 xl:flex-none">
                                            {{ $label }} <kbd class="hidden rounded bg-black/5 px-1.5 text-[11px] uppercase sm:inline">{{ $k }}</kbd>
                                        </x-button>
                                    @else
                                        <x-button form="desk-act" name="action" value="{{ $action }}" :variant="$variant" data-key="{{ $k }}"
                                            class="whitespace-nowrap {{ $variant === 'primary' ? 'w-full py-3 text-base xl:w-auto xl:flex-1' : 'flex-1 py-2.5 sm:py-3 xl:flex-none' }}">
                                            {{ $label }} <kbd class="hidden rounded {{ $variant === 'primary' ? 'bg-white/20' : 'bg-black/5' }} px-1.5 text-[11px] uppercase sm:inline">{{ $k }}</kbd>
                                        </x-button>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                    @endif
                </div>

                {{-- Hold / Cancel: a reason is required. Number keys pick one. --}}
                @foreach (['hold' => __('Put on hold: why?'), 'cancel' => __('Cancel the order: why?')] as $mode => $title)
                    <div x-show="reasonMode === '{{ $mode }}'" x-cloak class="fixed inset-0 z-[80] flex items-end justify-center sm:items-center sm:px-4">
                        <div class="absolute inset-0 bg-black/40" @click="reasonMode = null"></div>
                        <form method="POST" action="{{ route('desk.act', $order) }}" class="relative w-full max-w-md rounded-t-2xl bg-white p-5 shadow-xl sm:rounded-xl">
                            @csrf
                            <input type="hidden" name="action" value="{{ $mode }}">
                            <input type="hidden" name="lock_version" value="{{ $order->lock_version }}">
                            <input type="hidden" name="tab" value="{{ $tab }}">
                            @if ($embed)<input type="hidden" name="embed" value="1">@endif
                            <input type="hidden" name="reason_id" :value="reason">
                            <h3 class="text-sm font-semibold text-gray-800">{{ $title }}</h3>
                            <div class="mt-3 space-y-1.5">
                                @foreach ($reasons[$mode] as $id => $label)
                                    <button type="button" @click="reason = {{ $id }}" data-reason="{{ $mode }}-{{ $loop->iteration }}"
                                        class="flex w-full items-center justify-between rounded-lg border px-3 py-2.5 text-left text-sm"
                                        :class="reason === {{ $id }} ? 'border-primary bg-primary-soft font-medium text-primary' : 'border-gray-200 text-gray-700 hover:bg-gray-50'">
                                        {{ __($label) }} @if ($loop->iteration < 10)<kbd class="rounded bg-gray-100 px-1.5 text-[11px] text-gray-500">{{ $loop->iteration }}</kbd>@endif
                                    </button>
                                @endforeach
                            </div>
                            @if ($mode === 'hold')
                                <div class="mt-3"><x-date-input name="hold_expected_date" :min="now()->toDateString()" :placeholder="__('Expected date (optional)')" full-width /></div>
                            @endif
                            <input name="note" maxlength="500" placeholder="{{ __('Note (optional)') }}" class="{{ $input }} mt-3">
                            <div class="mt-4 flex gap-2">
                                <button type="button" @click="reasonMode = null" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('Back') }}</button>
                                <button type="submit" :disabled="!reason" class="flex-1 rounded-lg px-4 py-2 text-sm font-medium text-white disabled:opacity-50 {{ $mode === 'cancel' ? 'bg-red-600 hover:bg-red-700' : 'bg-primary hover:bg-primary-dark' }}">{{ $mode === 'cancel' ? __('Cancel the order') : __('Put on hold') }}</button>
                            </div>
                        </form>
                    </div>
                @endforeach
            @endif
        </section>
    </div>
</div>

<script>window.iqsOpenOrder = {{ ($order ?? null)?->id ?? 'null' }};</script>
{{-- Edit popup: the full edit form in a large window over this page. It closes
     only with its Close button (a stray click outside must not lose typing).
     After a save the form tells this page, which reloads on the same order. --}}
@if ($order ?? null)
    <template x-teleport="body">
        <div x-data="{ open: false, src: '' }" x-show="open" x-cloak
            @open-order-edit.window="src = @js(route('orders.edit', ['order' => $order->id, 'embed' => 1])); open = true"
            @message.window="if ($event.origin === location.origin && $event.data?.iqsOrderEdited) location.href = @js(route('desk.index', $embed ? ['embed' => 1, 'order' => $order->id] : ['tab' => $tab, 'order' => $order->id]))"
            class="fixed inset-0 z-[120] flex items-stretch justify-center bg-black/50 sm:items-center sm:p-6" role="dialog" aria-modal="true">
            <div class="flex h-full w-full max-w-6xl flex-col overflow-hidden bg-white shadow-2xl sm:h-[92vh] sm:rounded-2xl">
                <div class="flex items-center justify-between gap-3 border-b border-gray-200 px-4 py-3 sm:px-6">
                    <h3 class="text-base font-semibold text-gray-900">{{ __('Edit :no', ['no' => $order->order_no]) }}</h3>
                    <button type="button" @click="open = false; src = ''" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Close') }}</button>
                </div>
                <iframe :src="src" title="{{ __('Edit order') }}" class="min-h-0 w-full flex-1 border-0 bg-gray-50"></iframe>
            </div>
        </div>
    </template>
@endif
</x-dynamic-component>
