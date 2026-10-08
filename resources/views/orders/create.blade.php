@php
    $input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:outline-none';
@endphp

{{-- embed=1: the form sits in the New order popup from Communication, so no sidebar or header. --}}
<x-dynamic-component :component="request('embed') ? 'layouts.embed' : 'layouts.app'" :heading="__('Quick order')">
    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
            @foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('orders.store') }}" x-ref="form"
        x-data="{
            channel: @js(old('channel', $chatChannel?->orderChannel() ?? (in_array(request('channel'), ['messenger', 'whatsapp', 'phone', 'b2b'], true) ? request('channel') : 'messenger'))),
            chatChannel: @js((string) old('chat_channel_id', $chatChannel?->id ?? '')),
            phone: @js(old('phone', '')), name: @js(old('name', '')),
            customer: null, looking: false,
            addressId: @js(old('customer_address_id')), zoneId: @js(old('zone_id')),
            items: [], q: '', results: [], searching: false, active: 0, timer: null,
            orderDiscount: @js((float) old('order_discount', 0)), delivery: 0, chargeTimer: null,
            payMethod: @js(old('advance.method_id')), payAmount: @js(old('advance.amount')),
            sf: null, sfLoading: false, nameAuto: false, addrAuto: false,
            {{-- Bangla digits, +880 / 880, spaces and dashes: one shape, 01XXXXXXXXX. --}}
            normalizePhone(v) {
                let d = String(v || '').replace(/[০-৯]/g, c => String('০১২৩৪৫৬৭৮৯'.indexOf(c))).replace(/\D/g, '');
                if (d.length === 13 && d.startsWith('880')) d = d.slice(2);
                if (d.length === 10 && d.startsWith('1')) d = '0' + d;
                return d;
            },
            async lookup() {
                const phone = this.normalizePhone(this.phone);
                if (!/^01[3-9]\d{8}$/.test(phone)) { this.customer = null; this.sf = null; return; }
                if (this.phone !== phone) this.phone = phone;
                this.looking = true;
                try {
                    const r = await fetch(@js(route('customers.lookup')) + '?phone=' + phone, { headers: { Accept: 'application/json' } });
                    const d = await r.json();
                    this.customer = d.found ? d : null;
                    {{-- The saved name and address fill in (still editable); another number replaces what was filled, never what was typed. --}}
                    if (d.found) {
                        if (!this.name || this.nameAuto) { this.name = d.name; this.nameAuto = true; }
                        const def = d.addresses.find(a => a.is_default) || d.addresses[0];
                        if (def && (!this.addressId || this.addrAuto)) { this.addressId = def.id; this.zoneId = def.zone_id; this.addrAuto = true; }
                    } else {
                        if (this.nameAuto) { this.name = ''; this.nameAuto = false; }
                        if (this.addrAuto) { this.addressId = null; this.addrAuto = false; }
                    }
                } catch (e) {}
                this.looking = false; this.recharge();
                this.steadfast(phone);
            },
            async steadfast(phone) {
                this.sfLoading = true; this.sf = null;
                try { this.sf = await (await fetch(@js(route('customers.steadfast')) + '?phone=' + phone, { headers: { Accept: 'application/json' } })).json() } catch (e) {}
                this.sfLoading = false;
            },
            search() {
                clearTimeout(this.timer);
                if (this.q.trim().length < 2) { this.results = []; return; }
                this.timer = setTimeout(async () => {
                    this.searching = true;
                    const r = await fetch(@js(route('products.search')) + '?q=' + encodeURIComponent(this.q), { headers: { Accept: 'application/json' } });
                    this.results = r.ok ? await r.json() : []; this.active = 0; this.searching = false;
                }, 250);
            },
            add(p) {
                if (!p || !p.sellable) return;
                const existing = this.items.find(i => i.variant_id === p.value);
                if (existing) existing.qty = Number(existing.qty) + 1;
                else this.items.push({ variant_id: p.value, label: p.label, sub: p.sub, price: p.price || 0, unit: p.unit, weight_g: p.weight_g, qty: ({ g: 500, ml: 250 })[p.unit] || 1, line_discount: 0, preorder: p.availability === 'backorder' });
                this.q = ''; this.results = []; this.$refs.search.focus(); this.recharge();
            },
            lineTotal(i) { return Math.max(0, i.qty * i.price - (Number(i.line_discount) || 0)); },
            weight() { const per = { g: 1, kg: 1000, ml: 1, l: 1000 }; return this.items.reduce((s, i) => s + (per[i.unit] ? Number(i.qty) * per[i.unit] : i.weight_g * i.qty), 0); },
            subtotal() { return this.items.reduce((s, i) => s + i.qty * i.price, 0); },
            discount() { return this.items.reduce((s, i) => s + (Number(i.line_discount) || 0), 0) + (Number(this.orderDiscount) || 0); },
            total() { return Math.max(0, this.subtotal() - this.discount()) + this.delivery; },
            recharge() {
                clearTimeout(this.chargeTimer);
                this.chargeTimer = setTimeout(async () => {
                    const zone = this.addressId && this.customer ? (this.customer.addresses.find(a => a.id == this.addressId)?.zone_id ?? this.zoneId) : this.zoneId;
                    const url = @js(route('orders.delivery-charge')) + `?zone_id=${zone || ''}&weight_g=${Math.round(this.weight())}&total=${Math.max(0, this.subtotal() - this.discount())}`;
                    try { this.delivery = (await (await fetch(url, { headers: { Accept: 'application/json' } })).json()).charge; } catch (e) {}
                }, 300);
            },
            money(n) { return '৳' + Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 }); },
        }">
        @csrf
        @if (request('embed'))<input type="hidden" name="embed" value="1">@endif
        <input type="hidden" name="channel" :value="channel">
        <input type="hidden" name="chat_channel_id" :value="chatChannel">

        <div class="grid gap-6 xl:grid-cols-3">
            <div class="space-y-6 xl:col-span-2">
                <x-card :title="__('Customer')">
                    <div class="mb-4 flex flex-wrap gap-2">
                        @foreach (['messenger' => 'Messenger', 'whatsapp' => 'WhatsApp', 'phone' => __('Phone call'), 'b2b' => 'B2B'] as $key => $label)
                            <button type="button" @click="channel = @js($key)" class="rounded-full border px-3 py-1.5 text-sm"
                                :class="channel === @js($key) ? 'border-primary bg-primary text-white' : 'border-gray-300 text-gray-600'">{{ $label }}</button>
                        @endforeach
                    </div>
                    {{-- Which number or page the customer wrote to: counted per channel in reports. --}}
                    @if ($chatChannels->isNotEmpty())
                        <div class="mb-4">
                            <p class="mb-1 text-sm font-medium text-gray-700">{{ __('From which chat') }}</p>
                            <x-simple-select :options="['' => __('Not from a chat')] + $chatChannels->mapWithKeys(fn ($c) => [(string) $c->id => $c->name])->all()"
                                :value="(string) old('chat_channel_id', $chatChannel?->id ?? '')" full-width class="w-full sm:w-72"
                                @select-change="chatChannel = $event.detail; const t = @js($chatChannels->mapWithKeys(fn ($c) => [(string) $c->id => $c->orderChannel()])->all())[$event.detail]; if (t) channel = t" />
                            @error('chat_channel_id')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                    @endif
                    <div class="grid gap-3 md:grid-cols-2">
                        <label class="text-sm font-medium text-gray-700">{{ __('Phone') }}
                            <input name="phone" x-model="phone" @input.debounce.400ms="lookup()" required inputmode="tel" placeholder="01XXXXXXXXX" class="{{ $input }} mt-1" autofocus>
                        </label>
                        <label class="text-sm font-medium text-gray-700">{{ __('Name') }}
                            <input name="name" x-model="name" @input="nameAuto = false" required maxlength="150" class="{{ $input }} mt-1">
                        </label>
                    </div>
                    <p x-show="looking" class="mt-2 text-xs text-gray-400">{{ __('Looking up…') }}</p>
                    {{-- Steadfast record of this number (new customers too): D delivered in green, C cancelled in red. --}}
                    <p x-show="sfLoading || sf" x-cloak class="mt-2 text-sm">
                        <span class="text-gray-500">Steadfast:</span>
                        <span x-show="sfLoading" class="text-gray-400">{{ __('checking…') }}</span>
                        <template x-if="sf && !sfLoading">
                            <span class="tabular-nums">
                                <template x-if="sf.found && sf.has_history"><span><b class="text-green-700" x-text="'D ' + sf.delivered + '%'"></b><template x-if="sf.cancelled !== null"><span> · <b class="text-red-600" x-text="'C ' + sf.cancelled + '%'"></b></span></template> · <span x-text="sf.parcels + ' ' + @js(__('parcels'))"></span></span></template>
                                <template x-if="sf.found && !sf.has_history"><span class="text-gray-600">{{ __('no history (new to couriers)') }}</span></template>
                                <template x-if="!sf.found"><span class="text-gray-400" x-text="sf.error || @js(__('not available'))"></span></template>
                            </span>
                        </template>
                    </p>
                    <div x-show="customer" x-cloak class="mt-3 rounded-lg bg-gray-50 p-3 text-sm">
                        <p><span class="font-medium" x-text="customer?.name"></span>
                            <span class="ml-2 text-xs text-gray-500" x-text="`${customer?.orders} orders · ${customer?.delivered} delivered · ${customer?.returned} returned`"></span>
                            <span x-show="customer?.risk_level !== 'normal'" class="ml-2 rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700" x-text="customer?.risk_level"></span>
                        </p>
                    </div>
                </x-card>

                <x-card :title="__('Delivery address')">
                    <template x-if="customer && customer.addresses.length">
                        <div class="mb-3 space-y-2">
                            <template x-for="a in customer.addresses" :key="a.id">
                                <label class="flex cursor-pointer items-start gap-2 rounded-lg border p-2 text-sm" :class="addressId == a.id ? 'border-primary bg-primary-soft' : 'border-gray-200'">
                                    <input type="radio" :value="a.id" x-model="addressId" @change="zoneId = a.zone_id; addrAuto = false; recharge()" class="mt-0.5 text-primary">
                                    <span x-text="a.line"></span>
                                </label>
                            </template>
                            <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-dashed border-gray-300 p-2 text-sm">
                                <input type="radio" value="" x-model="addressId" @change="addrAuto = false" class="text-primary"> {{ __('New address') }}
                            </label>
                        </div>
                    </template>
                    <input type="hidden" name="customer_address_id" :value="addressId || ''">
                    <div x-show="!addressId" class="space-y-2">
                        <textarea name="address_line" rows="2" maxlength="500" placeholder="{{ __('House, road, area') }}" class="{{ $input }}">{{ old('address_line') }}</textarea>
                        <div class="grid grid-cols-2 gap-2">
                            <input name="district" list="bd-districts" value="{{ old('district') }}" placeholder="{{ __('District') }}" class="{{ $input }}">
                            <input name="thana" value="{{ old('thana') }}" placeholder="{{ __('Thana / area') }}" class="{{ $input }}">
                        </div>
                        <datalist id="bd-districts">@foreach ($districts as $d)<option value="{{ $d }}">@endforeach</datalist>
                        <input type="hidden" name="zone_id" :value="zoneId || ''">
                        <div @class(['flex flex-wrap gap-2', 'hidden' => \App\Services\Orders\DeliveryCharges::flat()])>
                            @foreach ($zones as $id => $zone)
                                <button type="button" @click="zoneId = {{ $id }}; recharge()" class="rounded-full border px-2.5 py-1 text-xs"
                                    :class="zoneId == {{ $id }} ? 'border-primary bg-primary text-white' : 'border-gray-300 text-gray-600'">{{ $zone }}</button>
                            @endforeach
                        </div>
                    </div>
                </x-card>

                <x-card :title="__('Products')">
                    <div class="relative mb-4" @click.outside="results = []">
                        <input x-ref="search" x-model="q" @input="search()" type="search" placeholder="{{ __('Search product, SKU or barcode') }}"
                            @keydown.arrow-down.prevent="active = Math.min(active + 1, results.length - 1)" @keydown.arrow-up.prevent="active = Math.max(active - 1, 0)"
                            @keydown.enter.prevent="add(results[active])" class="{{ $input }}">
                        <div x-show="results.length || searching" x-cloak class="absolute z-30 mt-1 w-full rounded-lg border border-gray-200 bg-white py-1 shadow-lg">
                            <p x-show="searching" class="px-3 py-2 text-xs text-gray-400">{{ __('Searching…') }}</p>
                            <template x-for="(p, i) in results" :key="p.value">
                                <button type="button" @click="add(p)" @mouseenter="active = i" :disabled="!p.sellable"
                                    class="block w-full px-3 py-1.5 text-left text-sm disabled:cursor-not-allowed disabled:opacity-50" :class="i === active ? 'bg-green-50' : ''">
                                    <span x-text="p.label"></span><span class="block text-xs text-gray-400" x-text="p.sub"></span>
                                </button>
                            </template>
                        </div>
                    </div>

                    <p x-show="!items.length" class="text-sm text-gray-400">{{ __('No products yet. Search above and press Enter.') }}</p>
                    <div class="space-y-2">
                        <template x-for="(it, i) in items" :key="it.variant_id">
                            <div class="grid grid-cols-12 items-end gap-2 rounded-lg border border-gray-100 p-2 text-sm">
                                <input type="hidden" :name="`items[${i}][variant_id]`" :value="it.variant_id">
                                <div class="col-span-12 self-center md:col-span-5">
                                    <p class="font-medium text-gray-800" x-text="it.label"></p>
                                    <p class="text-xs" :class="it.preorder ? 'text-amber-700' : 'text-gray-400'" x-text="it.preorder ? @js(__('Pre-order: order will wait on Hold')) : it.sub"></p>
                                </div>
                                <label class="col-span-4 text-xs text-gray-500 md:col-span-2"><span x-text="({ g: @js(__('Grams')), kg: @js(__('KG')), ml: @js(__('ML')), l: @js(__('Litres')), packet: @js(__('Packets')), box: @js(__('Boxes')) })[it.unit] || @js(__('Qty'))"></span>
                                    <input type="number" step="any" min="0.001" :name="`items[${i}][qty]`" x-model.number="it.qty" @input="recharge()" class="{{ $input }} mt-0.5 px-2 py-1">
                                </label>
                                <label class="col-span-4 text-xs text-gray-500 md:col-span-2">{{ __('Discount') }}
                                    <input type="number" step="0.01" min="0" :name="`items[${i}][line_discount]`" x-model.number="it.line_discount" @input="recharge()" class="{{ $input }} mt-0.5 px-2 py-1">
                                </label>
                                <p class="col-span-3 border border-transparent py-2 text-right leading-5 tabular-nums md:col-span-2" x-text="money(lineTotal(it))"></p>
                                <button type="button" @click="items.splice(i, 1); recharge()" class="col-span-1 border border-transparent py-2 leading-5 text-gray-400 hover:text-red-600" aria-label="{{ __('Remove') }}">&times;</button>
                            </div>
                        </template>
                    </div>
                </x-card>
            </div>

            <div class="space-y-6">
                <x-card :title="__('Totals')">
                    <dl class="space-y-1.5 text-sm">
                        <div class="flex justify-between"><dt class="text-gray-500">{{ __('Subtotal') }}</dt><dd class="tabular-nums" x-text="money(subtotal())"></dd></div>
                        <div class="flex items-center justify-between gap-2"><dt class="text-gray-500">{{ __('Order discount') }}</dt>
                            <dd><input type="number" step="0.01" min="0" name="order_discount" x-model.number="orderDiscount" @input="recharge()" class="w-24 rounded-md border border-gray-300 px-2 py-1 text-right text-sm"></dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">{{ __('Delivery (from rules)') }}</dt><dd class="tabular-nums" x-text="money(delivery)"></dd></div>
                        <div class="flex justify-between border-t border-gray-100 pt-2 text-base font-semibold"><dt>{{ __('Total') }}</dt><dd class="tabular-nums" x-text="money(total())"></dd></div>
                    </dl>
                    <p x-show="discount() > {{ $discountLimit }}" class="mt-2 text-xs text-amber-700">{{ __('Discount above ৳:n needs a manager.', ['n' => $discountLimit]) }}</p>
                </x-card>

                <x-card :title="__('Advance payment (optional)')">
                    <input type="hidden" name="advance[method_id]" :value="payMethod || ''">
                    <div class="mb-3 flex flex-wrap gap-2">
                        @foreach ($methods as $m)
                            <button type="button" @click="payMethod = payMethod == {{ $m->id }} ? null : {{ $m->id }}" class="rounded-full border px-2.5 py-1 text-xs"
                                :class="payMethod == {{ $m->id }} ? 'border-primary bg-primary text-white' : 'border-gray-300 text-gray-600'">{{ $m->name }}</button>
                        @endforeach
                    </div>
                    <div x-show="payMethod" class="space-y-2">
                        <input type="number" step="0.01" min="0" name="advance[amount]" x-model="payAmount" placeholder="{{ __('Amount') }}" class="{{ $input }}">
                        <input name="advance[transaction_id]" value="{{ old('advance.transaction_id') }}" placeholder="{{ __('Transaction ID') }}" class="{{ $input }} font-mono">
                        <input name="advance[sender_number]" value="{{ old('advance.sender_number') }}" inputmode="tel" placeholder="{{ __('Sender number') }}" class="{{ $input }}">
                        <p class="text-xs text-gray-500">{{ __('COD drops only after a manager verifies the payment.') }}</p>
                    </div>
                </x-card>

                <x-card :title="__('Note for the order')">
                    <textarea name="customer_note" rows="2" maxlength="500" class="{{ $input }}">{{ old('customer_note') }}</textarea>
                </x-card>

                <x-button class="w-full" ::disabled="!items.length">{{ __('Create order') }}</x-button>
            </div>
        </div>
    </form>
</x-dynamic-component>
