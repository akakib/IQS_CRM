{{-- Communication, beside Break: chats and rider calls in one window. Shown to people with a chat channel or the hotline.
     Pressing it turns communication mode on (the time counts as work).
     Chats: today's count per channel, + per answered message, − and No order with a reason, a shortcut to a new order.
     Rider calls: find the parcel (CN, order no or phone), what the rider said, checked with the customer, what was done.
     Closing keeps the mode on; Stop ends it. Other pages open it with window.dispatchEvent(new CustomEvent('chat-open')). --}}
@php
    $chatUser = auth()->user();
    // One query for someone with neither (most pages, most people): the types of their active channels. The open session only when it matters.
    $rider = \App\Models\ChatChannel::RIDER;
    $myTypes = $chatUser->current_break_id ? collect() : app(\App\Services\Work\ChatService::class)->allFor($chatUser)->pluck('type')->unique();
    $hasChannels = $myTypes->contains(fn ($t) => $t !== $rider);
    // Rider calls: the Rider line channel or the hotline permission. Owners watch, they do not take rider calls.
    $hotline = ! $chatUser->current_break_id && ! $chatUser->isOwner() && ($myTypes->contains($rider) || $chatUser->can('hotline.view'));
    // Ended here if nothing was done for 15 minutes (no cron needed).
    $chatSession = $hasChannels || $hotline ? app(\App\Services\Work\ChatService::class)->openSessionChecked($chatUser->id) : null;
@endphp

