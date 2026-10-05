@php
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Js;

    $tabLabels = ['verify' => __('Verify'), 'call' => __('Call'), 'again' => __('Call again'), 'hold' => __('On hold'), 'send' => __('To send'), 'packing' => __('Packing')];
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
        'confirmed' => __('Confirmed. Send it to packing: the courier is booked for you.'),
    ];
    $input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-green-800 focus:outline-none';
    $showDetailOnPhone = request()->filled('order');
@endphp

<x-layouts.app :heading="__('Order management')">
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
    <div class="mb-4 flex flex-col gap-3 rounded-xl border border-gray-200 bg-white p-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-4">
            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl text-lg font-bold tabular-nums {{ $waiting ? 'bg-green-900 text-white' : 'bg-gray-100 text-gray-400' }}">{{ $waiting }}</div>
            <div>
                <p class="text-sm font-semibold text-gray-800">{{ trans_choice('{0} No new orders|{1} New order waiting|[2,*] New orders waiting', $waiting) }}</p>
                <p class="text-xs text-gray-500">
                    @if ($waiting) {{ __('Oldest: :t ago', ['t' => $age($oldestWaiting)]) }} · @endif
                    {{ __('You hold :a of :l', ['a' => $counts['active'], 'l' => $limit]) }}
                    @if ($counts['again']) · {{ __(':n waiting on No response', ['n' => $counts['again']]) }} @endif
                </p>
            </div>
        </div>
        @if ($canTake)
            <form method="POST" action="{{ route('desk.next') }}">
                @csrf
                <x-button class="w-full sm:w-auto" data-key="t" :disabled="! $waiting || $atLimit">
                    {{ $atLimit ? __('Finish one first') : __('Take next') }} <kbd class="rounded bg-white/20 px-1.5 text-[11px]">T</kbd>
                </x-button>
            </form>
        @endif
    </div>

    <x-tabs :tabs="collect($tabLabels)->map(fn ($label, $t) => [$label, $url(['tab' => $t]), $counts[$t]])->all()" :active="$tab" />

    <div class="grid gap-4 lg:grid-cols-[300px_minmax(0,1fr)]">
        {{-- My orders in this stage --}}
        <aside @class(['lg:block', 'hidden' => $showDetailOnPhone])>
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <p class="border-b border-gray-100 px-4 py-2.5 text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ $tabLabels[$tab] }} · {{ __('oldest first') }}</p>
                @forelse ($list as $row)
                    @php $current = $order && $order->id === $row->id; @endphp
                    <a href="{{ $url(['tab' => $tab, 'page' => $list->currentPage() > 1 ? $list->currentPage() : null, 'order' => $row->id]) }}" data-row data-current="{{ $current ? 1 : 0 }}"
                        @class(['flex items-center justify-between gap-2 border-b border-l-4 border-b-gray-100 px-4 py-3 last:border-b-0 hover:bg-gray-50',
                            'border-l-green-900 bg-green-50/60' => $current, 'border-l-transparent' => ! $current])>
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-medium text-gray-800">{{ $row->ship_name }}</span>
                            <span class="block truncate font-mono text-xs text-gray-500">{{ $row->order_no }} · {{ $money($row->grand_total) }}@if ($row->channel !== 'web') · {{ $row->channel }}@endif</span>
                        </span>
                        <span class="shrink-0 text-right">
                            @if ($row->action_due_at)
                                <x-countdown :seconds="$secondsLeft($row->action_due_at)" />
                            @elseif ($tab === 'again')
                                <span class="text-xs text-amber-700">{{ Carbon::parse($row->next_call_at)->isToday() ? Carbon::parse($row->next_call_at)->format('g:i A') : Carbon::parse($row->next_call_at)->format('d M, g A') }}</span>
                            @elseif ($tab === 'packing')
                                <x-badge :color="$statuses[$row->status_id]['color']">{{ $row->booking_state === 'failed' ? __('Booking failed') : ($row->booking_state === 'queued' ? __('Booking…') : __($statuses[$row->status_id]['name'])) }}</x-badge>
                            @else
                                <span class="text-xs text-gray-400">{{ $age($row->assigned_at ?? $row->created_at) }}</span>
                            @endif
                            @if ($row->no_response_count && $tab !== 'packing')<span class="mt-0.5 block text-[11px] text-amber-700">{{ __('Try :n', ['n' => $row->no_response_count + 1]) }}</span>@endif
                        </span>
                    </a>
                @empty
                    <p class="px-4 py-10 text-center text-sm text-gray-400">{{ __('Nothing here.') }}</p>
                @endforelse
            </div>
            @if ($list->hasPages())<div class="mt-3">{{ $list->links() }}</div>@endif
            <p class="mt-3 hidden text-[11px] text-gray-400 lg:block">{{ __('Keys: J / K next and previous order · the letter on a button presses it · T take next') }}</p>
        </aside>

        {{-- The open order --}}
        <section @class(['min-w-0', 'hidden lg:block' => ! $showDetailOnPhone])>
            @if (! $order)
                <div class="rounded-xl border border-dashed border-gray-300 bg-white p-12 text-center">
                    <p class="text-sm font-medium text-gray-700">{{ __('This list is empty') }}</p>
                    <p class="mt-1 text-sm text-gray-500">{{ $waiting && $canTake ? __('Press Take next to get the oldest new order.') : __('New orders will show at the top when they arrive.') }}</p>
                </div>
            @else
                <a href="{{ $url(['tab' => $tab]) }}" class="mb-3 inline-block text-sm text-green-900 hover:underline lg:hidden">&larr; {{ $tabLabels[$tab] }}</a>

                @if ($timed)
                    <a href="{{ $url(['tab' => $statuses[$timed->status_id]['key'] === 'new' ? 'verify' : 'call', 'order' => $timed->id]) }}"
                        class="mb-3 flex items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 hover:bg-amber-100">
                        <span>{{ __('Your timer is running on :no. Finish that one first.', ['no' => $timed->order_no]) }}</span>
                        <x-countdown :seconds="$secondsLeft($timed->action_due_at)" />
                    </a>
                @endif

                <div class="rounded-xl border border-gray-200 bg-white">
                    <div class="p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2 text-xs text-gray-500">
                                    <a href="{{ route('orders.show', $order) }}" class="font-mono font-medium text-green-900 hover:underline">{{ $order->order_no }}</a>
                                    <span>{{ ucfirst($order->channel) }}</span>
                                    <span>{{ __(':t ago', ['t' => $age($order->created_at)]) }}</span>
                                    <x-order-status :order="$order" :statuses="$statuses" />
                                    @if ($order->action_due_at)<x-countdown :seconds="$secondsLeft($order->action_due_at)" />@endif
                                    @if ($order->no_response_count)<x-badge color="amber">{{ __('No response ×:n', ['n' => $order->no_response_count]) }}</x-badge>@endif
                                </div>
                                <h2 class="mt-1 text-2xl font-semibold text-gray-900">{{ $order->ship_name }}</h2>
                                <p class="text-sm text-gray-600">{{ collect([$order->ship_address, $order->ship_thana, $order->ship_district])->filter()->join(', ') }}</p>
                            </div>
                            <div class="text-right">
                                <p class="text-2xl font-semibold tabular-nums text-gray-900">{{ $money($order->grand_total) }}</p>
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

                        <div class="mt-4 grid gap-3 md:grid-cols-3">
                            <div class="rounded-lg border border-gray-200 p-4">
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
                                    <a href="{{ route('orders.show', $d->id) }}" class="block text-sm text-gray-800 hover:text-green-900">
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
                                    <span class="min-w-0 text-gray-800">{{ $i->name_snapshot }} <b class="whitespace-nowrap">×{{ rtrim(rtrim(number_format((float) $i->qty, 3), '0'), '.') }}{{ $i->unit === 'g' ? ' g' : '' }}</b></span>
                                    <span class="flex shrink-0 items-center gap-3">
                                        <span class="tabular-nums text-gray-600">{{ $money($i->line_total) }}</span>
                                        <x-badge :color="$stock[$i->availability_status][0] ?? 'gray'">{{ $stock[$i->availability_status][1] ?? $i->availability_status }}</x-badge>
                                    </span>
                                </div>
                            @endforeach
                        </div>

                        @if ($tab === 'packing' || in_array($key, ['ready_for_packaging', 'packed', 'ready_for_pickup'], true))
                            <div class="mt-3 rounded-lg border border-gray-200 px-4 py-3 text-sm">
                                @if ($order->booking_state === 'queued')
                                    <p class="text-gray-700">{{ __('Booking the courier… this takes a few seconds. Reload to see the CN.') }}</p>
                                @elseif ($order->booking_state === 'failed')
                                    <p class="font-medium text-red-700">{{ __('Courier booking failed: :e', ['e' => $order->booking_error]) }}</p>
                                @else
                                    <p class="text-gray-700">{{ __('CN :cn', ['cn' => $detail['consignment'] ?? '-']) }} · {{ __('in the packing queue since :t', ['t' => $order->packing_sent_at?->format('g:i A') ?? '-']) }}</p>
                                    <p class="mt-1 font-medium text-gray-900">
                                        @if ($order->packed_at) {{ __('Packed by :n at :t', ['n' => $order->packer->name ?? '-', 't' => $order->packed_at->format('g:i A')]) }}
                                        @elseif (in_array($key, ['packed', 'ready_for_pickup'], true)) {{ __('Packed') }}
                                        @elseif ($order->packer_id) {{ __('Packing: :n, since :t', ['n' => $order->packer->name ?? '-', 't' => $order->packing_started_at?->format('g:i A')]) }}
                                        @else {{ __('Waiting for a packer') }} @endif
                                    </p>
                                @endif
                            </div>
                        @endif

                        <details class="mt-3 rounded-lg border border-gray-200" @if ($detail['notes']->count() <= 4) open @endif>
                            <summary class="cursor-pointer px-4 py-2.5 text-sm font-medium text-gray-700">{{ __('History') }} <span class="text-gray-400">({{ $detail['notes']->count() }})</span></summary>
                            <div class="space-y-2 border-t border-gray-100 px-4 py-3">
                                <form method="POST" action="{{ route('orders.notes', $order) }}" class="flex gap-2">
                                    @csrf
                                    <input type="hidden" name="type" value="manual">
                                    <input name="body" required maxlength="2000" placeholder="{{ __('Add a note') }}" class="{{ $input }}">
                                    <x-button size="sm" variant="secondary">{{ __('Add') }}</x-button>
                                </form>
                                @foreach ($detail['notes'] as $n)
                                    <p class="text-xs text-gray-600"><span class="mr-1">{{ $noteIcon[$n->note_type] ?? '·' }}</span>{{ $n->body }}
                                        <span class="text-gray-400">· {{ $n->user ?? __('System') }} · {{ Carbon::parse($n->created_at)->format('d M, g:i A') }}</span></p>
                                @endforeach
                            </div>
                        </details>
                    </div>

                    {{-- Actions for this stage --}}
                    @if ($isMine || auth()->user()->permissionScope('orders.view') === 'all')
                        @php
                            $actions = match (true) {
                                $key === 'new' => [['verify', __('Record OK, call next'), 'v', 'primary'], ['hold', __('Hold'), 'h', 'secondary'], ['cancel', __('Cancel'), 'x', 'danger-outline']],
                                in_array($key, ['record_verified', 'no_answer'], true) => [['confirm', $order->channel === 'web' ? __('Call verified') : __('Confirm'), 'v', 'primary'], ['no_response', __('No response'), 'n', 'secondary'], ['hold', __('Hold'), 'h', 'secondary'], ['cancel', __('Cancel'), 'x', 'danger-outline']],
                                $key === 'hold' => [$detail['consignment'] ? ['back_to_packing', __('Back to packing'), 'p', 'primary'] : ['resume', __('Resume, call next'), 'r', 'primary'], ['cancel', __('Cancel'), 'x', 'danger-outline']],
                                $key === 'confirmed' && $order->booking_state === 'none' => [['send', __('Send to packing'), 'p', 'primary'], ['hold', __('Hold'), 'h', 'secondary'], ['cancel', __('Cancel'), 'x', 'danger-outline']],
                                $key === 'confirmed' && $order->booking_state === 'failed' => [['send', __('Try booking again'), 'p', 'primary'], ['cancel', __('Cancel'), 'x', 'danger-outline']],
                                default => [],
                            };
                        @endphp
                        @if ($actions)
                            <form method="POST" action="{{ route('desk.act', $order) }}" class="sticky bottom-0 rounded-b-xl border-t border-gray-200 bg-white p-4">
                                @csrf
                                <input type="hidden" name="lock_version" value="{{ $order->lock_version }}">
                                <input type="hidden" name="tab" value="{{ $tab }}">
                                @if (in_array($key, ['record_verified', 'no_answer'], true))
                                    <input name="note" maxlength="500" placeholder="{{ __('Call note (optional)') }}" class="{{ $input }} mb-3">
                                @endif
                                @error('order')<p class="mb-2 text-sm text-red-600">{{ $message }}</p>@enderror
                                @error('status')<p class="mb-2 text-sm text-red-600">{{ $message }}</p>@enderror
                                <div class="grid grid-cols-2 gap-2 sm:flex">
                                    @foreach ($actions as [$action, $label, $k, $variant])
                                        @if (in_array($action, ['hold', 'cancel'], true))
                                            <x-button type="button" :variant="$variant" data-key="{{ $k }}" @click="openReason('{{ $action }}')" class="py-3">
                                                {{ $label }} <kbd class="rounded bg-black/5 px-1.5 text-[11px] uppercase">{{ $k }}</kbd>
                                            </x-button>
                                        @else
                                            <x-button name="action" value="{{ $action }}" :variant="$variant" data-key="{{ $k }}" class="py-3 {{ $variant === 'primary' ? 'col-span-2 sm:flex-1 text-base' : '' }}">
                                                {{ $label }} <kbd class="rounded {{ $variant === 'primary' ? 'bg-white/20' : 'bg-black/5' }} px-1.5 text-[11px] uppercase">{{ $k }}</kbd>
                                            </x-button>
                                        @endif
                                    @endforeach
                                </div>
                            </form>
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
                            <input type="hidden" name="reason_id" :value="reason">
                            <h3 class="text-sm font-semibold text-gray-800">{{ $title }}</h3>
                            <div class="mt-3 space-y-1.5">
                                @foreach ($reasons[$mode] as $id => $label)
                                    <button type="button" @click="reason = {{ $id }}" data-reason="{{ $mode }}-{{ $loop->iteration }}"
                                        class="flex w-full items-center justify-between rounded-lg border px-3 py-2.5 text-left text-sm"
                                        :class="reason === {{ $id }} ? 'border-green-900 bg-green-50 font-medium text-green-900' : 'border-gray-200 text-gray-700 hover:bg-gray-50'">
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
                                <button type="submit" :disabled="!reason" class="flex-1 rounded-lg px-4 py-2 text-sm font-medium text-white disabled:opacity-50 {{ $mode === 'cancel' ? 'bg-red-600 hover:bg-red-700' : 'bg-green-900 hover:bg-green-800' }}">{{ $mode === 'cancel' ? __('Cancel the order') : __('Put on hold') }}</button>
                            </div>
                        </form>
                    </div>
                @endforeach
            @endif
        </section>
    </div>
</div>
</x-layouts.app>
