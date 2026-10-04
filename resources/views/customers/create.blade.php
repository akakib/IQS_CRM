<x-layouts.app :heading="__('New customer')">
    <form method="POST" action="{{ route('customers.store') }}">
        @include('customers._form')
    </form>
</x-layouts.app>
