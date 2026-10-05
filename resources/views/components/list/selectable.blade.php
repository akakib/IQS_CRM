{{-- Wraps a list so rows can be ticked and acted on together (batch first).
     Rows use <x-list.check :id="$row->id" />; actions go in the `actions` slot
     as forms that include <x-list.selected-inputs />.
     <x-list.selectable :ids="$rows->pluck('id')"> table + cards …
         <x-slot:actions><form …><x-list.selected-inputs /><x-button size="sm">Activate</x-button></form></x-slot:actions>
     </x-list.selectable> --}}
@props(['ids', 'actions' => null])

<div x-data="{ selected: [], all: @js(collect($ids)->map(fn ($id) => (string) $id)->values()) }" {{ $attributes }}>
    <label class="mb-2 hidden items-center gap-2 text-xs text-gray-500 md:inline-flex">
        <input type="checkbox" class="h-4 w-4 rounded border-gray-300 text-primary"
            :checked="all.length && selected.length === all.length"
            @change="selected = $event.target.checked ? [...all] : []">
        {{ __('Select all on this page') }}
    </label>

    {{ $slot }}

    @if ($actions)
        <div x-show="selected.length" x-cloak x-transition
            class="sticky bottom-3 z-30 mx-auto mt-4 flex max-w-xl flex-wrap items-center gap-3 rounded-xl bg-gray-900 px-4 py-3 text-sm text-white shadow-xl">
            <span><span x-text="selected.length"></span> {{ __('selected') }}</span>
            <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
            <button type="button" @click="selected = []" class="ml-auto text-xs text-gray-300 hover:text-white">{{ __('Clear') }}</button>
        </div>
    @endif
</div>
