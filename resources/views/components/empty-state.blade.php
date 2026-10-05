{{-- Nothing to show, plus the next useful action.
     <x-empty-state :message="__('No staff yet.')" :action="route('users.create')" :action-label="__('Add staff')" /> --}}
@props(['message', 'action' => null, 'actionLabel' => null])

<div {{ $attributes->merge(['class' => 'rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center']) }}>
    <svg class="mx-auto mb-3 h-8 w-8 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5m6 4.125l2.25 2.25m0 0l2.25 2.25M12 13.875l2.25-2.25M12 13.875l-2.25 2.25M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" /></svg>
    <p class="text-sm text-gray-500">{{ $message }}</p>
    @if ($action && $actionLabel)
        <a href="{{ $action }}" class="mt-3 inline-block text-sm font-medium text-primary hover:underline">{{ $actionLabel }}</a>
    @endif
    {{ $slot }}
</div>
