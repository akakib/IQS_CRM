<x-layouts.app :heading="__('Edit :name', ['name' => $customer->name])">
    <form method="POST" action="{{ route('customers.update', $customer) }}">
        @method('PUT')
        @include('customers._form')
    </form>
</x-layouts.app>
