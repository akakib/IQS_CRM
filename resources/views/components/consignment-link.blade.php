{{-- A parcel's CN. A Steadfast parcel opens its page in the Steadfast panel in a new tab; any other courier is plain text.
     <x-consignment-link :cn="$s->consignment_id" :courier="$s->courier" /> --}}
@props(['cn', 'courier' => 'steadfast'])
@if ($cn && $courier === 'steadfast')
    <a href="{{ config('courier.steadfast.consignment_url').rawurlencode((string) $cn) }}" target="_blank" rel="noopener noreferrer" title="{{ __('Open in Steadfast') }}"
        {{ $attributes->merge(['class' => 'font-mono text-primary hover:underline']) }}>{{ __('CN') }} {{ $cn }}</a>
@else
    <span {{ $attributes->merge(['class' => 'font-mono']) }}>{{ __('CN') }} {{ $cn ?: '-' }}</span>
@endif
