<x-layouts.app :heading="__('Add staff')">
    <form method="POST" action="{{ route('users.store') }}" enctype="multipart/form-data">
        @include('users._form')
    </form>
</x-layouts.app>
