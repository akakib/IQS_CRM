<x-layouts.app :heading="__('New product')">
    <form method="POST" action="{{ route('products.store') }}">
        @include('products._form')
    </form>
    <div class="h-20 lg:hidden"></div>{{-- room above the phone's Save bar --}}
</x-layouts.app>
