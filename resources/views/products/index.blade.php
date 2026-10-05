@php
    $availabilityColor = ['in_stock' => 'green', 'backorder' => 'amber', 'out_of_stock' => 'red'];
    $statusColor = ['active' => 'green', 'draft' => 'gray', 'archived' => 'gray'];
    $priceRange = function ($product) {
        $prices = $product->variants->map(fn ($v) => $v->prices->first()?->effective())->filter()->map(fn ($p) => (float) $p);
        if ($prices->isEmpty()) {
            return '-';
        }
        return $prices->min() === $prices->max()
            ? '৳'.number_format($prices->min(), 0)
            : '৳'.number_format($prices->min(), 0).' - ৳'.number_format($prices->max(), 0);
    };
    $stockSummary = function ($product) {
        $counts = $product->variants->countBy('availability_status');
        return collect(['out_of_stock', 'backorder'])->filter(fn ($s) => $counts->has($s))->mapWithKeys(fn ($s) => [$s => $counts[$s]]);
    };
@endphp

<x-layouts.app :heading="__('Products')">
    <x-products.subnav active="products" />

    <div class="mb-4 flex items-center justify-between gap-3">
        <p class="text-sm text-gray-500">{{ trans_choice(':count product|:count products', $products->total(), ['count' => $products->total()]) }}</p>
        @can('products.create')
            <x-button :href="route('products.create')">+ {{ __('New product') }}</x-button>
        @endcan
    </div>

    <x-list.filter-bar :list="$list" :action="route('products.index')" :placeholder="__('Search name, SKU or barcode')">
        <x-simple-select name="category" :options="['' => __('All categories')] + $categoryOptions" :value="$list->filter('category') ?? ''" />
        <x-simple-select name="status" :options="['' => __('Any status'), 'active' => __('Active'), 'draft' => __('Draft'), 'archived' => __('Archived')]" :value="$list->filter('status') ?? ''" />
        <x-simple-select name="availability" :options="['' => __('Any stock status'), 'in_stock' => __('In stock'), 'backorder' => __('Pre-order'), 'out_of_stock' => __('Out of stock')]" :value="$list->filter('availability') ?? ''" />
    </x-list.filter-bar>

    @if ($products->isEmpty())
        @if ($list->hasFilters())
            <x-empty-state :message="__('No products match these filters.')" :action="route('products.index')" :action-label="__('Clear filters')" />
        @else
            <x-empty-state :message="__('No products yet. Import them from the website or add one.')" :action="route('products.create')" :action-label="__('Add a product')" />
        @endif
    @else
        <x-list.table>
            <x-slot:head>
                <th><x-list.sort :list="$list" column="name">{{ __('Product') }}</x-list.sort></th>
                <th>{{ __('Category') }}</th>
                <th>{{ __('Variants') }}</th>
                <th>{{ __('Online price') }}</th>
                <th>{{ __('Stock status') }}</th>
                <th><x-list.sort :list="$list" column="updated_at">{{ __('Updated') }}</x-list.sort></th>
                <th class="text-right">{{ __('Actions') }}</th>
            </x-slot:head>
            @foreach ($products as $product)
                <tr>
                    <td>
                        <div class="flex items-center gap-3">
                            @if ($product->image_url)
                                <img src="{{ $product->image_url }}" alt="" loading="lazy" class="h-9 w-9 shrink-0 rounded-md border border-gray-200 object-cover">
                            @endif
                            <div class="min-w-0">
                                <p class="font-medium text-gray-800">{{ $product->name }}</p>
                                @if ($product->status !== 'active')<x-badge :color="$statusColor[$product->status]">{{ ucfirst($product->status) }}</x-badge>@endif
                            </div>
                        </div>
                    </td>
                    <td class="text-gray-600">{{ $product->category?->name ?? '-' }}</td>
                    <td class="text-gray-600">{{ $product->variants->count() }}</td>
                    <td class="tabular-nums text-gray-800">{{ $priceRange($product) }}</td>
                    <td>
                        @forelse ($stockSummary($product) as $status => $n)
                            <x-badge :color="$availabilityColor[$status]">{{ $n }} {{ __(\App\Models\ProductVariant::AVAILABILITY[$status]) }}</x-badge>
                        @empty
                            <x-badge color="green">{{ __('In stock') }}</x-badge>
                        @endforelse
                    </td>
                    <td class="text-gray-500">{{ $product->updated_at->format('d M Y') }}</td>
                    <td class="text-right">
                        @can('products.edit')
                            <a href="{{ route('products.edit', $product) }}" class="text-sm font-medium text-primary hover:underline">{{ __('Edit') }}</a>
                        @endcan
                    </td>
                </tr>
            @endforeach
        </x-list.table>

        <x-list.cards>
            @foreach ($products as $product)
                <x-record-card :title="$product->name" :subtitle="($product->category?->name ?? '-').' · '.$priceRange($product)">
                    <x-slot:badge>
                        @forelse ($stockSummary($product) as $status => $n)
                            <x-badge :color="$availabilityColor[$status]">{{ $n }} {{ __(\App\Models\ProductVariant::AVAILABILITY[$status]) }}</x-badge>
                        @empty
                            <x-badge color="green">{{ __('In stock') }}</x-badge>
                        @endforelse
                    </x-slot:badge>
                    <x-slot:footer>{{ trans_choice(':count variant|:count variants', $product->variants->count(), ['count' => $product->variants->count()]) }}</x-slot:footer>
                    <x-slot:actions>
                        @can('products.edit')<a href="{{ route('products.edit', $product) }}" class="text-sm font-medium text-primary">{{ __('Edit') }}</a>@endcan
                    </x-slot:actions>
                </x-record-card>
            @endforeach
        </x-list.cards>

        <div class="mt-4">{{ $products->links() }}</div>
    @endif
</x-layouts.app>
