<x-layouts.app :heading="__('Locations')">
    <div class="mb-4 flex items-center justify-between gap-3">
        <p class="text-sm text-gray-500">{{ trans_choice(':count location|:count locations', $locations->total(), ['count' => $locations->total()]) }}</p>
        @can('locations.create')
            <x-button :href="route('locations.create')">+ {{ __('New location') }}</x-button>
        @endcan
    </div>

    <x-list.filter-bar :list="$list" :action="route('locations.index')" :placeholder="__('Search by name')">
        <x-simple-select name="type" :options="['' => __('All types')] + $typeOptions" :value="$list->filter('type') ?? ''" />
        <x-simple-select name="status" :options="['' => __('All statuses'), 'active' => __('Active'), 'inactive' => __('Inactive')]" :value="$list->filter('status') ?? ''" />
    </x-list.filter-bar>

    @if ($locations->isEmpty())
        @if ($list->hasFilters())
            <x-empty-state :message="__('No locations match these filters.')" :action="route('locations.index')" :action-label="__('Clear filters')" />
        @else
            <x-empty-state :message="__('No locations yet.')" :action="route('locations.create')" :action-label="__('Add the first location')" />
        @endif
    @else
        @php($canBulk = auth()->user()->can('locations.edit'))
        <x-list.selectable :ids="$canBulk ? $locations->pluck('id') : []">
            <x-list.table>
                <x-slot:head>
                    @if ($canBulk)<th class="w-8"></th>@endif
                    <th><x-list.sort :list="$list" column="name">{{ __('Name') }}</x-list.sort></th>
                    <th><x-list.sort :list="$list" column="type">{{ __('Type') }}</x-list.sort></th>
                    <th>{{ __('Status') }}</th>
                    <th><x-list.sort :list="$list" column="updated_at">{{ __('Updated') }}</x-list.sort></th>
                    <th class="text-right">{{ __('Actions') }}</th>
                </x-slot:head>
                @foreach ($locations as $location)
                    <tr>
                        @if ($canBulk)<td><x-list.check :id="$location->id" /></td>@endif
                        <td class="font-medium text-gray-800">{{ $location->name }}</td>
                        <td class="text-gray-600">{{ $location->type->label() }}</td>
                        <td><x-status-badge :active="$location->is_active" /></td>
                        <td class="text-gray-500">{{ $location->updated_at->format('d M Y, g:i A') }}</td>
                        <td class="text-right"><x-locations.actions :location="$location" /></td>
                    </tr>
                @endforeach
            </x-list.table>

            <x-list.cards>
                @foreach ($locations as $location)
                    <x-record-card :title="$location->name" :subtitle="$location->type->label()">
                        <x-slot:badge>
                            <div class="flex items-center gap-2">
                                <x-status-badge :active="$location->is_active" />
                                @if ($canBulk)<x-list.check :id="$location->id" />@endif
                            </div>
                        </x-slot:badge>
                        <x-slot:footer>{{ $location->updated_at->format('d M Y, g:i A') }}</x-slot:footer>
                        <x-slot:actions><x-locations.actions :location="$location" /></x-slot:actions>
                    </x-record-card>
                @endforeach
            </x-list.cards>

            @if ($canBulk)
                <x-slot:actions>
                    @foreach (['activate' => __('Activate'), 'deactivate' => __('Deactivate')] as $action => $label)
                        <form method="POST" action="{{ route('locations.bulk') }}">
                            @csrf
                            <input type="hidden" name="action" value="{{ $action }}">
                            <x-list.selected-inputs />
                            <button type="submit" class="rounded-lg bg-white/10 px-3 py-1.5 text-xs font-medium hover:bg-white/20">{{ $label }}</button>
                        </form>
                    @endforeach
                </x-slot:actions>
            @endif
        </x-list.selectable>

        <div class="mt-4">{{ $locations->links() }}</div>
    @endif

    <x-confirm-modal id="delete-location" :message="__('The location will be removed from lists.')" />
</x-layouts.app>
