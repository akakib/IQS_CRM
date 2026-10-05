<x-layouts.app :heading="__('Edit product')">
    <div class="mb-4 flex items-center justify-between">
        <a href="{{ route('products.index') }}" class="text-sm text-primary hover:underline">{{ __('Back to products') }}</a>
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

    @if ($history->isNotEmpty())
        <x-card :title="__('Price history')" :subtitle="__('Last 30 changes. Nothing here can be edited.')" class="mt-6">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide text-gray-500"><tr><th class="py-2 pr-4">{{ __('When') }}</th><th class="py-2 pr-4">{{ __('Variant') }}</th><th class="py-2 pr-4">{{ __('Price') }}</th><th class="py-2 pr-4">{{ __('Change') }}</th><th class="py-2">{{ __('By') }}</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($history as $h)
                            <tr>
                                <td class="py-2 pr-4 text-gray-500">{{ \Illuminate\Support\Carbon::parse($h->created_at)->format('d M Y, g:i A') }}</td>
                                <td class="py-2 pr-4">{{ $h->variant }}</td>
                                <td class="py-2 pr-4 text-gray-600">{{ $h->field === 'cost' ? __('Cost') : $h->list.' · '.__(ucfirst($h->field)) }}</td>
                                <td class="py-2 pr-4 tabular-nums">{{ $h->old_value !== null ? '৳'.number_format((float) $h->old_value, 2) : '-' }} → <b>{{ $h->new_value !== null ? '৳'.number_format((float) $h->new_value, 2) : '-' }}</b></td>
                                <td class="py-2 text-gray-500">{{ $h->user ?? __('System') }}{{ $h->source !== 'manual' ? ' ('.$h->source.')' : '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
    @endif

    <x-confirm-modal id="delete-product" :message="__('It disappears from lists and search. Past orders keep their copy.')" />
</x-layouts.app>
