<x-layouts.app :heading="__('Add staff')">
    <form method="POST" action="{{ route('users.store') }}">
        @include('users._form')
    </form>
</x-layouts.app>
