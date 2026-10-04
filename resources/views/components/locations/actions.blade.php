@props(['location'])

{{-- Rendered twice per row (table + card), so the form id carries a random suffix. --}}
@php($formId = 'delete-location-'.$location->id.'-'.\Illuminate\Support\Str::random(4))

<div class="inline-flex items-center gap-3">
    @can('locations.edit')
        <a href="{{ route('locations.edit', $location) }}" class="text-sm font-medium text-green-900 hover:underline">{{ __('Edit') }}</a>
    @endcan
    @can('locations.delete')
    <form id="{{ $formId }}" method="POST" action="{{ route('locations.destroy', $location) }}">
        @csrf
        @method('DELETE')
        <button type="button" class="text-sm font-medium text-red-600 hover:underline"
            @click="$dispatch('open-confirm', { id: 'delete-location', form: @js($formId), label: @js($location->name) })">{{ __('Delete') }}</button>
    </form>
    @endcan
</div>
