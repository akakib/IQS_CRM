{{-- One rule form for Add and Edit. $rule = null adds; a rule row edits it with its $ruleConditions. --}}
@php
    $conds = collect($ruleConditions ?? [])->map(fn ($c) => ['field' => $c->field, 'operator' => $c->operator, 'value' => (string) $c->value, 'provider_id' => $c->provider_id])->values();
@endphp
<form method="POST" action="{{ $rule ? route('settings.verification.update', $rule->id) : route('settings.verification.store') }}" id="{{ $formId }}" class="space-y-3 text-left"
    x-data="{ conds: {{ \Illuminate\Support\Js::from($conds) }}, provFields: {{ \Illuminate\Support\Js::from($providerFields) }},
        outcome: {{ \Illuminate\Support\Js::from($rule->outcome ?? 'record_verified') }}, match: {{ \Illuminate\Support\Js::from($rule->match_mode ?? 'all') }},
        adv: {{ \Illuminate\Support\Js::from($rule->advance_type ?? 'delivery') }} }">
    @csrf
    @if ($rule) @method('PUT') @endif
    <label class="block text-xs font-medium text-gray-600">{{ __('Rule name') }}
        <input name="name" required maxlength="150" value="{{ $rule->name ?? '' }}" placeholder="{{ __('e.g. Repeat customer, small order') }}" class="{{ $input }} mt-1">
    </label>
    <label class="block text-xs font-medium text-gray-600">{{ __('Position in the list') }}
        <input name="priority" type="number" min="1" max="999" value="{{ $rule->priority ?? old('priority', 45) }}" placeholder="45" class="{{ $input }} mt-1">
        <span class="mt-1 block text-[11px] font-normal text-gray-400">{{ __('Rules are checked from the smallest number up; the first one that matches decides. 45 puts this rule between #40 and #50.') }}</span>
    </label>
    <div>
        <p class="mb-1 text-xs font-medium text-gray-600">{{ __('What happens when it matches') }}</p>
        <x-simple-select name="outcome" :options="$outcomes" :value="$rule->outcome ?? 'record_verified'" full-width class="w-full" @select-change="outcome = $event.detail" />
    </div>
    {{-- Hold for advance: how much. --}}
    <div x-show="outcome === 'hold_for_advance'" class="rounded-lg bg-amber-50 p-2">
        <p class="mb-1 text-xs font-medium text-gray-600">{{ __('Advance to ask for') }}</p>
        <input type="hidden" name="advance_type" :value="adv">
        <div class="flex flex-wrap items-center gap-1">
            @foreach (['delivery' => __('Delivery charge'), 'fixed' => __('Fixed ৳'), 'percent' => __('% of total')] as $k => $l)
                <button type="button" @click="adv = @js($k)" class="rounded-full border px-2.5 py-1 text-xs" :class="adv === @js($k) ? 'border-primary bg-primary text-white' : 'border-gray-300 bg-white text-gray-700'">{{ $l }}</button>
            @endforeach
            <input x-show="adv !== 'delivery'" :disabled="adv === 'delivery'" name="advance_value" type="number" min="1" :max="adv === 'percent' ? 100 : 1000000" step="any" value="{{ $rule->advance_value ?? '' }}"
                :placeholder="adv === 'percent' ? '20' : '300'" class="w-24 rounded border border-gray-300 px-2 py-1 text-xs" :required="outcome === 'hold_for_advance' && adv !== 'delivery'">
        </div>
    </div>
    <div>
        <p class="mb-1 text-xs font-medium text-gray-600">{{ __('Which orders it applies to') }}</p>
        <x-simple-select name="applies_to_channel" :options="['all' => __('All channels'), 'web' => __('Website'), 'messenger' => 'Messenger', 'whatsapp' => 'WhatsApp', 'phone' => __('Phone')]" :value="$rule->applies_to_channel ?? 'all'" full-width class="w-full" />
    </div>
    <div>
        <p class="mb-1 text-xs font-medium text-gray-600">{{ __('Conditions. None = matches every order.') }}</p>
        <input type="hidden" name="match_mode" :value="match">
        <div class="flex flex-wrap gap-1">
            @foreach (['all' => __('All must be true'), 'any' => __('Any one is enough')] as $k => $l)
                <button type="button" @click="match = @js($k)" class="rounded-full border px-2.5 py-1 text-xs" :class="match === @js($k) ? 'border-primary bg-primary text-white' : 'border-gray-300 text-gray-700'">{{ $l }}</button>
            @endforeach
        </div>
    </div>
    <template x-for="(c, i) in conds" :key="i">
        <div class="rounded-lg border border-gray-200 p-2">
            <div class="mb-1 flex flex-wrap gap-1">
                @foreach ($fields as $key => $label)
                    <button type="button" @click="c.field = @js($key)" class="rounded border px-1.5 py-0.5 text-[11px]" :class="c.field === @js($key) ? 'border-primary bg-primary text-white' : 'border-gray-200 text-gray-600'">{{ __($label) }}</button>
                @endforeach
            </div>
            <input type="hidden" :name="`conditions[${i}][field]`" :value="c.field">
            <div x-show="provFields.includes(c.field)" class="mb-1 flex flex-wrap gap-1">
                <input type="hidden" :name="`conditions[${i}][provider_id]`" :value="c.provider_id ?? ''">
                @foreach ($providers as $id => $name)
                    <button type="button" @click="c.provider_id = {{ $id }}" class="rounded-full border px-2 py-0.5 text-[11px]" :class="c.provider_id == {{ $id }} ? 'border-primary bg-primary text-white' : 'border-gray-300 text-gray-600'">{{ $name }}</button>
                @endforeach
            </div>
            <div class="flex gap-1">
                <input type="hidden" :name="`conditions[${i}][operator]`" :value="c.operator">
                @foreach (['>=', '<=', '=', '!=', '>', '<'] as $op)
                    <button type="button" @click="c.operator = @js($op)" class="w-8 rounded border text-xs" :class="c.operator === @js($op) ? 'border-primary bg-primary text-white' : 'border-gray-300'">{{ $op }}</button>
                @endforeach
                <input :name="`conditions[${i}][value]`" x-model="c.value" required placeholder="80 / true" class="min-w-0 flex-1 rounded border border-gray-300 px-2 text-xs">
                <button type="button" @click="conds.splice(i, 1)" class="px-1 text-gray-400 hover:text-red-600" aria-label="{{ __('Remove') }}">&times;</button>
            </div>
        </div>
    </template>
    <button type="button" @click="conds.push({ field: 'cod_amount', operator: '<=', value: '', provider_id: null })" class="text-sm font-medium text-primary hover:underline">+ {{ __('Add condition') }}</button>
    @unless ($rule)
        <x-button class="w-full">{{ __('Save rule') }}</x-button>
    @endunless
</form>
