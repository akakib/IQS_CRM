{{-- An order's items in a short list (search results, batches, a customer's past orders): each with its photo,
     name and amount, wrapping on a phone. Items need variant.product loaded (image_url) to show the photo.
     <x-item-chips :items="$order->items" /> --}}
@props(['items'])
<div {{ $attributes->merge(['class' => 'flex flex-wrap gap-1.5']) }}>
    @foreach ($items as $i)
        <span class="inline-flex max-w-full items-center gap-1.5 rounded-lg border border-gray-200 bg-white py-0.5 pl-0.5 pr-2 text-xs text-gray-700">
            <x-product-thumb :src="$i->variant?->product?->image_url" size="sm" />
            <span class="min-w-0 truncate">{{ $i->name_snapshot }}</span>
            <b class="shrink-0 tabular-nums">× {{ \App\Support\Units::qty($i->qty, $i->unit) }}</b>
        </span>
    @endforeach
</div>
