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
    {{-- Phone: the Block button sits at the top right of its card (the card is relative); a blocked customer's mark keeps its own line. --}}
    <div @class(['mt-3 flex flex-wrap items-center gap-2', 'max-sm:absolute max-sm:right-3 max-sm:top-2 max-sm:mt-0' => ! $blocked]) x-data="{ open: false, cancel: {{ $final ? 'false' : 'true' }}, cwhy: null, phone: true, device: true, ipOn: false, days: 30, ip: null, asked: false,
            show() { this.open = true; if ({{ $web ? 'true' : 'false' }} && ! this.asked) { this.asked = true; fetch(@js(route('orders.block-info', $order)), { headers: { Accept: 'application/json' } }).then(r => r.json()).then(d => this.ip = d.ip).catch(() => {}); } } }">
        @if ($blocked)
            <span class="inline-flex items-center rounded-full bg-red-50 px-2.5 py-1 text-xs font-semibold text-red-700">{{ __('Blocked') }}@if ($c->blocked_reason) · {{ $c->blocked_reason }}@endif</span>
            @if ($canBlock)
                <form method="POST" action="{{ route('orders.unblock', $order) }}" id="unblock-{{ $order->id }}">@csrf</form>
                <button type="button" @click="$dispatch('open-confirm', { id: 'unblock-confirm-{{ $order->id }}', form: 'unblock-{{ $order->id }}', label: @js($c->primary_phone ?: $order->ship_phone) })"
                    class="lockable rounded-lg border border-gray-300 px-2.5 py-1 text-xs font-semibold text-gray-700 hover:border-primary hover:text-primary">{{ __('Unblock') }}</button>
                <x-confirm-modal :id="'unblock-confirm-'.$order->id" :verb="__('Unblock')" :danger="false"
                    :message="$web ? __('New orders from this customer go on as usual, here and on the website.') : __('New orders from this customer go on as usual.')" />
            @endif
        @elseif ($canBlock)
            <button type="button" @click="show()"
                class="lockable rounded-lg border border-red-300 px-2.5 py-1 text-xs font-semibold text-red-700 hover:bg-red-50">{{ __('Block') }}</button>

            <div x-show="open" x-cloak class="fixed inset-0 z-[80] flex items-end justify-center sm:items-center sm:px-4" @keydown.escape.window="open = false">
                <div class="absolute inset-0 bg-black/40"></div>
                <form method="POST" action="{{ route('orders.block', $order) }}" class="relative max-h-[90dvh] w-full max-w-md overflow-y-auto rounded-t-2xl bg-white p-5 shadow-xl sm:rounded-xl">
                    @csrf
                    <input type="hidden" name="cancel" :value="cancel ? 1 : 0">
                    <input type="hidden" name="cancel_reason_id" :value="cwhy">
                    <h3 class="text-base font-semibold text-gray-900">{{ __('Block this customer') }}</h3>
                    <p class="mt-0.5 text-xs text-gray-500">{{ $order->order_no }} · {{ $order->ship_name }}</p>

                    {{-- Same choices as the website's Block box (Fake Order Guard). A website order is blocked there too. --}}
                    <div class="mt-3 divide-y divide-gray-100">
                        <label class="flex items-start gap-3 py-2.5">
                            <input type="checkbox" @if ($web) name="types[]" value="phone" x-model="phone" @else checked disabled @endif class="mt-0.5 size-5 shrink-0 rounded border-gray-300">
                            <span><span class="block text-sm text-gray-900">{{ __('Phone :p', ['p' => $c->primary_phone ?: $order->ship_phone]) }}</span>
                                <span class="block text-xs text-gray-500">{{ __('Most reliable, belongs to one person') }}</span></span>
                        </label>
                        @if ($web)
                            <label class="flex items-start gap-3 py-2.5">
                                <input type="checkbox" name="types[]" value="device" x-model="device" class="mt-0.5 size-5 shrink-0 rounded border-gray-300">
                                <span><span class="block text-sm text-gray-900">{{ __('This device') }}</span>
                                    <span class="block text-xs text-gray-500">{{ __('Works even when the IP changes') }}</span></span>
                            </label>
                            <label class="flex items-start gap-3 py-2.5">
                                <input type="checkbox" name="types[]" value="ip" x-model="ipOn" class="mt-0.5 size-5 shrink-0 rounded border-gray-300">
                                <span><span class="block text-sm text-gray-900">{{ __('IP') }} <span x-text="ip || ''"></span></span>
                                    <span class="block text-xs text-gray-500">{{ __('Off by default: a mobile IP is shared by many real customers. Tick only for home Wi-Fi') }}</span></span>
                            </label>
                        @endif
                    </div>

                    @if ($web)
                        <input type="hidden" name="days" :value="days">
                        <p class="mt-3 text-sm font-semibold text-gray-800">{{ __('How long') }}</p>
                        <div class="mt-1.5 flex flex-wrap gap-2">
                            @foreach ([7 => '7 days', 30 => '30 days', 0 => 'Permanent'] as $d => $label)
                                <button type="button" @click="days = {{ $d }}" class="h-8 rounded-full border px-3.5 text-sm"
                                    :class="days === {{ $d }} ? 'border-primary bg-primary font-medium text-white' : 'border-gray-300 text-gray-700 hover:bg-gray-50'">{{ __($label) }}</button>
                            @endforeach
                        </div>
                        <p class="mt-2 text-xs text-gray-500">{{ __('IP is always blocked for 7 days at most.') }}</p>
                    @else
                        <p class="mt-2 text-xs text-gray-500">{{ __('This order did not come from the website, so the customer is blocked in T5 CRM only: new orders stop for a person to check.') }}</p>
                    @endif

                    <textarea name="reason" maxlength="200" rows="2" placeholder="{{ __('Reason (optional)') }}" class="mt-3 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-primary focus:outline-none"></textarea>

                    @unless ($final)
                        <label class="mt-1 flex items-center gap-2 text-sm text-gray-800"><input type="checkbox" x-model="cancel" class="rounded border-gray-300"> {{ __('Also cancel this order') }}</label>
                        <div x-show="cancel" class="mt-1.5 flex flex-wrap gap-1.5">
                            @foreach ($cancelReasons as $id => $label)
                                <button type="button" @click="cwhy = {{ $id }}" class="rounded-lg border px-2.5 py-1 text-xs"
                                    :class="cwhy === {{ $id }} ? 'border-red-400 bg-red-50 font-medium text-red-800' : 'border-gray-300 text-gray-700 hover:bg-gray-50'">{{ __($label) }}</button>
                            @endforeach
                        </div>
                    @endunless
                    <div class="mt-4 flex gap-2">
                        <button type="button" @click="open = false" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 hover:bg-gray-50">{{ __('Back') }}</button>
                        <button type="submit" :disabled="(cancel && ! cwhy){{ $web ? ' || ! (phone || device || ipOn)' : '' }}" class="flex-1 rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50"
                            x-text="cancel ? @js(__('Block and cancel')) : @js(__('Block'))"></button>
                    </div>
                </form>
            </div>
        @endif
    </div>
@endif
