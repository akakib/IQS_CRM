<x-layouts.app :heading="__('Staff')">
    <div class="mb-4 flex items-center justify-between gap-3">
        <p class="text-sm text-gray-500">{{ trans_choice(':count staff member|:count staff members', $users->total(), ['count' => $users->total()]) }}</p>
        @can('staff.create')
            <x-button :href="route('users.create')">+ {{ __('Add staff') }}</x-button>
        @endcan
    </div>

    <x-list.filter-bar :list="$list" :action="route('users.index')" :placeholder="__('Search name, email or phone')">
        <x-simple-select name="employment_type" :options="['' => __('All types')] + $employmentOptions" :value="$list->filter('employment_type') ?? ''" />
        <x-simple-select name="location" :options="['' => __('All locations')] + $locationOptions" :value="$list->filter('location') ?? ''" />
        <x-simple-select name="status" :options="['' => __('All statuses'), 'active' => __('Active'), 'inactive' => __('Inactive')]" :value="$list->filter('status') ?? ''" />
    </x-list.filter-bar>

    @if ($users->isEmpty())
        <x-empty-state :message="__('No staff match these filters.')" :action="route('users.index')" :action-label="__('Clear filters')" />
    @else
        <x-list.table>
            <x-slot:head>
                <th><x-list.sort :list="$list" column="name">{{ __('Name') }}</x-list.sort></th>
                <th><x-list.sort :list="$list" column="email">{{ __('Email') }}</x-list.sort></th>
                <th>{{ __('Phone') }}</th>
                <th>{{ __('Type') }}</th>
                <th>{{ __('Location') }}</th>
                <th>{{ __('Status') }}</th>
                <th class="text-right">{{ __('Actions') }}</th>
            </x-slot:head>
            @foreach ($users as $user)
                <tr>
                    <td class="font-medium text-gray-800">{{ $user->name }}</td>
                    <td class="text-gray-600">{{ $user->email }}</td>
                    <td class="text-gray-600">{{ $user->phone ?? '-' }}</td>
                    <td class="text-gray-600">{{ $user->employment_type?->label() ?? '-' }}</td>
                    <td class="text-gray-600">{{ $user->workLocation?->name ?? '-' }}</td>
                    <td><x-status-badge :active="$user->is_active" /></td>
                    <td class="text-right"><x-users.actions :user="$user" /></td>
                </tr>
            @endforeach
        </x-list.table>

        <x-list.cards>
            @foreach ($users as $user)
                <x-record-card :title="$user->name" :subtitle="$user->email">
                    @if ($user->phone)<p class="text-sm text-gray-500">{{ $user->phone }}</p>@endif
                    <x-slot:badge><x-status-badge :active="$user->is_active" /></x-slot:badge>
                    <x-slot:footer>{{ collect([$user->employment_type?->label(), $user->workLocation?->name])->filter()->join(' · ') ?: '-' }}</x-slot:footer>
                    <x-slot:actions><x-users.actions :user="$user" /></x-slot:actions>
                </x-record-card>
            @endforeach
        </x-list.cards>

        <div class="mt-4">{{ $users->links() }}</div>
    @endif

    <x-confirm-modal id="user-status" />
</x-layouts.app>
