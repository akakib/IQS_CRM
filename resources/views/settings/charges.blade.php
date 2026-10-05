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
                                    <form method="POST" action="{{ route('settings.charges.rules.toggle', $r->id) }}">@csrf<button class="text-xs text-primary hover:underline">{{ $r->is_active ? __('Switch off') : __('Switch on') }}</button></form>
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
                        <input name="min_weight_g" type="number" min="0" value="0" placeholder="{{ __('From g') }}" class="{{ $input }}" required>
                        <input name="max_weight_g" type="number" min="0" placeholder="{{ __('To g (empty = no limit)') }}" class="{{ $input }}">
                        <input name="min_order_total" type="number" min="0" step="0.01" value="0" placeholder="{{ __('Order total from') }}" class="{{ $input }}" required>
                        <input name="charge" type="number" min="0" step="0.01" placeholder="{{ __('Charge (0 = free)') }}" class="{{ $input }}" required>
                    </div>
                    <input name="priority" type="number" min="1" max="999" value="100" class="{{ $input }}" required aria-label="{{ __('Priority') }}">
                    @if ($errors->any())<p class="text-sm text-red-600">{{ $errors->first() }}</p>@endif
                    <x-button>{{ __('Add rule') }}</x-button>
                </form>
            </x-card>

            <x-card :title="__('Zones')">
                <ul class="mb-3 space-y-1 text-sm">@foreach ($zones as $z)<li>{{ $z->name }}</li>@endforeach</ul>
                <form method="POST" action="{{ route('settings.charges.zones.store') }}" class="flex gap-2">
                    @csrf
                    <input name="name" required maxlength="100" placeholder="{{ __('New zone name') }}" class="{{ $input }}">
                    <x-button size="sm">{{ __('Add') }}</x-button>
                </form>
            </x-card>
        </div>
    </div>
</x-layouts.app>
