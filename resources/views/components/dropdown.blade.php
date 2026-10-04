{{-- Menu under a trigger. Panel width follows the trigger unless width is given.
     <x-dropdown><x-slot:trigger>More</x-slot:trigger><a …>Export</a></x-dropdown> --}}
@props(['trigger', 'align' => 'right', 'width' => 'min-w-[12rem]'])

<div x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false" {{ $attributes->merge(['class' => 'relative inline-block']) }}>
    <button type="button" @click="open = !open" class="flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">
        {{ $trigger }}
        <svg class="h-3.5 w-3.5 text-gray-400 transition-transform" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
    </button>
    <div x-show="open" x-cloak x-transition @click="open = false"
        class="absolute z-30 mt-1 {{ $width }} {{ $align === 'left' ? 'left-0' : 'right-0' }} rounded-lg border border-gray-200 bg-white py-1 shadow-lg [&>*]:block [&>*]:w-full [&>*]:px-3 [&>*]:py-1.5 [&>*]:text-left [&>*]:text-sm [&>*]:text-gray-700 [&>*:hover]:bg-gray-50">
        {{ $slot }}
    </div>
</div>
