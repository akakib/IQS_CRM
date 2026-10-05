{{-- A customer's number, ready to call: big digits, tap to call on a phone,
     copy, and a QR code to scan from a PC screen with your phone.
     <x-phone-dial :phone="$order->ship_phone" :alt="$order->ship_alt_phone" /> --}}
@props(['phone', 'alt' => null])

@php($visible = auth()->user()->canSeeField('customer_contact'))

<div x-data="phoneDial({{ \Illuminate\Support\Js::from($visible ? $phone : '') }})" {{ $attributes }}>
    @if ($visible)
        <a href="tel:{{ $phone }}" class="block font-mono text-xl font-semibold tracking-wide text-gray-900 hover:text-primary">{{ $phone }}</a>
        @if ($alt)<a href="tel:{{ $alt }}" class="block font-mono text-sm text-gray-500 hover:text-primary">{{ $alt }}</a>@endif
        <div class="mt-1.5 flex flex-wrap items-center gap-3 text-xs font-medium">
            <a href="tel:{{ $phone }}" class="text-primary hover:underline md:hidden">{{ __('Tap to call') }}</a>
            <button type="button" @click="copy()" class="text-primary hover:underline" x-text="copied ? @js(__('Copied')) : @js(__('Copy'))"></button>
            <button type="button" @click="toggleQr()" class="hidden text-primary hover:underline md:inline" x-text="showQr ? @js(__('Hide QR')) : @js(__('QR to call from phone'))"></button>
        </div>
        <div x-show="showQr" x-cloak class="mt-2 h-32 w-32 rounded-lg border border-gray-200 bg-white p-1" x-html="qr"></div>
    @else
        <p class="font-mono text-xl font-semibold text-gray-400">{{ substr($phone, 0, 3) }}*****{{ substr($phone, -3) }}</p>
    @endif
</div>
