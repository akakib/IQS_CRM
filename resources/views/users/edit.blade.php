<x-layouts.app :heading="__('Edit staff')">
    <form method="POST" action="{{ route('users.update', $user) }}">
        @method('PUT')
        @include('users._form')
    </form>
</x-layouts.app>
