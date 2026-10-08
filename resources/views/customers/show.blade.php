@php
    use App\Support\Permissions\Mask;
    $riskColor = ['normal' => 'gray', 'watch' => 'amber', 'blocked' => 'red'];
    $total = $customer->delivered_count + $customer->returned_count;
@endphp

<x-layouts.app :heading="$customer->name">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <a href="{{ route('customers.index') }}" class="text-sm text-primary hover:underline">{{ __('Back to customers') }}</a>
        <div class="flex items-center gap-2">
            @can('customers.edit')
                @if ($canSeeContact)<x-button variant="secondary" :href="route('customers.edit', $customer)">{{ __('Edit') }}</x-button>@endif
                <x-button type="button" variant="secondary" @click="$dispatch('open-modal', 'merge')">{{ __('Merge duplicate') }}</x-button>
            @endcan
        </div>
    </div>

    <div class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-4">
        <x-stat-tile :label="__('Orders')" :value="$customer->orders_count" />
        <x-stat-tile :label="__('Delivered')" :value="$customer->delivered_count" />
        <x-stat-tile :label="__('Returned')" :value="$customer->returned_count" :trend="$customer->returned_count ? 'down' : null" :hint="$total ? round($customer->delivered_count * 100 / $total).'% '.__('success') : __('New customer')" />
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('Risk') }}</p>
            <div class="mt-2"><x-badge :color="$riskColor[$customer->risk_level]">{{ ucfirst($customer->risk_level) }}</x-badge></div>
            @if ($customer->blocked_reason)<p class="mt-1 text-xs text-gray-500">{{ $customer->blocked_reason }}</p>@endif
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-card :title="__('Contact')">
            <dl class="space-y-2 text-sm">
                @foreach ($customer->phones as $p)
                    <div class="flex justify-between"><dt class="text-gray-500">{{ $p->is_primary ? __('Primary phone') : __('Other phone') }}</dt><dd class="font-mono">{{ Mask::value($p->phone, 'customer_contact') }}</dd></div>
                @endforeach
                @if ($customer->whatsapp_number)
                    <div class="flex justify-between"><dt class="text-gray-500">WhatsApp</dt><dd class="font-mono">{{ Mask::value($customer->whatsapp_number, 'customer_contact') }}</dd></div>
                @endif
                <div class="flex justify-between"><dt class="text-gray-500">{{ __('Marketing consent') }}</dt><dd>{{ $customer->marketing_consent ? __('Yes, since :d', ['d' => $customer->consent_at?->format('d M Y')]) : __('No') }}</dd></div>
                @if ($customer->note)<div><dt class="text-gray-500">{{ __('Note') }}</dt><dd class="mt-1 text-gray-700">{{ $customer->note }}</dd></div>@endif
            </dl>
            <p class="mb-2 mt-5 text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Addresses') }}</p>
            @forelse ($customer->addresses as $a)
                <div class="mb-2 rounded-lg bg-gray-50 p-3 text-sm">
                    @if ($canSeeContact){{ $a->oneLine() }}@else<span class="text-gray-400">{{ __('Hidden for your role') }}</span>@endif
                    <span class="ml-1 text-xs text-gray-500">{{ $a->zone?->name }}{{ $a->is_default ? ' · '.__('default') : '' }}</span>
                </div>
            @empty
                <p class="text-sm text-gray-400">{{ __('No address yet.') }}</p>
            @endforelse
        </x-card>

        <x-card :title="__('Delivery history (fraud check)')">
            <x-slot:actions>
                @can('customers.view')
                    <form method="POST" action="{{ route('customers.fraud-check', $customer) }}">@csrf<x-button size="sm" variant="secondary">{{ __('Check now') }}</x-button></form>
                @endcan
            </x-slot:actions>
            @forelse ($latest as $check)
                <div class="mb-3 flex items-center justify-between rounded-lg border border-gray-100 p-3">
                    <div>
                        <p class="text-sm font-medium text-gray-800">{{ $check->provider->name }}</p>
                        <p class="text-xs text-gray-500">{{ __(':t parcels · :d delivered · :c cancelled', ['t' => $check->total_parcels ?? 0, 'd' => $check->delivered ?? 0, 'c' => $check->cancelled ?? 0]) }} · {{ $check->checked_at->diffForHumans() }}</p>
                    </div>
                    @php($rate = $check->success_rate)
                    <x-badge :color="$rate === null ? 'gray' : ($rate >= 80 ? 'green' : ($rate >= 50 ? 'amber' : 'red'))">{{ $rate === null ? __('No history') : rtrim(rtrim($rate, '0'), '.').'%' }}</x-badge>
                </div>
            @empty
                <p class="text-sm text-gray-400">{{ __('Not checked yet.') }}</p>
            @endforelse
            <p class="mt-2 text-xs text-gray-400">{{ __('A high rate with very few parcels says little; rules pair the rate with a minimum parcel count.') }}</p>
        </x-card>
    </div>

    @can('customers.edit')
        <x-modal id="merge" :title="__('Merge a duplicate into :name', ['name' => $customer->name])" persistent>
            <form method="POST" action="{{ route('customers.merge', $customer) }}" id="merge-form">
                @csrf
                <p class="mb-3 text-sm text-gray-600">{{ __('Enter any phone number of the duplicate customer. Their numbers, addresses, history and orders move here; the duplicate is removed.') }}</p>
                <input name="duplicate_phone" required inputmode="tel" placeholder="01XXXXXXXXX" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:outline-none">
            </form>
            <x-slot:footer>
                <x-button type="button" variant="secondary" @click="$dispatch('close-modal', 'merge')">{{ __('Cancel') }}</x-button>
                <x-button type="button" @click="document.getElementById('merge-form').reportValidity() && $dispatch('open-confirm', { id: 'merge-confirm', form: 'merge-form', label: document.querySelector('#merge-form [name=duplicate_phone]').value })">{{ __('Merge') }}</x-button>
            </x-slot:footer>
        </x-modal>
        <x-confirm-modal id="merge-confirm" :verb="__('Merge')" :message="__('The other customer is removed for good; everything moves here.')" />
    @endcan
</x-layouts.app>
