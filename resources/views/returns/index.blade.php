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
                    $dispatch('scan-result', { ok: r.ok, message: r.message, level: r.level });
                },
                get ready() { return this.order && this.order.items.every(i => this.marks[i.id]) },
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
                    <p x-show="order.partial" class="text-xs text-amber-700">{{ __('Partly delivered: mark what came back as Good or Damaged, and what the customer kept as Not in the parcel.') }}</p>
                    <template x-for="i in order.items" :key="i.id">
                        <div class="flex flex-col gap-2 rounded-lg border border-gray-200 p-3 sm:flex-row sm:items-center sm:justify-between">
                            <p class="text-sm text-gray-800"><span x-text="i.name"></span> <b x-text="'×' + i.qty"></b></p>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($states as $key => $label)
                                    <button type="button" @click="marks[i.id] = @js($key)" class="rounded-full border px-3 py-1.5 text-xs font-medium"
                                        :class="marks[i.id] === @js($key) ? @js($key === 'good' ? 'border-green-600 bg-green-600 text-white' : 'border-red-600 bg-red-600 text-white') : 'border-gray-300 text-gray-700'">{{ __($label) }}</button>
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
                            <span class="text-green-700">{{ __(':n good', ['n' => (int) ($c['good'] ?? 0)]) }}</span>
                            @if ($c['damaged'] ?? 0) · <span class="font-medium text-red-700">{{ __(':n damaged', ['n' => (int) $c['damaged']]) }}</span>@endif
                            @if ($c['missing'] ?? 0) · <span class="font-medium text-red-700">{{ __(':n not in the parcel', ['n' => (int) $c['missing']]) }}</span>@endif
                        </span>
                    @endif
                </div>
            @endforeach
        </div>
        <div class="mt-4">{{ $rows->links() }}</div>
    @endif
</x-layouts.app>
