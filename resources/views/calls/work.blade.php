@php
    use App\Services\Calls\CallListService;
    use App\Support\Permissions\Mask;
    $faces = [0 => '😠', 25 => '🙁', 50 => '😐', 75 => '🙂', 100 => '😍'];
    $faceColor = [0 => 'peer-checked:border-red-500 peer-checked:bg-red-50', 25 => 'peer-checked:border-orange-500 peer-checked:bg-orange-50', 50 => 'peer-checked:border-yellow-500 peer-checked:bg-yellow-50',
        75 => 'peer-checked:border-lime-600 peer-checked:bg-lime-50', 100 => 'peer-checked:border-green-600 peer-checked:bg-green-50'];
    $w = $list->daily_target ? min(100, round($today / $list->daily_target * 100)) : 0;
@endphp

<x-layouts.app :heading="$list->name">
    <div class="mx-auto max-w-xl space-y-3">
        {{-- Today against the target, always in sight. --}}
        <div class="rounded-2xl bg-primary px-4 py-3 text-white">
            <div class="flex items-baseline justify-between text-sm">
                <span>{{ __('Today :n of :t', ['n' => $today, 't' => $list->daily_target]) }}</span>
                <a href="{{ route('calls.mine') }}" class="text-xs text-white/80 underline">{{ __('My calls') }}</a>
            </div>
            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-white/25"><div class="h-full rounded-full bg-green-300" style="width: {{ $w }}%"></div></div>
            <p class="mt-1 text-xs text-white/80">{{ trans_choice(':count number left|:count numbers left', $left, ['count' => number_format($left)]) }}</p>
        </div>

        @if (! $item)
            <div class="rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-12 text-center">
                <p class="text-base font-medium text-gray-800">{{ __('All done for now') }}</p>
                <p class="mt-1 text-sm text-gray-500">{{ __('Call-backs come back when they are due.') }}</p>
                <x-button :href="route('calls.mine')" variant="secondary" class="mt-4">{{ __('Back to my calls') }}</x-button>
            </div>
        @else
            {{-- The customer, and the one big Call button. --}}
            <div class="rounded-2xl border border-gray-200 bg-white p-4">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="truncate text-lg font-semibold text-gray-900">{{ $item->name }}</p>
                        <p class="font-mono text-sm text-gray-500">{{ $seePhone ? $item->phone : Mask::value($item->phone, 'customer_contact') }}@if ($item->attempts) · {{ __('Try :n', ['n' => $item->attempts + 1]) }}@endif</p>
                    </div>
                    @if ($item->customer_id)
                        @can('customers.view')<a href="{{ route('journey.show', $item->customer_id) }}" target="_blank" rel="noopener" class="shrink-0 text-xs text-primary hover:underline">{{ __('Journey') }}</a>@endcan
                    @endif
                </div>
                <a href="tel:{{ $item->phone }}" @click="window.iqsCallStart = Date.now(); fetch(@js(route('calls.touch', [$list->id, $item->id])), { method: 'POST', keepalive: true, headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' } }).catch(() => {})" class="mt-3 flex items-center justify-center gap-2 rounded-xl bg-green-600 px-4 py-4 text-lg font-semibold text-white hover:bg-green-700">
                    <x-icon name="phone" class="h-5 w-5" /> {{ __('Call') }}
                </a>
                @if ($list->instructions)
                    <div class="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-900">
                        <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">{{ __(CallListService::PURPOSES[$list->purpose] ?? $list->purpose) }}</p>
                        <p class="mt-0.5 whitespace-pre-line">{{ $list->instructions }}</p>
                    </div>
                @endif
            </div>

            {{-- What to know before dialing. --}}
            <div class="rounded-2xl border border-gray-200 bg-white p-4 text-sm">
                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Before you call') }}</p>
                @forelse ($history['calls'] as $c)
                    <p class="text-gray-700"><span class="text-gray-500">{{ \Illuminate\Support\Carbon::parse($c->started_at)->format('d M Y') }}</span> · {{ $c->note }}
                        @if ($c->satisfaction !== null)<x-badge :color="$c->satisfaction < 50 ? 'red' : ($c->satisfaction < 75 ? 'amber' : 'green')">{{ $c->satisfaction }}%</x-badge>@endif
                        <span class="text-xs text-gray-400">{{ $c->by }}</span></p>
                @empty
                    <p class="text-gray-500">{{ __('Never called before.') }}</p>
                @endforelse
                <div class="mt-3 space-y-1.5 border-t border-gray-100 pt-3">
                    @foreach ($history['orders'] as $o)
                        <p class="text-gray-700"><span class="text-gray-500">{{ $o->created_at->format('d M Y') }}</span> · <span class="font-mono">{{ $o->order_no }}</span> · ৳{{ number_format((float) $o->grand_total) }} <x-order-status :order="$o" /><br>
                            <span class="text-xs text-gray-500">{{ $o->items->map(fn ($i) => $i->name_snapshot)->join(', ') }}</span></p>
                    @endforeach
                    @foreach ($history['past'] as $p)
                        <p class="text-gray-700"><span class="text-gray-500">{{ \Illuminate\Support\Carbon::parse($p->at)->format('d M Y') }}</span> · ৳{{ number_format((float) $p->amount) }} · {{ __(\App\Services\Customers\HistoryImport::STATUSES[$p->status] ?? $p->status) }}
                            @if ($p->items)<br><span class="text-xs text-gray-500">{{ $p->items }}</span>@endif</p>
                    @endforeach
                    @if ($history['orders']->isEmpty() && $history['past']->isEmpty())<p class="text-gray-500">{{ __('No orders found.') }}</p>@endif
                </div>
            </div>

            {{-- After the call: always on the page (nothing to close by mistake); Save sends the next number. --}}
            <form method="POST" action="{{ route('calls.record', [$list->id, $item->id]) }}" enctype="multipart/form-data" id="call-form"
                class="space-y-4 rounded-2xl border-2 border-primary/30 bg-white p-4"
                x-data="{
                    outcome: @js(old('outcome', '')), sat: @js(old('satisfaction') === null ? null : (int) old('satisfaction')), topics: {}, laterAt: @js(old('later_at', '')),
                    file: '', order: null, busy: false, note: '', key: 'iqs-call-note-{{ $item->id }}',
                    spoke() { return this.spokeOf(this.outcome) },
                    spokeOf(o) { return @js(CallListService::SPOKE).includes(o) }, orderLinked: true,
                    init() {
                        try { this.note = @js(old('note', '')) || localStorage.getItem(this.key) || '' } catch (e) {}
                        this.$watch('note', v => { try { localStorage.setItem(this.key, v) } catch (e) {} });
                        // Back from the dialer: how long the call took.
                        window.iqsCallSeconds = 0;
                        document.addEventListener('visibilitychange', () => {
                            if (! document.hidden && window.iqsCallStart) { window.iqsCallSeconds += Math.round((Date.now() - window.iqsCallStart) / 1000); window.iqsCallStart = null }
                        });
                        window.addEventListener('iqs-order-made', e => {
                            this.order = e.detail.no; this.orderLinked = e.detail.linked;
                            if (e.detail.linked) this.outcome = 'ordered';
                        });
                        // A recording for a talk, a picture for no answer: a file picked for the other kind is dropped.
                        this.$watch('outcome', (v, old) => { if (old && this.spokeOf(v) !== this.spokeOf(old)) window.dispatchEvent(new CustomEvent('proof-clear', { detail: 'proof-list' })) });
                        @if (session('saved_item')) try { localStorage.removeItem('iqs-call-note-{{ (int) session('saved_item') }}') } catch (e) {} @endif
                    },
                    laterIn(h) { const d = new Date(Date.now() + h * 3600000); this.laterAt = this.local(d) },
                    tomorrow() { const d = new Date(); d.setDate(d.getDate() + 1); d.setHours(11, 0, 0, 0); this.laterAt = this.local(d) },
                    local(d) { const p = n => String(n).padStart(2, '0'); return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + 'T' + p(d.getHours()) + ':' + p(d.getMinutes()) },
                    ready() { return this.outcome && this.file && (! this.spoke() || this.sat !== null) && (this.outcome !== 'later' || this.laterAt) },
                    submit(e) { if (! this.ready()) { e.preventDefault(); return } this.$refs.seconds.value = window.iqsCallSeconds || ''; this.busy = true },
                }" @submit="submit($event)">
                @csrf
                <input type="hidden" name="seconds" x-ref="seconds">
                @if ($errors->any())<div class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ $errors->first() }}</div>@endif

                <div>
                    <p class="mb-2 text-sm font-semibold text-gray-900">{{ __('What happened?') }}</p>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach (CallListService::OUTCOMES as $key => $label)
                            <label class="cursor-pointer">
                                <input type="radio" name="outcome" value="{{ $key }}" x-model="outcome" class="peer sr-only">
                                <span class="block rounded-xl border border-gray-300 px-3 py-3 text-center text-sm font-medium text-gray-800 peer-checked:border-primary peer-checked:bg-primary peer-checked:text-white">{{ __($label) }}</span>
                            </label>
                        @endforeach
                    </div>
                    <p x-show="order && orderLinked" x-cloak class="mt-2 rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ __('Order made:') }} <span class="font-mono font-semibold" x-text="order"></span></p>
                    <p x-show="order && ! orderLinked" x-cloak class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">{{ __('The order was made, but this number waited too long and went back to the list, so the order is not linked to it. Press Next call.') }} <span class="font-mono font-semibold" x-text="order"></span></p>
                    @if ($canOrder)
                        <button type="button" class="mt-2 w-full rounded-xl border border-primary px-3 py-2.5 text-sm font-semibold text-primary hover:bg-green-50"
                            @click="window.dispatchEvent(new CustomEvent('order-new', { detail: { url: @js(route('orders.create', ['phone' => $item->phone, 'channel' => 'phone', 'embed' => 1, 'call_item' => $item->id])), title: @js(__('Order for :n', ['n' => $item->name])) } }))">+ {{ __('Take an order') }}</button>
                    @endif
                </div>

                <div x-show="outcome === 'later'" x-cloak>
                    <p class="mb-2 text-sm font-semibold text-gray-900">{{ __('Call again') }}</p>
                    <div class="flex flex-wrap gap-2 text-sm">
                        <button type="button" @click="laterIn(1)" class="rounded-full border border-gray-300 px-3 py-1.5">{{ __('In 1 hour') }}</button>
                        <button type="button" @click="laterIn(3)" class="rounded-full border border-gray-300 px-3 py-1.5">{{ __('In 3 hours') }}</button>
                        <button type="button" @click="tomorrow()" class="rounded-full border border-gray-300 px-3 py-1.5">{{ __('Tomorrow 11 AM') }}</button>
                    </div>
                    <input type="datetime-local" name="later_at" x-model="laterAt" class="mt-2 w-full rounded-xl border border-gray-300 px-3 py-2 text-base focus:border-primary focus:outline-none">
                </div>

                <div x-show="spoke()" x-cloak>
                    <p class="mb-2 text-sm font-semibold text-gray-900">{{ __('How satisfied?') }} <span class="text-red-600">*</span></p>
                    <div class="grid grid-cols-5 gap-1.5">
                        @foreach (CallListService::SATISFACTION as $v => $label)
                            <label class="cursor-pointer">
                                <input type="radio" name="satisfaction" value="{{ $v }}" x-model.number="sat" class="peer sr-only">
                                <span class="block rounded-xl border border-gray-300 px-1 py-2 text-center peer-checked:border-2 {{ $faceColor[$v] }}">
                                    <span class="block text-2xl leading-none">{{ $faces[$v] }}</span>
                                    <span class="mt-1 block text-[10px] leading-tight text-gray-600">{{ __($label) }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    <p class="mt-2 text-xs text-gray-500">{{ __('About what (tap once good, twice bad)') }}</p>
                    <div class="mt-1 flex flex-wrap gap-1.5">
                        @foreach (CallListService::TOPICS as $t => $label)
                            <button type="button" @click="topics[@js($t)] = topics[@js($t)] === 'good' ? 'bad' : (topics[@js($t)] === 'bad' ? null : 'good')"
                                :class="topics[@js($t)] === 'good' ? 'border-green-600 bg-green-50 text-green-800' : (topics[@js($t)] === 'bad' ? 'border-red-600 bg-red-50 text-red-800' : 'border-gray-300 text-gray-700')"
                                class="rounded-full border px-3 py-1.5 text-sm">{{ __($label) }}</button>
                            <input type="hidden" name="topics[{{ $t }}]" :value="topics[@js($t)] || ''">
                        @endforeach
                    </div>
                </div>

                <div x-show="outcome" x-cloak @proof-changed="file = $event.detail.name">
                    <x-call-proof id="proof-list" kind="spoke() ? 'audio' : 'image'" :title="'spoke() ? '.\Illuminate\Support\Js::from(__('Call recording')).' : '.\Illuminate\Support\Js::from(__('Screenshot of the call log'))" />
                </div>

                <div x-show="outcome" x-cloak>
                    <p class="mb-2 text-sm font-semibold text-gray-900">{{ __('Note') }} <span class="text-xs font-normal text-gray-500">({{ __('optional') }})</span></p>
                    <textarea name="note" rows="2" maxlength="500" x-model="note" placeholder="{{ __('What did the customer say?') }}" class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-base focus:border-primary focus:outline-none"></textarea>
                </div>

                <button type="submit" :disabled="busy || ! ready()" class="w-full rounded-xl bg-primary px-4 py-3.5 text-base font-semibold text-white hover:bg-primary-dark disabled:opacity-50"
                    x-text="busy ? @js(__('Saving...')) : (ready() ? @js(__('Save and next')) : (! outcome ? @js(__('Pick what happened')) : (! file ? (spoke() ? @js(__('Add the recording')) : @js(__('Add the screenshot'))) : (spoke() && sat === null ? @js(__('Pick how satisfied')) : @js(__('Pick when to call again'))))))"></button>
            </form>

            <form method="POST" action="{{ route('calls.skip', [$list->id, $item->id]) }}" class="text-center">
                @csrf
                <button type="submit" class="text-sm text-gray-500 underline hover:text-gray-800">{{ __('Skip this number (it goes back to the list)') }}</button>
            </form>
        @endif
    </div>
</x-layouts.app>
