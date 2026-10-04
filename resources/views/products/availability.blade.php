@php
    $tabs = [
        'out_of_stock' => [__('Out of stock'), request()->fullUrlWithQuery(['status' => 'out_of_stock', 'page' => null]), $counts['out_of_stock'] ?? 0],
        'backorder' => [__('Pre-order'), request()->fullUrlWithQuery(['status' => 'backorder', 'page' => null]), $counts['backorder'] ?? 0],
        'review' => [__('Review due'), request()->fullUrlWithQuery(['status' => 'review', 'page' => null]), $reviewDue],
        'in_stock' => [__('In stock'), request()->fullUrlWithQuery(['status' => 'in_stock', 'page' => null]), $counts['in_stock'] ?? 0],
    ];
    $color = ['in_stock' => 'green', 'backorder' => 'amber', 'out_of_stock' => 'red'];
@endphp

<x-layouts.app :heading="__('Stock status')">
    <x-products.subnav active="availability" />

    <p class="mb-3 text-sm text-gray-500">{{ __('Out of stock hides the item from order entry and the website. Pre-order stays sellable (the website shows it as in stock) and orders wait on Hold until stock arrives.') }}</p>

    <x-tabs :tabs="$tabs" :active="$status" />

    <x-list.filter-bar :list="$list" :action="route('products.availability')" :placeholder="__('Search name or SKU')">
        <input type="hidden" name="status" value="{{ $status }}">
    </x-list.filter-bar>

    @if ($variants->isEmpty())
        <x-empty-state :message="__('Nothing here.')" />
    @else
        <x-list.selectable :ids="$variants->pluck('id')">
            <x-list.table>
                <x-slot:head>
                    <th class="w-8"></th>
                    <th>{{ __('Product') }}</th>
                    <th>SKU</th>
                    <th>{{ __('Status') }}</th>
                    <th><x-list.sort :list="$list" column="expected_restock_date">{{ __('Expected') }}</x-list.sort></th>
                    <th><x-list.sort :list="$list" column="oos_marked_at">{{ __('Marked') }}</x-list.sort></th>
                </x-slot:head>
                @foreach ($variants as $v)
                    <tr>
                        <td><x-list.check :id="$v->id" /></td>
                        <td class="font-medium text-gray-800">{{ $v->product?->name }}{{ $v->name !== 'Default' ? ' · '.$v->name : '' }}</td>
                        <td class="font-mono text-xs text-gray-600">{{ $v->sku }}</td>
                        <td><x-badge :color="$color[$v->availability_status]">{{ $v->availabilityLabel() }}</x-badge></td>
                        <td @class(['text-gray-600', 'font-medium text-red-600' => $v->expected_restock_date?->isPast()])>{{ $v->expected_restock_date?->format('d M Y') ?? '-' }}</td>
                        <td class="text-xs text-gray-500">{{ $v->oos_marked_at ? $v->oos_marked_at->format('d M, g:i A').' · '.($v->markedBy?->name ?? '') : '-' }}</td>
                    </tr>
                @endforeach
            </x-list.table>
            <x-list.cards>
                @foreach ($variants as $v)
                    <x-record-card :title="$v->product?->name.($v->name !== 'Default' ? ' · '.$v->name : '')" :subtitle="$v->sku">
                        <x-slot:badge><div class="flex items-center gap-2"><x-badge :color="$color[$v->availability_status]">{{ $v->availabilityLabel() }}</x-badge><x-list.check :id="$v->id" /></div></x-slot:badge>
                        <x-slot:footer>{{ $v->expected_restock_date ? __('Expected :d', ['d' => $v->expected_restock_date->format('d M Y')]) : '' }}</x-slot:footer>
                    </x-record-card>
                @endforeach
            </x-list.cards>

            <x-slot:actions>
                <form method="POST" action="{{ route('products.availability.update') }}" class="flex flex-wrap items-center gap-2" x-data="{ s: 'in_stock' }">
                    @csrf
                    <x-list.selected-inputs />
                    <input type="hidden" name="status" :value="s">
                    @foreach (['in_stock' => __('In stock'), 'backorder' => __('Pre-order'), 'out_of_stock' => __('Out of stock')] as $key => $label)
                        <button type="button" @click="s = @js($key)" class="rounded-lg px-2.5 py-1 text-xs" :class="s === @js($key) ? 'bg-white text-gray-900' : 'bg-white/10'">{{ $label }}</button>
                    @endforeach
                    <input type="date" name="expected_restock_date" x-show="s !== 'in_stock'" min="{{ now()->toDateString() }}" class="rounded-lg border-0 px-2 py-1 text-xs text-gray-900" aria-label="{{ __('Expected restock date') }}">
                    <button type="submit" class="rounded-lg bg-green-700 px-3 py-1.5 text-xs font-medium hover:bg-green-600">{{ __('Apply') }}</button>
                </form>
            </x-slot:actions>
        </x-list.selectable>
        <div class="mt-4">{{ $variants->links() }}</div>
    @endif
</x-layouts.app>
