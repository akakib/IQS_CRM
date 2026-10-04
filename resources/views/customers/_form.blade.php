@php
    $input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-green-800 focus:outline-none';
    $extraPhones = old('extra_phones', $customer->exists ? $customer->phones->where('is_primary', false)->pluck('phone')->values()->all() : []);
    $addresses = old('addresses', $customer->exists ? $customer->addresses->map->only(['id', 'address_line', 'district', 'thana', 'zone_id', 'is_default'])->values()->all() : []);
    if ($addresses === []) {
        $addresses = [['id' => null, 'address_line' => '', 'district' => '', 'thana' => '', 'zone_id' => '', 'is_default' => true]];
    }
@endphp

@csrf
<datalist id="bd-districts">@foreach ($districts as $d)<option value="{{ $d }}">@endforeach</datalist>

<div class="grid gap-6 lg:grid-cols-2">
    <x-card :title="__('Customer')">
        <x-form.input name="name" :label="__('Name')" :value="$customer->name" required maxlength="150" />
        <x-form.input name="primary_phone" :label="__('Phone')" :value="$customer->primary_phone" required inputmode="tel" placeholder="01XXXXXXXXX" />

        <div class="mb-4" x-data="{ phones: {{ \Illuminate\Support\Js::from($extraPhones) }} }">
            <p class="mb-2 text-sm font-medium text-gray-700">{{ __('Other numbers') }}</p>
            <template x-for="(p, i) in phones" :key="i">
                <div class="mb-2 flex gap-2">
                    <input :name="`extra_phones[${i}]`" x-model="phones[i]" inputmode="tel" placeholder="01XXXXXXXXX" class="{{ $input }}">
                    <button type="button" @click="phones.splice(i, 1)" class="px-2 text-gray-400 hover:text-red-600" aria-label="{{ __('Remove') }}">&times;</button>
                </div>
            </template>
            <button type="button" x-show="phones.length < 5" @click="phones.push('')" class="text-sm font-medium text-green-900 hover:underline">+ {{ __('Add number') }}</button>
            @error('extra_phones.*')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>

        <div class="grid gap-x-4 md:grid-cols-2">
            <x-form.input name="whatsapp_number" label="WhatsApp" :value="$customer->whatsapp_number" inputmode="tel" />
            <x-form.input name="messenger_psid" :label="__('Messenger ID')" :value="$customer->messenger_psid" maxlength="64" />
        </div>

        <div class="mb-4" x-data="{ risk: @js(old('risk_level', $customer->risk_level ?? 'normal')) }" @select-change="if (['normal','watch','blocked'].includes($event.detail)) risk = $event.detail">
            <p class="mb-2 text-sm font-medium text-gray-700">{{ __('Risk level') }}</p>
            <x-simple-select name="risk_level" :options="['normal' => __('Normal'), 'watch' => __('Watch'), 'blocked' => __('Blocked')]" :value="old('risk_level', $customer->risk_level ?? 'normal')" full-width class="w-full" />
            <input x-show="risk !== 'normal'" name="blocked_reason" value="{{ old('blocked_reason', $customer->blocked_reason) }}" maxlength="255" placeholder="{{ __('Reason') }}" class="{{ $input }} mt-2">
            @error('blocked_reason')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>

        <div class="mb-4">
            <label for="note" class="mb-2 block text-sm font-medium text-gray-700">{{ __('Note') }}</label>
            <textarea id="note" name="note" rows="2" maxlength="500" class="{{ $input }}">{{ old('note', $customer->note) }}</textarea>
        </div>
        <label class="flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" name="marketing_consent" value="1" @checked(old('marketing_consent', $customer->marketing_consent)) class="rounded border-gray-300 text-green-900">
            {{ __('Agreed to receive offers (marketing consent)') }}
        </label>
    </x-card>

    <x-card :title="__('Addresses')" x-data="{ rows: {{ \Illuminate\Support\Js::from($addresses) }}, def: {{ max(0, collect($addresses)->search(fn ($a) => ! empty($a['is_default']))) }} }">
        <template x-for="(a, i) in rows" :key="i">
            <div class="mb-3 rounded-lg border border-gray-200 p-3">
                <input type="hidden" :name="`addresses[${i}][id]`" :value="a.id ?? ''">
                <textarea :name="`addresses[${i}][address_line]`" x-model="a.address_line" rows="2" maxlength="500" placeholder="{{ __('House, road, area') }}" class="{{ $input }}"></textarea>
                <div class="mt-2 grid grid-cols-2 gap-2">
                    <input :name="`addresses[${i}][district]`" x-model="a.district" list="bd-districts" placeholder="{{ __('District') }}" class="{{ $input }}">
                    <input :name="`addresses[${i}][thana]`" x-model="a.thana" placeholder="{{ __('Thana / area') }}" class="{{ $input }}">
                </div>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <input type="hidden" :name="`addresses[${i}][zone_id]`" :value="a.zone_id ?? ''">
                    @foreach ($zones as $id => $zone)
                        <button type="button" @click="a.zone_id = {{ $id }}" class="rounded-full border px-2.5 py-1 text-xs"
                            :class="a.zone_id == {{ $id }} ? 'border-green-900 bg-green-900 text-white' : 'border-gray-300 text-gray-600'">{{ $zone }}</button>
                    @endforeach
                    <label class="ml-auto flex items-center gap-1 text-xs text-gray-600">
                        <input type="radio" :checked="def === i" @change="def = i" class="text-green-900"> {{ __('Default') }}
                    </label>
                    <input type="hidden" :name="`addresses[${i}][is_default]`" :value="def === i ? 1 : 0">
                    <button type="button" x-show="rows.length > 1" @click="rows.splice(i, 1); if (def >= rows.length) def = 0" class="text-xs text-red-600 hover:underline">{{ __('Remove') }}</button>
                </div>
            </div>
        </template>
        <button type="button" x-show="rows.length < 10" @click="rows.push({ id: null, address_line: '', district: '', thana: '', zone_id: '' })" class="text-sm font-medium text-green-900 hover:underline">+ {{ __('Add address') }}</button>
    </x-card>
</div>

<div class="mt-6 flex items-center gap-2">
    <x-button>{{ __('Save customer') }}</x-button>
    <x-button variant="secondary" :href="$customer->exists ? route('customers.show', $customer) : route('customers.index')">{{ __('Cancel') }}</x-button>
</div>
