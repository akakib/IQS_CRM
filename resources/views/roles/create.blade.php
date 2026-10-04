<x-layouts.app :heading="__('New role')">
    <form method="POST" action="{{ route('roles.store') }}">
        @include('roles._form')
    </form>
</x-layouts.app>
