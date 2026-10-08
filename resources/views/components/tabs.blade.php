{{-- Underlined page tabs (links). active = key of the current tab.
     <x-tabs :tabs="['all' => ['All', url], 'hold' => ['Hold', url, 12]]" active="hold" />
     In-page tabs: give a URL of '#key'; the tab switches in place (no reload,
     no scrolling) and the address keeps #key, so a reload opens the same tab.
     Wrap each panel in <section x-show="tab === 'key'">, inside the same
     element as the tabs, with x-data="tabs('default')". --}}
@props(['tabs', 'active'])

<nav {{ $attributes->merge(['class' => 'mb-4 flex gap-5 overflow-x-auto overflow-y-hidden border-b border-gray-200']) }}>
    @foreach ($tabs as $key => $tab)
        @php([$label, $url, $count] = array_pad($tab, 3, null))
        @if (str_starts_with($url, '#') && strlen($url) > 1)
            <a href="{{ $url }}" @click.prevent="go(@js($key))"
                :class="tab === @js($key) ? 'border-primary font-medium text-primary' : 'border-transparent text-gray-500 hover:text-gray-700'"
                class="-mb-px flex shrink-0 items-center gap-1.5 border-b-2 px-0.5 pb-2 text-sm">
        @else
            <a href="{{ $url }}" @class([
                '-mb-px flex shrink-0 items-center gap-1.5 border-b-2 px-0.5 pb-2 text-sm',
                'border-primary font-medium text-primary' => $key === $active,
                'border-transparent text-gray-500 hover:text-gray-700' => $key !== $active,
            ])>
        @endif
            {{ $label }}
            @if ($count !== null)
                <span class="rounded-full bg-gray-100 px-1.5 text-xs text-gray-600">{{ $count }}</span>
            @endif
        </a>
    @endforeach
</nav>
