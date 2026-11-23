{{-- Dashboard money part (Owner and Manager): sales, cost, profit, cash and when orders come in, for the picked dates. --}}
@php
    $tk = fn ($v) => ($v < 0 ? '-' : '').'৳'.number_format(abs($v));
    $pl = $money['pl'];
    $hourName = fn (int $h) => ($h % 12 ?: 12).($h < 12 ? 'am' : 'pm');
    $peak = max($money['hours']);
    $peakHour = $peak ? array_search($peak, $money['hours'], true) : null;
    $maxDay = max(array_column($money['days'], 'n') ?: [0]);
@endphp

<form method="GET" action="{{ route('dashboard') }}" x-ref="f" @select-change="setTimeout(() => $refs.f.requestSubmit(), 0)"
    class="mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-gray-200 bg-white p-3">
    <x-date-range :from="$money['from']" :to="$money['to']" />
    <x-button size="sm">{{ __('Show') }}</x-button>
    @if ($money['picked'])
        <a href="{{ route('dashboard', ['clear' => 1]) }}" class="inline-flex h-8 items-center rounded-lg border border-gray-300 px-3 text-xs text-gray-600 hover:bg-gray-50">{{ __('Clear') }}</a>
    @endif
</form>

<h2 class="mb-2 text-sm font-semibold text-gray-700">{{ __('Sales') }}</h2>
<div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
    <x-stat-tile :label="__('Confirmed')" :value="$tk($money['confirmed']->money)" :hint="trans_choice(':n order|:n orders', $money['confirmed']->n, ['n' => number_format($money['confirmed']->n)])" :href="route('sales-report.index', ['from' => $money['from'], 'to' => $money['to']])" />
    <x-stat-tile :label="__('Delivered')" :value="$tk($money['delivered']->money)" :hint="trans_choice(':n order|:n orders', $money['delivered']->n, ['n' => number_format($money['delivered']->n)])" :href="route('sales-report.index', ['from' => $money['from'], 'to' => $money['to'], 'stage' => 'delivered'])" />
    <x-stat-tile :label="__('Orders arrived')" :value="number_format($money['arrived'])" :href="route('orders.index')" />
    <x-stat-tile :label="__('Ad spend')" :value="$tk($pl['ad_cost'])" :href="route('analysis.index', ['from' => $money['from'], 'to' => $money['to']])" />
</div>

<h2 class="mb-1 text-sm font-semibold text-gray-700">{{ __('Profit') }}</h2>
<p class="mb-2 text-xs text-gray-500">{{ __('Orders that ended in these dates (delivered, partly delivered or returned), as Analysis counts them.') }}</p>
<div class="mb-2 grid grid-cols-2 gap-3 md:grid-cols-4">
    <x-stat-tile :label="__('Money in')" :value="$tk($pl['revenue'])" :hint="trans_choice(':n order|:n orders', $pl['orders'], ['n' => number_format($pl['orders'])])" />
    @if ($money['seeCost'])
        <x-stat-tile :label="__('Product cost')" :value="$tk($pl['cogs'])" />
    @endif
    <x-stat-tile :label="__('Delivery, COD fee, packing')" :value="$tk($pl['delivery'] + $pl['cod_fee'] + $pl['packaging'])" />
    <x-stat-tile :label="__('Returns')" :value="$tk($pl['returns'])" />
    <x-stat-tile :label="__('Profit before ads')" :value="$tk($pl['profit_before_ads'])" :trend="$pl['profit_before_ads'] < 0 ? 'down' : null" />
    <x-stat-tile :label="__('Profit after ads')" :value="$tk($pl['profit'])" :hint="$pl['profit'] < 0 ? __('Loss') : null" :trend="$pl['profit'] < 0 ? 'down' : 'up'" :href="route('analysis.index', ['from' => $money['from'], 'to' => $money['to']])" />
</div>
@if ($money['missingCost'])
    <p class="mb-2 text-xs text-amber-700">{{ trans_choice(':n order has an item with no cost price, so its whole price counts as profit.|:n orders have an item with no cost price, so their whole price counts as profit.', $money['missingCost'], ['n' => $money['missingCost']]) }}</p>
