@php
    $sortUrl = function (string $column) use ($filters) {
        $dir = ($filters['sort'] === $column && $filters['dir'] === 'asc') ? 'desc' : 'asc';

        return request()->fullUrlWithQuery(['sort' => $column, 'dir' => $dir, 'page' => null]);
    };
    $sortIcon = fn (string $column) => $filters['sort'] === $column ? ($filters['dir'] === 'asc' ? '↑' : '↓') : '';
    $hasFilters = $filters['q'] !== '' || $filters['employment_type'] || $filters['location'] || $filters['status'];
@endphp

<x-layouts.app :heading="__('Staff')">
    <div class="mb-4 flex items-center justify-between gap-3">
        <p class="text-sm text-gray-500">{{ trans_choice(':count staff member|:count staff members', $users->total(), ['count' => $users->total()]) }}</p>
        @can('staff.create')
            <a href="{{ route('users.create') }}"
                class="rounded-lg bg-green-900 px-4 py-2 text-sm font-medium text-white hover:bg-green-800">+ {{ __('Add staff') }}</a>
        @endcan
    </div>

    {{-- Filters live in the query string so a shared link opens the same list. --}}
    <form method="GET" action="{{ route('users.index') }}" x-ref="filters"
        x-data="{ timer: null, go() { clearTimeout(this.timer); this.timer = setTimeout(() => $refs.filters.requestSubmit(), 300) } }"
        @select-change="setTimeout(() => $refs.filters.requestSubmit(), 0)"
        class="mb-4 flex flex-col gap-2 rounded-xl border border-gray-200 bg-white p-3 md:flex-row md:flex-wrap md:items-center">
        <input type="hidden" name="sort" value="{{ $filters['sort'] }}">
        <input type="hidden" name="dir" value="{{ $filters['dir'] }}">

        <input type="search" name="q" value="{{ $filters['q'] }}" @input="go()" placeholder="{{ __('Search name, email or phone') }}"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-green-800 focus:outline-none md:max-w-xs">

        <div class="grid grid-cols-2 gap-2 md:flex">
            <x-simple-select name="employment_type" :options="['' => __('All types')] + $employmentOptions" :value="$filters['employment_type'] ?? ''" full-width />
            <x-simple-select name="location" :options="['' => __('All locations')] + $locationOptions" :value="(string) ($filters['location'] ?? '')" full-width />
            <x-simple-select name="status" :options="['' => __('All statuses'), 'active' => __('Active'), 'inactive' => __('Inactive')]" :value="$filters['status'] ?? ''" full-width />
        </div>

        <div class="flex items-center gap-2 md:ml-auto">
            <x-simple-select name="per_page" :options="array_combine($perPageOptions, array_map(fn ($n) => __(':n / page', ['n' => $n]), $perPageOptions))" :value="$filters['per_page']" />
            @if ($hasFilters)
                <a href="{{ route('users.index') }}" class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('Clear') }}</a>
            @endif
        </div>
    </form>

    @if ($users->isEmpty())
        <div class="rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center">
            <p class="text-sm text-gray-500">{{ __('No staff match these filters.') }}</p>
            <a href="{{ route('users.index') }}" class="mt-3 inline-block text-sm font-medium text-green-900 hover:underline">{{ __('Clear filters') }}</a>
        </div>
    @else
        {{-- Desktop: table --}}
        <div class="hidden overflow-hidden rounded-xl border border-gray-200 bg-white md:block" data-view="table">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3"><a href="{{ $sortUrl('name') }}" class="hover:text-gray-800">{{ __('Name') }} {{ $sortIcon('name') }}</a></th>
                        <th class="px-4 py-3"><a href="{{ $sortUrl('email') }}" class="hover:text-gray-800">{{ __('Email') }} {{ $sortIcon('email') }}</a></th>
                        <th class="px-4 py-3">{{ __('Phone') }}</th>
                        <th class="px-4 py-3">{{ __('Type') }}</th>
                        <th class="px-4 py-3">{{ __('Location') }}</th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($users as $user)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-medium text-gray-800">{{ $user->name }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $user->email }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $user->phone ?? '-' }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $user->employment_type?->label() ?? '-' }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $user->workLocation?->name ?? '-' }}</td>
                            <td class="px-4 py-3"><x-status-badge :active="$user->is_active" /></td>
                            <td class="px-4 py-3 text-right"><x-users.actions :user="$user" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Mobile: cards --}}
        <div class="space-y-3 md:hidden" data-view="cards">
            @foreach ($users as $user)
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-medium text-gray-800">{{ $user->name }}</p>
                            <p class="truncate text-sm text-gray-500">{{ $user->email }}</p>
                            @if ($user->phone)
                                <p class="text-sm text-gray-500">{{ $user->phone }}</p>
                            @endif
                        </div>
                        <x-status-badge :active="$user->is_active" />
                    </div>
                    <div class="mt-3 flex items-center justify-between border-t border-gray-100 pt-3">
                        <span class="text-xs text-gray-400">{{ collect([$user->employment_type?->label(), $user->workLocation?->name])->filter()->join(' · ') ?: '-' }}</span>
                        <x-users.actions :user="$user" />
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-4">{{ $users->links() }}</div>
    @endif

    <x-confirm-modal id="user-status" />
</x-layouts.app>
