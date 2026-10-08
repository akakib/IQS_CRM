{{-- Orders to Excel or print (orders.export). One Export button opens the panel; a board column opens it
     with its stage chosen ($dispatch('order-export', { stages: ['hold'] })). Dates and person start from the page.
     <x-order-export :from="$from" :to="$to" :staff="$staffKey" :people="$people" /> --}}
@props(['from', 'to', 'staff' => null, 'people' => collect()])

@can('orders.export')
@php
    $stages = collect(\App\Services\Orders\OrderStages::all())->map(fn ($s) => $s['label'])->all();
    $personOptions = ['' => __('Everyone')] + collect($people)->mapWithKeys(fn ($u) => [(string) $u->id => $u->name])->all() + ['none' => __('Nobody took them')];
@endphp
<div x-data="{
        from: @js($from), to: @js($to), staff: @js((string) ($staff ?? '')), stages: [], sort: 'placed',
        totals: null, error: null, timer: null,
        open(detail) { this.stages = detail?.stages ?? []; $dispatch('open-modal', 'order-export'); this.count() },
        days(n) { const t = new Date(); const f = new Date(); f.setDate(t.getDate() - n + 1); this.from = this.iso(f); this.to = this.iso(t); this.count() },
        iso(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0') },
        toggle(k) { this.stages = this.stages.includes(k) ? this.stages.filter(s => s !== k) : [...this.stages, k]; this.count() },
        query() {
            const p = new URLSearchParams({ from: this.from || '', to: this.to || '' });
            if (this.staff) p.set('staff', this.staff);
            if (this.sort !== 'placed') p.set('sort', this.sort);
            this.stages.forEach(s => p.append('stages[]', s));
            return p.toString();
        },
        count() {
            clearTimeout(this.timer);
            this.timer = setTimeout(async () => {
                this.error = null;
                if (!this.from || !this.to) { this.totals = null; return }
                try {
                    const r = await fetch(@js(route('orders.export.count')) + '?' + this.query(), { headers: { Accept: 'application/json' } });
                    const j = await r.json();
                    if (r.ok) { this.totals = j } else { this.totals = null; this.error = Object.values(j.errors || {})[0]?.[0] || @js(__('Check the dates.')) }
                } catch (e) { this.error = @js(__('Could not count. Try again.')) }
            }, 250);
        },
        money(n) { return '৳' + Math.round(n).toLocaleString('en-IN') },
    }" @order-export.window="open($event.detail)" {{ $attributes }}>
    <x-button variant="secondary" size="sm" type="button" @click="open()">
        <x-icon name="download" class="h-3.5 w-3.5" /> {{ __('Export') }}
    </x-button>

    <x-modal id="order-export" :title="__('Export orders')" width="max-w-xl">
        <div class="space-y-4">
            <div>
                <p class="mb-2 text-xs font-medium text-gray-500">{{ __('Dates (when the order came in)') }}</p>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" @click="days(1)" class="rounded-full border border-gray-300 px-3 py-1 text-xs text-gray-700 hover:bg-gray-50">{{ __('Today') }}</button>
                    <button type="button" @click="days(7)" class="rounded-full border border-gray-300 px-3 py-1 text-xs text-gray-700 hover:bg-gray-50">{{ __('7 days') }}</button>
                    <button type="button" @click="days(30)" class="rounded-full border border-gray-300 px-3 py-1 text-xs text-gray-700 hover:bg-gray-50">{{ __('30 days') }}</button>
                </div>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <x-date-input x-model="from" :clearable="false" :placeholder="__('From')" @date-change="count()" />
                    <span class="text-sm text-gray-400">{{ __('to') }}</span>
                    <x-date-input x-model="to" :clearable="false" :placeholder="__('To')" @date-change="count()" />
                </div>
            </div>

            <div>
                <p class="mb-2 text-xs font-medium text-gray-500">{{ __('Stage') }} <span class="font-normal">({{ __('none ticked = all') }})</span></p>
                <div class="flex flex-wrap gap-1.5">
                    <button type="button" @click="stages = []; count()" class="rounded-lg border px-2.5 py-1 text-xs"
                        :class="stages.length ? 'border-gray-300 text-gray-700 hover:bg-gray-50' : 'border-primary bg-primary-soft font-medium text-primary'">{{ __('All') }}</button>
                    @foreach ($stages as $key => $label)
                        <button type="button" @click="toggle(@js($key))" class="rounded-lg border px-2.5 py-1 text-xs"
                            :class="stages.includes(@js($key)) ? 'border-primary bg-primary-soft font-medium text-primary' : 'border-gray-300 text-gray-700 hover:bg-gray-50'">{{ $label }}</button>
                    @endforeach
                </div>
            </div>

            <div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <p class="mb-2 text-xs font-medium text-gray-500">{{ __('Person') }}</p>
                        <x-simple-select :options="$personOptions" :value="(string) ($staff ?? '')" full-width class="w-full" @select-change="staff = $event.detail; count()" />
                    </div>
                    <div>
                        <p class="mb-2 text-xs font-medium text-gray-500">{{ __('Sort by') }}</p>
                        <x-simple-select :options="\App\Services\Orders\OrderExport::sorts()" value="placed" full-width class="w-full" @select-change="sort = $event.detail" />
                    </div>
                </div>
            </div>

            <div class="rounded-lg bg-gray-50 px-3 py-2 text-sm">
                <template x-if="error"><span class="text-red-600" x-text="error"></span></template>
                <template x-if="!error && totals">
                    <span><b x-text="totals.orders.toLocaleString('en-IN')"></b> {{ __('orders') }} · <b x-text="money(totals.total)"></b> · {{ __('COD') }} <b x-text="money(totals.cod)"></b></span>
                </template>
                <template x-if="!error && !totals"><span class="text-gray-400">{{ __('Counting...') }}</span></template>
            </div>
        </div>
        <x-slot:footer>
            <div class="flex flex-wrap justify-end gap-2">
                <a :href="@js(route('orders.export.print')) + '?' + query()" target="_blank" rel="noopener"
                    :class="(!totals || !totals.orders) && 'pointer-events-none opacity-50'"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    <x-icon name="printer" class="h-4 w-4" /> {{ __('Print') }}
                </a>
                <a :href="@js(route('orders.export.excel')) + '?' + query()"
                    :class="(!totals || !totals.orders) && 'pointer-events-none opacity-50'"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-dark">
                    <x-icon name="download" class="h-4 w-4" /> {{ __('Excel') }}
                </a>
            </div>
        </x-slot:footer>
    </x-modal>
</div>
@endcan
