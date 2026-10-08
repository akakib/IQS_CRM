@php
    $tab = request('tab') === 'manual' ? 'manual' : 'scan';
    $total = $handed->count() + $missing->count();
    $refused = session('refused', []);
@endphp

<x-layouts.app :heading="__('Handover to :r', ['r' => $session->rider_name ?? __('rider')])">
    {{-- Two pages under one tab bar: scan each label, or tick parcels by hand when scanning is not possible. --}}
    <x-tabs :tabs="[
        'scan' => [__('Scan'), route('handover.show', $session->id)],
        'manual' => [__('Manual'), route('handover.show', ['session' => $session->id, 'tab' => 'manual']), $missing->count()],
    ]" :active="$tab" />

    @if ($tab === 'scan')
        <div class="grid gap-6 lg:grid-cols-2"
            x-data="{
                last: null, count: {{ $handed->count() }}, total: {{ $total }},
                async handle(code) {
                    try {
                        const r = await fetch(@js(route('handover.scan', $session->id)), { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify({ code }) });
                        this.last = await r.json();
                    } catch (e) { this.last = { ok: false, level: 'red', message: @js(__('Connection problem. Scan again.')) }; }
                    if (this.last.ok) { this.count++; }
                    $dispatch('scan-result', { ok: this.last.ok, message: this.last.message });
                },
            }" @scan="handle($event.detail)">
            <div>
                <x-scan-input quiet :placeholder="__('Scan each parcel')" />
                {{-- COD not updated at the courier has its own amber: the parcel is right, only the cash amount is waiting. --}}
                <div x-show="last" x-cloak class="mt-4 rounded-xl border-2 p-4"
                    :class="{ 'border-green-600 bg-green-50': last?.level === 'ok', 'border-purple-600 bg-purple-50': last?.level === 'edited', 'border-orange-500 bg-orange-50': ['orange', 'warn'].includes(last?.level), 'border-red-600 bg-red-50': last?.level === 'red', 'border-amber-500 bg-amber-100 text-amber-950': last?.level === 'cod' }">
                    <p x-show="last?.level === 'cod'" class="mb-1 inline-block rounded-full bg-amber-500 px-2.5 py-0.5 text-xs font-bold uppercase tracking-wide text-white">{{ __('COD not updated') }}</p>
                    <p class="text-lg font-bold" x-text="last?.message"></p>
                    <p class="font-mono text-sm" x-text="last?.order?.order_no"></p>
                </div>
                <div class="mt-4 grid grid-cols-3 gap-3 text-center">
                    <div class="rounded-xl border border-gray-200 bg-white p-4"><p class="text-xs uppercase text-gray-500">{{ __('To hand over') }}</p><p class="text-2xl font-semibold tabular-nums" x-text="total"></p></div>
                    <div class="rounded-xl border border-green-200 bg-green-50 p-4"><p class="text-xs uppercase text-green-800">{{ __('Done') }}</p><p class="text-2xl font-semibold tabular-nums text-green-800" x-text="count"></p></div>
                    <div class="rounded-xl border p-4" :class="total - count > 0 ? 'border-red-200 bg-red-50' : 'border-gray-200 bg-white'"><p class="text-xs uppercase text-gray-500">{{ __('Left') }}</p><p class="text-2xl font-semibold tabular-nums" x-text="Math.max(0, total - count)"></p></div>
                </div>
                <form method="POST" action="{{ route('handover.close', $session->id) }}" class="mt-4">@csrf<x-button class="w-full">{{ __('Finish handover') }}</x-button></form>
                <p class="mt-1 text-center text-xs text-gray-500">{{ __('Parcels not scanned move to the next pickup.') }}</p>
            </div>

            <x-card :title="__('Ready but not handed over yet')">
                @forelse ($missing as $m)
                    <div class="border-b border-gray-100 py-1.5 font-mono text-sm last:border-0">{{ $m->order_no }}</div>
                @empty
                    <p class="text-sm text-gray-400">{{ __('Everything ready has been handed over.') }}</p>
                @endforelse
                <p class="mt-2 text-xs text-gray-400">{{ __('Reload to refresh this list. Scanner not working? Use the Manual tab.') }}</p>
            </x-card>
        </div>
    @else
        <div class="mx-auto max-w-3xl"
            x-data="{
                q: '', picked: [],
                rows: {{ \Illuminate\Support\Js::from($missing->map(fn ($m) => ['id' => $m->id, 'no' => $m->order_no, 'cn' => (string) $m->consignment_id])->values()) }},
                shown(r) { const q = this.q.trim().toLowerCase(); return !q || r.no.toLowerCase().includes(q) || r.cn.toLowerCase().includes(q) },
                get visible() { return this.rows.filter(r => this.shown(r)) },
                get allPicked() { return this.visible.length > 0 && this.visible.every(r => this.picked.includes(r.id)) },
                toggleAll() { const ids = this.visible.map(r => r.id); this.picked = this.allPicked ? this.picked.filter(id => !ids.includes(id)) : [...new Set([...this.picked, ...ids])] },
            }">
            <div class="mb-4 grid grid-cols-3 gap-3 text-center">
                <div class="rounded-xl border border-gray-200 bg-white p-4"><p class="text-xs uppercase text-gray-500">{{ __('To hand over') }}</p><p class="text-2xl font-semibold tabular-nums">{{ $total }}</p></div>
                <div class="rounded-xl border border-green-200 bg-green-50 p-4"><p class="text-xs uppercase text-green-800">{{ __('Done') }}</p><p class="text-2xl font-semibold tabular-nums text-green-800">{{ $handed->count() }}</p></div>
                <div @class(['rounded-xl border p-4', 'border-red-200 bg-red-50' => $missing->isNotEmpty(), 'border-gray-200 bg-white' => $missing->isEmpty()])><p class="text-xs uppercase text-gray-500">{{ __('Left') }}</p><p class="text-2xl font-semibold tabular-nums">{{ $missing->count() }}</p></div>
            </div>

            <p class="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">{{ __('Use this only when scanning is not possible. Check each parcel\'s number yourself: these are recorded as handed over by hand.') }}</p>

            @if ($refused)
                <div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                    <p class="font-medium">{{ __('Not handed over:') }}</p>
                    @foreach ($refused as $line)<p>{{ $line }}</p>@endforeach
                </div>
            @endif

            @if ($missing->isEmpty())
                <div class="rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center text-sm text-gray-500">{{ __('Everything ready has been handed over.') }}</div>
            @else
                <form id="manual-handover" method="POST" action="{{ route('handover.manual', $session->id) }}">
                    @csrf
                    <template x-for="id in picked" :key="id"><input type="hidden" name="order_ids[]" :value="id"></template>

                    <div class="mb-3 flex items-center gap-2">
                        <input type="search" x-model="q" placeholder="{{ __('Find by order no or CN') }}" class="min-w-0 flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:outline-none">
                        <button type="button" @click="toggleAll()" class="shrink-0 rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-600 hover:bg-gray-50" x-text="allPicked ? @js(__('Clear')) : @js(__('Select all'))"></button>
                    </div>

                    <div class="space-y-2 pb-6">
                        @foreach ($missing as $m)
                            <label x-show="shown({ no: @js($m->order_no), cn: @js((string) $m->consignment_id) })"
                                class="flex cursor-pointer items-center gap-4 rounded-xl border bg-white p-4"
                                :class="picked.includes({{ $m->id }}) ? 'border-primary bg-primary-soft' : 'border-gray-200'">
                                <input type="checkbox" value="{{ $m->id }}" x-model.number="picked" class="h-6 w-6 shrink-0 rounded border-gray-300 text-primary">
                                <span class="min-w-0 flex-1">
                                    <span class="block font-mono text-base font-bold text-gray-900">{{ $m->order_no }}</span>
                                    <span class="block text-xs text-gray-500">{{ __('CN :cn', ['cn' => $m->consignment_id ?: '-']) }} · {{ $m->ship_district ?: $m->ship_thana ?: '-' }} · {{ trans_choice(':count item|:count items', $m->items) }}</span>
                                </span>
                                {{-- One parcel at a time: no need to tick and scroll to the bottom. --}}
                                <button type="button"
                                    @click.prevent.stop="$dispatch('open-confirm', { id: 'manual-handover-confirm', form: 'hand-one-{{ $m->id }}', label: @js($m->order_no), verb: @js(__('Hand over')), message: @js(__('Confirm the rider has this parcel in hand. It will be recorded as handed over by hand.')), danger: false })"
                                    class="shrink-0 rounded-lg border border-primary px-3 py-2 text-sm font-semibold text-primary hover:bg-primary hover:text-white">{{ __('Hand over') }}</button>
                            </label>
                        @endforeach
                    </div>
                </form>
                {{-- One small form per parcel for its own Hand over button (forms cannot sit inside the form above). --}}
                @foreach ($missing as $m)
                    <form id="hand-one-{{ $m->id }}" method="POST" action="{{ route('handover.manual', $session->id) }}" class="hidden">@csrf<input type="hidden" name="order_ids[]" value="{{ $m->id }}"></form>
                @endforeach

                <div class="sticky bottom-0 z-10 -mx-4 border-t border-gray-200 bg-white p-3 md:mx-0 md:rounded-xl md:border">
                    <button type="button" :disabled="picked.length === 0"
                        @click="$dispatch('open-confirm', { id: 'manual-handover-confirm', form: 'manual-handover', label: picked.length + ' ' + @js(__('parcels')), verb: @js(__('Hand over')), message: @js(__('Confirm the rider has these parcels in hand. They will be recorded as handed over by hand.')), danger: false })"
                        class="w-full rounded-xl bg-primary px-4 py-3.5 text-base font-semibold text-white hover:bg-primary-dark disabled:opacity-40"
                        x-text="picked.length ? @js(__('Hand over selected')) + ' (' + picked.length + ')' : @js(__('Tick several to hand them over together'))"></button>
                </div>
                <x-confirm-modal id="manual-handover-confirm" :verb="__('Hand over')" :danger="false" />
            @endif

            <form method="POST" action="{{ route('handover.close', $session->id) }}" class="mt-4">@csrf<x-button variant="secondary" class="w-full">{{ __('Finish handover') }}</x-button></form>
            <p class="mt-1 text-center text-xs text-gray-500">{{ __('Parcels not handed over move to the next pickup.') }}</p>
        </div>
    @endif
</x-layouts.app>
