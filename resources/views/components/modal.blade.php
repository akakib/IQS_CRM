{{-- Centered dialog. Open with $dispatch('open-modal', 'id'), close with
     $dispatch('close-modal', 'id') or Esc / backdrop.
     persistent: only the close button closes it (no Esc, no backdrop click).
     Closing sends 'modal-closed' with the id.
     <x-modal id="edit-note" :title="__('Edit note')">…<x-slot:footer>…</x-slot:footer></x-modal> --}}
@props(['id', 'title' => null, 'footer' => null, 'width' => 'max-w-lg', 'persistent' => false, 'show' => false])

<div x-data="{ open: @js((bool) $show), close() { this.open = false; this.$dispatch('modal-closed', @js($id)); } }"
    x-on:open-modal.window="if ($event.detail === @js($id)) open = true"
    x-on:close-modal.window="if ($event.detail === @js($id)) close()"
    x-show="open" x-cloak @if (! $persistent) @keydown.escape.window="open && close()" @endif
    class="fixed inset-0 z-[80] flex items-end justify-center sm:items-center sm:px-4">
    <div class="absolute inset-0 bg-black/40" @if (! $persistent) @click="close()" @endif></div>
    <div x-show="open" x-transition class="relative w-full {{ $width }} rounded-t-2xl bg-white shadow-xl sm:rounded-xl">
        @if ($title)
            <div class="flex items-center justify-between border-b border-gray-100 px-5 py-3">
                <h3 class="text-sm font-semibold text-gray-800">{{ $title }}</h3>
                <button type="button" @click="close()" class="text-xl leading-none text-gray-400 hover:text-gray-600" aria-label="{{ __('Close') }}">&times;</button>
            </div>
        @endif
        <div class="max-h-[70vh] overflow-y-auto p-5">{{ $slot }}</div>
        @if ($footer)
            <div class="flex justify-end gap-2 border-t border-gray-100 px-5 py-3">{{ $footer }}</div>
        @endif
    </div>
</div>
