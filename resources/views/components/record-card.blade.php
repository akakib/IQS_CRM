{{-- One record on a phone.
     <x-record-card :title="$user->name" :subtitle="$user->email">
         <x-slot:badge><x-badge color="green">Active</x-badge></x-slot:badge>
         extra lines…
         <x-slot:footer>meta</x-slot:footer>
         <x-slot:actions>Edit</x-slot:actions>
     </x-record-card> --}}
@props(['title', 'subtitle' => null, 'badge' => null, 'footer' => null, 'actions' => null])

<div {{ $attributes->merge(['class' => 'rounded-xl border border-gray-200 bg-white p-4']) }}>
    <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
            <p class="font-medium text-gray-800">{{ $title }}</p>
            @if ($subtitle)<p class="truncate text-sm text-gray-500">{{ $subtitle }}</p>@endif
            {{ $slot }}
        </div>
        {{ $badge }}
    </div>
    @if ($footer || $actions)
        <div class="mt-3 flex items-center justify-between gap-2 border-t border-gray-100 pt-3">
            <span class="min-w-0 truncate text-xs text-gray-400">{{ $footer }}</span>
            <div class="shrink-0">{{ $actions }}</div>
        </div>
    @endif
</div>
