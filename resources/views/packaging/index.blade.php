@php
    use Illuminate\Support\Carbon;
    $filters = ['all' => __('All'), 'red' => __('Repack'), 'waiting' => __('Waiting'), 'mine' => __('Mine'), 'hold' => __('On hold')];
    $wait = fn ($t) => $t ? Carbon::parse($t)->diffForHumans(now(), ['short' => true, 'syntax' => Carbon::DIFF_ABSOLUTE, 'parts' => 1]) : '-';
    $canPack = auth()->user()->can('packaging.create') && $working;
@endphp

<x-layouts.app :heading="__('Packaging')">
<div class="mx-auto max-w-3xl" x-init="follow(); setInterval(() => follow(), 30000)"
    x-data="{
        last: null, list: null, ticked: [], busy: false, holding: false, holdReason: null,
        open: null, previews: {}, picked: [], tools: false,
        // Live list: every 30 seconds the page asks what the list holds; when it changed, it reloads,
        // but never while a packer is busy (a checklist or a card open, a row ticked, typing).
        sig: null,
        async follow() {
            if (document.hidden) return;
            let d;
            try { d = await (await fetch(@js(route('packaging.pulse')), { headers: { Accept: 'application/json' } })).json() } catch (e) { return }
            if (this.sig === null) { this.sig = d.sig; return }
            const typing = ['INPUT', 'TEXTAREA'].includes(document.activeElement?.tagName) && document.activeElement.value;
            if (d.sig !== this.sig && !this.list && !this.open && !this.tools && !this.picked.length && !this.busy && !this.holding && !typing) location.reload();
        },
        printPicked(ids) { window.open(@js(route('packaging.labels')) + '?orders=' + ids.join(','), '_blank'); setTimeout(() => location.reload(), 1500) },
        async peek(id) {
            if (this.open === id) { this.open = null; return }
            this.open = id;
            if (!this.previews[id]) {
                try { this.previews[id] = await (await fetch(@js(url('/packaging/orders')) + '/' + id, { headers: { Accept: 'application/json' } })).json() }
                catch (e) { this.previews[id] = { error: true } }
            }
        },
        csrf: document.querySelector('meta[name=csrf-token]').content,
        async post(url, body) {
            const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf }, body: JSON.stringify(body) });
            const json = await r.json().catch(() => null);
            if (!r.ok || json === null) throw new Error(json?.message || @js(__('Something went wrong. Try again.')));
            return json;
        },
        async scan(code) {
            try { this.last = await this.post(@js(route('packaging.scan.post')), { code, via: window.iqsScanVia || null }) }
            catch (e) { this.last = { ok: false, level: 'red', message: e.message } }
            this.list = this.last.checklist ?? null; this.ticked = []; this.holding = false;
            // keep: no checklist to show (refused, already packed), so the camera stays on for the next label.
            $dispatch('scan-result', { ok: this.last.ok, message: this.last.message, level: this.last.level, keep: !this.list });
        },
        tick(id) { this.ticked = this.ticked.includes(id) ? this.ticked.filter(i => i !== id) : [...this.ticked, id] },
        get allTicked() { return this.list && this.ticked.length === this.list.items.length },
        async packed() {
            if (!this.allTicked || this.busy) return;
            this.busy = true;
            try {
                const r = await this.post(@js(url('/packaging/orders')) + '/' + this.list.id + '/pack', { items: this.ticked });
                window.toast(r.message); this.list = null; this.last = null;
                setTimeout(() => window.location.reload(), 700);
            } catch (e) { window.toast(e.message, 'error') }
            this.busy = false;
        },
    }" @scan="scan($event.detail)">

    {{-- Scan first: it is the packer's job, so it stays at the top while the list scrolls. --}}
    <div class="sticky top-[61px] z-20 -mx-4 bg-gray-50 px-4 pb-2 pt-1 md:-mx-6 md:px-6 lg:top-[63px]">
        @if ($canPack)
            {{-- Camera mode: after Packed the camera comes back by itself for the next label; it ends when nothing is left to pack. --}}
            <x-scan-input once quiet remember="packaging" :left="$counts['waiting'] + $counts['red'] + $counts['mine']"
                :done-message="__('All parcels packed. Camera off.')" :placeholder="__('Scan a label')" />
        @endif
        <div class="mt-2 grid grid-cols-4 gap-2">
            @foreach ([[__('Packed'), $myDay['packed'], false], [__('Average'), $myDay['avg'] === null ? '-' : $myDay['avg'].' min', false], [__('Scan errors'), $myDay['errors'], $myDay['errors'] > 0], [__('Waiting'), $counts['waiting'], false]] as [$label, $value, $bad])
                <div class="rounded-lg border border-gray-200 bg-white px-2.5 py-1.5">
                    <p class="truncate text-[11px] text-gray-500">{{ $label }}</p>
                    <p @class(['text-base font-semibold tabular-nums', 'text-red-600' => $bad, 'text-gray-900' => ! $bad])>{{ $value }}</p>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Who packs today, and everything that is not packing itself behind one button. --}}
    <div class="mt-2 flex items-center justify-between gap-3 text-sm">
        <span class="min-w-0 truncate text-gray-600">{{ __('On duty') }}: <b class="text-gray-800">{{ $onDuty->isEmpty() ? __('not set') : $onDuty->map(fn ($name, $id) => $name.(($helpersToday[$id] ?? null) ? ' + '.$helpersToday[$id] : ''))->join(', ') }}</b></span>
        <button type="button" @click="tools = true" class="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:bg-gray-50">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h10"/></svg>
            {{ $canManage ? __('Manage') : __('Tools') }}
        </button>
    </div>

    {{-- Alerts: one short line each, the action on the right. --}}
    <div class="mt-2 space-y-2">
        @if ($pause && $controlsPause)
            <form method="POST" action="{{ route('packaging.pause') }}" class="flex items-center justify-between gap-3 rounded-xl border border-amber-300 bg-amber-50 px-3 py-2.5 text-sm text-amber-900">
                @csrf
                <span><b>{{ __('Packaging paused') }}</b> · {{ __('since :t by :n', ['t' => Carbon::parse($pause->paused_at)->format('g:i A'), 'n' => $pause->by ?? '-']) }} · {{ trans_choice(':n order waiting|:n orders waiting', $held, ['n' => $held]) }}</span>
                <x-button size="sm" class="shrink-0">{{ __('Resume') }}</x-button>
            </form>
        @endif
        @if ($newLabels)
            <a href="{{ route('packaging.labels') }}" target="_blank" class="flex items-center justify-between gap-3 rounded-xl border border-orange-200 bg-orange-50 px-3 py-2.5 text-sm font-medium text-orange-900">
                <span>{{ trans_choice(':n new label to print|:n new labels to print', $newLabels, ['n' => $newLabels]) }}</span>
                <span class="shrink-0 rounded-lg border border-orange-300 bg-white px-3 py-1 text-xs font-semibold">{{ __('Print') }}</span>
            </a>
        @endif
        @if ($openIssues)
            <a href="{{ route('packaging.issues') }}" class="flex items-center justify-between gap-3 rounded-xl border border-red-200 bg-red-50 px-3 py-2.5 text-sm font-medium text-red-800">
                <span>{{ __('Missing items (:n)', ['n' => $openIssues]) }}</span>
                <span class="shrink-0 rounded-lg border border-red-300 bg-white px-3 py-1 text-xs font-semibold">{{ __('Show') }}</span>
            </a>
        @endif
    </div>

    {{-- Tools: sheets and handover for everyone; the pause and today's packers for managers. --}}
    <div x-show="tools" x-cloak class="fixed inset-0 z-[60] flex items-end justify-center bg-black/40 sm:items-center sm:p-4" @keydown.escape.window="tools = false">
        <div class="absolute inset-0" @click="tools = false"></div>
        <div class="relative max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-t-2xl bg-white p-4 shadow-xl sm:rounded-2xl">
            <div class="mb-3 flex items-center justify-between">
                <p class="text-base font-semibold text-gray-900">{{ $canManage ? __('Manage packaging') : __('Tools') }}</p>
                <button type="button" @click="tools = false" class="h-8 rounded-lg border border-gray-300 px-3 text-sm text-gray-600">{{ __('Close') }}</button>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <a href="{{ route('packaging.sheet', ['today' => 1]) }}" target="_blank" class="flex h-10 items-center justify-center rounded-lg border border-gray-300 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Today\'s sheet') }}</a>
                <a href="{{ route('handover.index') }}" class="flex h-10 items-center justify-center rounded-lg border border-gray-300 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Handover') }}</a>
                @if ($newLabels)<a href="{{ route('packaging.labels') }}" target="_blank" class="flex h-10 items-center justify-center rounded-lg border border-gray-300 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Print new labels (:n)', ['n' => $newLabels]) }}</a>@endif
                @if ($openIssues)<a href="{{ route('packaging.issues') }}" class="flex h-10 items-center justify-center rounded-lg border border-red-200 text-sm font-medium text-red-700 hover:bg-red-50">{{ __('Missing items (:n)', ['n' => $openIssues]) }}</a>@endif
            </div>
            @if ($controlsPause && ! $pause)
                {{-- Managers only: stop new orders reaching the packers' list (booking goes on). The packers are not told. --}}
                <form method="POST" action="{{ route('packaging.pause') }}" class="mt-3 flex items-center justify-between gap-3 rounded-lg border border-gray-200 px-3 py-2.5 text-sm text-gray-700">
                    @csrf
                    <span>{{ __('New booked orders go to the packers now.') }}</span>
                    <x-button size="sm" variant="secondary" class="shrink-0">{{ __('Pause packaging') }}</x-button>
                </form>
            @endif
            @if ($canManage)
                <form method="POST" action="{{ route('packaging.shift') }}" class="mt-3 border-t border-gray-100 pt-3">
                    @csrf
                    <p class="text-sm font-semibold text-gray-800">{{ __('Today\'s packers') }}</p>
                    {{-- Helpers: names only (no login), comma separated, so the Packers report can count packs per head. --}}
                    <p class="mb-2 text-xs text-gray-500">{{ __('Tick the packers on duty today. Helpers working with a packer: their names, separated by commas (they need no login).') }}</p>
                    <div class="space-y-2">
                        @foreach ($staff as $id => $name)
                            <div class="flex flex-wrap items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm" x-data="{ on: @js($onDuty->has($id)) }">
                                <label class="flex items-center gap-2"><input type="checkbox" name="user_ids[]" value="{{ $id }}" x-model="on" class="h-5 w-5 rounded border-gray-300 text-primary"> {{ $name }}</label>
                                <input x-show="on" x-cloak name="helpers[{{ $id }}]" value="{{ $helpersToday[$id] ?? '' }}" maxlength="255" placeholder="{{ __('Helpers, e.g. Karim, Sohel') }}"
                                    class="min-w-0 flex-1 rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm focus:border-primary focus:outline-none">
                            </div>
                        @endforeach
                    </div>
                    <x-button class="mt-3 w-full">{{ __('Save for today') }}</x-button>
                </form>
            @endif
        </div>
    </div>

    @if ($counts['red'] > 0)
        <a href="{{ route('packaging.index', ['show' => 'red']) }}" class="mt-2 flex items-center justify-between gap-3 rounded-xl border-2 border-red-600 bg-red-50 px-3 py-2.5 text-sm font-semibold text-red-800">
            <span>{{ trans_choice(':n order was edited after packing. Repack it before it goes.|:n orders were edited after packing. Repack them before they go.', $counts['red'], ['n' => $counts['red']]) }}</span>
            <span class="shrink-0 rounded-lg border border-red-300 bg-white px-3 py-1 text-xs font-semibold">{{ __('Show') }}</span>
        </a>
    @endif

    @unless ($working)
        <div class="mt-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-800">{{ __('You are not on duty for packaging today. Ask the admin to add you.') }}</div>
    @endunless

    @if ($canPack)
        {{-- Scan result that is not a checklist (blocked, already packed…) --}}
        <div x-show="last && !list" x-cloak class="mt-3 rounded-xl border-2 p-4"
            :class="{ 'border-orange-500 bg-orange-50': ['orange', 'warn'].includes(last?.level), 'border-red-600 bg-red-50': last?.level === 'red' }">
            <p class="text-lg font-bold" x-text="last?.message"></p>
            <p class="font-mono text-sm" x-text="last?.order?.order_no"></p>
        </div>

        {{-- The order in my hands: tick every item, then Packed. --}}
        <template x-if="list">
            <div class="fixed inset-0 z-[60] flex items-end justify-center bg-black/40 sm:items-center sm:p-4">
            <div class="max-h-[92vh] w-full max-w-lg overflow-y-auto rounded-t-2xl border-t-4 bg-white sm:rounded-2xl sm:border-4" :class="list.repack ? 'border-red-600' : 'border-primary'">
                <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-3">
                    <div>
                        <p class="font-mono text-lg font-bold text-gray-900" x-text="list.order_no"></p>
                        <p class="text-xs text-gray-500" x-show="list.moderator">{{ __('Prepared by') }} <span x-text="list.moderator"></span></p>
                    </div>
                    <span class="text-lg font-semibold tabular-nums text-gray-900" x-text="ticked.length + ' / ' + list.items.length"></span>
                </div>
                <div class="h-1.5 bg-gray-100"><div class="h-full bg-primary transition-all" :style="'width:' + (list.items.length ? ticked.length / list.items.length * 100 : 0) + '%'"></div></div>
                <p x-show="!list.repack" class="bg-primary-soft px-4 py-2 text-sm font-medium text-primary" x-text="last?.message"></p>
                <p x-show="list.note" class="bg-amber-50 px-4 py-2 text-sm font-medium text-amber-900">{{ __('Customer note') }}: <span x-text="list.note"></span></p>
                <p x-show="list.packer_note" class="whitespace-pre-line bg-orange-50 px-4 py-2 text-sm font-medium text-orange-900">{{ __('For packer') }}: <span x-text="list.packer_note"></span></p>
                <p x-show="list.repack" class="bg-red-50 px-4 py-2 text-sm font-medium text-red-700">{{ __('Edited after packaging. Change the box:') }} <span x-text="list.diff"></span></p>
                <div class="divide-y divide-gray-100">
                    <template x-for="i in list.items" :key="i.id">
                        <button type="button" @click="tick(i.id)" class="flex w-full items-center gap-4 px-4 py-4 text-left" :class="ticked.includes(i.id) ? 'bg-green-50' : ''">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border-2 text-xl font-bold"
                                :class="ticked.includes(i.id) ? 'border-primary bg-primary-dark text-white' : 'border-gray-300 text-transparent'">✓</span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-base font-medium text-gray-900" x-text="i.name"></span>
                                <span class="text-xs text-gray-500" x-show="i.shelf">{{ __('Shelf') }} <b x-text="i.shelf"></b></span>
                            </span>
                            <span class="shrink-0 text-xl font-bold tabular-nums text-gray-900" x-text="i.qty"></span>
                        </button>
                    </template>
                </div>
                <div class="grid grid-cols-3 gap-2 border-t border-gray-100 p-4">
                    <button type="button" @click="holding = !holding" class="rounded-xl border border-amber-300 px-3 py-4 text-sm font-semibold text-amber-800 hover:bg-amber-50">{{ __('Hold') }}</button>
                    <button type="button" @click="packed()" :disabled="!allTicked || busy"
                        class="col-span-2 rounded-xl bg-primary px-3 py-4 text-base font-semibold text-white hover:bg-primary-dark disabled:cursor-not-allowed disabled:opacity-40"
                        x-text="allTicked ? @js(__('Packed')) : @js(__('Tick every item'))"></button>
                </div>
                <form x-show="holding" x-cloak method="POST" :action="@js(url('/packaging/orders')) + '/' + list.id + '/hold'" class="space-y-2 border-t border-gray-100 p-4">
                    @csrf
                    <input type="hidden" name="reason_id" :value="holdReason">
                    <p class="text-sm font-medium text-gray-700">{{ __('Why can it not be packed?') }}</p>
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach ($holdReasons as $id => $label)
                            <button type="button" @click="holdReason = {{ $id }}" class="rounded-lg border px-3 py-2.5 text-left text-sm"
                                :class="holdReason === {{ $id }} ? 'border-amber-600 bg-amber-50 font-medium text-amber-900' : 'border-gray-200 text-gray-700'">{{ __($label) }}</button>
                        @endforeach
                    </div>
                    <input name="note" maxlength="255" placeholder="{{ __('Which item? (optional)') }}" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                    <button type="submit" :disabled="!holdReason" class="w-full rounded-lg bg-amber-600 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-40">{{ __('Put on hold and tell admin') }}</button>
                </form>
            </div>
            </div>
        </template>
    @endif

    {{-- Boxes to open: taken back (booked by mistake) or cancelled after a packer started them. --}}
    @if ($toUnpack->isNotEmpty())
        <div class="mt-2 rounded-xl border border-red-300 bg-red-50 p-3">
            <p class="text-sm font-semibold text-red-900">{{ __('Open these boxes and put the items back') }}</p>
            <p class="mt-0.5 text-xs text-red-800">{{ __('These orders were taken back or cancelled after packaging started. Do not send them.') }}</p>
            <div class="mt-3 space-y-2">
                @foreach ($toUnpack as $u)
                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-white px-3 py-2 text-sm">
                        <span><span class="font-semibold text-gray-900">{{ $u->order_no }}</span>
                            <span class="text-gray-500">· {{ $u->packer ? __('was with :n', ['n' => $u->packer]) : __('no packer') }} · {{ \Illuminate\Support\Carbon::parse($u->unpack_needed_at)->diffForHumans() }}</span></span>
                        @if (! $u->unpack_packer_id || (int) $u->unpack_packer_id === auth()->id() || $canManage)
                            <form method="POST" action="{{ route('packaging.unpacked', $u->id) }}">@csrf
                                <x-button size="sm">{{ __('Unpacked') }}</x-button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Shared queue --}}
    <div class="mt-4 grid grid-cols-5 gap-1 rounded-xl bg-gray-200/70 p-1">
        @foreach ($filters as $f => $label)
            <a href="{{ route('packaging.index', $f === 'all' ? [] : ['show' => $f]) }}"
                @class(['flex flex-col items-center justify-center rounded-lg py-1.5 text-[11px] leading-tight sm:text-xs',
                    'bg-white font-semibold text-gray-900 shadow-sm' => $filter === $f, 'text-gray-600 hover:bg-white/60' => $filter !== $f])>
                <span class="text-base font-semibold tabular-nums">{{ $counts[$f] }}</span>{{ $label }}
            </a>
        @endforeach
    </div>

    @if ($filter === 'hold')
        {{-- Reached the shop, then put on hold: set the parcel aside with its label; do not send it. It leaves this list when it goes on. --}}
        <div class="mt-3 space-y-2">
            @forelse ($heldInShop as $h)
                <div class="rounded-xl border border-amber-300 bg-amber-50 p-4">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-mono text-base font-bold text-gray-900">{{ $h->order_no }} @if ($h->label)<span class="text-sm font-medium text-gray-600">· {{ __('Label :l', ['l' => $h->label]) }}</span>@endif</p>
                            <p class="mt-0.5 text-sm font-medium text-amber-900">{{ $h->reason ? __($h->reason) : __('On hold') }}@if ($h->hold_expected_date) · {{ __('until :d', ['d' => \Illuminate\Support\Carbon::parse($h->hold_expected_date)->format('d M')]) }}@endif</p>
                            @if ($h->hold_note)<p class="mt-0.5 text-sm text-gray-700">“{{ \App\Support\Phone::hideIn($h->hold_note) }}”</p>@endif
                        </div>
                        <span class="shrink-0 text-right text-xs text-gray-500">{{ $h->ship_district ?: '-' }}@if ($h->moderator)<br>{{ __('by :n', ['n' => $h->moderator]) }}@endif</span>
                    </div>
                    <p class="mt-2 text-xs font-semibold text-amber-900">{{ __('Set the parcel aside with its label. Do not send it.') }}</p>
                </div>
            @empty
                <div class="rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center text-sm text-gray-500">{{ __('No order on hold in the shop.') }}</div>
            @endforelse
        </div>
    @else
    <div class="mt-3 space-y-2">
        @forelse ($queue as $o)
            <div @class(['overflow-hidden rounded-xl border bg-white',
                'border-red-300' => $o->is_red, 'border-orange-300' => ! $o->is_red && $o->is_orange, 'border-gray-200' => ! $o->is_red && ! $o->is_orange])>
                {{-- Tick to print several labels at once; Print prints this one. Tap the rest to see what is inside. Looking does not start packaging; scanning the label does. --}}
                <div @class(['flex items-stretch', 'bg-red-50/40' => $o->is_red])>
                <label class="flex shrink-0 cursor-pointer items-center pl-4 pr-1" title="{{ __('Pick to print') }}">
                    <input type="checkbox" value="{{ $o->id }}" x-model="picked" class="h-5 w-5 rounded border-gray-300 text-primary focus:ring-primary">
                </label>
                <button type="button" @click="peek({{ $o->id }})" class="flex min-w-0 flex-1 items-center justify-between gap-3 p-4 pl-3 text-left hover:bg-gray-50">
                    <span class="min-w-0">
                        <span class="flex flex-wrap items-center gap-2">
                            <span class="font-mono text-base font-bold text-gray-900">{{ $o->order_no }}</span>
                            @if ($o->is_red)<x-badge color="red">{{ __('Repack') }}</x-badge>
                            @elseif ($o->is_orange)<x-badge color="amber">{{ __('New label') }}</x-badge>
                            @elseif ($o->edited_after_pack)<x-badge color="purple">{{ __('Edited') }}</x-badge>@endif
                            @if ($o->packer)<x-badge color="purple">{{ __('Packing: :n', ['n' => $o->packer]) }}</x-badge>@endif
                        </span>
                        <span class="mt-0.5 block text-xs text-gray-500">
                            @php $area = \App\Support\BdDistricts::name($o->ship_district) ?: $o->ship_thana; @endphp
                            {{ trans_choice(':count item|:count items', $o->items) }}@if ($area) · {{ $area }}@endif
                        </span>
                    </span>
                    <span class="flex shrink-0 items-center gap-3">
                        <span class="text-right">
                            @php $mins = $o->packaging_sent_at ? Carbon::parse($o->packaging_sent_at)->diffInMinutes(now()) : null; @endphp
                            {{-- Green under 15 minutes, amber under 30, red after. --}}
                            <span @class(['block text-sm font-semibold tabular-nums', 'text-gray-500' => $mins === null, 'text-green-700' => $mins !== null && $mins < 15, 'text-amber-600' => $mins !== null && $mins >= 15 && $mins < 30, 'text-red-600' => $mins !== null && $mins >= 30])>{{ $wait($o->packaging_sent_at) }}</span>
                            <span class="block text-xs text-gray-500">{{ __('waiting') }}</span>
                        </span>
                        <svg class="h-4 w-4 text-gray-400 transition-transform" :class="open === {{ $o->id }} && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </span>
                </button>
                <span class="flex shrink-0 items-center pr-3">
                    <button type="button" @click="printPicked([{{ $o->id }}])" class="rounded-lg border border-primary px-3 py-1.5 text-sm font-semibold text-primary hover:bg-primary hover:text-white">{{ __('Print') }}</button>
                </span>
                </div>

                <div x-show="open === {{ $o->id }}" x-cloak class="border-t border-gray-100 bg-gray-50/60 px-4 py-3">
                    <template x-if="!previews[{{ $o->id }}]"><p class="text-sm text-gray-400">{{ __('Loading…') }}</p></template>
                    <template x-if="previews[{{ $o->id }}]?.error"><p class="text-sm text-red-600">{{ __('Could not load. Tap again.') }}</p></template>
                    <template x-if="previews[{{ $o->id }}]?.items">
                        <div>
                            <p x-show="previews[{{ $o->id }}].note" class="mb-2 rounded-lg bg-amber-50 px-3 py-2 text-sm font-medium text-amber-900">{{ __('Customer note') }}: <span x-text="previews[{{ $o->id }}].note"></span></p>
                            <p x-show="previews[{{ $o->id }}].packer_note" class="mb-2 whitespace-pre-line rounded-lg bg-orange-50 px-3 py-2 text-sm font-medium text-orange-900">{{ __('For packer') }}: <span x-text="previews[{{ $o->id }}].packer_note"></span></p>
                            <p x-show="previews[{{ $o->id }}].repack" class="mb-2 rounded-lg bg-red-50 px-3 py-2 text-sm font-medium text-red-700">{{ __('Edited after packaging. Change the box:') }} <span x-text="previews[{{ $o->id }}].diff"></span></p>
                            @if ($o->moderator)<p class="mb-2 text-xs text-gray-500">{{ __('Prepared by') }} {{ $o->moderator }}</p>@endif
                            <div class="divide-y divide-gray-200 rounded-lg border border-gray-200 bg-white">
                                <template x-for="i in previews[{{ $o->id }}].items" :key="i.id">
                                    <div class="flex items-center justify-between gap-3 px-3 py-2.5">
                                        <span class="min-w-0">
                                            <span class="block text-sm font-medium text-gray-900" x-text="i.name"></span>
                                            <span class="text-xs text-gray-500" x-show="i.shelf">{{ __('Shelf') }} <b x-text="i.shelf"></b></span>
                                        </span>
                                        <span class="shrink-0 text-base font-bold tabular-nums text-gray-900" x-text="i.qty"></span>
                                    </div>
                                </template>
                            </div>
                            <p class="mt-2 text-xs text-gray-500">
                                <template x-if="previews[{{ $o->id }}].label && previews[{{ $o->id }}].label_current">
                                    <span>{{ __('To start packaging, scan its label.') }}<span x-show="!previews[{{ $o->id }}].label_printed"> · {{ __('not printed yet') }}</span></span>
                                </template>
                                <template x-if="previews[{{ $o->id }}].label && !previews[{{ $o->id }}].label_current">
                                    <span class="font-medium text-orange-700">{{ __('The order changed: press Print for the new label, then scan it.') }}</span>
                                </template>
                            </p>
                        </div>
                    </template>
                </div>
            </div>
        @empty
            <div class="rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center text-sm text-gray-500">{{ __('Nothing to pack right now.') }}</div>
        @endforelse
    </div>
    <div class="mt-4">{{ $queue->links() }}</div>
    @endif

    {{-- Picked orders: print their labels together (with their A4 packing sheet). --}}
    <div x-show="picked.length" x-cloak class="above-nav fixed inset-x-0 bottom-0 z-30 border-t border-gray-200 bg-white px-4 py-3 shadow-lg">
        <div class="mx-auto flex max-w-3xl items-center justify-between gap-3">
            <button type="button" @click="picked = []" class="text-sm font-medium text-gray-600 hover:underline">{{ __('Clear') }}</button>
            <button type="button" @click="printPicked(picked)" class="rounded-xl bg-primary px-5 py-3 text-sm font-semibold text-white hover:bg-primary-dark" x-text="@js(__('Print selected')) + ' (' + picked.length + ')'"></button>
        </div>
    </div>
</div>
</x-layouts.app>
