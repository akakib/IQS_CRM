{{-- Delete confirmation, replaces native confirm(). Open it with:
     $dispatch('open-confirm', { id: 'delete-location', form: 'form-id', label: 'Shop' })
     On confirm it submits the form with that id. --}}
@props([
    'id',
    'title' => __('Are you sure?'),
    'message' => __('This action cannot be undone.'),
    'confirmLabel' => __('Delete'),
])

<div x-data="{ open: false, form: null, label: null }"
    x-on:open-confirm.window="if ($event.detail.id === @js($id)) { open = true; form = $event.detail.form; label = $event.detail.label || null }"
    x-show="open" x-cloak @keydown.escape.window="open = false"
    class="fixed inset-0 z-[90] flex items-center justify-center px-4">
    <div class="absolute inset-0 bg-black/40" @click="open = false"></div>

    <div class="relative w-full max-w-sm rounded-xl bg-white p-6 shadow-xl" x-transition>
        <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-red-50">
            <svg class="h-6 w-6 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
            </svg>
        </div>
        <h3 class="text-center text-sm font-semibold text-gray-800" x-text="label ? `{{ __('Delete') }} “${label}”?` : @js($title)"></h3>
        <p class="mt-1 text-center text-sm text-gray-500">{{ $message }}</p>

        <div class="mt-6 flex justify-center gap-2">
            <button type="button" @click="open = false"
                class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('Cancel') }}</button>
            <button type="button" @click="open = false; document.getElementById(form)?.submit()"
                class="rounded-lg bg-red-600 px-4 py-2 text-sm text-white hover:bg-red-700">{{ $confirmLabel }}</button>
        </div>
    </div>
</div>
