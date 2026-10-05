{{-- One order on the activity board: the customer, the money, who holds it
     and what is happening right now. Tap opens the order in the popup. --}}
@php
    use Illuminate\Support\Carbon;
    $key = $statuses[$o->status_id]['key'] ?? '';
    $since = Carbon::parse($o->queue_since ?? $o->assigned_at ?? $o->created_at);
    $age = $since->diffForHumans(now(), ['short' => true, 'syntax' => Carbon::DIFF_ABSOLUTE, 'parts' => 1]);
    $left = $o->action_due_at ? max(0, now()->diffInSeconds(Carbon::parse($o->action_due_at), false)) : null;
    $late = (in_array($key, ['new', 'record_verified', 'no_answer'], true) && ! $o->moderator_id && $since->lt(now()->subMinutes((int) settings('desk.auto_assign_minutes'))))
        || ($left !== null && $left <= 120)
        || $o->booking_state === 'failed';
    $repack = $o->packed_version && $o->packed_version < $o->current_version && in_array($key, ['packed', 'ready_for_pickup'], true);
@endphp

<button type="button" @click="$dispatch('open-order', {{ $o->id }})"
    @class([
        'block w-full cursor-grab rounded-xl border bg-white p-3 text-left shadow-sm transition hover:border-primary hover:shadow',
        'border-red-300 ring-1 ring-red-200' => $late || $repack,
        'border-gray-200' => ! $late && ! $repack,
    ])>
    <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
            <p class="truncate text-sm font-semibold text-gray-900">{{ $o->ship_name }}</p>
            <p class="text-xs text-gray-500">{{ $o->ship_phone }}</p>
        </div>
        <p class="shrink-0 text-sm font-semibold tabular-nums text-gray-900">৳{{ number_format((float) $o->grand_total) }}</p>
    </div>
    <p class="mt-1 text-[11px] text-gray-500">{{ $o->order_no }} · {{ ucfirst($o->channel) }} · <span @class(['text-red-600 font-medium' => $late])>{{ $age }}</span></p>

    {{-- What is happening now --}}
    <div class="mt-2 text-xs">
        @if ($repack)
            <span class="font-medium text-red-700">{{ __('Edited after packing: repack') }}</span>
        @elseif (in_array($key, ['new', 'record_verified', 'no_answer'], true) && ! $o->moderator_id)
            <span class="text-gray-600">{{ __('Waiting for someone to take it') }}</span>
        @elseif (in_array($key, ['new', 'record_verified', 'no_answer'], true) && $left !== null)
            <span class="inline-flex items-center gap-1.5 text-gray-700">{{ $key === 'new' ? __('Checking the record') : __('Calling') }} <x-countdown :seconds="$left" /></span>
        @elseif ($key === 'no_answer' && $o->next_call_at && Carbon::parse($o->next_call_at)->isFuture())
            <span class="text-orange-700">{{ __('Call again at :t · try :n', ['t' => Carbon::parse($o->next_call_at)->isToday() ? Carbon::parse($o->next_call_at)->format('g:i A') : Carbon::parse($o->next_call_at)->format('d M, g:i A'), 'n' => $o->no_response_count + 1]) }}</span>
        @elseif (in_array($key, ['new', 'record_verified', 'no_answer'], true))
            <span class="text-gray-600">{{ __('Not opened yet') }}</span>
        @elseif ($key === 'hold')
            <span class="text-red-700">{{ $o->hold_reason ?? __('On hold') }}@if ($o->hold_expected_date) · {{ __('until :d', ['d' => Carbon::parse($o->hold_expected_date)->format('d M')]) }}@endif</span>
        @elseif ($key === 'confirmed' && $o->booking_state === 'failed')
            <span class="font-medium text-red-700">{{ __('Courier booking failed') }}</span>
        @elseif ($key === 'confirmed' && $o->booking_state === 'queued')
            <span class="text-purple-700">{{ __('Booking the courier…') }}</span>
        @elseif ($key === 'confirmed')
            <span class="text-green-700">{{ __('Confirmed: ready to send to packaging') }}</span>
        @elseif ($key === 'ready_for_packaging')
            <span class="text-purple-700">{{ $o->packer ? __('Being packed by :n', ['n' => $o->packer]) : __('Waiting for a packer') }}</span>
        @elseif ($key === 'packed')
            <span class="text-purple-700">{{ $o->packer ? __('Packed by :n', ['n' => $o->packer]) : __('Packed') }}</span>
        @elseif ($key === 'ready_for_pickup')
            <span class="text-purple-700">{{ __('Ready for the rider') }}</span>
        @elseif ($key === 'handed_over')
            <span class="text-teal-700">{{ __('Handed to the rider') }}</span>
        @elseif ($key === 'in_transit')
            <span class="text-teal-700">{{ __('On the way to the customer') }}</span>
        @endif
    </div>

    {{-- Who worked on it: the moderator who prepared the order, and (once packaging started) the packer. --}}
    <div class="mt-2 flex items-center justify-between gap-2 border-t border-gray-100 pt-2">
        @if ($o->moderator)
            <span class="flex min-w-0 items-center gap-1.5" title="{{ __('Order by :n', ['n' => $o->moderator]) }}">
                <x-avatar :name="$o->moderator" :photo="$o->moderator_photo" size="sm" />
                <span class="min-w-0">
                    <span class="block truncate text-xs font-medium text-gray-700">{{ $o->moderator }}</span>
                    @if ($o->packer)<span class="block text-[10px] leading-none text-gray-400">{{ __('Order') }}</span>@endif
                </span>
                @if ($o->moderator_on_break)<span class="shrink-0 rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-medium text-amber-700">{{ __('On break') }}</span>@endif
            </span>
        @else
            <span class="text-xs text-gray-400">{{ __('Nobody') }}</span>
        @endif
        @if ($o->packer)
            <span class="flex min-w-0 shrink-0 items-center gap-1.5" title="{{ __('Packaging by :n', ['n' => $o->packer]) }}">
                <span class="min-w-0 text-right">
                    <span class="block truncate text-xs font-medium text-gray-700">{{ $o->packer }}</span>
                    <span class="block text-[10px] leading-none text-gray-400">{{ __('Packing') }}</span>
                </span>
                <span class="relative">
                    <x-avatar :name="$o->packer" :photo="$o->packer_photo" size="sm" />
                    <span class="absolute -bottom-1 -right-1 flex h-3.5 w-3.5 items-center justify-center rounded-full bg-purple-600 text-white ring-2 ring-white" aria-hidden="true"><x-icon name="box" class="h-2 w-2" /></span>
                </span>
            </span>
        @endif
    </div>
</button>
