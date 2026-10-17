{{-- The orders behind a Sales report number, loaded into its popup (SalesReportController::orders).
     A list, not a table, so it reads the same on a phone. Tap an order number to open the order. --}}
@php
    $money = fn ($n) => '৳'.number_format((float) $n);
    $num = fn ($n) => rtrim(rtrim(number_format((float) $n, 3), '0'), '.');
    // What became of an order since: shown so a cancelled or held order is never read as a sale.
    $since = collect(['cancelled' => __('Now cancelled'), 'hold' => __('Now on hold'), 'returned' => __('Returned')])
        ->mapWithKeys(fn ($l, $k) => [\App\Models\OrderStatus::idFor($k) => $l]);
@endphp

<p class="mb-3 text-sm text-gray-600">
    {{ trans_choice(':n order|:n orders', $count, ['n' => number_format($count)]) }}
    @isset($only['item'])· {{ __('Returned: :n', ['n' => $returned]) }}@endisset
    @if ($count > $rows->count())· {{ __('showing the first :n', ['n' => $rows->count()]) }}@endif
</p>

@if ($rows->isEmpty())
    <p class="py-6 text-center text-sm text-gray-500">{{ __('No orders.') }}</p>
@else
    <ul class="divide-y divide-gray-100">
        @foreach ($rows as $r)
            <li class="flex items-start justify-between gap-3 py-2.5">
                <div class="min-w-0">
                    <p class="truncate text-sm">
                        @if ($canOpen)
                            <button type="button" data-order="{{ $r->id }}" data-no="{{ $r->order_no }}" class="font-semibold text-primary underline-offset-2 hover:underline">{{ $r->order_no }} ›</button>
                        @else
                            <span class="font-semibold text-gray-900">{{ $r->order_no }}</span>
                        @endif
                        <span class="text-gray-800">{{ $r->customer }}</span>
                    </p>
                    <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500">
                        <span>{{ \Illuminate\Support\Carbon::parse($r->at)->format('d M, g:i A') }}</span>
                        @if ($r->first_item)<span class="truncate">{{ $r->first_item }}{{ $r->line_count > 1 ? ' '.__('+ :n more', ['n' => $r->line_count - 1]) : '' }}</span>@endif
                        <span @class(['rounded-full px-2 py-0.5 font-medium', 'bg-primary-soft text-primary' => $r->is_returning, 'bg-gray-100 text-gray-600' => ! $r->is_returning])>{{ $r->is_returning ? __('Repeat customer') : __('New customer') }}</span>
                        @isset($since[$r->status_id])<span class="rounded-full bg-amber-100 px-2 py-0.5 font-semibold text-amber-800">{{ $since[$r->status_id] }}</span>@endisset
                        @unless (isset($only['channel']))<span>{{ \App\Models\Order::channelLabel($r->channel) }}</span>@endunless
                        @if ($r->moderator)<span>{{ __('with :name', ['name' => $r->moderator]) }}</span>@endif
                    </p>
                </div>
                <div class="shrink-0 text-right text-sm tabular-nums">
                    <p class="font-semibold text-gray-900">{{ $money($r->grand_total) }}</p>
                    @isset($only['item'])<p class="text-xs text-gray-500">{{ __('Qty :n', ['n' => $num($r->item_qty)]) }}</p>@endisset
                </div>
            </li>
        @endforeach
    </ul>
@endif
