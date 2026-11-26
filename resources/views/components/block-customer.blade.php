{{-- Block the customer from an order: owner and managers (orders.approve). Blocked here = new orders stop for a person;
     the order can be cancelled in the same step; a website order is blocked on the website too (Fake Order Guard:
     phone / device / IP, 7 or 30 days or for good). Everyone sees the red Blocked mark and its reason.
     <x-block-customer :order="$order" /> --}}
@props(['order'])

@php
    $c = $order->customer;
    $canBlock = $c && auth()->user()?->can('orders.approve');
    $blocked = $c && $c->risk_level === 'blocked';
    $web = $order->channel === 'web' && $order->external_ref;
    $final = \App\Models\OrderStatus::map()[$order->status_id]['final'] ?? false;
    $cancelReasons = $canBlock && ! $blocked && ! $final ? \App\Models\StatusReason::staffOptions('cancel') : [];
@endphp

@if ($c && ($blocked || $canBlock))
    <div class="mt-3 flex flex-wrap items-center gap-2" x-data="{ open: false, why: null, cancel: {{ $final ? 'false' : 'true' }}, cwhy: null }">
        @if ($blocked)
            <span class="inline-flex items-center rounded-full bg-red-50 px-2.5 py-1 text-xs font-semibold text-red-700">{{ __('Blocked') }}@if ($c->blocked_reason) · {{ $c->blocked_reason }}@endif</span>
            @if ($canBlock)
                <form method="POST" action="{{ route('orders.unblock', $order) }}" id="unblock-{{ $order->id }}">@csrf</form>
                <button type="button" @click="$dispatch('open-confirm', { id: 'unblock-confirm-{{ $order->id }}', form: 'unblock-{{ $order->id }}', label: @js($c->primary_phone) })"
                    class="lockable rounded-lg border border-gray-300 px-2.5 py-1 text-xs font-semibold text-gray-700 hover:border-primary hover:text-primary">{{ __('Unblock') }}</button>
                <x-confirm-modal :id="'unblock-confirm-'.$order->id" :verb="__('Unblock')" :danger="false"
                    :message="$web ? __('New orders from this customer go on as usual, here and on the website.') : __('New orders from this customer go on as usual.')" />
            @endif
        @elseif ($canBlock)
            <button type="button" @click="open = true; why = null"
                class="lockable rounded-lg border border-red-300 px-2.5 py-1 text-xs font-semibold text-red-700 hover:bg-red-50">{{ __('Block') }}</button>

            <div x-show="open" x-cloak class="fixed inset-0 z-[80] flex items-end justify-center sm:items-center sm:px-4" @keydown.escape.window="open = false">
                <div class="absolute inset-0 bg-black/40"></div>
                <form method="POST" action="{{ route('orders.block', $order) }}" class="relative max-h-[90dvh] w-full max-w-md overflow-y-auto rounded-t-2xl bg-white p-5 shadow-xl sm:rounded-xl">
                    @csrf
                    <input type="hidden" name="reason" :value="why">
                    <input type="hidden" name="cancel" :value="cancel ? 1 : 0">
                    <input type="hidden" name="cancel_reason_id" :value="cwhy">
                    <h3 class="text-sm font-semibold text-gray-800">{{ __('Block :p?', ['p' => $c->primary_phone]) }}</h3>
                    <p class="mt-0.5 text-xs text-gray-500">{{ $web ? __('New orders from this customer stop here and on the website.') : __('New orders from this customer stop here for a person to check.') }}</p>

                    <p class="mt-3 text-xs font-medium text-gray-600">{{ __('Why block?') }}</p>
                    <div class="mt-1.5 space-y-1.5">
                        @foreach (\App\Services\Customers\CustomerBlocker::REASONS as $r)
                            <button type="button" @click="why = @js($r)"
                                class="flex w-full items-center rounded-lg border px-3 py-2 text-left text-sm"
                                :class="why === @js($r) ? 'border-red-400 bg-red-50 font-medium text-red-800' : 'border-gray-200 text-gray-700 hover:bg-gray-50'">{{ __($r) }}</button>
                        @endforeach
                    </div>

                    @if ($web)
                        {{-- The website's Fake Order Guard: same choices as its Block box. --}}
                        <p class="mt-3 text-xs font-medium text-gray-600">{{ __('Block on the website') }}</p>
                        <div class="mt-1.5 flex flex-wrap gap-x-4 gap-y-1.5 text-sm text-gray-700">
                            <label class="inline-flex items-center gap-1.5"><input type="checkbox" name="types[]" value="phone" checked class="rounded border-gray-300"> {{ __('Phone') }}</label>
                            <label class="inline-flex items-center gap-1.5"><input type="checkbox" name="types[]" value="device" checked class="rounded border-gray-300"> {{ __('Device') }}</label>
                            <label class="inline-flex items-center gap-1.5" title="{{ __('A mobile IP is shared by many people: 7 days at most') }}"><input type="checkbox" name="types[]" value="ip" class="rounded border-gray-300"> {{ __('IP (7 days at most)') }}</label>
                        </div>
                        <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1.5 text-sm text-gray-700">
                            <label class="inline-flex items-center gap-1.5"><input type="radio" name="days" value="7"> {{ __('7 days') }}</label>
                            <label class="inline-flex items-center gap-1.5"><input type="radio" name="days" value="30" checked> {{ __('30 days') }}</label>
                            <label class="inline-flex items-center gap-1.5"><input type="radio" name="days" value="0"> {{ __('Permanent') }}</label>
                        </div>
                    @endif

                    @unless ($final)
                        <label class="mt-3 flex items-center gap-2 text-sm text-gray-800"><input type="checkbox" x-model="cancel" class="rounded border-gray-300"> {{ __('Also cancel this order') }}</label>
                        <div x-show="cancel" class="mt-1.5 flex flex-wrap gap-1.5">
                            @foreach ($cancelReasons as $id => $label)
                                <button type="button" @click="cwhy = {{ $id }}" class="rounded-lg border px-2.5 py-1 text-xs"
                                    :class="cwhy === {{ $id }} ? 'border-red-400 bg-red-50 font-medium text-red-800' : 'border-gray-300 text-gray-700 hover:bg-gray-50'">{{ __($label) }}</button>
                            @endforeach
                        </div>
                    @endunless

                    <input name="note" maxlength="200" placeholder="{{ __('Note (optional)') }}" class="mt-3 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-primary focus:outline-none">
                    <div class="mt-4 flex gap-2">
                        <button type="button" @click="open = false" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('Back') }}</button>
                        <button type="submit" :disabled="! why || (cancel && ! cwhy)" class="flex-1 rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50"
                            x-text="cancel ? @js(__('Block and cancel')) : @js(__('Block'))"></button>
                    </div>
                </form>
            </div>
        @endif
    </div>
@endif
