@php
    $input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:outline-none';
    $items = $order->items->map(fn ($i) => [
        'variant_id' => $i->variant_id, 'label' => $i->name_snapshot, 'sub' => $i->sku_snapshot, 'price' => (float) $i->unit_price,
        'unit' => $i->unit, 'weight_g' => (int) ($i->variant?->weight_g ?? 0), 'qty' => (float) $i->qty, 'line_discount' => (float) $i->line_discount,
    ])->values();
@endphp

{{-- embed=1: the form sits in the edit popup on Order management, so no sidebar or header. --}}
<x-dynamic-component :component="request('embed') ? 'layouts.embed' : 'layouts.app'" :heading="__('Edit :no', ['no' => $order->order_no])">
    <div class="mb-4 flex flex-wrap items-center gap-2 text-sm">
        @unless (request('embed'))<a href="{{ route('orders.show', $order) }}" class="text-primary hover:underline">{{ __('Back to the order') }}</a>@endunless
        @if ($policy === 'approval' && ! auth()->user()->can('orders.approve'))
            <x-badge color="amber">{{ __('In this stage a manager must approve the change') }}</x-badge>
        @endif
        @if ($order->packed_version)
            <x-badge color="red">{{ __('Already packed: a content change marks it for repack') }}</x-badge>
        @endif
    </div>

    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>
    @endif

    <form method="POST" action="{{ route('orders.amend', $order) }}"
        x-data="{
            items: {{ \Illuminate\Support\Js::from($items) }}, q: '', results: [], active: 0, timer: null, searched: false,
            search() {
                clearTimeout(this.timer);
                if (this.q.trim().length < 2) { this.results = []; this.searched = false; return; }
                this.timer = setTimeout(async () => {
                    const r = await fetch(@js(route('products.search')) + '?q=' + encodeURIComponent(this.q), { headers: { Accept: 'application/json' } });
                    this.results = r.ok ? await r.json() : []; this.active = 0; this.searched = true;
                }, 250);
            },
            add(p) {
                if (!p || !p.sellable) return;
                const e = this.items.find(i => i.variant_id === p.value);
                if (e) e.qty = Number(e.qty) + 1;
                else this.items.push({ variant_id: p.value, label: p.label, sub: String(p.sub || '').split(' · ')[0], price: p.price || 0, unit: p.unit, weight_g: p.weight_g, qty: ({ g: 500, ml: 250 })[p.unit] || 1, line_discount: 0 });
                this.q = ''; this.results = []; this.searched = false;
            },
            money(n) { return '৳' + Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 }); },
        }">
        @csrf
        @if (request('embed'))<input type="hidden" name="embed" value="1">@endif
        <input type="hidden" name="lock_version" value="{{ $order->lock_version }}">

        <div class="grid gap-6 xl:grid-cols-3">
            <div class="space-y-6 xl:col-span-2">
                <x-card :title="__('Products')">
                    {{-- Add: search and pick. Remove: the button on each line (an order keeps at least one product). --}}
                    <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('Add a product') }}</label>
                    <div class="relative mb-4" @click.outside="results = []; searched = false">
                        <input x-model="q" @input="search()" type="search" placeholder="{{ __('Type a name, SKU or barcode, then pick from the list') }}"
                            @keydown.arrow-down.prevent="active = Math.min(active + 1, results.length - 1)" @keydown.arrow-up.prevent="active = Math.max(active - 1, 0)"
                            @keydown.enter.prevent="add(results[active])" class="{{ $input }}">
                        <div x-show="results.length" x-cloak class="absolute z-30 mt-1 w-full rounded-lg border border-gray-200 bg-white py-1 shadow-lg">
                            <template x-for="(p, i) in results" :key="p.value">
                                <button type="button" @click="add(p)" :disabled="!p.sellable" class="block w-full px-3 py-1.5 text-left text-sm disabled:opacity-50" :class="i === active ? 'bg-green-50' : ''">
                                    <span x-text="p.label"></span><span class="block text-xs text-gray-400" x-text="p.sub"></span>
                                </button>
                            </template>
                        </div>
                        <p x-show="searched && !results.length" x-cloak class="absolute z-30 mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-500 shadow-lg">{{ __('No product found.') }}</p>
                    </div>
                    <div class="space-y-2">
                        <template x-for="(it, i) in items" :key="it.variant_id">
                            <div class="grid grid-cols-12 items-center gap-2 rounded-lg border border-gray-100 p-2 text-sm">
                                <input type="hidden" :name="`items[${i}][variant_id]`" :value="it.variant_id">
                                <div class="col-span-12 md:col-span-5"><p class="font-medium text-gray-800" x-text="it.label"></p><p class="text-xs text-gray-400" x-text="it.sub + ' · ' + money(it.price)"></p></div>
                                <label class="col-span-4 text-xs text-gray-500 md:col-span-2"><span x-text="({ g: @js(__('Grams')), kg: @js(__('KG')), ml: @js(__('ML')), l: @js(__('Litres')), packet: @js(__('Packets')), box: @js(__('Boxes')) })[it.unit] || @js(__('Qty'))"></span>
                                    <input type="number" step="any" min="0.001" :name="`items[${i}][qty]`" x-model.number="it.qty" class="{{ $input }} mt-0.5 px-2 py-1"></label>
                                <label class="col-span-4 text-xs text-gray-500 md:col-span-2">{{ __('Discount') }}
                                    <input type="number" step="0.01" min="0" :name="`items[${i}][line_discount]`" x-model.number="it.line_discount" class="{{ $input }} mt-0.5 px-2 py-1"></label>
                                <p class="col-span-1 hidden text-right tabular-nums md:block" x-text="money(Math.max(0, it.qty * it.price - (it.line_discount || 0)))"></p>
                                <button type="button" :disabled="items.length < 2" @click="items.splice(i, 1)"
                                    class="col-span-4 self-end rounded-lg border border-red-200 px-2 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 disabled:cursor-not-allowed disabled:border-gray-200 disabled:text-gray-300 md:col-span-2">{{ __('Remove') }}</button>
                            </div>
                        </template>
                    </div>
                    <p x-show="items.length < 2" class="mt-2 text-xs text-gray-500">{{ __('To swap the product, add the new one first, then remove this one.') }}</p>
                    <p class="mt-2 text-xs text-gray-400">{{ __('Lines already on the order keep the price they were sold at. Totals, delivery and COD are recalculated on save.') }}</p>
                </x-card>

                <x-card :title="__('Delivery details')">
                    <div class="grid gap-x-4 md:grid-cols-2">
                        <x-form.input name="ship_name" :label="__('Name')" :value="$order->ship_name" required />
                        <x-form.input name="ship_phone" :label="__('Phone')" :value="$order->ship_phone" required inputmode="tel" />
                        <x-form.input name="ship_alt_phone" :label="__('Other phone')" :value="$order->ship_alt_phone" inputmode="tel" />
                        <x-form.input name="ship_district" :label="__('District')" :value="$order->ship_district" />
                        <x-form.input name="ship_thana" :label="__('Thana / area')" :value="$order->ship_thana" />
                    </div>
                    <label class="mb-2 block text-sm font-medium text-gray-700" for="ship_address">{{ __('Address') }}</label>
                    <textarea id="ship_address" name="ship_address" rows="2" required maxlength="500" class="{{ $input }}">{{ old('ship_address', $order->ship_address) }}</textarea>
                    <div class="mt-3" x-data="{ zone: @js((string) old('zone_id', $order->zone_id ?? '')) }">
                        <input type="hidden" name="zone_id" :value="zone">
                        <div class="flex flex-wrap gap-2">
                            @foreach ($zones as $id => $zone)
                                <button type="button" @click="zone = '{{ $id }}'" class="rounded-full border px-2.5 py-1 text-xs" :class="zone == '{{ $id }}' ? 'border-primary bg-primary text-white' : 'border-gray-300 text-gray-600'">{{ $zone }}</button>
                            @endforeach
                        </div>
                    </div>
                </x-card>
            </div>

            <div class="space-y-6">
                <x-card :title="__('Why the change?')">
                    <x-simple-select name="reason_id" :options="['' => __('Choose a reason')] + $reasons" :value="(string) old('reason_id', '')" full-width class="w-full" />
                    <p class="mt-2 text-xs text-gray-500">{{ __('"Entry error" counts against whoever made the mistake.') }}</p>
                </x-card>
                <x-button class="w-full">{{ __('Save change') }}</x-button>
            </div>
        </div>
    </form>
</x-dynamic-component>
