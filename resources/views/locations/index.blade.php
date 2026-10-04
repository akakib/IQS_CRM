@php
    $sortUrl = function (string $column) use ($filters) {
        $dir = ($filters['sort'] === $column && $filters['dir'] === 'asc') ? 'desc' : 'asc';

        return request()->fullUrlWithQuery(['sort' => $column, 'dir' => $dir, 'page' => null]);
    };
    $sortIcon = fn (string $column) => $filters['sort'] === $column ? ($filters['dir'] === 'asc' ? '↑' : '↓') : '';
    $hasFilters = $filters['q'] !== '' || $filters['type'] || $filters['status'];
@endphp

<x-layouts.app :heading="__('Locations')">
    <div class="mb-4 flex items-center justify-between gap-3">
        <p class="text-sm text-gray-500">{{ trans_choice(':count location|:count locations', $locations->total(), ['count' => $locations->total()]) }}</p>
        @can('locations.create')
            <a href="{{ route('locations.create') }}"
                class="rounded-lg bg-green-900 px-4 py-2 text-sm font-medium text-white hover:bg-green-800">+ {{ __('New location') }}</a>
        @endcan
    </div>

    {{-- Filters live in the query string so a shared link opens the same list. --}}
    <form method="GET" action="{{ route('locations.index') }}" x-ref="filters"
        x-data="{ timer: null, go() { clearTimeout(this.timer); this.timer = setTimeout(() => $refs.filters.requestSubmit(), 300) } }"
        @select-change="setTimeout(() => $refs.filters.requestSubmit(), 0)"
        class="mb-4 flex flex-col gap-2 rounded-xl border border-gray-200 bg-white p-3 md:flex-row md:items-center">
        <input type="hidden" name="sort" value="{{ $filters['sort'] }}">
        <input type="hidden" name="dir" value="{{ $filters['dir'] }}">

        <input type="search" name="q" value="{{ $filters['q'] }}" @input="go()" placeholder="{{ __('Search by name') }}"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-green-800 focus:outline-none md:max-w-xs">

        <div class="grid grid-cols-2 gap-2 md:flex">
            <x-simple-select name="type" :options="['' => __('All types')] + $typeOptions" :value="$filters['type'] ?? ''" full-width />
            <x-simple-select name="status" :options="['' => __('All statuses'), 'active' => __('Active'), 'inactive' => __('Inactive')]" :value="$filters['status'] ?? ''" full-width />
        </div>

        <div class="flex items-center gap-2 md:ml-auto">
            <x-simple-select name="per_page" :options="array_combine($perPageOptions, array_map(fn ($n) => __(':n / page', ['n' => $n]), $perPageOptions))" :value="$filters['per_page']" />
            @if ($hasFilters)
                <a href="{{ route('locations.index') }}" class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('Clear') }}</a>
            @endif
        </div>
    </form>

    @if ($locations->isEmpty())
        <div class="rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center">
            <p class="text-sm text-gray-500">{{ $hasFilters ? __('No locations match these filters.') : __('No locations yet.') }}</p>
            <a href="{{ $hasFilters ? route('locations.index') : route('locations.create') }}" class="mt-3 inline-block text-sm font-medium text-green-900 hover:underline">
                {{ $hasFilters ? __('Clear filters') : __('Add the first location') }}
            </a>
        </div>
    @else
        {{-- Desktop: table --}}
        <div class="hidden overflow-hidden rounded-xl border border-gray-200 bg-white md:block" data-view="table">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3"><a href="{{ $sortUrl('name') }}" class="hover:text-gray-800">{{ __('Name') }} {{ $sortIcon('name') }}</a></th>
                        <th class="px-4 py-3"><a href="{{ $sortUrl('type') }}" class="hover:text-gray-800">{{ __('Type') }} {{ $sortIcon('type') }}</a></th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3"><a href="{{ $sortUrl('updated_at') }}" class="hover:text-gray-800">{{ __('Updated') }} {{ $sortIcon('updated_at') }}</a></th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($locations as $location)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-medium text-gray-800">{{ $location->name }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $location->type->label() }}</td>
                            <td class="px-4 py-3"><x-status-badge :active="$location->is_active" /></td>
                            <td class="px-4 py-3 text-gray-500">{{ $location->updated_at->format('d M Y, g:i A') }}</td>
                            <td class="px-4 py-3 text-right"><x-locations.actions :location="$location" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Mobile: cards --}}
        <div class="space-y-3 md:hidden" data-view="cards">
            @foreach ($locations as $location)
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <p class="font-medium text-gray-800">{{ $location->name }}</p>
                            <p class="text-sm text-gray-500">{{ $location->type->label() }}</p>
                        </div>
                        <x-status-badge :active="$location->is_active" />
                    </div>
                    <div class="mt-3 flex items-center justify-between border-t border-gray-100 pt-3">
                        <span class="text-xs text-gray-400">{{ $location->updated_at->format('d M Y, g:i A') }}</span>
                        <x-locations.actions :location="$location" />
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-4">{{ $locations->links() }}</div>
    @endif

    <x-confirm-modal id="delete-location" :message="__('The location will be removed from lists.')" />
</x-layouts.app>
