{{-- Dashboard home for the Owner and Manager: greeting and date chips, then one card per question.
     Live cards (Needs action now, Steadfast owes us) ignore the dates; the rest follow the picked dates until Clear. --}}
@php
    $tk = fn ($v) => ($v < 0 ? '-' : '').'৳'.number_format(abs($v));
    $orders = fn ($n) => trans_choice(':n order|:n orders', $n, ['n' => number_format($n)]);
    $pl = $money['pl'];
    $hourName = fn (int $h) => ($h % 12 ?: 12).($h < 12 ? 'am' : 'pm');
    $peak = max($money['hours']);
    $peakHour = $peak ? array_search($peak, $money['hours'], true) : null;
    $maxDay = max(array_column($money['days'], 'n') ?: [0]);

    $t = today();
    $chips = [
        __('Today') => [$t->toDateString(), $t->toDateString()],
        __('Yesterday') => [$t->copy()->subDay()->toDateString(), $t->copy()->subDay()->toDateString()],
        __('7 days') => [$t->copy()->subDays(6)->toDateString(), $t->toDateString()],
        __('30 days') => [$t->copy()->subDays(29)->toDateString(), $t->toDateString()],
        __('This month') => [$t->copy()->startOfMonth()->toDateString(), $t->toDateString()],
    ];
    $picked = [$money['from'], $money['to']];
    $custom = ! in_array($picked, $chips, true);
    $day = fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('d M');
    $showing = $money['from'] === $money['to'] ? $day($money['from']) : $day($money['from']).' to '.$day($money['to']);
    $chip = 'inline-flex h-8 items-center rounded-full border px-3 text-sm';
    $live = array_values(array_filter($tiles, fn ($tile) => empty($tile[4])));
    $confirmedN = $money['confirmed']->n;
@endphp

<div class="mb-4">
    <p class="text-sm text-gray-500">{{ now()->format('l, j F') }}</p>
    <p class="text-xl font-semibold text-gray-900">{{ $money['greeting'] }}, {{ auth()->user()->name }}</p>
</div>

