@props(['active'])

<span @class([
    'inline-block rounded-full px-2.5 py-0.5 text-xs font-medium',
    'bg-green-50 text-green-800' => $active,
    'bg-gray-100 text-gray-500' => ! $active,
])>{{ $active ? __('Active') : __('Inactive') }}</span>
