{{-- Settings > Connections: every outside service in one place, one tab each.
     <x-connections-tabs active="website" /> --}}
@props(['active'])

<x-tabs :tabs="[
    'website' => [__('Website'), route('settings.integrations')],
    'courier' => [__('Courier'), route('settings.couriers')],
    'drive' => [__('Google Drive'), route('settings.google-drive')],
]" :active="$active" />
