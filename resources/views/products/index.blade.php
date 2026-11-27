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
    // Quick edit: tapping a product opens it, only for who may change stock (products.availability) or open the
    // full form (products.edit); anyone else only sees the list and a tap does nothing.
    $canStock = auth()->user()->can('products.availability');
    $canEdit = auth()->user()->can('products.edit');
    $quick = $canStock || $canEdit;
    $quickData = fn ($product) => [
        'name' => $product->name, 'image' => $product->image_url, 'category' => $product->category?->name,
        'edit' => $canEdit ? route('products.edit', $product) : null,
        'variants' => $product->variants->map(fn ($v) => [
            'id' => $v->id, 'name' => $v->name === 'Default' ? null : $v->name, 'status' => $v->availability_status, 'open' => (int) ($openOrders[$v->id] ?? 0),
            'price' => ($p = $v->prices->first()?->effective()) !== null ? '৳'.number_format((float) $p, 0) : null,
        ])->values()->all(),
    ];
@endphp

<x-layouts.app :heading="__('Products')">
    <x-products.subnav active="products" />

    <div class="mb-4 flex items-center justify-end gap-3">
        @can('products.create')
            <x-button :href="route('products.create')">+ {{ __('New product') }}</x-button>
        @endcan
    </div>

    <x-list.filter-bar :list="$list" :action="route('products.index')" :placeholder="__('Search name, SKU or barcode')" :results="$products" :noun="[__('product'), __('products')]">
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
    <div x-data="productQuick({ url: @js(route('products.availability.update')), canStock: @js($canStock) })">
        <x-list.table>
            <x-slot:head>
                <th><x-list.sort :list="$list" column="name">{{ __('Product') }}</x-list.sort></th>
                <th>{{ __('Category') }}</th>
                <th>{{ __('Variants') }}</th>
                <th>{{ __('Online price') }}</th>
                <th>{{ __('Stock status') }}</th>
                <th><x-list.sort :list="$list" column="updated_at">{{ __('Updated') }}</x-list.sort></th>
            </x-slot:head>
            @foreach ($products as $product)
                <tr @if ($quick) @click="open(@js($quickData($product)))" class="cursor-pointer hover:bg-gray-50" @endif>
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
                </tr>
            @endforeach
        </x-list.table>

        <x-list.cards>
            @foreach ($products as $product)
                <x-record-card :title="$product->name" :image="$product->image_url" :subtitle="($product->category?->name ?? '-').' · '.$priceRange($product)"
                    x-on:click="{{ $quick ? 'open('.\Illuminate\Support\Js::from($quickData($product)).')' : '' }}" class="{{ $quick ? 'cursor-pointer active:bg-gray-50' : '' }}">
                    <x-slot:badge>
                        @forelse ($stockSummary($product) as $status => $n)
                            <x-badge :color="$availabilityColor[$status]">{{ $n }} {{ __(\App\Models\ProductVariant::AVAILABILITY[$status]) }}</x-badge>
                        @empty
                            <x-badge color="green">{{ __('In stock') }}</x-badge>
                        @endforelse
                    </x-slot:badge>
                    <x-slot:footer>{{ trans_choice(':count variant|:count variants', $product->variants->count(), ['count' => $product->variants->count()]) }}</x-slot:footer>
                </x-record-card>
            @endforeach
        </x-list.cards>

        <div class="mt-4">{{ $products->links() }}</div>

        @if ($quick)
            {{-- Quick edit, the app's one popup pattern (as Block, Cancel, Confirm): a sheet from the bottom on a phone,
                 a centred box on a computer. --}}
            <div x-show="item" x-cloak class="fixed inset-0 z-[80] flex items-end justify-center sm:items-center sm:px-4" @keydown.escape.window="close()">
                <div class="absolute inset-0 bg-black/40" @click="close()"></div>
                <div class="relative max-h-[90dvh] w-full max-w-md overflow-y-auto rounded-t-2xl bg-white p-5 shadow-xl sm:rounded-xl">
                    <template x-if="item">
                        <div>
                            <div class="flex items-center gap-3">
                                <img x-show="item.image" :src="item.image" alt="" class="h-12 w-12 shrink-0 rounded-lg border border-gray-200 object-cover">
                                <div class="min-w-0 flex-1">
                                    <p class="font-medium leading-snug text-gray-900" x-text="item.name"></p>
                                    <p class="text-sm text-gray-500" x-text="item.category || ''"></p>
                                </div>
                                <button type="button" @click="close()" aria-label="{{ __('Close') }}" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
                                </button>
                            </div>
                            <template x-for="v in item.variants" :key="v.id">
                                <div class="mt-3 border-t border-gray-100 pt-3">
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="font-medium text-gray-800" x-text="v.name || @js(__('Stock'))"></span>
                                        <span class="tabular-nums text-gray-500" x-text="v.price || ''"></span>
                                    </div>
                                    <div class="mt-2 grid grid-cols-3 overflow-hidden rounded-lg border border-gray-300 text-center text-sm">
                                        @foreach (['in_stock' => 'bg-primary', 'backorder' => 'bg-amber-600', 'out_of_stock' => 'bg-red-600'] as $key => $on)
                                            <button type="button" @click="set(v, @js($key))" :disabled="! canStock || busy"
                                                class="whitespace-nowrap py-2 {{ $loop->first ? '' : 'border-l border-gray-200' }}"
                                                :class="v.status === @js($key) ? '{{ $on }} font-medium text-white' : 'bg-white text-gray-600' + (canStock ? ' hover:bg-gray-50' : '')">{{ __(\App\Models\ProductVariant::AVAILABILITY[$key]) }}</button>
                                        @endforeach
                                    </div>
                                    <p x-show="v.status === 'out_of_stock' && v.open" class="mt-1.5 text-xs text-amber-700"
                                        x-text="v.open === 1 ? @js(__('1 open order has this item')) : @js(__(':n open orders have this item')).replace(':n', v.open)"></p>
                                </div>
                            </template>
                            <p x-show="saved" class="mt-3 text-sm text-green-700">{{ __('Saved') }}</p>
                            <p x-show="error" class="mt-3 text-sm text-red-600" x-text="error"></p>
                            <div x-show="item.edit" class="mt-4 text-center">
                                <a :href="item.edit" class="text-sm font-medium text-primary hover:underline">{{ __('Edit in full form') }}</a>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        @endif
    </div>
    @endif
</x-layouts.app>
