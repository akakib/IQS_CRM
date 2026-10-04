{{-- White section box. <x-card :title="__('Profile')">…</x-card> --}}
@props(['title' => null, 'subtitle' => null, 'actions' => null])

<section {{ $attributes->merge(['class' => 'rounded-xl border border-gray-200 bg-white p-5']) }}>
    @if ($title || $actions)
        <div class="mb-4 flex items-start justify-between gap-3">
            <div>
                @if ($title)<h2 class="text-sm font-semibold text-gray-800">{{ $title }}</h2>@endif
                @if ($subtitle)<p class="text-xs text-gray-500">{{ $subtitle }}</p>@endif
            </div>
            {{ $actions }}
        </div>
    @endif
    {{ $slot }}
</section>
