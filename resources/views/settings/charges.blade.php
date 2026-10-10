@php($input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:outline-none')

<x-layouts.app :heading="__('Delivery charges')">
    <p class="mb-4 text-sm text-gray-500">{{ __('Staff never type the delivery charge. By area: an order takes the rule whose area (or Any zone), parcel weight and "order total from" all fit it. If more than one fits, the lower priority number wins; at the same priority a free-delivery rule (a higher order total) wins over the normal charge.') }}</p>

    @php($flat = \App\Services\Orders\DeliveryCharges::flat())
    <x-card :title="__('How delivery is charged')" class="mb-6">
        <form method="POST" action="{{ route('settings.charges.mode') }}" x-data="{ mode: @js($flat ? 'flat' : 'area') }" class="space-y-3">
            @csrf
            <input type="hidden" name="mode" :value="mode">
            <div class="grid gap-2 sm:grid-cols-2">
                <button type="button" @click="mode = 'area'" class="rounded-xl border-2 p-4 text-left" :class="mode === 'area' ? 'border-primary bg-primary-soft' : 'border-gray-200 hover:bg-gray-50'">
                    <p class="text-sm font-semibold text-gray-900">{{ __('By delivery area') }}</p>
                    <p class="mt-0.5 text-xs text-gray-500">{{ __('Inside Dhaka, outside Dhaka and so on, with the rules below (weight, free above an order total).') }}</p>
                </button>
                <button type="button" @click="mode = 'flat'" class="rounded-xl border-2 p-4 text-left" :class="mode === 'flat' ? 'border-primary bg-primary-soft' : 'border-gray-200 hover:bg-gray-50'">
                    <p class="text-sm font-semibold text-gray-900">{{ __('One charge for the whole country') }}</p>
                    <p class="mt-0.5 text-xs text-gray-500">{{ __('Same charge everywhere. Order forms stop asking for the delivery area.') }}</p>
                </button>
            </div>
            <div x-show="mode === 'flat'" x-cloak class="flex flex-wrap items-center gap-2">
                <label for="flat_charge" class="text-sm text-gray-700">{{ __('Charge') }} ৳</label>
                <input id="flat_charge" name="flat_charge" type="number" min="0" step="1" value="{{ old('flat_charge', (float) settings('delivery.flat_charge')) }}" class="w-32 rounded-lg border border-gray-300 px-3 py-2 text-sm tabular-nums focus:border-primary focus:outline-none">
            </div>
            @error('flat_charge')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            <x-button>{{ __('Save') }}</x-button>
        </form>
    </x-card>

    @if ($flat)
        <p class="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">{{ __('Not in use while one charge applies to the whole country. Kept for when you switch back.') }}</p>
    @endif
    <div @class(['grid gap-6 xl:grid-cols-3', 'opacity-50' => $flat])>
        <x-card :title="__('Rules')" class="min-w-0 xl:col-span-2">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide text-gray-500"><tr>
                        <th class="py-2 pr-3">{{ __('Zone') }}</th><th class="py-2 pr-3">{{ __('Weight') }}</th><th class="py-2 pr-3">{{ __('Order total from') }}</th><th class="py-2 pr-3 text-right">{{ __('Charge') }}</th><th class="py-2 pr-3">{{ __('Priority') }}</th><th></th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($rules as $r)
                            <tr @class(['opacity-40' => ! $r->is_active])>
                                <td class="py-2 pr-3">{{ $r->zone ?? __('Any zone') }}</td>
                                <td class="py-2 pr-3 tabular-nums">{{ number_format($r->min_weight_g) }}–{{ $r->max_weight_g ? number_format($r->max_weight_g).' g' : '∞' }}</td>
                                <td class="py-2 pr-3 tabular-nums">৳{{ number_format((float) $r->min_order_total) }}</td>
                                <td class="py-2 pr-3 text-right font-medium tabular-nums">{{ (float) $r->charge ? '৳'.number_format((float) $r->charge) : __('Free') }}</td>
                                <td class="py-2 pr-3">{{ $r->priority }}</td>
                                <td class="py-2 text-right">
                                    <span class="inline-flex items-center gap-3">
                                    <form method="POST" action="{{ route('settings.charges.rules.toggle', $r->id) }}">@csrf<button class="text-xs text-primary hover:underline">{{ $r->is_active ? __('Switch off') : __('Switch on') }}</button></form>
                                    @can('settings.edit')
                                        <button type="button" @click="$dispatch('open-modal', 'rule-{{ $r->id }}')" class="text-xs text-primary hover:underline">{{ __('Edit') }}</button>
                                        <form method="POST" action="{{ route('settings.charges.rules.destroy', $r->id) }}" id="rule-del-{{ $r->id }}">@csrf @method('DELETE')
                                            <button type="button" @click="$dispatch('open-confirm', { id: 'charge-rule-delete', form: 'rule-del-{{ $r->id }}', label: @js($r->zone ?? __('Any zone')) })" class="text-xs text-red-600 hover:underline">{{ __('Delete') }}</button></form>
                                    @endcan
                                    </span>
                                    @can('settings.edit')
                                    <x-modal :id="'rule-'.$r->id" :title="__('Edit charge rule')" persistent>
                                        <form method="POST" action="{{ route('settings.charges.rules.update', $r->id) }}" id="rule-form-{{ $r->id }}" class="grid gap-3 text-left sm:grid-cols-2">@csrf @method('PUT')
                                            <div class="sm:col-span-2"><p class="mb-1 text-sm font-medium text-gray-700">{{ __('Zone') }}</p><x-simple-select name="zone_id" :options="['' => __('Any zone')] + $zones->pluck('name', 'id')->all()" :value="(string) ($r->zone_id ?? '')" full-width class="w-full" /></div>
                                            <label class="text-sm text-gray-700">{{ __('From weight (g)') }}<input name="min_weight_g" type="number" min="0" value="{{ $r->min_weight_g }}" required class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-1 text-sm focus:border-primary focus:outline-none"></label>
                                            <label class="text-sm text-gray-700">{{ __('Up to weight (g), empty = no limit') }}<input name="max_weight_g" type="number" min="0" value="{{ $r->max_weight_g }}" class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-1 text-sm focus:border-primary focus:outline-none"></label>
                                            <label class="text-sm text-gray-700">{{ __('From order total (৳)') }}<input name="min_order_total" type="number" min="0" step="0.01" value="{{ $r->min_order_total + 0 }}" required class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-1 text-sm focus:border-primary focus:outline-none"></label>
                                            <label class="text-sm text-gray-700">{{ __('Charge (৳)') }}<input name="charge" type="number" min="0" step="0.01" value="{{ $r->charge + 0 }}" required class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-1 text-sm focus:border-primary focus:outline-none"></label>
                                            <label class="text-sm text-gray-700">{{ __('Priority (lower wins)') }}<input name="priority" type="number" min="1" max="999" value="{{ $r->priority }}" required class="mt-1 w-full rounded-lg border border-gray-300 px-2 py-1 text-sm focus:border-primary focus:outline-none"></label>
                                        </form>
                                        <x-slot:footer><x-button type="button" variant="secondary" @click="$dispatch('close-modal', 'rule-{{ $r->id }}')">{{ __('Cancel') }}</x-button><x-button type="submit" form="rule-form-{{ $r->id }}">{{ __('Save') }}</x-button></x-slot:footer>
                                    </x-modal>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>

        <div class="space-y-6">
            <x-card :title="__('Add a rule')">
                <form method="POST" action="{{ route('settings.charges.rules.store') }}" class="space-y-2">
                    @csrf
                    <x-simple-select name="zone_id" :options="['' => __('Any zone')] + $zones->pluck('name', 'id')->all()" value="" full-width class="w-full" />
                    <div class="grid grid-cols-2 gap-2">
                        <label class="text-xs font-medium text-gray-600">{{ __('From weight (g)') }}<input name="min_weight_g" type="number" min="0" value="0" class="mt-1 w-full {{ $input }}" required></label>
                        <label class="text-xs font-medium text-gray-600">{{ __('Up to weight (g)') }}<input name="max_weight_g" type="number" min="0" placeholder="{{ __('No limit') }}" class="mt-1 w-full {{ $input }}"></label>
                        <label class="text-xs font-medium text-gray-600">{{ __('From order total (৳)') }}<input name="min_order_total" type="number" min="0" step="0.01" value="0" class="mt-1 w-full {{ $input }}" required></label>
                        <label class="text-xs font-medium text-gray-600">{{ __('Charge (৳), 0 = free') }}<input name="charge" type="number" min="0" step="0.01" class="mt-1 w-full {{ $input }}" required></label>
                    </div>
                    <label class="block text-xs font-medium text-gray-600">{{ __('Priority (lower wins)') }}<input name="priority" type="number" min="1" max="999" value="100" class="mt-1 w-full {{ $input }}" required></label>
                    @if ($errors->any())<p class="text-sm text-red-600">{{ $errors->first() }}</p>@endif
                    <x-button>{{ __('Add rule') }}</x-button>
                </form>
            </x-card>

            <x-card :title="__('Zones')">
                <ul class="mb-3 space-y-1 text-sm">@foreach ($zones as $z)<li x-data="{ edit: false }">
                    <span x-show="! edit">{{ $z->name }} @can('settings.edit')<button type="button" @click="edit = true" class="ml-1 text-xs text-primary hover:underline">{{ __('Rename') }}</button>@endcan</span>
                    @can('settings.edit')<form x-show="edit" x-cloak method="POST" action="{{ route('settings.charges.zones.update', $z->id) }}" class="flex items-center gap-1.5">@csrf @method('PUT')
                        <input name="name" value="{{ $z->name }}" maxlength="100" required class="min-w-0 flex-1 rounded-lg border border-gray-300 px-2 py-1 text-sm focus:border-primary focus:outline-none">
                        <button class="text-xs font-medium text-primary hover:underline">{{ __('Save') }}</button><button type="button" @click="edit = false" class="text-xs text-gray-500">{{ __('Cancel') }}</button></form>@endcan
                </li>@endforeach</ul>
                <form method="POST" action="{{ route('settings.charges.zones.store') }}" class="flex gap-2">
                    @csrf
                    <input name="name" required maxlength="100" placeholder="{{ __('New zone name') }}" class="{{ $input }}">
                    <x-button size="sm">{{ __('Add') }}</x-button>
                </form>
            </x-card>
        </div>
    </div>
    <x-confirm-modal id="charge-rule-delete" :verb="__('Delete the rule for')" :message="__('Orders already made keep their charge.')" />
</x-layouts.app>
