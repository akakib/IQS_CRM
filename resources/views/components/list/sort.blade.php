{{-- Sortable column header link. <x-list.sort :list="$list" column="name">Name</x-list.sort> --}}
@props(['list', 'column'])

<a href="{{ $list->sortUrl($column) }}" class="inline-flex items-center gap-1 hover:text-gray-800">{{ $slot }} <span class="text-gray-400">{{ $list->sortIcon($column) }}</span></a>
