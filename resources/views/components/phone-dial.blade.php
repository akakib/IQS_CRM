{{-- A customer's number, ready to call: big digits, tap to call on a phone,
     copy, and a QR code to scan from a PC screen with your phone. With an
     order, each call is logged: who, when, how it went, how long, recording.
     <x-phone-dial :phone="$order->ship_phone" :alt="$order->ship_alt_phone" :order-id="$order->id" /> --}}
@props(['phone', 'alt' => null, 'orderId' => null])

@php($visible = auth()->user()->canSeeField('customer_contact'))

<div x-data="phoneDial({{ \Illuminate\Support\Js::from($visible ? $phone : '') }}, {{ \Illuminate\Support\Js::from($orderId) }}, {{ \Illuminate\Support\Js::from(['start' => route('customer-calls.start'), 'finish' => route('customer-calls.finish', 0)]) }})" {{ $attributes }}>
    @if ($visible)
        <a href="tel:{{ $phone }}" @click="started()" class="block font-mono text-xl font-semibold tracking-wide text-gray-900 hover:text-primary">{{ $phone }}</a>
        @if ($alt)<a href="tel:{{ $alt }}" @click="started()" class="block font-mono text-sm text-gray-500 hover:text-primary">{{ $alt }}</a>@endif
        <div class="mt-1.5 flex flex-wrap items-center gap-3 text-xs font-medium">
            <a href="tel:{{ $phone }}" @click="started()" class="text-primary hover:underline md:hidden">{{ __('Tap to call') }}</a>
            <button type="button" @click="copy()" class="text-primary hover:underline" x-text="copied ? @js(__('Copied')) : @js(__('Copy'))"></button>
            <button type="button" @click="toggleQr()" class="hidden text-primary hover:underline md:inline" x-text="showQr ? @js(__('Hide QR')) : @js(__('QR to call from phone'))"></button>
        </div>
        <div x-show="showQr" x-cloak class="mt-2 h-32 w-32 rounded-lg border border-gray-200 bg-white p-1" x-html="qr"></div>

        @if ($orderId)
            <p x-show="saved" x-cloak class="mt-2 text-xs font-medium text-emerald-700" x-text="saved"></p>
            <div x-show="after" x-cloak class="mt-3 space-y-2.5 rounded-lg border border-gray-200 bg-gray-50 p-3">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('After the call') }}</p>
                <div class="flex flex-wrap gap-1.5">
                    @foreach (\App\Http\Controllers\CustomerCallController::OUTCOMES as $key => $label)
                        <button type="button" @click="form.outcome = @js($key)"
                            :class="form.outcome === @js($key) ? 'border-primary bg-primary text-white' : 'border-gray-300 bg-white text-gray-700 hover:border-primary'"
                            class="rounded-full border px-3 py-1 text-xs font-medium">{{ __($label) }}</button>
                    @endforeach
                </div>
                <div class="flex items-center gap-1.5 text-sm">
                    <span class="w-16 text-xs text-gray-500">{{ __('Duration') }}</span>
                    <input type="number" min="0" max="300" x-model="form.minutes" placeholder="{{ __('min') }}" class="w-16 rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-sm focus:border-primary focus:outline-none">
                    <span class="text-gray-400">:</span>
                    <input type="number" min="0" max="59" x-model="form.seconds" placeholder="{{ __('sec') }}" class="w-16 rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-sm focus:border-primary focus:outline-none">
                </div>
                <input type="url" x-model="form.recording_url" placeholder="{{ __('Recording link (Google Drive)') }}" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none">
                <input type="text" x-model="form.note" maxlength="500" placeholder="{{ __('Note (optional)') }}" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none">
                <p x-show="error" x-cloak class="text-xs text-red-600" x-text="error"></p>
                <div class="flex items-center gap-2">
                    <button type="button" @click="save()" :disabled="saving" class="rounded-md bg-primary px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-60">{{ __('Save call') }}</button>
                    <button type="button" @click="after = false" class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700">{{ __('Later') }}</button>
                </div>
            </div>
        @endif
    @else
        <p class="font-mono text-xl font-semibold text-gray-400">{{ substr($phone, 0, 3) }}*****{{ substr($phone, -3) }}</p>
    @endif
</div>
