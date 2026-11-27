{{-- Numbered pages for a list from the server: 1 2 3 ... 7, the open one filled, and which rows are shown.
     Few pages show all their numbers; many keep the first, the last and the ones around the open page.
     <x-page-numbers :pages="$list" /> --}}
@props(['pages'])
@php
    $last = $pages->lastPage();
    $now = $pages->currentPage();
    $show = collect(range(1, $last))->filter(fn ($n) => $last <= 7 || $n === 1 || $n === $last || abs($n - $now) <= 1)->values();
@endphp
@if ($last > 1)
    <nav {{ $attributes->merge(['class' => 'flex flex-wrap items-center justify-between gap-x-3 gap-y-2 text-sm']) }} aria-label="{{ __('Pages') }}">
        <span class="tabular-nums text-gray-500">{{ __(':a to :b of :n', ['a' => $pages->firstItem(), 'b' => $pages->lastItem(), 'n' => $pages->total()]) }}</span>
        <span class="flex flex-wrap items-center gap-1.5">
            @foreach ($show as $i => $n)
                @if ($i > 0 && $n - $show[$i - 1] > 1)<span class="px-0.5 text-gray-400" aria-hidden="true">...</span>@endif
                @if ($n === $now)
                    <span aria-current="page" class="flex h-9 min-w-9 items-center justify-center rounded-lg bg-primary px-2 font-semibold tabular-nums text-white">{{ $n }}</span>
                @else
                    <a href="{{ $pages->url($n) }}" class="flex h-9 min-w-9 items-center justify-center rounded-lg border border-gray-300 bg-white px-2 tabular-nums text-gray-700 hover:bg-gray-50" aria-label="{{ __('Page :n', ['n' => $n]) }}">{{ $n }}</a>
                @endif
            @endforeach
        </span>
    </nav>
@endif
