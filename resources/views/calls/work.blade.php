@php
    use App\Services\Calls\CallListService;
    use App\Support\Permissions\Mask;
    $faces = [0 => '😠', 25 => '🙁', 50 => '😐', 75 => '🙂', 100 => '😍'];
    $faceColor = [0 => 'peer-checked:border-red-500 peer-checked:bg-red-50', 25 => 'peer-checked:border-orange-500 peer-checked:bg-orange-50', 50 => 'peer-checked:border-yellow-500 peer-checked:bg-yellow-50',
        75 => 'peer-checked:border-lime-600 peer-checked:bg-lime-50', 100 => 'peer-checked:border-green-600 peer-checked:bg-green-50'];
    $w = $list->daily_target ? min(100, round($today / $list->daily_target * 100)) : 0;
    $notes = ['Will order later', 'Price is high', 'Delivery was late', 'Happy with the product'];
    $canOpenOrders = auth()->user()->can('orders.view');
@endphp

<x-layouts.app :heading="$list->name">
    <div class="mx-auto max-w-6xl space-y-3"
        @if ($item)
        x-data="{
            key: 'iqs-call-start-{{ $item->id }}', started: null, now: Date.now(), away: 0, leftAt: null, leaving: false, warn: false, qr: '',
            init() {
                try {
                    this.started = +localStorage.getItem(this.key) || null;
                    // A call of this page that is no longer here (saved, or went back to the list): its bar goes.
                    const live = JSON.parse(localStorage.getItem('iqs-call-live') || 'null');
                    if (live && live.url === location.pathname + location.search && live.item !== {{ $item->id }}) localStorage.removeItem('iqs-call-live');
                    if (this.started) this.live();
                } catch (e) {}
                setInterval(() => this.now = Date.now(), 1000);
                // Back from the phone's dialer: the time away is the talk.
                document.addEventListener('visibilitychange', () => {
                    if (! this.started) return;
                    if (document.hidden) this.leftAt = Date.now();
                    else if (this.leftAt) { this.away += Math.round((Date.now() - this.leftAt) / 1000); this.leftAt = null }
                });
                @if ($seePhone) if (window.matchMedia('(min-width: 768px)').matches) window.iqsQr(@js($item->phone)).then(s => this.qr = s); @endif
            },
            start() {
                if (this.started) return;
                this.started = Date.now();
                try { localStorage.setItem(this.key, this.started) } catch (e) {}
                this.live();
                fetch(@js(route('calls.touch', [$list->id, $item->id])), { method: 'POST', keepalive: true, headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' } }).catch(() => {});
            },
            // The call follows the caller to other pages until it is saved.
            live() { try { localStorage.setItem('iqs-call-live', JSON.stringify({ item: {{ $item->id }}, name: @js($item->name), url: location.pathname + location.search, started: this.started })) } catch (e) {} },
            done() { this.leaving = true; try { localStorage.removeItem(this.key); localStorage.removeItem('iqs-call-live') } catch (e) {} },
            mmss(s) { return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0') },
            elapsed() { return this.started ? Math.max(0, Math.round((this.now - this.started) / 1000)) : 0 },
            // How long they talked when the recording does not tell: the time away at the dialer, else since Call.
            talk() { return this.away || this.elapsed() },
            skip(e) { if (this.started) { e.preventDefault(); this.warn = true; setTimeout(() => this.warn = false, 3000) } else this.leaving = true },
        }"
        @endif
    >
        {{-- Today against the target, always in sight. --}}
        <div class="rounded-2xl bg-primary px-4 py-3 text-white">
            <div class="flex items-baseline justify-between text-sm">
                <span>{{ __('Today :n of :t', ['n' => $today, 't' => $list->daily_target]) }}</span>
                <a href="{{ route('calls.history') }}" class="text-xs text-white/80 underline">{{ __('My call history') }}</a>
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
            {{-- Until it is saved: the call stays in sight, with its clock. --}}
            <div x-show="started" x-cloak class="sticky top-[4.5rem] z-20 flex items-center justify-between gap-2 rounded-xl bg-amber-100 px-3 py-2.5 text-sm font-semibold text-amber-900 shadow-sm">
                <span x-show="! warn" class="tabular-nums">⏱ {{ __('Call in progress') }} <span x-text="mmss(elapsed())"></span> · {{ __('not saved') }}</span>
                <span x-show="warn" x-cloak>{{ __('Save this call first.') }}</span>
                <button type="button" @click="document.getElementById('call-form').scrollIntoView({ behavior: 'smooth', block: 'start' })" class="inline-flex min-h-8 shrink-0 items-center rounded-lg border border-amber-700 px-3 text-xs font-semibold">{{ __('Finish it') }}</button>
            </div>

            <div class="grid items-start gap-3 lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
                <div class="space-y-3">
                    {{-- The customer, and the one big Call button. --}}
                    <div class="rounded-2xl border border-gray-200 bg-white p-4">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="truncate text-lg font-semibold text-gray-900">{{ $item->name }}</p>
                                <p class="font-mono text-sm text-gray-500">{{ $seePhone ? $item->phone : Mask::value($item->phone, 'customer_contact') }}@if ($item->attempts) · {{ __('Try :n', ['n' => $item->attempts + 1]) }}@endif</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                @if ($item->customer_id)
                                    @can('customers.view')<a href="{{ route('customers.show', $item->customer_id) }}#journey" target="_blank" rel="noopener" class="text-xs text-primary hover:underline">{{ __('Customer') }}</a>@endcan
                                @endif
                                <form method="POST" action="{{ route('calls.skip', [$list->id, $item->id]) }}" @submit="skip($event)">
                                    @csrf
                                    <button type="submit" class="inline-flex min-h-8 items-center rounded-lg border border-gray-300 px-3 text-xs font-medium text-gray-700 hover:bg-gray-50" title="{{ __('Skip this number (it goes back to the list)') }}">{{ __('Skip') }}</button>
                                </form>
                            </div>
                        </div>

                        {{-- Phone: the dialer. PC: scan the QR with the phone, or copy the number. --}}
                        <a href="tel:{{ $item->phone }}" @click="start()" class="mt-3 flex items-center justify-center gap-2 rounded-xl bg-green-600 px-4 py-4 text-lg font-semibold text-white hover:bg-green-700 md:hidden">
                            <x-icon name="phone" class="h-5 w-5" /> {{ __('Call') }}
                        </a>
                        <div class="mt-3 hidden items-center gap-4 md:flex">
                            @if ($seePhone)
                                <div class="h-28 w-28 shrink-0 rounded-lg border border-gray-200 bg-white p-1" x-html="qr"></div>
                            @endif
                            <div class="min-w-0 space-y-2">
                                @if ($seePhone)
                                    <p class="font-mono text-2xl font-semibold tracking-wide text-gray-900">{{ $item->phone }}</p>
                                    <p class="text-xs text-gray-500">{{ __('Scan with your phone to call it.') }}</p>
                                @endif
                                <div class="flex flex-wrap gap-2">
                                    @if ($seePhone)
                                        <button type="button" x-data="{ copied: false }" @click="navigator.clipboard?.writeText(@js($item->phone)); copied = true; setTimeout(() => copied = false, 1500); start()"
                                            class="inline-flex min-h-8 items-center rounded-lg border border-gray-300 px-3 text-xs font-medium text-gray-700 hover:bg-gray-50" x-text="copied ? @js(__('Copied')) : @js(__('Copy number'))"></button>
                                    @endif
                                    <button type="button" @click="start()" :disabled="started" class="inline-flex min-h-8 items-center rounded-lg bg-green-600 px-3 text-xs font-semibold text-white hover:bg-green-700 disabled:opacity-50">{{ __('I am calling now') }}</button>
                                </div>
                            </div>
                        </div>

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
                                <p class="text-gray-700"><span class="text-gray-500">{{ $o->created_at->format('d M Y') }}</span> ·
                                    @if ($canOpenOrders)<a href="{{ route('orders.show', $o->id) }}" @click.prevent="window.dispatchEvent(new CustomEvent('order-new', { detail: { url: @js(route('orders.show', ['order' => $o->id, 'embed' => 1])), title: @js($o->order_no), page: @js(route('orders.show', $o->id)) } }))" class="font-mono font-medium text-primary hover:underline">{{ $o->order_no }}</a>@else<span class="font-mono">{{ $o->order_no }}</span>@endif
                                    · ৳{{ number_format((float) $o->grand_total) }} <x-order-status :order="$o" /><br>
                                    <span class="text-xs text-gray-500">{{ $o->items->map(fn ($i) => $i->name_snapshot)->join(', ') }}</span></p>
                            @endforeach
                            @foreach ($history['past'] as $p)
                                <p class="text-gray-700"><span class="text-gray-500">{{ \Illuminate\Support\Carbon::parse($p->at)->format('d M Y') }}</span> · ৳{{ number_format((float) $p->amount) }} · {{ __(\App\Services\Customers\HistoryImport::STATUSES[$p->status] ?? $p->status) }}
                                    @if ($p->items)<br><span class="text-xs text-gray-500">{{ $p->items }}</span>@endif</p>
                            @endforeach
                            @if ($history['orders']->isEmpty() && $history['past']->isEmpty())<p class="text-gray-500">{{ __('No orders found.') }}</p>@endif
                        </div>
                    </div>
                </div>

                {{-- After the call: always on the page (nothing to close by mistake); Save sends the next number. --}}
                <form method="POST" action="{{ route('calls.record', [$list->id, $item->id]) }}" enctype="multipart/form-data" id="call-form"
                    class="scroll-mt-32 space-y-4 rounded-2xl border-2 border-primary/30 bg-white p-4"
                    x-data="{
                        outcome: @js(old('outcome', '')), sat: @js(old('satisfaction') === null ? null : (int) old('satisfaction')), topics: {}, laterAt: @js(old('later_at', '')),
                        file: '', order: @js($item->order_id ? \Illuminate\Support\Facades\DB::table('orders')->where('id', $item->order_id)->value('order_no') : null), busy: false, note: '', noteKey: 'iqs-call-note-{{ $item->id }}',
                        spoke() { return @js(CallListService::SPOKE).includes(this.outcome) }, orderLinked: true,
                        init() {
                            try { this.note = @js(old('note', '')) || localStorage.getItem(this.noteKey) || '' } catch (e) {}
                            this.$watch('note', v => { try { localStorage.setItem(this.noteKey, v) } catch (e) {} });
                            this.$watch('outcome', v => { if (v) start() });
                            window.addEventListener('iqs-order-made', e => {
                                this.order = e.detail.no; this.orderLinked = e.detail.linked;
                                if (e.detail.linked) this.outcome = 'ordered';
                            });
                            @if (session('saved_item')) try { localStorage.removeItem('iqs-call-note-{{ (int) session('saved_item') }}') } catch (e) {} @endif
                        },
                        addNote(t) { this.note = (this.note.trim() ? this.note.trim().replace(/[.]$/, '') + '. ' : '') + t },
                        laterIn(h) { const d = new Date(Date.now() + h * 3600000); this.laterAt = this.local(d) },
                        tomorrow() { const d = new Date(); d.setDate(d.getDate() + 1); d.setHours(11, 0, 0, 0); this.laterAt = this.local(d) },
                        local(d) { const p = n => String(n).padStart(2, '0'); return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + 'T' + p(d.getHours()) + ':' + p(d.getMinutes()) },
                        ready() { return this.outcome && this.file && (! this.spoke() || this.sat !== null) && (this.outcome !== 'later' || this.laterAt) },
                        errors: {}, failed: '',
                        // PC: 1 to {{ count(CallListService::OUTCOMES) }} pick the result, Enter saves (not while typing).
                        keys(e) {
                            if (['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName) || e.ctrlKey || e.metaKey || e.altKey || document.querySelector('[role=dialog]:not([style*=none])')) return;
                            const radios = this.$el.querySelectorAll('input[name=outcome]');
                            const n = +e.key;
                            if (n >= 1 && n <= radios.length && ! radios[n - 1].disabled) { this.outcome = radios[n - 1].value; e.preventDefault() }
                            else if (e.key === 'Enter' && ! ['BUTTON', 'A'].includes(e.target.tagName)) { e.preventDefault(); this.submit(e) }
                        },
                        async submit(e) {
                            e.preventDefault();
                            if (this.busy) return;
                            if (! this.ready()) { this.hint(); return }
                            this.$refs.seconds.value = (+document.getElementById('proof-list')?.dataset.seconds || talk()) || '';
                            this.busy = true; this.errors = {}; this.failed = '';
                            try {
                                const r = await fetch(this.$el.action, { method: 'POST', headers: { Accept: 'application/json' }, body: new FormData(this.$el) });
                                if (r.ok) { const d = await r.json(); done(); window.location.href = d.next; return }
                                const d = await r.json().catch(() => ({}));
                                if (r.status === 422 && d.errors) { this.errors = d.errors; this.show() }
                                else { this.failed = d.message || @js(__('Could not save. Check the connection and press Save again.')) }
                            } catch (err) { this.failed = @js(__('Could not save. Check the connection and press Save again.')) }
                            this.busy = false;
                        },
                        // Save pressed too early: each missing thing is said under its own box.
                        hint() {
                            const e = {};
                            if (! this.outcome) e.outcome = [@js(__('Pick what happened.'))];
                            if (this.outcome && this.spoke() && this.sat === null) e.satisfaction = [@js(__('Pick how satisfied the customer is.'))];
                            if (this.outcome === 'later' && ! this.laterAt) e.later_at = [@js(__('Pick when to call again.'))];
                            if (! this.file) e.proof = [@js(__('Add the call recording or a screenshot of the call log.'))];
                            this.errors = e; this.show();
                        },
                        show() { this.$nextTick(() => this.$el.querySelector('[data-error]:not([style*=none])')?.scrollIntoView({ block: 'center', behavior: 'smooth' })) },
                        err(k) { return (this.errors[k] || [])[0] || '' },
                    }" @submit="submit($event)" @keydown.window="keys($event)">
                    @csrf
                    <input type="hidden" name="seconds" x-ref="seconds">
                    @if ($errors->any())<div class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ $errors->first() }}</div>@endif

                    <div>
                        <p class="mb-2 text-sm font-semibold text-gray-900">{{ __('What happened?') }}</p>
                        <div class="grid grid-cols-2 gap-2">
                            @foreach (CallListService::OUTCOMES as $key => $label)
                                <label class="cursor-pointer">
                                    {{-- An order taken on this call: the customer was reached, so "not reached" results are closed. --}}
                                    <input type="radio" name="outcome" value="{{ $key }}" x-model="outcome" @change="delete errors.outcome" class="peer sr-only"
                                        @if (in_array($key, CallListService::NOT_REACHED, true)) :disabled="order && orderLinked" @endif>
                                    <span class="block rounded-xl border border-gray-300 px-3 py-3 text-center text-sm font-medium text-gray-800 peer-checked:border-primary peer-checked:bg-primary peer-checked:text-white peer-disabled:cursor-not-allowed peer-disabled:opacity-40"><span class="mr-1 hidden text-xs opacity-60 lg:inline">{{ $loop->iteration }}</span>{{ __($label) }}</span>
                                </label>
                            @endforeach
                        </div>
                        <p data-error x-show="err('outcome')" x-cloak class="mt-2 text-sm font-medium text-red-600" x-text="err('outcome')"></p>
                        <p x-show="order && orderLinked" x-cloak class="mt-2 rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ __('Order made:') }} <span class="font-mono font-semibold" x-text="order"></span></p>
                        <p x-show="order && ! orderLinked" x-cloak class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">{{ __('The order was made, but this number waited too long and went back to the list, so the order is not linked to it. Press Next call.') }} <span class="font-mono font-semibold" x-text="order"></span></p>
                        @if ($canOrder)
                            {{-- No order when the customer was not reached. --}}
                            <button type="button" x-show="! order && ! @js(CallListService::NOT_REACHED).includes(outcome)" class="mt-2 w-full rounded-xl border border-primary px-3 py-2.5 text-sm font-semibold text-primary hover:bg-green-50"
                                @click="start(); window.dispatchEvent(new CustomEvent('order-new', { detail: { url: @js(route('orders.create', ['phone' => $item->phone, 'channel' => 'phone', 'embed' => 1, 'call_item' => $item->id])), title: @js(__('Order for :n', ['n' => $item->name])) } }))">+ {{ __('Take an order') }}</button>
                        @endif
                    </div>

                    <div x-show="outcome === 'later'" x-cloak>
                        <p class="mb-2 text-sm font-semibold text-gray-900">{{ __('Call again') }}</p>
                        <div class="flex flex-wrap gap-2 text-sm">
                            <button type="button" @click="laterIn(1)" class="rounded-full border border-gray-300 px-3 py-1.5">{{ __('In 1 hour') }}</button>
                            <button type="button" @click="laterIn(3)" class="rounded-full border border-gray-300 px-3 py-1.5">{{ __('In 3 hours') }}</button>
                            <button type="button" @click="tomorrow()" class="rounded-full border border-gray-300 px-3 py-1.5">{{ __('Tomorrow 11 AM') }}</button>
                        </div>
                        <input type="datetime-local" name="later_at" x-model="laterAt" class="mt-2 h-10 w-full rounded-lg border border-gray-300 px-3 text-base focus:border-primary focus:outline-none">
                        <p data-error x-show="err('later_at')" x-cloak class="mt-1 text-sm font-medium text-red-600" x-text="err('later_at')"></p>
                    </div>

                    <div x-show="spoke()" x-cloak>
                        <p class="mb-2 text-sm font-semibold text-gray-900">{{ __('How satisfied?') }} <span class="text-red-600">*</span></p>
                        <div class="grid grid-cols-5 gap-1.5">
                            @foreach (CallListService::SATISFACTION as $v => $label)
                                <label class="cursor-pointer">
                                    <input type="radio" name="satisfaction" value="{{ $v }}" x-model.number="sat" @change="delete errors.satisfaction" class="peer sr-only">
                                    <span class="block rounded-xl border border-gray-300 px-1 py-2 text-center peer-checked:border-2 {{ $faceColor[$v] }}">
                                        <span class="block text-2xl leading-none">{{ $faces[$v] }}</span>
                                        <span class="mt-1 block text-[10px] leading-tight text-gray-600">{{ __($label) }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        <p data-error x-show="err('satisfaction')" x-cloak class="mt-1 text-sm font-medium text-red-600" x-text="err('satisfaction')"></p>
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

                    {{-- Any file, any time: the recording, or a screenshot of the call log. --}}
                    <div @proof-changed="file = $event.detail.name; if (file) delete errors.proof">
                        <x-call-proof id="proof-list" :title="\Illuminate\Support\Js::from(__('Call recording or screenshot'))" />
                    </div>
                    <p data-error x-show="err('proof')" x-cloak class="-mt-2 text-sm font-medium text-red-600" x-text="err('proof')"></p>

                    <div x-show="outcome" x-cloak>
                        <p class="mb-2 text-sm font-semibold text-gray-900">{{ __('Note') }} <span class="text-xs font-normal text-gray-500">({{ __('optional') }})</span></p>
                        <div class="mb-2 flex flex-wrap gap-1.5">
                            @foreach ($notes as $n)
                                <button type="button" @click="addNote(@js(__($n)))" class="rounded-full border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:border-primary">{{ __($n) }}</button>
                            @endforeach
                        </div>
                        <textarea name="note" rows="2" maxlength="500" x-model="note" placeholder="{{ __('What did the customer say?') }}" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-base sm:text-sm focus:border-primary focus:outline-none"></textarea>
                    </div>

                    <p x-show="failed" x-cloak class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" x-text="failed"></p>
                    <div class="sticky bottom-2 z-10">
                        <button type="submit" :disabled="busy" class="w-full rounded-xl bg-primary px-4 py-3.5 text-base font-semibold text-white shadow-sm hover:bg-primary-dark disabled:opacity-50"
                            x-text="busy ? @js(__('Saving...')) : @js(__('Save and next number'))"></button>
                    </div>
                </form>
            </div>
        @endif
    </div>
</x-layouts.app>
