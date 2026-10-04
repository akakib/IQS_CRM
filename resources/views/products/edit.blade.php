<x-layouts.app :heading="__('Edit product')">
    <div class="mb-4 flex items-center justify-between">
        <a href="{{ route('products.index') }}" class="text-sm text-green-900 hover:underline">{{ __('Back to products') }}</a>
        @can('products.delete')
            <form id="delete-product" method="POST" action="{{ route('products.destroy', $product) }}">
                @csrf
                @method('DELETE')
                <button type="button" class="text-sm text-red-600 hover:underline"
                    @click="$dispatch('open-confirm', { id: 'delete-product', form: 'delete-product', label: @js($product->name) })">{{ __('Delete product') }}</button>
            </form>
        @endcan
    </div>

    <form method="POST" action="{{ route('products.update', $product) }}">
        @method('PUT')
        @include('products._form')
    </form>

    <x-confirm-modal id="delete-product" :message="__('It disappears from lists and search. Past orders keep their copy.')" />
</x-layouts.app>
