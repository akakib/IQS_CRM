@php
    use Illuminate\Support\Carbon;
    $filters = ['all' => __('All'), 'red' => __('Repack'), 'waiting' => __('Waiting'), 'mine' => __('Mine')];
    $wait = fn ($t) => $t ? Carbon::parse($t)->diffForHumans(now(), ['short' => true, 'syntax' => Carbon::DIFF_ABSOLUTE, 'parts' => 1]) : '-';
    $canPack = auth()->user()->can('packing.create') && $working;
@endphp

<x-layouts.app :heading="__('Packing')">
<div class="mx-auto max-w-3xl"
    x-data="{
        last: null, list: null, ticked: [], busy: false, holding: false, holdReason: null,
        open: null, previews: {},
        async peek(id) {
            if (this.open === id) { this.open = null; return }
            this.open = id;
            if (!this.previews[id]) {
                try { this.previews[id] = await (await fetch(@js(url('/packing/orders')) + '/' + id, { headers: { Accept: 'application/json' } })).json() }
                catch (e) { this.previews[id] = { error: true } }
            }
        },
        csrf: document.querySelector('meta[name=csrf-token]').content,
        async post(url, body) {
            const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf }, body: JSON.stringify(body) });
            const json = await r.json().catch(() => ({}));
            if (!r.ok) throw new Error(json.message || @js(__('Something went wrong. Try again.')));
            return json;
        },
        async scan(code) {
            try { this.last = await this.post(@js(route('packing.scan.post')), { code }) }
            catch (e) { this.last = { ok: false, level: 'red', message: e.message } }
            this.list = this.last.checklist ?? null; this.ticked = []; this.holding = false;
            $dispatch('scan-result', { ok: this.last.ok, message: '' }); // the result is shown in the box below, once
        },
        tick(id) { this.ticked = this.ticked.includes(id) ? this.ticked.filter(i => i !== id) : [...this.ticked, id] },
        get allTicked() { return this.list && this.ticked.length === this.list.items.length },
        async packed() {
            if (!this.allTicked || this.busy) return;
            this.busy = true;
            try {
                const r = await this.post(@js(url('/packing/orders')) + '/' + this.list.id + '/pack', { items: this.ticked });
                window.toast(r.message); this.list = null; this.last = null;
                setTimeout(() => window.location.reload(), 700);
            } catch (e) { window.toast(e.message, 'error') }
            this.busy = false;
        },
    }" @scan="scan($event.detail)">

    <div class="mb-4 grid grid-cols-3 gap-3">
        <x-stat-tile :label="__('Packed today')" :value="$myDay['packed']" />
        <x-stat-tile :label="__('Average time')" :value="$myDay['avg'] === null ? '-' : $myDay['avg'].' min'" />
        <x-stat-tile :label="__('Scan errors')" :value="$myDay['errors']" :trend="$myDay['errors'] ? 'down' : null" />
    </div>

    <div class="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm">
        <span class="text-gray-600">{{ __('On duty today') }}:
            <b class="text-gray-800">{{ $onDuty->isEmpty() ? __('not set (everyone with packing access)') : $onDuty->join(', ') }}</b></span>
        <span class="flex items-center gap-3">
            @if ($newLabels)<a href="{{ route('packing.labels') }}" target="_blank" class="font-medium text-primary hover:underline">{{ __('Print new labels (:n)', ['n' => $newLabels]) }}</a>@endif
            <a href="{{ route('handover.index') }}" class="font-medium text-primary hover:underline">{{ __('Handover') }}</a>
            @if ($openIssues)<a href="{{ route('packing.issues') }}" class="font-medium text-red-600 hover:underline">{{ __('Missing items (:n)', ['n' => $openIssues]) }}</a>@endif
        </span>
    </div>

    @if ($canManage)
        <details class="mb-4 rounded-xl border border-gray-200 bg-white">
            <summary class="cursor-pointer px-4 py-3 text-sm font-medium text-gray-700">{{ __('Set today\'s packers') }}</summary>
            <form method="POST" action="{{ route('packing.shift') }}" class="border-t border-gray-100 p-4">
                @csrf
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    @foreach ($staff as $id => $name)
                        <label class="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm">
                            <input type="checkbox" name="user_ids[]" value="{{ $id }}" @checked($onDuty->has($id)) class="rounded border-gray-300 text-primary"> {{ $name }}
                        </label>
                    @endforeach
                </div>
                <x-button size="sm" class="mt-3">{{ __('Save for today') }}</x-button>
            </form>
        </details>
    @endif

    @unless ($working)
        <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">{{ __('You are not on duty for packing today. Ask the admin to add you.') }}</div>
    @endunless

    @if ($canPack)
        <x-scan-input :placeholder="__('Scan a label')" />

        {{-- Scan result that is not a checklist (blocked, already packed…) --}}
        <div x-show="last && !list" x-cloak class="mt-3 rounded-xl border-2 p-4"
            :class="{ 'border-orange-500 bg-orange-50': ['orange', 'warn'].includes(last?.level), 'border-red-600 bg-red-50': last?.level === 'red' }">
            <p class="text-lg font-bold" x-text="last?.message"></p>
            <p class="font-mono text-sm" x-text="last?.order?.order_no"></p>
        </div>

        {{-- The order in my hands: tick every item, then Packed. --}}
        <template x-if="list">
            <div class="mt-3 rounded-xl border-2 bg-white" :class="list.repack ? 'border-red-600' : 'border-primary'">
                <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-3">
                    <div>
                        <p class="font-mono text-lg font-bold text-gray-900" x-text="list.order_no"></p>
                        <p class="text-xs text-gray-500" x-show="list.moderator">{{ __('Prepared by') }} <span x-text="list.moderator"></span></p>
                    </div>
                    <span class="text-sm font-semibold tabular-nums text-gray-600" x-text="ticked.length + ' / ' + list.items.length"></span>
                </div>
                <p x-show="!list.repack" class="bg-primary-soft px-4 py-2 text-sm font-medium text-primary" x-text="last?.message"></p>
                <p x-show="list.repack" class="bg-red-50 px-4 py-2 text-sm font-medium text-red-700">{{ __('Edited after packing. Change the box:') }} <span x-text="list.diff"></span></p>
                <div class="divide-y divide-gray-100">
                    <template x-for="i in list.items" :key="i.id">
                        <button type="button" @click="tick(i.id)" class="flex w-full items-center gap-4 px-4 py-4 text-left" :class="ticked.includes(i.id) ? 'bg-green-50' : ''">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border-2 text-xl font-bold"
                                :class="ticked.includes(i.id) ? 'border-primary bg-primary-dark text-white' : 'border-gray-300 text-transparent'">✓</span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-base font-medium text-gray-900" x-text="i.name"></span>
                                <span class="text-xs text-gray-500" x-show="i.shelf">{{ __('Shelf') }} <b x-text="i.shelf"></b></span>
                            </span>
                            <span class="shrink-0 text-xl font-bold tabular-nums text-gray-900" x-text="'×' + i.qty"></span>
                        </button>
                    </template>
                </div>
                <div class="grid grid-cols-3 gap-2 border-t border-gray-100 p-4">
                    <button type="button" @click="holding = !holding" class="rounded-xl border border-amber-300 px-3 py-4 text-sm font-semibold text-amber-800 hover:bg-amber-50">{{ __('Hold') }}</button>
                    <button type="button" @click="packed()" :disabled="!allTicked || busy"
                        class="col-span-2 rounded-xl bg-primary px-3 py-4 text-base font-semibold text-white hover:bg-primary-dark disabled:cursor-not-allowed disabled:opacity-40"
                        x-text="allTicked ? @js(__('Packed')) : @js(__('Tick every item'))"></button>
                </div>
                <form x-show="holding" x-cloak method="POST" :action="@js(url('/packing/orders')) + '/' + list.id + '/hold'" class="space-y-2 border-t border-gray-100 p-4">
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
        </template>
    @endif

    {{-- Shared queue --}}
    <div class="mt-6 flex gap-2 overflow-x-auto">
        @foreach ($filters as $f => $label)
            <a href="{{ route('packing.index', $f === 'all' ? [] : ['show' => $f]) }}"
                @class(['flex shrink-0 items-center gap-1.5 rounded-full border px-3.5 py-1.5 text-sm font-medium',
                    'border-primary bg-primary text-white' => $filter === $f, 'border-gray-300 bg-white text-gray-600' => $filter !== $f])>
                {{ $label }} <span class="tabular-nums opacity-70">{{ $counts[$f] }}</span>
            </a>
        @endforeach
    </div>

    <div class="mt-3 space-y-2">
        @forelse ($queue as $o)
            <div @class(['overflow-hidden rounded-xl border bg-white',
                'border-red-300' => $o->is_red, 'border-orange-300' => ! $o->is_red && $o->is_orange, 'border-gray-200' => ! $o->is_red && ! $o->is_orange])>
                {{-- Tap to see what is inside. Looking does not start packing; scanning the label does. --}}
                <button type="button" @click="peek({{ $o->id }})" @class(['flex w-full items-center justify-between gap-3 p-4 text-left hover:bg-gray-50', 'bg-red-50/40' => $o->is_red])>
                    <span class="min-w-0">
                        <span class="flex flex-wrap items-center gap-2">
                            <span class="font-mono text-base font-bold text-gray-900">{{ $o->order_no }}</span>
                            @if ($o->is_red)<x-badge color="red">{{ __('Repack') }}</x-badge>
                            @elseif ($o->is_orange)<x-badge color="amber">{{ __('New label') }}</x-badge>
                            @elseif ($o->edited_after_pack)<x-badge color="purple">{{ __('Edited') }}</x-badge>@endif
                        </span>
                        <span class="mt-0.5 block text-xs text-gray-500">
                            {{ trans_choice(':count item|:count items', $o->items) }} · {{ $o->ship_district ?: $o->ship_thana ?: '-' }}
                            @if ($o->moderator) · {{ __('by :n', ['n' => $o->moderator]) }}@endif
                        </span>
                    </span>
                    <span class="flex shrink-0 items-center gap-3">
                        <span class="text-right">
                            <span class="block text-sm font-semibold tabular-nums {{ $o->packing_sent_at && Carbon::parse($o->packing_sent_at)->lt(now()->subMinutes(30)) ? 'text-red-600' : 'text-gray-700' }}">{{ $wait($o->packing_sent_at) }}</span>
                            <span class="block text-xs text-gray-500">{{ $o->packer ? __('Packing: :n', ['n' => $o->packer]) : __('Waiting') }}</span>
                        </span>
                        <svg class="h-4 w-4 text-gray-400 transition-transform" :class="open === {{ $o->id }} && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </span>
                </button>

                <div x-show="open === {{ $o->id }}" x-cloak class="border-t border-gray-100 bg-gray-50/60 px-4 py-3">
                    <template x-if="!previews[{{ $o->id }}]"><p class="text-sm text-gray-400">{{ __('Loading…') }}</p></template>
                    <template x-if="previews[{{ $o->id }}]?.error"><p class="text-sm text-red-600">{{ __('Could not load. Tap again.') }}</p></template>
                    <template x-if="previews[{{ $o->id }}]?.items">
                        <div>
                            <p x-show="previews[{{ $o->id }}].repack" class="mb-2 rounded-lg bg-red-50 px-3 py-2 text-sm font-medium text-red-700">{{ __('Edited after packing. Change the box:') }} <span x-text="previews[{{ $o->id }}].diff"></span></p>
                            <div class="divide-y divide-gray-200 rounded-lg border border-gray-200 bg-white">
                                <template x-for="i in previews[{{ $o->id }}].items" :key="i.id">
                                    <div class="flex items-center justify-between gap-3 px-3 py-2.5">
                                        <span class="min-w-0">
                                            <span class="block text-sm font-medium text-gray-900" x-text="i.name"></span>
                                            <span class="text-xs text-gray-500" x-show="i.shelf">{{ __('Shelf') }} <b x-text="i.shelf"></b></span>
                                        </span>
                                        <span class="shrink-0 text-base font-bold tabular-nums text-gray-900" x-text="'×' + i.qty"></span>
                                    </div>
                                </template>
                            </div>
                            <p class="mt-2 text-xs text-gray-500">
                                <template x-if="previews[{{ $o->id }}].label && previews[{{ $o->id }}].label_current">
                                    <span>{{ __('To start packing, scan its label:') }} <b class="font-mono text-gray-800" x-text="previews[{{ $o->id }}].label"></b><span x-show="!previews[{{ $o->id }}].label_printed"> · {{ __('not printed yet') }}</span></span>
                                </template>
                                <template x-if="previews[{{ $o->id }}].label && !previews[{{ $o->id }}].label_current">
                                    <span class="font-medium text-orange-700">{{ __('The order changed: a new label must be printed before it can be scanned.') }}</span>
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
</div>
</x-layouts.app>
