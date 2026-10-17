{{-- A customer's number, ready to call: big digits, tap to call on a phone,
     copy, and a QR code to scan from a PC screen with your phone. With an
     order, each call is logged: the After-the-call panel opens over the page
     (what happened, how happy, the recording or screenshot, a note), the same
     as the call list. How long it took comes by itself.
     <x-phone-dial :phone="$order->ship_phone" :alt="$order->ship_alt_phone" :order-id="$order->id" :name="$order->ship_name" :order-no="$order->order_no" /> --}}
@props(['phone', 'alt' => null, 'orderId' => null, 'name' => null, 'orderNo' => null, 'talked' => false])
{{-- talked: a Talked call is already saved on this order today, so Call verified (call-then-confirm) confirms at once. --}}

@php
    use App\Http\Controllers\CustomerCallController;
    use App\Services\Calls\CallListService;
    $visible = auth()->user()->canSeeField('customer_contact');
    $faces = [0 => '😠', 25 => '🙁', 50 => '😐', 75 => '🙂', 100 => '😍'];
@endphp

<div x-data="phoneDial({{ \Illuminate\Support\Js::from($visible ? $phone : '') }}, {{ \Illuminate\Support\Js::from($orderId) }}, {{ \Illuminate\Support\Js::from(['start' => route('customer-calls.start'), 'finish' => route('customer-calls.finish', 0), 'proof' => 'proof-order-'.$orderId, 'name' => $name, 'talked' => $talked]) }})" @call-then-confirm.window="askConfirm()" {{ $attributes }}>
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
            {{-- The panel was closed with the call not saved: one tap brings it back. --}}
            <button type="button" x-show="after && ! sheet" x-cloak @click="sheet = true" class="mt-2 inline-flex min-h-8 items-center rounded-lg bg-amber-100 px-3 text-xs font-semibold text-amber-900">⏱ {{ __('Call not saved') }} · {{ __('Finish it') }}</button>

            <template x-teleport="body">
                <div x-show="sheet" x-cloak class="fixed inset-0 z-[90] flex items-end justify-center bg-black/40 sm:items-center sm:p-6" role="dialog" aria-modal="true" @keydown.escape.window="sheet = false">
                    <div class="flex max-h-[88dvh] w-full max-w-xl flex-col overflow-hidden rounded-t-2xl bg-white shadow-xl sm:rounded-2xl">
                        <div class="flex shrink-0 items-center justify-between gap-3 border-b border-gray-100 px-4 py-3">
                            <h3 class="text-base font-semibold text-gray-900">{{ $orderNo ? __('Call about :no', ['no' => $orderNo]) : __('After the call') }}</h3>
                            <button type="button" @click="sheet = false" class="flex h-9 w-9 items-center justify-center rounded-full border border-gray-300 text-gray-600 hover:bg-gray-50" aria-label="{{ __('Close') }}">&times;</button>
                        </div>
                        <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-4 py-4">
                            <div class="flex items-center justify-between gap-2 rounded-xl bg-gray-50 px-3 py-2.5">
                                <span class="min-w-0"><span class="block truncate font-semibold text-gray-900">{{ $name }}</span><span class="block font-mono text-sm text-gray-500">{{ $phone }}</span></span>
                                <a href="tel:{{ $phone }}" class="inline-flex min-h-10 shrink-0 items-center gap-1.5 rounded-lg bg-green-600 px-4 text-sm font-semibold text-white hover:bg-green-700 md:hidden"><x-icon name="phone" class="h-4 w-4" /> {{ __('Call') }}</a>
                            </div>

                            <div>
                                <p class="mb-2 text-sm font-semibold text-gray-900">{{ __('What happened?') }}</p>
                                <div class="grid grid-cols-2 gap-2">
                                    @foreach (CustomerCallController::OUTCOMES as $key => $label)
                                        <button type="button" @click="form.outcome = @js($key); error = ''; if (form.outcome !== 'answered') form.satisfaction = null"
                                            :class="form.outcome === @js($key) ? 'border-primary bg-primary text-white' : 'border-gray-300 text-gray-800 hover:border-primary'"
                                            class="rounded-xl border px-3 py-3 text-center text-sm font-medium">{{ __($label) }}</button>
                                    @endforeach
                                </div>
                            </div>

                            <div x-show="form.outcome === 'answered'" x-cloak>
                                <p class="mb-2 text-sm font-semibold text-gray-900">{{ __('How satisfied?') }} <span class="text-xs font-normal text-gray-500">({{ __('optional') }})</span></p>
                                <div class="grid grid-cols-5 gap-1.5">
                                    @foreach (CallListService::SATISFACTION as $v => $label)
                                        <button type="button" @click="form.satisfaction = form.satisfaction === {{ $v }} ? null : {{ $v }}"
                                            :class="form.satisfaction === {{ $v }} ? 'border-2 border-primary bg-primary-soft' : 'border-gray-300'" class="rounded-xl border px-1 py-2 text-center">
                                            <span class="block text-2xl leading-none">{{ $faces[$v] }}</span>
                                            <span class="mt-1 block text-[10px] leading-tight text-gray-600">{{ __($label) }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            </div>

                            <x-call-proof :id="'proof-order-'.$orderId" :title="\Illuminate\Support\Js::from(__('Call recording or screenshot'))" />
                            <textarea x-model="form.note" rows="2" maxlength="500" placeholder="{{ __('Note (optional)') }}" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-base sm:text-sm focus:border-primary focus:outline-none"></textarea>
                            <p x-show="error" x-cloak class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" x-text="error"></p>
                        </div>
                        <div class="shrink-0 border-t border-gray-100 px-4 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
                            <button type="button" @click="save()" :disabled="saving" class="w-full rounded-xl bg-primary px-4 py-3 text-base font-semibold text-white hover:bg-primary-dark disabled:opacity-60" x-text="saving ? @js(__('Saving...')) : @js(__('Save'))"></button>
                        </div>
                    </div>
                </div>
            </template>
        @endif
    @else
        <p class="font-mono text-xl font-semibold text-gray-400">{{ substr($phone, 0, 3) }}*****{{ substr($phone, -3) }}</p>
    @endif
</div>
