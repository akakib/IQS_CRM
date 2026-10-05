{{-- Index-page filter bar. Search is debounced (300 ms); any x-simple-select
     inside submits on change. Desktop: one row. Phone: search stays on top,
     the filters open as a bottom sheet (the SAME controls, rendered once, so
     no field is sent twice). State lives in the query string.
     <x-list.filter-bar :list="$list" :action="route('users.index')" placeholder="Search">
         <x-simple-select name="status" ... />
     </x-list.filter-bar> --}}
@props(['list', 'action', 'placeholder' => __('Search')])

@php($activeFilters = count(array_filter($list->filters, fn ($v) => $v !== null)))

<form method="GET" action="{{ $action }}" x-ref="filters"
    x-data="{ sheet: false, timer: null, go() { clearTimeout(this.timer); this.timer = setTimeout(() => $refs.filters.requestSubmit(), 300) } }"
    @select-change="setTimeout(() => $refs.filters.requestSubmit(), 0)"
    class="mb-4 flex items-center gap-2 rounded-xl border border-gray-200 bg-white p-3">
    <input type="hidden" name="sort" value="{{ $list->sort }}">
    <input type="hidden" name="dir" value="{{ $list->dir }}">

    <input type="search" name="q" value="{{ $list->search }}" @input="go()" placeholder="{{ $placeholder }}"
        class="w-full min-w-0 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:outline-none md:max-w-xs">

    <button type="button" @click="sheet = true" class="relative shrink-0 rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-600 md:hidden">
        {{ __('Filters') }}
        @if ($activeFilters)
            <span class="ml-1 rounded-full bg-primary px-1.5 text-xs text-white">{{ $activeFilters }}</span>
        @endif
    </button>

    <div x-show="sheet" x-cloak @click="sheet = false" class="fixed inset-0 z-40 bg-black/40 md:hidden"></div>

    <div :class="sheet ? 'fixed inset-x-0 bottom-0 z-50 grid rounded-t-2xl bg-white p-5 pb-8 shadow-2xl' : 'hidden'"
        class="gap-2 md:static md:z-auto md:flex md:flex-1 md:flex-wrap md:items-center md:rounded-none md:p-0 md:pb-0 md:shadow-none">
        <p class="mb-1 text-sm font-semibold text-gray-800 md:hidden">{{ __('Filters') }}</p>
        {{ $slot }}
        <div class="mt-2 flex items-center gap-2 md:mt-0 md:ml-auto">
            <x-simple-select name="per_page" :options="collect(\App\Support\Lists\ListState::PER_PAGE)->mapWithKeys(fn ($n) => [$n => __(':n / page', ['n' => $n])])->all()" :value="$list->perPage" />
            @if ($list->hasFilters())
                <a href="{{ $action }}" class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('Clear') }}</a>
            @endif
            <button type="button" @click="sheet = false" class="ml-auto rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white md:hidden">{{ __('Done') }}</button>
        </div>
    </div>
</form>
