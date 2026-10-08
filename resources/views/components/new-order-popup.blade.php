{{-- New order in a popup over any page: the Quick order form without sidebar or header.
     Open with window.dispatchEvent(new CustomEvent('order-new', { detail: url })) where url is
     orders.create with embed=1. Closes only with Close (typing must not be lost to a stray click);
     after Create the page goes to Order management with the new order open. --}}
@can('orders.create')
<div x-data="{ src: '' }" @order-new.window="src = $event.detail"
    @message.window="if ($event.origin === location.origin && $event.data?.iqsOrderCreated) location.href = $event.data.url || @js(route('desk.index'))">
    <template x-teleport="body">
        <div x-show="src" x-cloak class="fixed inset-0 z-[130] flex items-stretch justify-center bg-black/50 sm:items-center sm:p-6" role="dialog" aria-modal="true">
            <div class="flex h-full w-full max-w-6xl flex-col overflow-hidden bg-white shadow-2xl sm:h-[92vh] sm:rounded-2xl">
                <div class="flex items-center justify-between gap-3 border-b border-gray-200 px-4 py-3 sm:px-6">
                    <h3 class="text-base font-semibold text-gray-900">{{ __('New order') }}</h3>
                    <button type="button" @click="src = ''" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Close') }}</button>
                </div>
                <template x-if="src"><iframe :src="src" title="{{ __('New order') }}" class="min-h-0 w-full flex-1 border-0 bg-gray-50"></iframe></template>
            </div>
        </div>
    </template>
</div>
@endcan
