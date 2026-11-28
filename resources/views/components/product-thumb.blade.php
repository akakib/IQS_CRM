{{-- A product's photo, the same everywhere a product is listed. No photo, or a link that no longer opens:
     a grey box of the same size, so the text never moves and no broken-image icon shows.
     <x-product-thumb :src="$item->image_url" />  size: sm (32px, lists), md (40px, default), lg (56px, packing checklist)
     In Alpine: <x-product-thumb x-src="i.image" /> binds the photo from the row. --}}
@props(['src' => null, 'size' => 'md', 'xSrc' => null])
@php
    $box = ['sm' => 'h-8 w-8', 'md' => 'h-10 w-10', 'lg' => 'h-14 w-14'][$size] ?? 'h-10 w-10';
@endphp
<span {{ $attributes->merge(['class' => "{$box} shrink-0 overflow-hidden rounded-md border border-gray-200 bg-gray-100"]) }} aria-hidden="true">
    @if ($xSrc)
        <template x-if="{{ $xSrc }}"><img :src="{{ $xSrc }}" alt="" loading="lazy" class="h-full w-full object-cover" onerror="this.remove()"></template>
    @elseif ($src)
        <img src="{{ $src }}" alt="" loading="lazy" class="h-full w-full object-cover" onerror="this.remove()">
    @endif
</span>
