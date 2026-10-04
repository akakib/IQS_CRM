<x-layouts.app :heading="__('Edit role')">
    <form method="POST" action="{{ route('roles.update', $role) }}">
        @method('PUT')
        @include('roles._form')
    </form>
</x-layouts.app>
