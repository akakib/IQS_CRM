{{-- embed=1: inside the popup (Search > Open customer), without sidebar or header; Save and Cancel go back into it. --}}
<x-dynamic-component :component="request()->boolean('embed') ? 'layouts.embed' : 'layouts.app'" :heading="__('Edit :name', ['name' => $customer->name])">
    <form method="POST" action="{{ route('customers.update', $customer) }}">
        @method('PUT')
        @if (request()->boolean('embed'))<input type="hidden" name="embed" value="1">@endif
        @include('customers._form')
    </form>
</x-dynamic-component>
