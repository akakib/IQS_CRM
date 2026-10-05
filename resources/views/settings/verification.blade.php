@php
    $input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:outline-none';
    $outcomes = [
        'record_verified' => __('Record verified (still needs the call)'),
        'record_verified_and_confirmed' => __('Verified and confirmed (skip the call)'),
        'manual_review' => __('Manual review (stays New)'),
        'hold_for_advance' => __('Hold: ask for advance payment'),
    ];
    $outcomeColor = ['record_verified' => 'blue', 'record_verified_and_confirmed' => 'green', 'manual_review' => 'gray', 'hold_for_advance' => 'amber'];
    $fmt = fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : (is_array($v) ? json_encode($v) : (string) ($v ?? '-'));
@endphp

<x-layouts.app :heading="__('Verification rules')">
    <p class="mb-4 text-sm text-gray-500">{{ __('Checked on every new order, top to bottom: the first rule whose conditions all pass decides. Staff cannot skip the call by choice; only these rules can. Pair a success rate with a minimum parcel count: 1 of 1 is 100% but says little.') }}</p>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-3 xl:col-span-2">
            @foreach ($rules as $r)
                <div @class(['rounded-xl border border-gray-200 bg-white p-4', 'opacity-50' => ! $r->is_active])>
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <p class="text-sm font-semibold text-gray-800"><span class="mr-1 text-xs text-gray-400">#{{ $r->priority }}</span>{{ $r->name }}</p>
                            <p class="mt-1 text-xs text-gray-600">
                                @forelse ($conditions[$r->id] ?? [] as $c)
                                    <span class="mr-1 inline-block rounded bg-gray-100 px-1.5 py-0.5">{{ __($fields[$c->field] ?? $c->field) }}{{ $c->provider ? ' ('.$c->provider.')' : '' }} {{ $c->operator }} {{ $c->value }}</span>
                                @empty
                                    <span class="text-gray-400">{{ __('No conditions: matches everything left') }}</span>
                                @endforelse
                                @if ($r->applies_to_channel !== 'all')<span class="ml-1 text-gray-400">· {{ $r->applies_to_channel }}</span>@endif
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <x-badge :color="$outcomeColor[$r->outcome]">{{ $outcomes[$r->outcome] }}</x-badge>
                            @can('settings.edit')
                                <form method="POST" action="{{ route('settings.verification.toggle', $r->id) }}">@csrf<button class="text-xs text-primary hover:underline">{{ $r->is_active ? __('Off') : __('On') }}</button></form>
                                <form method="POST" action="{{ route('settings.verification.destroy', $r->id) }}">@csrf @method('DELETE')<button class="text-xs text-red-600 hover:underline">{{ __('Delete') }}</button></form>
                            @endcan
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="space-y-6">
            <x-card :title="__('Test on an order')">
                <form method="POST" action="{{ route('settings.verification.test') }}" class="flex gap-2">
                    @csrf
                    <input name="order_no" required placeholder="IQ10001" class="{{ $input }} font-mono">
                    <x-button size="sm">{{ __('Test') }}</x-button>
                </form>
                @if ($test)
                    <div class="mt-3 rounded-lg bg-gray-50 p-3 text-xs">
                        <p class="mb-2 text-sm"><b>{{ $test['order_no'] }}</b> → {{ $outcomes[$test['outcome']] ?? $test['outcome'] }} <span class="text-gray-500">({{ $test['rule'] }})</span></p>
                        @foreach ($test['inputs'] as $k => $v)
                            <div class="flex justify-between gap-2 border-b border-gray-100 py-0.5"><span class="text-gray-500">{{ __($fields[$k] ?? $k) }}</span><span class="font-mono">{{ $fmt($v) }}</span></div>
                        @endforeach
                    </div>
                @endif
            </x-card>

            @can('settings.edit')
                <x-card :title="__('Add a rule')" x-data="{ conds: [], provFields: {{ \Illuminate\Support\Js::from($providerFields) }} }">
                    <form method="POST" action="{{ route('settings.verification.store') }}" class="space-y-3">
                        @csrf
                        <label class="block text-xs font-medium text-gray-600">{{ __('Rule name') }}
                            <input name="name" required maxlength="150" placeholder="{{ __('e.g. Repeat customer, small order') }}" class="{{ $input }} mt-1">
                        </label>
                        <label class="block text-xs font-medium text-gray-600">{{ __('Position in the list') }}
                            <input name="priority" type="number" min="1" max="999" value="{{ old('priority', 45) }}" placeholder="45" class="{{ $input }} mt-1">
                            <span class="mt-1 block text-[11px] font-normal text-gray-400">{{ __('Rules are checked from the smallest number up; the first one that matches decides. 45 puts this rule between #40 and #50.') }}</span>
                        </label>
                        <div>
                            <p class="mb-1 text-xs font-medium text-gray-600">{{ __('What happens when it matches') }}</p>
                            <x-simple-select name="outcome" :options="$outcomes" value="record_verified" full-width class="w-full" />
                        </div>
                        <div>
                            <p class="mb-1 text-xs font-medium text-gray-600">{{ __('Which orders it applies to') }}</p>
                            <x-simple-select name="applies_to_channel" :options="['all' => __('All channels'), 'web' => __('Website'), 'messenger' => 'Messenger', 'whatsapp' => 'WhatsApp', 'phone' => __('Phone')]" value="all" full-width class="w-full" />
                        </div>
                        <p class="text-xs font-medium text-gray-600">{{ __('Conditions (all must be true). None = matches every order.') }}</p>
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
                                    <button type="button" @click="conds.splice(i, 1)" class="px-1 text-gray-400 hover:text-red-600">&times;</button>
                                </div>
                            </div>
                        </template>
                        <button type="button" @click="conds.push({ field: 'cod_amount', operator: '<=', value: '', provider_id: null })" class="text-sm font-medium text-primary hover:underline">+ {{ __('Add condition') }}</button>
                        <x-button class="w-full">{{ __('Save rule') }}</x-button>
                    </form>
                </x-card>
            @endcan
        </div>
    </div>
</x-layouts.app>