@endif

<h2 class="mb-2 mt-5 text-sm font-semibold text-gray-700">{{ __('Cash') }}</h2>
<div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
    <x-stat-tile :label="__('With Steadfast, not paid yet')" :value="$tk($money['atCourier']->money)" :hint="__('Now, after the delivery charge: :n parcels', ['n' => number_format($money['atCourier']->n)])" :href="route('cod.index')" />
    <x-stat-tile :label="__('Paid by Steadfast')" :value="$tk($money['paidOut'])" :hint="__('Payout statements in these dates')" :href="route('cod.index', ['tab' => 'statements'])" />
    <x-stat-tile :label="__('Advance received')" :value="$tk($money['advance'])" :hint="__('bKash advance checked in these dates')" />
</div>

<div class="mb-5 grid gap-3 lg:grid-cols-2">
    {{-- Orders per hour of the day. One series: no legend, the title names it; hover a column for its number. --}}
    <div class="rounded-xl border border-gray-200 bg-white p-4" x-data="{ tip: null }">
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
            <p class="text-sm font-semibold text-gray-800">{{ __('Orders by hour') }}</p>
            <p class="text-xs text-gray-500" x-text="tip ?? @js($peakHour !== null ? __('Busiest: :a to :b, :n orders', ['a' => $hourName($peakHour), 'b' => $hourName(($peakHour + 1) % 24), 'n' => $peak]) : __('No orders in these dates'))"></p>
        </div>
        <div class="flex h-40 items-end gap-[2px] border-b border-gray-200">
            @foreach ($money['hours'] as $h => $n)
                @php($text = $hourName($h).' to '.$hourName(($h + 1) % 24).': '.trans_choice(':n order|:n orders', $n, ['n' => $n]))
                <div class="flex h-full flex-1 items-end hover:bg-gray-50" @mouseenter="tip = @js($text)" @mouseleave="tip = null" title="{{ $text }}">
                    <div class="w-full rounded-t bg-primary" style="height: {{ $peak ? max($n ? 2 : 0, round($n / $peak * 100)) : 0 }}%"></div>
                </div>
            @endforeach
        </div>
        <div class="mt-1 flex text-[11px] text-gray-500">
            @foreach ([0, 6, 12, 18] as $h)
                <span class="flex-1">{{ $hourName($h) }}</span>
            @endforeach
        </div>
    </div>

    {{-- Orders per day (per month past two months) in the dates. --}}
    <div class="rounded-xl border border-gray-200 bg-white p-4" x-data="{ tip: null }">
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
            <p class="text-sm font-semibold text-gray-800">{{ $money['monthly'] ? __('Orders by month') : __('Orders by day') }}</p>
            <p class="text-xs text-gray-500" x-text="tip ?? @js(trans_choice(':n order in these dates|:n orders in these dates', $money['arrived'], ['n' => number_format($money['arrived'])]))"></p>
        </div>
        <div class="flex h-40 items-end gap-[2px] border-b border-gray-200">
            @foreach ($money['days'] as $d)
                @php($text = $d['label'].': '.trans_choice(':n order|:n orders', $d['n'], ['n' => $d['n']]))
                <div class="flex h-full min-w-0 flex-1 items-end hover:bg-gray-50" @mouseenter="tip = @js($text)" @mouseleave="tip = null" title="{{ $text }}">
                    <div class="w-full rounded-t bg-primary" style="height: {{ $maxDay ? max($d['n'] ? 2 : 0, round($d['n'] / $maxDay * 100)) : 0 }}%"></div>
                </div>
            @endforeach
        </div>
        <div class="mt-1 flex justify-between text-[11px] text-gray-500">
            <span>{{ $money['days'][0]['label'] ?? '' }}</span>
            @if (count($money['days']) > 1)<span>{{ last($money['days'])['label'] }}</span>@endif
        </div>
    </div>
</div>

<h2 class="mb-2 text-sm font-semibold text-gray-700">{{ __('Orders now') }}</h2>