@if (($hasChannels || $hotline) && ! $chatUser->current_break_id)
<div x-data="{
        on: @js((bool) $chatSession), since: @js($chatSession ? \Illuminate\Support\Carbon::parse($chatSession->started_at)->format('g:i A') : null),
        open: false, data: null, ask: null, busy: false, error: null,
        // No chat tap, call or order action for 15 minutes: it stops (the server ends it at the last action + 5 minutes).
        lastAt: @js($chatSession ? $chatSession->last_action_at->getTimestampMs() : null), stopped: null,
        init() { setInterval(() => this.idle(), 30000) },
        idle() {
            if (! this.on || ! this.lastAt) return;
            try { if (localStorage.getItem('iqs-call-live')) { this.lastAt = Date.now(); return } } catch (e) {} // a call in progress is work
            if (Date.now() - this.lastAt < {{ \App\Services\Work\ChatService::IDLE_MINUTES }} * 60000) return;
            this.on = false;
            this.stopped = new Date(this.lastAt + {{ \App\Services\Work\ChatService::GRACE_MINUTES }} * 60000).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
        },
        acted() { this.lastAt = Date.now(); this.stopped = null },
        tab: @js($hasChannels ? 'chats' : 'calls'),
        q: '', found: null, finding: false, rider: '', riderPhone: '', claim: null, verdict: null, action: null, note: '', rstart: null, saved: null,
        headers() { return { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } },
        async call(url, body) {
            this.busy = true; this.error = null;
            try {
                const r = await fetch(url, { method: body ? 'POST' : 'GET', headers: this.headers(), body: body ? JSON.stringify(body) : undefined });
                const j = await r.json();
                if (!r.ok) { this.error = Object.values(j.errors || {})[0]?.[0] || @js(__('Something went wrong. Try again.')); return }
                this.data = j; this.on = j.on; this.since = j.since;
            } catch (e) { this.error = @js(__('No connection. Try again.')) } finally { this.busy = false }
        },
        async show() { this.open = true; this.ask = null; if (! this.on) this.acted(); await this.call(@js(route('chat.start')), {}) },
        // + counts at once however fast it is tapped: the number goes up now and each tap is sent on its own.
        async tap(c) {
            c.messages++; this.error = null; this.acted();
            if (!this.on) { this.on = true; this.since = new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) }
            try {
                const r = await fetch(@js(route('chat.record')), { method: 'POST', headers: this.headers(), body: JSON.stringify({ channel_id: c.id, kind: 'message' }) });
                if (!r.ok) { c.messages--; const j = await r.json().catch(() => ({})); this.error = Object.values(j.errors || {})[0]?.[0] || @js(__('Something went wrong. Try again.')) }
            } catch (e) { c.messages--; this.error = @js(__('No connection. Try again.')) }
        },
        count(channel, kind, reason = null) { this.ask = null; this.acted(); this.call(@js(route('chat.record')), { channel_id: channel, kind: kind, reason_id: reason }) },
        async stop() { await this.call(@js(route('chat.stop')), {}); this.open = false },
        async find() {
            if (!this.q.trim()) return;
            this.finding = true; this.found = null; this.error = null; this.saved = null;
            this.rstart = Date.now();
            try { this.found = await (await fetch(@js(route('rider-calls.find')) + '?q=' + encodeURIComponent(this.q.trim()), { headers: { Accept: 'application/json' } })).json() }
            catch (e) { this.error = @js(__('No connection. Try again.')) }
            this.finding = false;
        },
        async saveCall() {
            if (!this.found?.found) return;
            this.busy = true; this.error = null;
            try {
                const fd = window.iqsFormData({ order_id: this.found.id, rider_name: this.rider, rider_phone: this.riderPhone, claim: this.claim, verdict: this.verdict, action: this.action, note: this.note, ...window.iqsMinSec(window.iqsCallSeconds('proof-rider', this.rstart)) });
                window.iqsAddProof(fd, 'proof-rider');
                const r = await fetch(@js(route('rider-calls.store')), { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: fd });
                const j = await r.json();
                if (!r.ok) { this.error = Object.values(j.errors || {})[0]?.[0] || @js(__('Something went wrong. Try again.')); return }
                this.saved = j.message + ' ' + @js(__('Today:')) + ' ' + j.today; this.on = true; this.acted();
                this.q = ''; this.found = null; this.claim = null; this.verdict = null; this.action = null; this.note = ''; this.rstart = null; window.dispatchEvent(new CustomEvent('proof-clear', { detail: 'proof-rider' }));
            } catch (e) { this.error = @js(__('No connection. Try again.')) } finally { this.busy = false }
        },
    }" @chat-open.window="show()">
    <button type="button" @click="show()" class="flex h-9 items-center gap-1.5 rounded-lg border px-3 text-sm font-medium"
        :class="on ? 'border-green-600 bg-green-600 text-white hover:bg-green-700' : 'border-gray-200 text-gray-600 hover:bg-gray-100'">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
        <span class="hidden sm:inline">{{ __('Communication') }}</span>
    </button>

    <template x-teleport="body">
        <div x-show="stopped" x-cloak role="status" class="fixed inset-x-3 bottom-3 z-[70] mx-auto flex max-w-md items-start gap-3 rounded-xl bg-amber-100 px-4 py-3 text-sm text-amber-900 shadow-lg">
            <span class="min-w-0 flex-1"><span class="block font-semibold" x-text="@js(__('Communication stopped at')) + ' ' + stopped"></span>
                {{ __('No chat and no call for :n minutes, so your work clock stopped.', ['n' => \App\Services\Work\ChatService::IDLE_MINUTES]) }}</span>
            <button type="button" @click="stopped = null; show()" class="inline-flex min-h-8 shrink-0 items-center rounded-lg bg-primary px-3 text-xs font-semibold text-white hover:bg-primary-dark">{{ __('Start again') }}</button>
        </div>
    </template>

    <template x-teleport="body">
        <div x-show="open" x-cloak class="fixed inset-0 z-[80] flex items-end justify-center sm:items-center sm:px-4">
            <div class="absolute inset-0 bg-black/40"></div>
            <div class="relative flex max-h-[90vh] w-full max-w-2xl flex-col rounded-t-2xl bg-white shadow-xl sm:rounded-xl">
                <div class="flex items-start justify-between gap-3 border-b border-gray-100 px-5 py-3">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-800">{{ __('Communication') }} <span class="font-normal text-gray-500" x-text="data ? '· ' + data.date : ''"></span></h3>
                        <p class="text-xs" :class="on ? 'text-green-700' : 'text-gray-500'" x-text="on ? @js(__('Communication on since')) + ' ' + since : @js(__('Communication off'))"></p>
                    </div>
                    <button type="button" @click="open = false" class="text-gray-400 hover:text-gray-600" aria-label="{{ __('Close') }}">&times;</button>
                </div>
                {{-- Chats (their channels), Customer calls (a customer phoned in: everyone here), Rider calls (rider line or hotline). --}}
                <div class="flex gap-5 overflow-x-auto border-b border-gray-200 px-5">
                    @if ($hasChannels)
                        <button type="button" @click="tab = 'chats'" class="-mb-px shrink-0 border-b-2 pb-2 pt-2 text-sm" :class="tab === 'chats' ? 'border-primary font-medium text-primary' : 'border-transparent text-gray-500'">{{ __('Chats') }}</button>
                    @endif
                    <button type="button" @click="tab = 'calls'" class="-mb-px shrink-0 border-b-2 pb-2 pt-2 text-sm" :class="tab === 'calls' ? 'border-primary font-medium text-primary' : 'border-transparent text-gray-500'">{{ __('Customer calls') }}</button>
                    @if ($hotline)
                        <button type="button" @click="tab = 'riders'" class="-mb-px shrink-0 border-b-2 pb-2 pt-2 text-sm" :class="tab === 'riders' ? 'border-primary font-medium text-primary' : 'border-transparent text-gray-500'">{{ __('Rider calls') }}</button>
                    @endif
                </div>

                <div class="overflow-y-auto px-5 py-3">
                    <p x-show="error" x-cloak class="mb-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" x-text="error"></p>

                    @if ($hasChannels)
                    <div x-show="tab === 'chats'">
                        <template x-if="!data"><p class="py-6 text-center text-sm text-gray-400">{{ __('Loading…') }}</p></template>
                        <template x-for="c in data?.channels ?? []" :key="c.id">
                            <div class="border-b border-gray-100 py-3 last:border-0">
                                <div class="flex items-center gap-3">
                                    <x-channel-icon expr="c.kind" />
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-medium text-gray-800" x-text="c.name"></p>
                                        <p class="truncate text-xs text-gray-500" x-text="c.type"></p>
                                    </div>
                                    <button type="button" @click="ask = { id: c.id, kind: 'undo' }" :disabled="busy || c.messages < 1" class="h-9 w-9 rounded-lg border border-gray-300 text-lg text-gray-600 hover:bg-gray-50 disabled:opacity-40" aria-label="{{ __('Take one back') }}">−</button>
                                    <span class="w-8 text-center text-lg font-semibold tabular-nums text-gray-900" x-text="c.messages"></span>
                                    <button type="button" @click="tap(c)" class="h-9 w-9 rounded-lg bg-primary active:scale-95 text-lg font-semibold text-white hover:bg-primary-dark disabled:opacity-60" aria-label="{{ __('One more message answered') }}">+</button>
                                    <a :href="c.order_url" @click.prevent="open = false; window.dispatchEvent(new CustomEvent('order-new', { detail: c.order_url + '&embed=1' }))" class="flex h-9 w-9 items-center justify-center rounded-lg border border-gray-300 text-gray-600 hover:border-primary hover:text-primary" title="{{ __('New order from this chat') }}" aria-label="{{ __('New order from this chat') }}">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                                    </a>
                                </div>
                                {{-- Today's result on its own line, full width, so it never gets cut on a phone. --}}
                                <div class="mt-2 ml-12 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                                    <span class="text-gray-500"><span class="font-medium text-gray-700" x-text="c.orders"></span> {{ __('orders') }} · <span class="font-medium text-gray-700" x-text="c.no_order"></span> {{ __('no order') }}</span>
                                    <button type="button" @click="ask = { id: c.id, kind: 'no_order' }" class="font-medium text-gray-500 hover:text-red-600 hover:underline">{{ __('Chat ended with no order') }}</button>
                                </div>
                                <div x-show="ask && ask.id === c.id" x-cloak class="mt-2 rounded-lg bg-gray-50 p-2">
                                    <p class="mb-2 text-xs text-gray-600" x-text="ask?.kind === 'undo' ? @js(__('Why take one back?')) : @js(__('Why no order?'))"></p>
                                    <div class="flex flex-wrap gap-1.5">
                                        <template x-for="r in (ask?.kind === 'undo' ? data.reasons.undo : data.reasons.lost)" :key="r.id">
                                            <button type="button" @click="count(c.id, ask.kind, r.id)" class="rounded-lg border border-gray-300 bg-white px-2.5 py-1 text-xs text-gray-700 hover:border-primary hover:text-primary" x-text="r.label"></button>
                                        </template>
                                        <button type="button" @click="ask = null" class="px-2 py-1 text-xs text-gray-500">{{ __('Cancel') }}</button>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                    @endif

                    {{-- A customer phoned in: tap them in the list (or paste the number), why they called, the recording, Save.
                         How long it took comes from the recording, else from the clock since they were opened. --}}
                    <div x-show="tab === 'calls'" x-cloak class="space-y-3" x-data="{
                            cq: '', cfound: null, cfinding: false, corder: null, creason: null, cnote: '', csaved: null, cnew: null, cerror: null, csaving: false, cstart: null,
                            recent: null, loadedAt: 0, pasteHint: false,
                            init() {
                                this.$watch('tab', v => { if (v === 'calls') this.load() });
                                this.$watch('open', v => { if (v && tab === 'calls') this.load() });
                            },
                            async load() {
                                if (Date.now() - this.loadedAt < 60000) return;
                                this.loadedAt = Date.now();
                                try { this.recent = (await (await fetch(@js(route('customer-calls.recent')), { headers: { Accept: 'application/json' } })).json()).people } catch (e) {}
                            },
                            async paste() {
                                this.pasteHint = false;
                                try { const t = await navigator.clipboard.readText(); if (t.trim()) { this.cq = t.trim(); return this.cfind() } } catch (e) {}
                                this.pasteHint = true; this.$refs.cq.focus();
                            },
                            async cfind() {
                                if (!this.cq.trim()) return;
                                this.cfinding = true; this.cfound = null; this.corder = null; this.csaved = null; this.cnew = null; this.cerror = null;
                                try {
                                    const r = await fetch(@js(route('customer-calls.find')) + '?q=' + encodeURIComponent(this.cq.trim()), { headers: { Accept: 'application/json' } });
                                    if (!r.ok) throw new Error();
                                    this.cfound = await r.json(); this.cstart = Date.now();
                                    if (this.cfound.orders.length === 1) this.corder = this.cfound.orders[0].id;
                                } catch (e) { this.cerror = @js(__('No connection. Try again.')) }
                                this.cfinding = false;
                            },
                            async csave() {
                                this.cerror = null;
                                if (!this.creason) { this.cerror = @js(__('Pick why they called.')); return }
                                this.csaving = true;
                                try {
                                    const fd = window.iqsFormData({ phone: this.cfound?.phone || this.cq, order_id: this.corder, reason: this.creason, note: this.cnote, ...window.iqsMinSec(window.iqsCallSeconds('proof-in', this.cstart)) });
                                    window.iqsAddProof(fd, 'proof-in');
                                    const r = await fetch(@js(route('customer-calls.incoming')), { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: fd });
                                    const j = await r.json();
                                    if (!r.ok) { this.cerror = Object.values(j.errors || {})[0]?.[0] || @js(__('Something went wrong. Try again.')); return }
                                    this.csaved = j.message + ' ' + @js(__('Today:')) + ' ' + j.today; this.cnew = j.new_order_url; on = true; acted();
                                    this.cq = ''; this.cfound = null; this.corder = null; this.creason = null; this.cnote = ''; this.cstart = null;
                                } catch (e) { this.cerror = @js(__('No connection. Try again.')) } finally { this.csaving = false }
                            },
                        }">
                        <div x-show="csaved" x-cloak class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">
                            <span x-text="csaved"></span>
                            <button type="button" x-show="cnew" @click="open = false; window.dispatchEvent(new CustomEvent('order-new', { detail: cnew }))" class="inline-flex min-h-8 items-center rounded-lg bg-primary px-3 text-xs font-semibold text-white hover:bg-primary-dark">{{ __('Make the order') }}</button>
                        </div>
                        <p x-show="cerror" x-cloak class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" x-text="cerror"></p>

                        {{-- Who called. --}}
                        <div x-show="! cfound" class="space-y-3">
                            <div x-show="recent && recent.length" x-cloak>
                                <p class="mb-1.5 text-sm font-semibold text-gray-900">{{ __('Who called? Tap one') }}</p>
                                <div class="divide-y divide-gray-100 rounded-xl border border-gray-200">
                                    <template x-for="p in recent || []" :key="p.phone">
                                        <button type="button" @click="cq = p.phone; cfind()" class="flex w-full items-center justify-between gap-2 px-3 py-2.5 text-left hover:bg-gray-50">
                                            <span class="min-w-0"><span class="block truncate text-sm font-medium text-gray-900" x-text="p.name || p.phone"></span><span class="block truncate text-xs text-gray-500" x-text="p.phone + ' · ' + p.from"></span></span>
                                            <span class="text-lg text-gray-400" aria-hidden="true">&rsaquo;</span>
                                        </button>
                                    </template>
                                </div>
                            </div>
                            <form @submit.prevent="cfind()">
                                <p class="mb-1.5 text-sm font-semibold text-gray-900" x-text="recent && recent.length ? @js(__('Not in the list? Paste or type the number')) : @js(__('Who called? Paste or type the number'))"></p>
                                <div class="flex gap-2">
                                    <input x-model="cq" x-ref="cq" inputmode="tel" autocomplete="off" placeholder="{{ __('Number or order no') }}" class="h-10 min-w-0 flex-1 rounded-lg border border-gray-300 px-3 text-base sm:text-sm focus:border-primary focus:outline-none">
                                    <button type="button" @click="paste()" class="inline-flex h-10 shrink-0 items-center rounded-lg border border-gray-300 px-3 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Paste') }}</button>
                                    <button class="inline-flex h-10 shrink-0 items-center rounded-lg bg-primary px-4 text-sm font-semibold text-white hover:bg-primary-dark" x-text="cfinding ? @js(__('Finding…')) : @js(__('Open'))"></button>
                                </div>
                                <p x-show="pasteHint" x-cloak class="mt-1.5 text-xs text-gray-500">{{ __('Long-press the box and choose Paste.') }}</p>
                            </form>
                        </div>

                        {{-- The caller, and the call. --}}
                        <template x-if="cfound">
                            <div class="space-y-4">
                                <div class="flex items-start justify-between gap-2 rounded-xl bg-gray-50 px-3 py-2.5">
                                    <span class="min-w-0"><span class="block truncate text-base font-semibold text-gray-900" x-text="cfound.name || @js(__('New customer'))"></span><span class="block font-mono text-sm text-gray-500" x-text="cfound.phone"></span></span>
                                    <button type="button" @click="cfound = null; cq = ''; cstart = null" class="inline-flex min-h-8 shrink-0 items-center rounded-lg border border-gray-300 bg-white px-3 text-xs font-medium text-gray-700 hover:bg-gray-50">{{ __('Change') }}</button>
                                </div>
                                <div x-show="cfound.orders.length">
                                    <p class="mb-1.5 text-sm font-semibold text-gray-900">{{ __('About which order?') }} <span class="text-xs font-normal text-gray-500">({{ __('optional') }})</span></p>
                                    <div class="space-y-1.5">
                                        <template x-for="o in cfound.orders" :key="o.id">
                                            <button type="button" @click="corder = corder === o.id ? null : o.id" class="flex w-full items-center justify-between gap-2 rounded-lg border px-3 py-2 text-left text-sm"
                                                :class="corder === o.id ? 'border-primary bg-primary-soft' : 'border-gray-200 hover:border-gray-300'">
                                                <span><span class="font-mono font-medium text-gray-900" x-text="o.order_no"></span> <span class="text-xs text-gray-500" x-text="'· ' + o.when + ' · ' + o.status"></span></span>
                                                <span class="tabular-nums text-gray-700" x-text="'৳' + Math.round(o.total).toLocaleString('en-IN')"></span>
                                            </button>
                                        </template>
                                    </div>
                                </div>
                                <div>
                                    <p class="mb-1.5 text-sm font-semibold text-gray-900">{{ __('Why did they call?') }}</p>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach (\App\Http\Controllers\CustomerCallController::REASONS_IN as $k => $label)
                                            <button type="button" @click="creason = @js($k); cerror = null" class="min-h-10 rounded-full border px-4 text-sm" :class="creason === @js($k) ? 'border-primary bg-primary font-medium text-white' : 'border-gray-300 text-gray-800 hover:border-primary'">{{ __($label) }}</button>
                                        @endforeach
                                    </div>
                                </div>
                                <x-call-proof id="proof-in" :title="\Illuminate\Support\Js::from(__('Call recording or screenshot (optional)'))" />
                                <textarea x-model="cnote" rows="2" maxlength="500" placeholder="{{ __('Note (optional)') }}" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-base sm:text-sm focus:border-primary focus:outline-none"></textarea>
                                <button type="button" @click="csave()" :disabled="csaving" class="w-full rounded-xl bg-primary px-4 py-3 text-base font-semibold text-white hover:bg-primary-dark disabled:opacity-60" x-text="csaving ? @js(__('Saving...')) : @js(__('Save'))"></button>
                            </div>
                        </template>
                    </div>

                    @if ($hotline)
                    {{-- A rider is on the phone about a parcel. --}}
                    <div x-show="tab === 'riders'" x-cloak class="space-y-3">
                        <p x-show="saved" x-cloak class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800" x-text="saved"></p>
                        <form @submit.prevent="find()" class="flex gap-2">
                            <input x-model="q" placeholder="{{ __('CN, order no or customer phone') }}" class="min-w-0 flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:outline-none">
                            <button class="rounded-lg bg-primary px-3 py-2 text-sm font-medium text-white hover:bg-primary-dark" x-text="finding ? @js(__('Finding…')) : @js(__('Find'))"></button>
                        </form>
                        <p x-show="found && !found.found" x-cloak class="text-sm text-red-600">{{ __('No parcel with that CN, order number or phone.') }}</p>
                        <template x-if="found && found.found">
                            <div class="space-y-3">
                                <div class="rounded-lg bg-gray-50 p-3 text-sm">
                                    <p class="font-medium text-gray-900"><a :href="@js(url('/orders')) + '/' + found.id" target="_blank" class="hover:text-primary hover:underline" x-text="found.order_no"></a> · <span x-text="found.customer"></span></p>
                                    <p class="text-xs text-gray-600"><span x-text="found.phone"></span> · <span x-text="found.address"></span></p>
                                    <p class="mt-1 text-xs text-gray-700">COD <b x-text="'৳' + Math.round(found.cod).toLocaleString('en-IN')"></b> · <span x-text="found.status"></span><span x-show="found.cn"> · CN <span x-text="found.cn"></span></span><span x-show="found.moderator"> · <span x-text="found.moderator"></span></span></p>
                                    <p x-show="found.courier_note" class="mt-1 text-xs text-purple-800"><b>Steadfast:</b> <span x-text="found.courier_note"></span></p>
                                    <p x-show="found.earlier_calls" class="mt-1 text-xs text-amber-700" x-text="found.earlier_calls + ' ' + @js(__('earlier rider calls on this parcel'))"></p>
                                </div>
                                <div class="grid grid-cols-2 gap-2">
                                    <input x-model="rider" list="iqs-riders" placeholder="{{ __('Rider name') }}" class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:outline-none">
                                    <input x-model="riderPhone" inputmode="tel" placeholder="{{ __('Rider phone') }}" class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:outline-none">
                                </div>
                                <datalist id="iqs-riders"><template x-for="r in data?.riders ?? []" :key="r"><option :value="r"></option></template></datalist>
                                <div>
                                    <p class="mb-1 text-xs font-medium text-gray-500">{{ __('The rider says') }}</p>
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach (\App\Services\Work\RiderCallService::CLAIMS as $k => $label)
                                            <button type="button" @click="claim = @js($k)" class="rounded-lg border px-2.5 py-1 text-xs" :class="claim === @js($k) ? 'border-primary bg-primary-soft font-medium text-primary' : 'border-gray-300 text-gray-700'">{{ __($label) }}</button>
                                        @endforeach
                                    </div>
                                </div>
                                <div>
                                    <p class="mb-1 text-xs font-medium text-gray-500">{{ __('Checked with the customer') }}</p>
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach (\App\Services\Work\RiderCallService::VERDICTS as $k => $label)
                                            <button type="button" @click="verdict = @js($k)" class="rounded-lg border px-2.5 py-1 text-xs" :class="verdict === @js($k) ? (@js($k) === 'false' ? 'border-red-500 bg-red-50 font-medium text-red-700' : 'border-primary bg-primary-soft font-medium text-primary') : 'border-gray-300 text-gray-700'">{{ __($label) }}</button>
                                        @endforeach
                                    </div>
                                </div>
                                <div>
                                    <p class="mb-1 text-xs font-medium text-gray-500">{{ __('What was done') }}</p>
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach (\App\Services\Work\RiderCallService::ACTIONS as $k => $label)
                                            <button type="button" @click="action = @js($k)" class="rounded-lg border px-2.5 py-1 text-xs" :class="action === @js($k) ? 'border-primary bg-primary-soft font-medium text-primary' : 'border-gray-300 text-gray-700'">{{ __($label) }}</button>
                                        @endforeach
                                    </div>
                                </div>
                                <x-call-proof id="proof-rider" size="sm" />
                                <input x-model="note" maxlength="500" placeholder="{{ __('Note (optional)') }}" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:outline-none">
                                <button type="button" @click="saveCall()" :disabled="busy || !claim || !verdict || !action" class="w-full rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white hover:bg-primary-dark disabled:opacity-50">{{ __('Save rider call') }}</button>
                            </div>
                        </template>
                    </div>
                    @endif
                </div>

                <div class="flex items-center justify-between gap-2 border-t border-gray-100 px-5 py-3">
                    <p class="text-xs text-gray-500">{{ __('Closing keeps it on. Website orders still come first.') }}</p>
                    <button type="button" x-show="on" @click="stop()" :disabled="busy" class="shrink-0 rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Stop') }}</button>
                </div>
            </div>
        </div>
    </template>
</div>
@endif
