{{-- Control room popup: the orders behind one number. Pages load inside the popup. --}}
@php
    use Illuminate\Support\Carbon;
    $ago = fn ($t) => $t ? Carbon::parse($t)->diffForHumans(now(), ['short' => true, 'syntax' => Carbon::DIFF_ABSOLUTE, 'parts' => 1]) : '-';
@endphp
@if ($rows->isEmpty())
    <p class="py-10 text-center text-sm text-gray-500">{{ __('Nothing here right now.') }}</p>
@else
    <p class="mb-3 text-xs text-gray-500">{{ trans_choice(':count order|:count orders', $rows->total()) }}</p>
    <div class="divide-y divide-gray-100 rounded-xl border border-gray-200">
        @foreach ($rows as $o)
            @php $st = $statuses[$o->status_id] ?? null; @endphp
            <a href="{{ route('orders.show', $o->id) }}" target="_blank" class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-4 py-3 hover:bg-gray-50">
                <span class="min-w-0">
                    <span class="block text-sm font-medium text-gray-900">{{ $o->ship_name }} <span class="font-normal text-gray-500">· {{ $o->ship_phone }}</span></span>
                    <span class="block text-xs text-gray-500">{{ $o->order_no }} · ৳{{ number_format((float) $o->grand_total) }}
                        @if ($st) · <span style="color: {{ $st['color'] }}">{{ __($st['name']) }}</span>@endif
                        · @if ($box === 'timed_out'){{ __('given back at :t', ['t' => Carbon::parse($o->timed_out_at)->format('g:i A')]) }}@elseif ($box === 'stage'){{ __('untouched :t', ['t' => $ago($o->updated_at)]) }}@else{{ __('waiting :t', ['t' => $ago($o->queue_since ?? $o->assigned_at ?? $o->created_at)]) }}@endif
                    </span>
                </span>
                <span class="flex shrink-0 items-center gap-1.5 text-xs text-gray-600">
                    @if ($o->moderator)<x-avatar :name="$o->moderator" :photo="$o->moderator_photo" size="sm" /> {{ $o->moderator }}@else <span class="text-gray-400">{{ __('Nobody') }}</span>@endif
                </span>
            </a>
        @endforeach
    </div>
    @if ($rows->hasPages())<div class="mt-4" data-pages>{{ $rows->links() }}</div>@endif
@endif
