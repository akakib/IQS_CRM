{{-- An order held for its advance (the delivery charge): what is needed, and
     "Ask admin" for a moderator / "Process without advance" for an admin.
     <x-advance-wait :order="$order" /> --}}
@props(['order'])

@php
    $advanceHold = $order->hold_reason_id && \App\Models\OrderStatus::map()[$order->status_id]['key'] === 'hold'
        && $order->advance_required && ! $order->advance_waived_at
        && (int) $order->hold_reason_id === (int) \Illuminate\Support\Facades\DB::table('status_reasons')->where('reason_type', 'hold')->where('system_key', 'advance_wait')->value('id');
    $isAdmin = auth()->user()?->can('orders.approve');
@endphp

@if ($advanceHold)
    <div x-data="{ ask: false }" {{ $attributes->merge(['class' => 'mb-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900']) }}>
        <p class="font-semibold">{{ __('Advance needed: ৳:a (delivery charge)', ['a' => number_format((float) $order->advance_required)]) }}</p>
        <p class="mt-0.5">{{ __('Weak or new Steadfast history. Get the delivery charge by bKash / Nagad and add it under Payments: the order then goes to Call by itself.') }}</p>

        @if ($order->advance_waiver_requested_at)
            <p class="mt-2 rounded-lg bg-white px-3 py-2">{{ __('Asked an admin to go without advance: ":w"', ['w' => $order->advance_waiver_note]) }} · {{ $order->advance_waiver_requested_at->diffForHumans() }}</p>
        @endif

        <div class="mt-2 flex flex-wrap gap-2">
            @if ($isAdmin)
                <form method="POST" action="{{ route('orders.advance-waiver.decide', $order) }}">@csrf<input type="hidden" name="decision" value="allow">
                    <button class="rounded-lg bg-primary px-3 py-2 text-xs font-semibold text-white hover:bg-primary-dark">{{ __('Process without advance') }}</button></form>
                @if ($order->advance_waiver_requested_at)
                    <form method="POST" action="{{ route('orders.advance-waiver.decide', $order) }}">@csrf<input type="hidden" name="decision" value="refuse">
                        <button class="rounded-lg border border-amber-400 bg-white px-3 py-2 text-xs font-semibold text-amber-900 hover:bg-amber-100">{{ __('Refuse, advance needed') }}</button></form>
                @endif
            @elseif (! $order->advance_waiver_requested_at)
                <button type="button" @click="ask = !ask" class="rounded-lg border border-amber-400 bg-white px-3 py-2 text-xs font-semibold text-amber-900 hover:bg-amber-100">{{ __('Ask admin: process without advance') }}</button>
            @endif
        </div>
        @unless ($isAdmin)
            <form x-show="ask" x-cloak method="POST" action="{{ route('orders.advance-waiver', $order) }}" class="mt-2 space-y-2">
                @csrf
                <textarea name="why" required minlength="5" maxlength="300" rows="2" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-primary focus:outline-none" placeholder="{{ __('Why without advance? e.g. old customer of the shop, known person') }}"></textarea>
                @error('why')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                <x-button size="sm">{{ __('Send to admin') }}</x-button>
            </form>
        @endunless
    </div>
@endif
