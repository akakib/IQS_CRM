{{-- Who else is on this order right now. mode: view (Order management, activity popup) or edit (edit form).
     <x-order-presence :order="$order" mode="view" /> --}}
@props(['order', 'mode' => 'view'])

<div x-data="orderPresence({ url: @js(route('orders.presence', $order)), mode: @js($mode), me: {{ auth()->id() }} })" {{ $attributes }}>
    <template x-if="editor">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <p><b x-text="editor.name"></b> {{ __('is editing this order. Please wait: it opens again when they finish.') }}</p>
            @if ($mode === 'edit' && auth()->user()->can('orders.reassign'))
                <button type="button" @click="takeOver()" class="shrink-0 rounded-lg border border-amber-400 bg-white px-3 py-1.5 text-xs font-semibold text-amber-900 hover:bg-amber-100">{{ __('Take over') }}</button>
            @endif
        </div>
    </template>
    <template x-if="!editor && viewers.length">
        <p class="mb-3 rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs text-gray-600">
            <span x-text="viewers.join(', ')"></span> {{ __('also has this order open.') }}
        </p>
    </template>
</div>
