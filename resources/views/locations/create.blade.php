<x-layouts.app :heading="__('New location')">
    <form method="POST" action="{{ route('locations.store') }}">
        @include('locations._form')
    </form>
</x-layouts.app>
