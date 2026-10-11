@php
    $tabs = [
        'waiting' => [__('To receive'), request()->fullUrlWithQuery(['tab' => 'waiting', 'page' => null]), $waitingCount],
        'received' => [__('Received'), request()->fullUrlWithQuery(['tab' => 'received', 'page' => null])],
    ];
    $canReceive = auth()->user()->can('packaging.create');
    $states = \App\Services\Packaging\ReturnService::CONDITIONS;
@endphp

<x-layouts.app :heading="__('Returns')">
    <p class="mb-4 text-sm text-gray-500">{{ __('Parcels the courier sent back. Scan one when it reaches the shop and mark each item: Good goes back on the shelf, Damaged or Not in the parcel tells the managers.') }}</p>

    @if ($canReceive)
        {{-- Scan, mark each item, Save. One parcel at a time. --}}
        <div class="mb-6 rounded-xl border border-gray-200 bg-white p-4"
            x-data="{
                order: null, marks: {}, saving: false,
                headers() { return { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } },
                async find(code) {
                    let r;
                    try { r = await (await fetch(@js(route('returns.find')), { method: 'POST', headers: this.headers(), body: JSON.stringify({ code }) })).json() }
                    catch (e) { r = { ok: false, level: 'red', message: @js(__('Connection problem. Scan again.')) } }
                    this.order = r.ok ? r.order : null; this.marks = {};
                    if (this.order) this.order.items.forEach(i => this.marks[i.id] = { good: 0, damaged: 0, missing: 0, kept: 0 });
                    $dispatch('scan-result', { ok: r.ok, message: r.message, level: r.level });
                },
                left(i) { const m = this.marks[i.id] || {}; return Math.round((i.qty - (+m.good || 0) - (+m.damaged || 0) - (+m.missing || 0) - (+m.kept || 0)) * 1000) / 1000 },
                step(i, k, d) { const m = this.marks[i.id]; const v = Math.round(((+m[k] || 0) + d) * 1000) / 1000; if (v < 0 || (d > 0 && this.left(i) < d)) return; m[k] = v },
                all(i, k) { const m = this.marks[i.id]; Object.keys(m).forEach(x => m[x] = 0); m[k] = i.qty },
                get ready() { return this.order && this.order.items.every(i => this.left(i) === 0) },
                async save() {
                    this.saving = true;
                    try {
                        const r = await fetch(@js(url('/returns')) + '/' + this.order.id, { method: 'POST', headers: this.headers(), body: JSON.stringify({ items: this.marks }) });
                        const j = await r.json();
                        if (!r.ok) { $dispatch('scan-result', { ok: false, level: 'red', message: Object.values(j.errors || {})[0]?.[0] || @js(__('Could not save.')) }); return }
                        $dispatch('scan-result', { ok: true, level: 'ok', message: j.message });
                        this.order = null; this.marks = {};
                        setTimeout(() => location.reload(), 900);
                    } finally { this.saving = false }
                },
            }" @scan="find($event.detail)">
            <x-scan-input :placeholder="__('Scan the returned parcel (label, CN or order no)')" />
            <template x-if="order">
                <div class="mt-4 space-y-2">
                    <p class="text-sm font-semibold text-gray-900"><span class="font-mono" x-text="order.order_no"></span> · <span x-text="order.customer"></span></p>
                    <p x-show="order.partial" class="text-xs text-amber-700">{{ __('Partly delivered: what came back is Good or Damaged, what the customer kept is Customer kept.') }}</p>
                    <p class="text-xs text-gray-500">{{ __('Count each item in the unit it was sold in: how many are good, how many damaged. A part is fine: 0.9 of a 1 kg pack. Tap a name to put the whole line there.') }}</p>
                    <template x-for="i in order.items" :key="i.id">
                        <div class="rounded-lg border p-3" :class="left(i) === 0 ? 'border-green-300 bg-green-50/40' : 'border-gray-200'">
                            <div class="mb-2 flex items-center justify-between gap-2">
                                <p class="text-sm text-gray-800"><span x-text="i.name"></span> <b x-text="'×' + i.qty"></b> <span x-show="i.pack" class="text-xs text-gray-500" x-text="'(' + @js(__('each')) + ' ' + i.pack + ')'"></span></p>
                                <span class="shrink-0 text-xs font-medium" :class="left(i) === 0 ? 'text-green-700' : 'text-amber-700'"
                                    x-text="left(i) === 0 ? @js(__('Done')) : left(i) + ' ' + @js(__('left to count'))"></span>
                            </div>
                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                @foreach ($states as $key => $label)
                                    <div @if ($key === 'kept') x-show="order.partial" @endif class="flex items-center justify-between gap-1 rounded-lg border px-2 py-1.5"
                                        :class="marks[i.id]?.{{ $key }} > 0 ? @js($key === 'good' ? 'border-green-500 bg-green-50' : ($key === 'kept' ? 'border-blue-400 bg-blue-50' : 'border-red-400 bg-red-50')) : 'border-gray-200'">
                                        <button type="button" @click="all(i, @js($key))" class="min-w-0 truncate text-left text-xs font-medium text-gray-700">{{ __($label) }}</button>
                                        <span class="flex shrink-0 items-center gap-1">
                                            <button type="button" @click="step(i, @js($key), -1)" class="h-7 w-7 rounded-md border border-gray-300 text-sm leading-none text-gray-600" aria-label="{{ __('Less') }}">&minus;</button>
                                            <input type="number" min="0" step="any" inputmode="decimal" x-model.number="marks[i.id].{{ $key }}" @focus="$el.select()"
                                                class="w-14 rounded-md border border-gray-300 px-1 py-1 text-center text-sm font-semibold tabular-nums focus:border-primary focus:outline-none" aria-label="{{ __($label) }}">
                                            <button type="button" @click="step(i, @js($key), Math.min(1, left(i)))" class="h-7 w-7 rounded-md border border-gray-300 text-sm leading-none text-gray-600" aria-label="{{ __('More') }}">+</button>
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </template>
                    <div class="flex gap-2 pt-1">
                        <x-button type="button" class="flex-1" x-bind:disabled="!ready || saving" @click="save()">{{ __('Received') }}</x-button>
                        <x-button type="button" variant="secondary" @click="order = null; marks = {}">{{ __('Cancel') }}</x-button>
                    </div>
                </div>
            </template>
        </div>
    @endif

    <x-tabs :tabs="$tabs" :active="$tab" />
    <x-list.filter-bar :list="$list" :action="route('returns.index')" :placeholder="__('Order no or phone')">
        <input type="hidden" name="tab" value="{{ $tab }}">
    </x-list.filter-bar>

    @if ($rows->isEmpty())
        <x-empty-state :message="$tab === 'waiting' ? __('No returned parcel is on its way back.') : __('No returns received yet.')" />
    @else
        <div class="space-y-2">
            @foreach ($rows as $o)
                @php
                    $at = isset($returnedAt[$o->id]) ? \Illuminate\Support\Carbon::parse($returnedAt[$o->id]) : null;
                    $late = $tab === 'waiting' && $at && $at->diffInDays(now()) >= $alertDays;
                    $c = ($received[$o->id] ?? collect())->pluck('n', 'c');
                @endphp
                <div @class(['flex flex-col gap-1 rounded-xl border bg-white p-4 sm:flex-row sm:items-center sm:gap-3', 'border-red-300 bg-red-50' => $late, 'border-gray-200' => ! $late])>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-900"><a href="{{ route('orders.show', $o->id) }}" class="font-mono hover:underline">{{ $o->order_no }}</a> · {{ $o->ship_name }}
                            <span class="text-xs text-gray-500">· {{ __($statuses[$o->status_id]['name'] ?? '') }}</span></p>
                        <p class="text-xs text-gray-500">@if ($o->consignment_id)CN <span class="font-mono">{{ $o->consignment_id }}</span> · @endif{{ $at ? __('returned :d', ['d' => $at->format('d M')]) : '' }}
                            @if ($tab === 'received') · {{ __('received :d by :n', ['d' => \Illuminate\Support\Carbon::parse($o->return_received_at)->format('d M, g:i A'), 'n' => $o->receiver ?? '-']) }}@endif</p>
                    </div>
                    {{-- Why it came back: set by the person who knows (it comes from the courier as not set). --}}
                    @can('orders.edit')
                        @php $rid = (int) ($reasonOf[$o->id] ?? 0); @endphp
                        <form method="POST" action="{{ route('returns.reason', $o->id) }}" class="flex items-center gap-1.5" x-data>
                            @csrf
                            <x-simple-select name="reason_id" :options="$reasons" :value="$rid && $rid !== $unclassified ? $rid : null" :placeholder="__('Why did it come back?')" size="sm"
                                @select-change="$nextTick(() => $el.closest('form').requestSubmit())" />
                        </form>
                    @endcan
                    @if ($late)
                        <span class="text-sm font-semibold text-red-700">{{ __('Not back after :n days: ask the courier', ['n' => (int) $at->diffInDays(now())]) }}</span>
                    @elseif ($tab === 'received')
                        <span class="text-xs">
                            <span class="text-green-700">{{ __(':n good', ['n' => (float) ($c['good'] ?? 0) + 0]) }}</span>
                            @if ($c['damaged'] ?? 0) · <span class="font-medium text-red-700">{{ __(':n damaged', ['n' => (float) $c['damaged'] + 0]) }}</span>@endif
                            @if ($c['missing'] ?? 0) · <span class="font-medium text-red-700">{{ __(':n not in the parcel', ['n' => (float) $c['missing'] + 0]) }}</span>@endif
                            @if ($c['kept'] ?? 0) · <span class="text-blue-700">{{ __(':n kept by the customer', ['n' => (float) $c['kept'] + 0]) }}</span>@endif
                        </span>
                    @endif
                </div>
            @endforeach
        </div>
        <div class="mt-4">{{ $rows->links() }}</div>
    @endif
</x-layouts.app>
