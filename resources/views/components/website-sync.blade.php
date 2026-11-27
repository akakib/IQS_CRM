{{-- Admins and managers: bring website orders now (the last 24 hours). Hidden when no website is connected. --}}
{{-- compact: on a phone only the icon, a square button (the Order management top row). --}}
@props(['size' => 'md', 'compact' => false])
@can('orders.reassign')
    @if (app(\App\Services\Catalog\Store\WooApi::class)->configured())
        <form method="POST" action="{{ route('orders.sync-website') }}" x-data="{ busy: false }" @submit="busy = true" {{ $attributes->merge(['class' => 'shrink-0']) }}>
            @csrf
            <x-button variant="secondary" :size="$size" :class="$compact ? 'max-sm:w-10 max-sm:px-0 sm:w-auto' : 'w-full sm:w-auto'" x-bind:disabled="busy" :aria-label="__('Sync website orders')" :title="__('Sync website orders')">
                <x-icon name="refresh" @class(['h-3.5 w-3.5' => $size === 'sm', 'h-4 w-4' => $size !== 'sm']) x-bind:class="busy && 'animate-spin'" />
                <span class="max-sm:hidden" x-text="busy ? @js(__('Syncing...')) : @js(__('Sync website orders'))">{{ __('Sync website orders') }}</span>
                @unless ($compact)<span class="sm:hidden" x-text="busy ? @js(__('Syncing...')) : @js(__('Sync website'))">{{ __('Sync website') }}</span>@endunless
            </x-button>
        </form>
    @endif
@endcan
