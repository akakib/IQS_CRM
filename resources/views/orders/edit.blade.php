@php
    $input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:outline-none';
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

    <x-order-presence :order="$order" mode="edit" />

    <form method="POST" action="{{ route('orders.amend', $order) }}"
        @submit="if (!$el.querySelector('input[name=reason_id]')?.value) { $event.preventDefault(); needReason = true; $nextTick(() => $refs.reason.scrollIntoView({ behavior: 'smooth', block: 'center' })) }"
        x-data="{
            items: {{ \Illuminate\Support\Js::from($items) }}, q: '', results: [], active: 0, timer: null, searched: false, needReason: false,
            orderDiscount: {{ (float) old('order_discount', $orderDiscount) }}, delivery: {{ (float) $order->delivery_charge }}, chargeTimer: null,
            zone: @js((string) old('zone_id', $order->zone_id ?? '')), soldZone: @js((string) ($order->zone_id ?? '')), soldCharge: {{ (float) $order->delivery_charge }},
            district: @js((string) old('ship_district', $order->ship_district ?? '')), soldDistrict: @js((string) ($order->ship_district ?? '')),
            get zoneChanged() { return this.zone !== this.soldZone },
            get placeChanged() { return this.zoneChanged || this.district !== this.soldDistrict },
            subtotal() { return this.items.reduce((s, i) => s + Number(i.qty) * i.price, 0); },
            lineDiscounts() { return this.items.reduce((s, i) => s + (Number(i.line_discount) || 0), 0); },
            discount() { return this.lineDiscounts() + (Number(this.orderDiscount) || 0); },
            total() { return Math.max(0, this.subtotal() - this.discount()) + this.delivery; },
            weight() { const per = { g: 1, kg: 1000, ml: 1, l: 1000 }; return this.items.reduce((s, i) => s + (per[i.unit] ? Number(i.qty) * per[i.unit] : i.weight_g * i.qty), 0); },
            {{-- A website order keeps the delivery charge it was sold with while its delivery area stays the same; otherwise (and for other channels) the delivery rules decide. --}}
            recharge() {
                if (@js($order->channel === 'web') && !this.zoneChanged) { this.delivery = this.soldCharge; return }
                clearTimeout(this.chargeTimer);
                this.chargeTimer = setTimeout(async () => {
                    const zone = this.zone || '';
                    const url = @js(route('orders.delivery-charge')) + `?zone_id=${zone}&weight_g=${Math.round(this.weight())}&total=${Math.max(0, this.subtotal() - this.discount())}`;
                    try { this.delivery = (await (await fetch(url, { headers: { Accept: 'application/json' } })).json()).charge; } catch (e) {}
                }, 300);
            },
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

        {{-- One column in the popup: products, total, delivery, reason. On a wide page the total and the reason sit on the right. --}}
        <div class="grid items-start gap-6 xl:grid-cols-3">
            <div class="xl:col-span-2">
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
                            <div class="grid grid-cols-12 items-end gap-2 rounded-lg border border-gray-100 p-2 text-sm">
                                <input type="hidden" :name="`items[${i}][variant_id]`" :value="it.variant_id">
                                <div class="col-span-12 self-center md:col-span-5"><p class="font-medium text-gray-800" x-text="it.label"></p><p class="text-xs text-gray-400" x-text="it.sub + ' · ' + money(it.price)"></p></div>
                                <label class="col-span-4 text-xs text-gray-500 md:col-span-2"><span x-text="({ g: @js(__('Grams')), kg: @js(__('KG')), ml: @js(__('ML')), l: @js(__('Litres')), packet: @js(__('Packets')), box: @js(__('Boxes')) })[it.unit] || @js(__('Qty'))"></span>
                                    <input type="number" step="any" min="0.001" :name="`items[${i}][qty]`" x-model.number="it.qty" @input="recharge()" class="{{ $input }} mt-0.5 px-2 py-1"></label>
                                <label class="col-span-4 text-xs text-gray-500 md:col-span-2">{{ __('Discount') }}
                                    <input type="number" step="0.01" min="0" :name="`items[${i}][line_discount]`" x-model.number="it.line_discount" class="{{ $input }} mt-0.5 px-2 py-1"></label>
                                <p class="col-span-1 hidden border border-transparent py-2 text-right leading-5 tabular-nums md:block" x-text="money(Math.max(0, it.qty * it.price - (it.line_discount || 0)))"></p>
                                <button type="button" :disabled="items.length < 2" @click="items.splice(i, 1)"
                                    class="col-span-4 rounded-lg border border-red-200 px-2 py-2.5 text-xs font-medium text-red-600 hover:bg-red-50 disabled:cursor-not-allowed disabled:border-gray-200 disabled:text-gray-300 md:col-span-2">{{ __('Remove') }}</button>
                            </div>
                        </template>
                    </div>
                    <p x-show="items.length < 2" class="mt-2 text-xs text-gray-500">{{ __('To swap the product, add the new one first, then remove this one.') }}</p>
                    <p class="mt-2 text-xs text-gray-400">{{ __('Lines already on the order keep the price they were sold at. Totals, delivery and COD are recalculated on save.') }}</p>
                </x-card>
            </div>
            <div class="xl:col-start-3 xl:row-start-1">
                {{-- Totals as they will be after saving. --}}
                <x-card :title="__('Total')">
                    <dl class="space-y-2 text-sm" x-init="$watch('items', () => recharge(), { deep: true })">
                        <div class="flex justify-between"><dt class="text-gray-500">{{ __('Subtotal') }}</dt><dd class="tabular-nums" x-text="money(subtotal())"></dd></div>
                        <div class="flex justify-between" x-show="lineDiscounts() > 0"><dt class="text-gray-500">{{ __('Line discounts') }}</dt><dd class="tabular-nums" x-text="'−' + money(lineDiscounts())"></dd></div>
                        <div class="flex items-center justify-between gap-2"><dt class="text-gray-500">{{ __('Order discount') }}</dt>
                            <dd><input type="number" step="0.01" min="0" name="order_discount" x-model.number="orderDiscount" @input="recharge()" class="w-28 rounded-md border border-gray-300 px-2 py-1 text-right text-sm tabular-nums focus:border-primary focus:outline-none"></dd></div>
                        {{-- Delivery area: changing it updates the charge right here. --}}
                        <div class="flex items-center justify-between gap-2" @select-change.stop="zone = $event.detail; recharge()">
                            <dt class="flex min-w-0 items-center gap-2 text-gray-500">
                                <span class="shrink-0">{{ __('Delivery') }}</span>
                                @if (\App\Services\Orders\DeliveryCharges::flat())
                                    <input type="hidden" name="zone_id" value="{{ $order->zone_id }}"><span class="text-xs text-gray-400">{{ __('same everywhere') }}</span>
                                @else
                                    <x-simple-select name="zone_id" :options="$zones" :value="(string) old('zone_id', $order->zone_id ?? '')" :placeholder="__('Choose area')" size="sm" />
                                @endif
                            </dt>
                            <dd class="shrink-0 text-right tabular-nums"><span x-text="money(delivery)"></span>
                                <span class="block text-[11px] text-gray-400" x-text="@js($order->channel === 'web') && !zoneChanged ? @js(__('as sold')) : @js(__('from the delivery rules'))"></span></dd>
                        </div>
                        <div class="flex justify-between border-t border-gray-100 pt-2 text-base font-semibold"><dt>{{ __('New total') }}</dt><dd class="tabular-nums" x-text="money(total())"></dd></div>
                        <div class="flex justify-between text-xs text-gray-500"><dt>{{ __('Total before this change') }}</dt><dd class="tabular-nums">৳{{ number_format((float) $order->grand_total, 2) }}</dd></div>
                        @if ($paid > 0)
                            <div class="flex justify-between"><dt class="text-gray-500">{{ __('Advance paid') }}</dt><dd class="tabular-nums">−৳{{ number_format($paid, 2) }}</dd></div>
                        @endif
                        <div class="flex justify-between font-medium"><dt>{{ __('Cash to collect (COD)') }}</dt><dd class="tabular-nums" x-text="money(Math.max(0, total() - {{ $paid }}))"></dd></div>
                    </dl>
                    <p x-show="zoneChanged" x-cloak class="mt-2 text-xs text-amber-700">{{ __('Delivery area changed: the charge follows the new area. Check the address below.') }}</p>
                    <p x-show="discount() > {{ $discountLimit }} && discount() > {{ (float) $order->discount_total }}" x-cloak class="mt-2 text-xs text-amber-700">{{ __('Discount above ৳:n needs a manager: the change will wait for approval.', ['n' => number_format($discountLimit)]) }}</p>
                </x-card>
            </div>
            <div class="xl:col-span-2">
                <x-card :title="__('Delivery details')">
                    <div class="grid gap-x-4 md:grid-cols-2">
                        <x-form.input name="ship_name" :label="__('Name')" :value="$order->ship_name" required />
                        <x-form.input name="ship_phone" :label="__('Phone')" :value="$order->ship_phone" required inputmode="tel" />
                        <x-form.input name="ship_alt_phone" :label="__('Other phone')" :value="$order->ship_alt_phone" inputmode="tel" />
                        @php
                            $districtOptions = ['' => __('Choose district')] + array_combine(config('bd.districts'), config('bd.districts'));
                            if ($order->ship_district && ! isset($districtOptions[$order->ship_district])) {
                                $districtOptions[$order->ship_district] = $order->ship_district; // keep a spelling that is not in the list
                            }
                        @endphp
                        <div class="mb-4" @select-change.stop="district = $event.detail">
                            <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('District') }}</label>
                            <x-simple-select name="ship_district" :options="$districtOptions" :value="(string) old('ship_district', $order->ship_district ?? '')" searchable full-width class="w-full" />
                        </div>
                        <x-form.input name="ship_thana" :label="__('Thana / area')" :value="$order->ship_thana" />
                    </div>
                    <label class="mb-2 block text-sm font-medium text-gray-700" for="ship_address">{{ __('Address') }}</label>
                    <textarea id="ship_address" name="ship_address" rows="2" required maxlength="500" class="{{ $input }}">{{ old('ship_address', $order->ship_address) }}</textarea>
                    {{-- The delivery area is chosen in the Total card. Changing where it goes: check the address once more. --}}
                    <p x-show="placeChanged" x-cloak class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                        {{ __('The delivery area changed. Check the district, area and address are right before saving.') }}
                    </p>
                </x-card>
            </div>
            <div class="space-y-6 xl:col-start-3 xl:row-start-2">
                <x-card :title="__('Why the change?')">
                    <div x-ref="reason" @click="needReason = false" :class="needReason && 'rounded-lg ring-2 ring-red-500 ring-offset-2'">
                    <x-simple-select name="reason_id" :options="['' => __('Choose a reason')] + $reasons" :value="(string) old('reason_id', '')" full-width class="w-full" />
                    </div>
                    <p x-show="needReason" x-cloak class="mt-2 text-sm font-medium text-red-600">{{ __('Choose why the order is changing, then save.') }}</p>
                    @error('reason_id')<p class="mt-2 text-sm font-medium text-red-600">{{ $message }}</p>@enderror
                    <p class="mt-2 text-xs text-gray-500">{{ __('"Entry error" counts against whoever made the mistake.') }}</p>
                </x-card>
                <x-button class="lockable w-full">{{ __('Save change') }}</x-button>
            </div>
        </div>
    </form>
</x-dynamic-component>
