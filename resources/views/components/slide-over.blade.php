{{-- Side panel from the right (full width on phones). Same events as x-modal:
     $dispatch('open-modal', 'order-preview'). --}}
@props(['id', 'title' => null, 'footer' => null])

<div x-data="{ open: false }"
    x-on:open-modal.window="if ($event.detail === @js($id)) open = true"
    x-on:close-modal.window="if ($event.detail === @js($id)) open = false"
    x-show="open" x-cloak @keydown.escape.window="open = false" class="fixed inset-0 z-[80]">
    <div class="absolute inset-0 bg-black/40" @click="open = false"></div>
    <div x-show="open"
        x-transition:enter="transition ease-out duration-200" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
        x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
        class="absolute inset-y-0 right-0 flex w-full max-w-md flex-col bg-white shadow-xl">
        <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
            <h3 class="text-sm font-semibold text-gray-800">{{ $title }}</h3>
            <button type="button" @click="open = false" class="text-xl leading-none text-gray-400 hover:text-gray-600" aria-label="{{ __('Close') }}">&times;</button>
        </div>
        <div class="flex-1 overflow-y-auto p-5">{{ $slot }}</div>
        @if ($footer)
            <div class="flex justify-end gap-2 border-t border-gray-100 px-5 py-3">{{ $footer }}</div>
        @endif
    </div>
</div>