{{-- Date chips: one tap picks the dates for every dated card below. Custom opens From and To. --}}
<div class="mb-4" x-data="{ custom: @js($custom) }">
    <div class="flex flex-wrap gap-2">
        @foreach ($chips as $label => [$f, $to])
            <a href="{{ route('dashboard', ['from' => $f, 'to' => $to]) }}" @class([$chip, 'border-primary bg-primary text-white' => $picked === [$f, $to], 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' => $picked !== [$f, $to]])>{{ $label }}</a>
        @endforeach
        <button type="button" @click="custom = ! custom" @class([$chip, 'border-primary bg-primary text-white' => $custom, 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' => ! $custom])>{{ __('Custom') }}</button>
        <a href="{{ route('dashboard', ['clear' => 1]) }}" class="{{ $chip }} border-dashed border-gray-300 text-gray-600 hover:bg-gray-50">{{ __('Clear') }}</a>
    </div>
    <form method="GET" action="{{ route('dashboard') }}" x-show="custom" x-cloak class="mt-3 flex flex-wrap items-center gap-2">
        <x-date-input name="from" :value="$money['from']" :placeholder="__('From')" />
        <x-date-input name="to" :value="$money['to']" :placeholder="__('To')" />
        <x-button size="sm">{{ __('Show') }}</x-button>
    </form>
    <p class="mt-2 text-xs text-gray-500">{{ __('Showing :d. Your pick stays until Clear.', ['d' => $showing]) }}</p>
</div>

<div class="space-y-4">
    {{-- Needs action now: the same live counts as before, each opening its list. --}}
    <section class="rounded-2xl border border-gray-200 bg-white p-4">
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-base font-semibold text-gray-900">{{ __('Needs action now') }}</h2>
            <p class="text-xs text-gray-500">{{ __('Live, not affected by dates') }}</p>
        </div>
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            @foreach ($live as [$label, $value, $href, $trend])
                <x-stat-tile soft :label="$label" :value="number_format($value)" :href="$href" :trend="$trend" />
            @endforeach
        </div>
    </section>

    {{-- How the business is doing: orders and sales in the dates, and when the orders came in. --}}
    <section class="rounded-2xl border border-gray-200 bg-white p-4">
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-base font-semibold text-gray-900">{{ __('How the business is doing') }}</h2>
            <a href="{{ route('sales-report.index', ['from' => $money['from'], 'to' => $money['to']]) }}" class="text-sm text-primary hover:underline">{{ __('Open Sales report') }}</a>
        </div>
        <div class="grid grid-cols-2 gap-3 md:grid-cols-5">
            <x-stat-tile soft :label="__('Orders arrived')" :value="number_format($money['arrived'])" :href="route('orders.index')" />
            <x-stat-tile soft :label="__('Confirmed')" :value="$tk($money['confirmed']->money)" :hint="$orders($confirmedN)" :href="route('sales-report.index', ['from' => $money['from'], 'to' => $money['to']])" />
            <x-stat-tile soft :label="__('Delivered')" :value="$tk($money['delivered']->money)" :hint="$orders($money['delivered']->n)" :href="route('sales-report.index', ['from' => $money['from'], 'to' => $money['to'], 'stage' => 'delivered'])" />
            <x-stat-tile soft :label="__('Ad spend')" :value="$tk($pl['ad_cost'])" :href="route('analysis.index', ['from' => $money['from'], 'to' => $money['to']])" />
            <x-stat-tile soft :label="__('Cost per order')" :value="$confirmedN ? $tk($pl['ad_cost'] / $confirmedN) : '-'" :hint="$confirmedN ? __('Ad spend per confirmed order') : __('No confirmed orders')" />
        </div>

        @php($oneDay = count($money['days']) < 2)
        <div class="mt-4 grid gap-4 {{ $oneDay ? '' : 'lg:grid-cols-2' }}">
            {{-- Orders per day (per month past two months), only when there is more than one; one day is the hour chart alone. --}}
            @unless ($oneDay)
            <div x-data="{ tip: null }">
                <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                    <p class="text-sm text-gray-600">{{ $money['monthly'] ? __('Orders by month') : __('Orders by day') }}</p>
                    <p class="text-xs text-gray-500" x-text="tip ?? @js(trans_choice(':n order in these dates|:n orders in these dates', $money['arrived'], ['n' => number_format($money['arrived'])]))"></p>
                </div>
                <div class="flex h-36 items-end gap-[2px] border-b border-gray-200">
                    @foreach ($money['days'] as $d)
                        @php($text = $d['label'].': '.trans_choice(':n order|:n orders', $d['n'], ['n' => $d['n']]))
                        <div class="flex h-full min-w-0 flex-1 items-end hover:bg-gray-50" @mouseenter="tip = @js($text)" @mouseleave="tip = null" title="{{ $text }}">
                            <div class="w-full rounded-t bg-primary" style="height: {{ $maxDay ? max($d['n'] ? 2 : 0, round($d['n'] / $maxDay * 100)) : 0 }}%"></div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-1 flex justify-between text-[11px] text-gray-500">
                    <span>{{ $money['days'][0]['label'] ?? '' }}</span>
                    <span>{{ last($money['days'])['label'] }}</span>
                </div>
            </div>
            @endunless

            {{-- Orders per hour of the day. --}}
            <div x-data="{ tip: null }">
                <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                    <p class="text-sm text-gray-600">{{ __('Orders by hour') }}</p>
                    <p class="text-xs text-gray-500" x-text="tip ?? @js($peakHour !== null ? __('Busiest: :a to :b, :n orders', ['a' => $hourName($peakHour), 'b' => $hourName(($peakHour + 1) % 24), 'n' => $peak]) : __('No orders in these dates'))"></p>
                </div>
                <div class="flex h-36 items-end gap-[2px] border-b border-gray-200">
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
        </div>
    </section>

    {{-- Profit: orders that ended in the dates, as Analysis counts them. --}}
    <section class="rounded-2xl border border-gray-200 bg-white p-4">
        <div class="mb-1 flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-base font-semibold text-gray-900">{{ __('Profit') }}</h2>
            <a href="{{ route('analysis.index', ['from' => $money['from'], 'to' => $money['to']]) }}" class="text-sm text-primary hover:underline">{{ __('Open Analysis') }}</a>
        </div>
        <p class="mb-3 text-xs text-gray-500">{{ __('Orders that ended in these dates (delivered, partly delivered or returned).') }}</p>
        <div class="grid grid-cols-2 gap-3 md:grid-cols-3">
            <x-stat-tile soft :label="__('Money in')" :value="$tk($pl['revenue'])" :hint="$orders($pl['orders'])" />
            @if ($money['seeCost'])
                <x-stat-tile soft :label="__('Product cost')" :value="$tk($pl['cogs'])" />
            @endif
            <x-stat-tile soft :label="__('Delivery, COD fee, packing')" :value="$tk($pl['delivery'] + $pl['cod_fee'] + $pl['packaging'])" />
            <x-stat-tile soft :label="__('Returns')" :value="$tk($pl['returns'])" />
            <x-stat-tile soft :label="__('Profit before ads')" :value="$tk($pl['profit_before_ads'])" :trend="$pl['profit_before_ads'] < 0 ? 'down' : null" />
            <x-stat-tile soft :label="__('Profit after ads')" :value="$tk($pl['profit'])" :hint="$pl['profit'] < 0 ? __('Loss') : null" :trend="$pl['profit'] < 0 ? 'down' : 'up'" />
        </div>
        @if ($money['missingCost'])
            <p class="mt-3 text-xs text-amber-700">{{ trans_choice(':n order has an item with no cost price, so its whole price counts as profit.|:n orders have an item with no cost price, so their whole price counts as profit.', $money['missingCost'], ['n' => $money['missingCost']]) }}</p>
        @endif
    </section>

    {{-- Products and stock: best sellers in the dates, or what is out of stock now. --}}
    <section class="rounded-2xl border border-gray-200 bg-white p-4" x-data="{ tab: 'selling' }">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-base font-semibold text-gray-900">{{ __('Products and stock') }}</h2>
            <div class="flex gap-2">
                <button type="button" @click="tab = 'selling'" class="{{ $chip }}" :class="tab === 'selling' ? 'border-primary bg-primary text-white' : 'border-gray-300 bg-white text-gray-700'">{{ __('Selling') }}</button>
                <button type="button" @click="tab = 'out'" class="{{ $chip }}" :class="tab === 'out' ? 'border-primary bg-primary text-white' : 'border-gray-300 bg-white text-gray-700'">{{ __('Out of stock') }} ({{ number_format($money['outCount']) }})</button>
            </div>
        </div>
        @php($thumb = fn ($url) => $url ? '<img src="'.e($url).'" onerror="this.style.visibility=\'hidden\'" alt="" loading="lazy" class="size-10 shrink-0 rounded-lg border border-gray-100 object-cover">' : '<span class="size-10 shrink-0 rounded-lg bg-gray-100"></span>')
        <div x-show="tab === 'selling'">
            @forelse ($money['selling'] as $p)
                <div class="flex items-center gap-3 border-b border-gray-100 py-2 last:border-0">
                    {!! $thumb($p->image_url) !!}
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-gray-900">{{ $p->name }}{{ $p->variant !== 'Default' ? ' · '.$p->variant : '' }}</span>
                        <span class="block text-xs text-gray-500">{{ trans_choice(':n order|:n orders', $p->orders, ['n' => number_format($p->orders)]) }}</span>
                    </span>
                    <span class="shrink-0 text-sm font-semibold tabular-nums text-gray-800">{{ $tk($p->money) }}</span>
                </div>
            @empty
                <p class="py-4 text-sm text-gray-500">{{ __('Nothing confirmed in these dates.') }}</p>
            @endforelse
        </div>
        <div x-show="tab === 'out'" x-cloak>
            @forelse ($money['out'] as $p)
                <div class="flex items-center gap-3 border-b border-gray-100 py-2 last:border-0">
                    {!! $thumb($p->image_url) !!}
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-gray-900">{{ $p->name }}{{ $p->variant !== 'Default' ? ' · '.$p->variant : '' }}</span>
                        <span class="block text-xs text-gray-500">{{ $p->oos_marked_at ? __('Out since :d', ['d' => $day($p->oos_marked_at)]) : '' }}</span>
                    </span>
                </div>
            @empty
                <p class="py-4 text-sm text-gray-500">{{ __('Nothing is out of stock.') }}</p>
            @endforelse
            @if ($money['outCount'] > count($money['out']))
                <a href="{{ route('products.availability') }}" class="mt-2 inline-block text-sm text-primary hover:underline">{{ __('See all :n', ['n' => number_format($money['outCount'])]) }}</a>
            @endif
        </div>
    </section>

    {{-- Money: what Steadfast owes now, and what came in during the dates. --}}
    <section class="rounded-2xl border border-gray-200 bg-white p-4">
        <h2 class="mb-3 text-base font-semibold text-gray-900">{{ __('Money owed and received') }}</h2>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <x-stat-tile soft :label="__('Steadfast owes us')" :value="$tk($money['atCourier']->money)" :hint="__('Right now: delivered, not paid out yet (:n parcels)', ['n' => number_format($money['atCourier']->n)])" :href="route('cod.index')" />
            <x-stat-tile soft :label="__('Paid by Steadfast')" :value="$tk($money['paidOut'])" :hint="__('Payout statements in these dates')" :href="route('cod.index', ['tab' => 'statements'])" />
            <x-stat-tile soft :label="__('Advance received')" :value="$tk($money['advance'])" :hint="__('bKash advance checked in these dates')" />
        </div>
    </section>
</div>
