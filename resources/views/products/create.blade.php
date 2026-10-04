<x-layouts.app :heading="__('New product')">
    <form method="POST" action="{{ route('products.store') }}">
        @include('products._form')
    </form>
</x-layouts.app>
