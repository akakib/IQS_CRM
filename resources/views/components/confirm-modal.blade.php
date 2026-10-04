{{-- Confirmation dialog, replaces native confirm(). Open it with:
     $dispatch('open-confirm', { id: 'delete-location', form: 'form-id', label: 'Shop' })
     Optional detail keys override the props per open: verb, message, danger.
     On confirm it submits the form with that id. --}}
@props([
    'id',
    'verb' => __('Delete'),
    'message' => __('This action cannot be undone.'),
    'danger' => true,
])

<div x-data="{ open: false, form: null, label: null, verb: @js($verb), message: @js($message), danger: @js($danger) }"
    x-on:open-confirm.window="if ($event.detail.id === @js($id)) {
        open = true;
        form = $event.detail.form;
        label = $event.detail.label || null;
        verb = $event.detail.verb || @js($verb);
        message = $event.detail.message || @js($message);
        danger = $event.detail.danger ?? @js($danger);
    }"
    x-show="open" x-cloak @keydown.escape.window="open = false"
    class="fixed inset-0 z-[90] flex items-center justify-center px-4">
    <div class="absolute inset-0 bg-black/40" @click="open = false"></div>

    <div class="relative w-full max-w-sm rounded-xl bg-white p-6 shadow-xl" x-transition>
        <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full" :class="danger ? 'bg-red-50' : 'bg-green-50'">
            <svg class="h-6 w-6" :class="danger ? 'text-red-500' : 'text-green-800'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
            </svg>
        </div>
        <h3 class="text-center text-sm font-semibold text-gray-800" x-text="label ? `${verb} “${label}”?` : `${verb}?`"></h3>
        <p class="mt-1 text-center text-sm text-gray-500" x-text="message"></p>

        <div class="mt-6 flex justify-center gap-2">
            <button type="button" @click="open = false"
                class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('Cancel') }}</button>
            <button type="button" @click="open = false; document.getElementById(form)?.submit()"
                class="rounded-lg px-4 py-2 text-sm text-white" :class="danger ? 'bg-red-600 hover:bg-red-700' : 'bg-green-900 hover:bg-green-800'"
                x-text="verb"></button>
        </div>
    </div>
</div>
