@php
    use App\Support\Permissions\Mask;
    $riskColor = ['normal' => 'gray', 'watch' => 'amber', 'blocked' => 'red'];
    $rate = fn ($c) => ($c->delivered_count + $c->returned_count) ? round($c->delivered_count * 100 / ($c->delivered_count + $c->returned_count)).'%' : '-';
@endphp

<x-layouts.app :heading="__('Customers')">
    <div class="mb-4 flex items-center justify-between gap-3">
        <p class="text-sm text-gray-500">{{ trans_choice(':count customer|:count customers', $customers->total(), ['count' => $customers->total()]) }}</p>
        @can('customers.create')
            <x-button :href="route('customers.create')">+ {{ __('New customer') }}</x-button>
        @endcan
    </div>

    <x-list.filter-bar :list="$list" :action="route('customers.index')" :placeholder="__('Phone number or name')">
        <x-simple-select name="risk" :options="['' => __('Any risk'), 'normal' => __('Normal'), 'watch' => __('Watch'), 'blocked' => __('Blocked')]" :value="$list->filter('risk') ?? ''" />
    </x-list.filter-bar>

    @if ($customers->isEmpty())
        <x-empty-state :message="$list->hasFilters() ? __('No customer matches.') : __('No customers yet. They are created with orders, or add one.')" />
    @else
        <x-list.table>
            <x-slot:head>
                <th><x-list.sort :list="$list" column="name">{{ __('Name') }}</x-list.sort></th>
                <th>{{ __('Phone') }}</th>
                <th><x-list.sort :list="$list" column="orders_count">{{ __('Orders') }}</x-list.sort></th>
                <th>{{ __('Delivered') }}</th>
                <th>{{ __('Risk') }}</th>
                <th><x-list.sort :list="$list" column="created_at">{{ __('Since') }}</x-list.sort></th>
            </x-slot:head>
            @foreach ($customers as $c)
                <tr class="cursor-pointer" onclick="location.href='{{ route('customers.show', $c) }}'">
                    <td class="font-medium text-gray-800">{{ $c->name }}</td>
                    <td class="font-mono text-gray-600">{{ Mask::value($c->primary_phone, 'customer_contact') }}</td>
                    <td class="tabular-nums text-gray-600">{{ $c->orders_count }}</td>
                    <td class="tabular-nums text-gray-600">{{ $rate($c) }}</td>
                    <td><x-badge :color="$riskColor[$c->risk_level]">{{ ucfirst($c->risk_level) }}</x-badge></td>
                    <td class="text-gray-500">{{ $c->created_at->format('d M Y') }}</td>
                </tr>
            @endforeach
        </x-list.table>

        <x-list.cards>
            @foreach ($customers as $c)
                <a href="{{ route('customers.show', $c) }}" class="block">
                    <x-record-card :title="$c->name" :subtitle="Mask::value($c->primary_phone, 'customer_contact')">
                        <x-slot:badge><x-badge :color="$riskColor[$c->risk_level]">{{ ucfirst($c->risk_level) }}</x-badge></x-slot:badge>
                        <x-slot:footer>{{ trans_choice(':count order|:count orders', $c->orders_count, ['count' => $c->orders_count]) }} · {{ __('delivered :r', ['r' => $rate($c)]) }}</x-slot:footer>
                    </x-record-card>
                </a>
            @endforeach
        </x-list.cards>

        <div class="mt-4">{{ $customers->links() }}</div>
    @endif
</x-layouts.app>
