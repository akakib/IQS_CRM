<x-layouts.app :heading="__('Edit location')">
    <form method="POST" action="{{ route('locations.update', $location) }}">
        @method('PUT')
        @include('locations._form')
    </form>
</x-layouts.app>
