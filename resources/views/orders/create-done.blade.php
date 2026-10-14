{{-- Shown inside the New order popup after a save: the page behind it goes to Order management, on the new order. --}}
<x-layouts.embed>
    <p class="py-16 text-center text-sm text-gray-500">{{ __('Order created.') }}</p>
    <script>window.parent.postMessage({ iqsOrderCreated: true, url: @js(route('desk.notice', $order)), stay: @js($stay ?? false), no: @js($order->order_no), id: @js($order->id) }, window.location.origin);</script>
</x-layouts.embed>
