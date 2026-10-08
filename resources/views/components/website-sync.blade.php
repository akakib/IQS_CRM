{{-- Admins and managers: bring website orders now (the last 24 hours). Hidden when no website is connected. --}}
@can('orders.reassign')
    @if (app(\App\Services\Catalog\Store\WooApi::class)->configured())
        <form method="POST" action="{{ route('orders.sync-website') }}" x-data="{ busy: false }" @submit="busy = true" {{ $attributes->merge(['class' => 'shrink-0']) }}>
            @csrf
            <x-button variant="secondary" class="w-full sm:w-auto" x-bind:disabled="busy">
                <x-icon name="refresh" class="h-4 w-4" x-bind:class="busy && 'animate-spin'" />
                <span x-text="busy ? @js(__('Syncing...')) : @js(__('Sync website orders'))">{{ __('Sync website orders') }}</span>
            </x-button>
        </form>
    @endif
@endcan
