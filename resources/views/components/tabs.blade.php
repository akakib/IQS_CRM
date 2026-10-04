{{-- Underlined page tabs (links). active = key of the current tab.
     <x-tabs :tabs="['all' => ['All', url], 'hold' => ['Hold', url, 12]]" active="hold" /> --}}
@props(['tabs', 'active'])

<nav {{ $attributes->merge(['class' => 'mb-4 flex gap-5 overflow-x-auto border-b border-gray-200']) }}>
    @foreach ($tabs as $key => $tab)
        @php([$label, $url, $count] = array_pad($tab, 3, null))
        <a href="{{ $url }}" @class([
            '-mb-px flex shrink-0 items-center gap-1.5 border-b-2 px-0.5 pb-2 text-sm',
            'border-green-900 font-medium text-green-900' => $key === $active,
            'border-transparent text-gray-500 hover:text-gray-700' => $key !== $active,
        ])>
            {{ $label }}
            @if ($count !== null)
                <span class="rounded-full bg-gray-100 px-1.5 text-xs text-gray-600">{{ $count }}</span>
            @endif
        </a>
    @endforeach
</nav>
