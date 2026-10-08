{{-- Shown inside the New order popup after a save: the page behind it goes to Order management. --}}
<x-layouts.embed>
    <p class="py-16 text-center text-sm text-gray-500">{{ __('Order created.') }}</p>
    <script>window.parent.postMessage({ iqsOrderCreated: true }, window.location.origin);</script>
</x-layouts.embed>
