{{-- An order held for its advance (the delivery charge). The moderator calls
     once (address, upsell, ask for the advance) and records the outcome here:
     paid now (Add payment: the order goes on to booking), will pay by a date,
     or Ask admin to go without. Admins can allow it straight away.
     <x-advance-wait :order="$order" /> --}}
@props(['order'])

@php
    $advanceHold = $order->hold_reason_id && \App\Models\OrderStatus::map()[$order->status_id]['key'] === 'hold'
        && $order->advance_required && ! $order->advance_waived_at
        && (int) $order->hold_reason_id === (int) app(\App\Services\Orders\DeskService::class)->advanceReasonId();
    $isAdmin = auth()->user()?->can('orders.approve');
    $selfLimit = (int) settings('advance.self_waive_up_to');
    $canSelfWaive = ! $isAdmin && $selfLimit > 0 && (float) $order->cod_amount <= $selfLimit;
    $remaining = max(0, (float) $order->advance_required - (float) $order->advance_verified);
    $methods = $advanceHold ? \Illuminate\Support\Facades\DB::table('payment_methods')->where('is_active', true)->orderBy('id')->pluck('name', 'id')->all() : [];
    $input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-primary focus:outline-none';
@endphp

@if ($advanceHold)
    <div x-data="{ panel: null }" {{ $attributes->merge(['class' => 'mb-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900']) }}>
        <p class="font-semibold">{{ __('Advance needed: ৳:a (delivery charge)', ['a' => number_format((float) $order->advance_required)]) }}
            @if ($remaining > 0 && $remaining < (float) $order->advance_required) · {{ __('৳:r more', ['r' => number_format($remaining)]) }}@endif</p>
        <p class="mt-0.5">{{ __('Weak or new Steadfast history. Call once: check the address, offer more, and ask for the delivery charge by bKash / Nagad.') }}</p>
        @if ($order->hold_expected_date)
            <p class="mt-1 text-xs">{{ __('Customer said: by :d', ['d' => $order->hold_expected_date->format('d M')]) }}</p>
        @endif
        @if ($order->advance_waiver_requested_at)
            <p class="mt-2 rounded-lg bg-white px-3 py-2">{{ __('Asked an admin to go without advance: ":w"', ['w' => $order->advance_waiver_note]) }} · {{ $order->advance_waiver_requested_at->diffForHumans() }}</p>
        @endif

        <div class="mt-2 flex flex-wrap gap-2">
            <button type="button" @click="panel = panel === 'pay' ? null : 'pay'" class="rounded-lg bg-primary px-3 py-2 text-xs font-semibold text-white hover:bg-primary-dark">{{ __('Customer paid: add payment') }}</button>
            <button type="button" @click="panel = panel === 'date' ? null : 'date'" class="rounded-lg border border-amber-400 bg-white px-3 py-2 text-xs font-semibold text-amber-900 hover:bg-amber-100">{{ __('Will pay by a date') }}</button>
            @if ($isAdmin)
                <form method="POST" action="{{ route('orders.advance-waiver.decide', $order) }}" id="waive-{{ $order->id }}">@csrf<input type="hidden" name="decision" value="allow">
                    <button type="button" @click="$dispatch('open-confirm', { id: 'waive-confirm-{{ $order->id }}', form: 'waive-{{ $order->id }}', label: @js($order->order_no) })" class="rounded-lg border border-amber-400 bg-white px-3 py-2 text-xs font-semibold text-amber-900 hover:bg-amber-100">{{ __('Process without advance') }}</button></form>
                <x-confirm-modal :id="'waive-confirm-'.$order->id" :verb="__('Process without advance')" :message="__('The order goes on to booking with no advance paid.')" :danger="false" />
                @if ($order->advance_waiver_requested_at)
                    <form method="POST" action="{{ route('orders.advance-waiver.decide', $order) }}">@csrf<input type="hidden" name="decision" value="refuse">
                        <button class="rounded-lg border border-amber-400 bg-white px-3 py-2 text-xs font-semibold text-amber-900 hover:bg-amber-100">{{ __('Refuse, advance needed') }}</button></form>
                @endif
            @elseif ($canSelfWaive)
                <button type="button" @click="panel = panel === 'self' ? null : 'self'" class="rounded-lg border border-amber-400 bg-white px-3 py-2 text-xs font-semibold text-amber-900 hover:bg-amber-100">{{ __('Process without advance') }}</button>
            @elseif (! $order->advance_waiver_requested_at)
                <button type="button" @click="panel = panel === 'ask' ? null : 'ask'" class="rounded-lg border border-amber-400 bg-white px-3 py-2 text-xs font-semibold text-amber-900 hover:bg-amber-100">{{ __('Ask admin: process without advance') }}</button>
            @endif
        </div>

        <form x-show="panel === 'pay'" x-cloak method="POST" action="{{ route('orders.payments.store', $order) }}" class="mt-2 grid gap-2 sm:grid-cols-2">
            @csrf
            <input type="hidden" name="called" value="1">
            <x-simple-select name="advance[method_id]" :options="$methods" :value="old('advance.method_id')" :placeholder="__('How did they pay?')" full-width />
            <input name="advance[amount]" type="number" step="0.01" min="1" required value="{{ old('advance.amount', $remaining ?: $order->advance_required) }}" class="{{ $input }}">
            <input name="advance[transaction_id]" maxlength="100" required placeholder="{{ __('TrxID') }}" class="{{ $input }} font-mono">
            <input name="advance[sender_number]" maxlength="20" placeholder="{{ __('Sender number (optional)') }}" class="{{ $input }} font-mono">
            @foreach (['advance.method_id', 'advance.amount', 'advance.transaction_id'] as $f)
                @error($f)<p class="text-xs text-red-600 sm:col-span-2">{{ $message }}</p>@enderror
            @endforeach
            <p class="text-xs sm:col-span-2">{{ __('Saved as a call. Once the advance counts, the order goes to booking by itself (no second call).') }}</p>
            <div class="sm:col-span-2"><x-button size="sm">{{ __('Save payment') }}</x-button></div>
        </form>

        <form x-show="panel === 'date'" x-cloak method="POST" action="{{ route('orders.advance-date', $order) }}" class="mt-2 flex flex-wrap items-end gap-2">
            @csrf
            <label class="text-xs">{{ __('Customer will send it by') }}<x-date-input name="date" :min="today()->toDateString()" :value="$order->hold_expected_date?->toDateString()" :clearable="false" full-width class="mt-1" /></label>
            <div class="flex w-full flex-wrap gap-1.5">
                @foreach (\App\Services\Orders\DeskService::holdQuickNotes() as $line)
                    <button type="button" @click="$refs.payNote.value = @js($line)" class="rounded-full border border-amber-300 bg-white px-2.5 py-1 text-xs text-amber-900 hover:border-amber-500">{{ $line }}</button>
                @endforeach
            </div>
            <textarea name="note" x-ref="payNote" required minlength="3" maxlength="500" rows="2" placeholder="{{ __('What the customer said (whoever opens it next reads this)') }}" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:outline-none">{{ old('note') }}</textarea>
            @error('note')<p class="w-full text-xs text-red-600">{{ $message }}</p>@enderror
            <x-button size="sm">{{ __('Save') }}</x-button>
            @error('date')<p class="w-full text-xs text-red-600">{{ $message }}</p>@enderror
        </form>

        @if ($canSelfWaive)
            <form x-show="panel === 'self'" x-cloak method="POST" action="{{ route('orders.advance-waiver.self', $order) }}" class="mt-2 space-y-2">
                @csrf
                <p class="text-xs">{{ __('Small order (COD up to ৳:l): you may let it go without the advance. Your reason is kept on the order.', ['l' => number_format($selfLimit)]) }}</p>
                <textarea name="why" required minlength="5" maxlength="300" rows="2" class="{{ $input }}" placeholder="{{ __('Why without advance? e.g. old customer of the shop, known person') }}"></textarea>
                @error('why')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                <x-button size="sm">{{ __('Go without advance') }}</x-button>
            </form>
        @endif
        @unless ($isAdmin)
            <form x-show="panel === 'ask'" x-cloak method="POST" action="{{ route('orders.advance-waiver', $order) }}" class="mt-2 space-y-2">
                @csrf
                <textarea name="why" required minlength="5" maxlength="300" rows="2" class="{{ $input }}" placeholder="{{ __('Why without advance? e.g. old customer of the shop, known person') }}"></textarea>
                @error('why')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                <x-button size="sm">{{ __('Send to admin') }}</x-button>
            </form>
        @endunless
    </div>
@endif
