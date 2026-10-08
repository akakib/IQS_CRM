@php
    $tk = fn ($v) => '৳'.number_format((float) $v);
    $groups = ['day' => __('By day'), 'channel' => __('By channel'), 'moderator' => __('By moderator'), 'product' => __('By product'), 'category' => __('By category')];
    $lineLevel = in_array($group, ['product', 'category'], true);
    $label = fn ($r) => match ($group) {
        'moderator' => $moderators[$r->g] ?? __('Nobody'),
        'channel' => ucfirst($r->g),
        'day' => \Illuminate\Support\Carbon::parse($r->g)->format('d M Y'),
        default => $r->label ?? __('Uncategorised'),
    };
    $query = fn ($extra) => route('analysis.index', array_filter(['from' => $from, 'to' => $to, 'channel' => $channel] + $extra));
@endphp

<x-layouts.app :heading="__('Order P&L')">
    <p class="mb-4 text-sm text-gray-500">{{ __('Orders that ended (delivered, partial or returned) in the period. Ad cost: the ad cost of the day an order was placed, shared equally over the orders of that day (real taka from the dollar lots).') }}</p>

    <form method="GET" action="{{ route('analysis.index') }}" x-ref="f" @select-change="setTimeout(() => $refs.f.requestSubmit(), 0)"
        class="mb-4 flex flex-col gap-2 rounded-xl border border-gray-200 bg-white p-3 md:flex-row md:flex-wrap md:items-center">
        <input type="hidden" name="group" value="{{ $group }}">
        <x-date-range :from="$from" :to="$to" />
        <div class="flex items-center gap-2 md:ml-auto">
            <x-simple-select name="channel" :options="['' => __('All channels'), 'web' => __('Website'), 'messenger' => 'Messenger', 'whatsapp' => 'WhatsApp', 'phone' => __('Phone'), 'b2b' => 'B2B', 'other' => __('Other')]" :value="$channel ?? ''" />
            <x-simple-select name="per_page" :options="[25 => __(':n / page', ['n' => 25]), 50 => __(':n / page', ['n' => 50]), 100 => __(':n / page', ['n' => 100])]" :value="$perPage" />
            <x-button size="sm">{{ __('Show') }}</x-button>
            @if (request()->hasAny(['from', 'to', 'channel']))
                <a href="{{ route('analysis.index', ['group' => $group]) }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-50">{{ __('Clear') }}</a>
            @endif
        </div>
    </form>

    <div class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-4">
        <x-stat-tile :label="__('Revenue')" :value="$tk($totals['revenue'])" :hint="__(':d delivered of :n ended', ['d' => $totals['delivered'], 'n' => $totals['orders']])" />
        @if ($seeCost)
            <x-stat-tile :label="__('Product cost')" :value="$tk($totals['cogs'])" />
        @endif
        <x-stat-tile :label="__('Delivery, COD fee, packaging')" :value="$tk($totals['delivery'] + $totals['cod_fee'] + $totals['packaging'])" />
        <x-stat-tile :label="__('Ad cost')" :value="$tk($totals['ad_cost'])" />
        @if ($seeProfit)
            <x-stat-tile :label="__('Profit before ads')" :value="$tk($totals['profit_before_ads'])" :trend="$totals['profit_before_ads'] >= 0 ? 'up' : 'down'" />
            <x-stat-tile :label="__('Profit after ads')" :value="$tk($totals['profit'])" :trend="$totals['profit'] >= 0 ? 'up' : 'down'"
                :hint="$totals['revenue'] > 0 ? __('Margin :p%', ['p' => round(100 * $totals['profit'] / $totals['revenue'], 1)]) : null" />
        @endif
    </div>

    <x-tabs :tabs="collect($groups)->map(fn ($l, $k) => [$l, $query(['group' => $k])])->all()" :active="$group" />

    @if ($rows->isEmpty())
        <div class="rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center text-sm text-gray-500">{{ __('No ended orders in this period.') }}</div>
    @else
        <x-list.table>
            <x-slot:head>
                <th>{{ $groups[$group] }}</th><th class="text-right">{{ __('Orders') }}</th>
                @if ($lineLevel)<th class="text-right">{{ __('Qty') }}</th>@endif
                <th class="text-right">{{ __('Revenue') }}</th>
                @if ($seeCost)<th class="text-right">{{ __('Product cost') }}</th>@endif
                @unless ($lineLevel)<th class="text-right">{{ __('Delivery + COD + pack') }}</th><th class="text-right">{{ __('Ad cost') }}</th>@endunless
                @if ($seeProfit)<th class="text-right">{{ $lineLevel ? __('Gross profit') : __('Profit after ads') }}</th>@endif
            </x-slot:head>
            @foreach ($rows as $r)
                <tr class="border-t border-gray-100 [&_td]:px-4 [&_td]:py-3">
                    <td class="font-medium text-gray-800">{{ $label($r) }}</td>
                    <td class="text-right tabular-nums">{{ $r->orders }}</td>
                    @if ($lineLevel)<td class="text-right tabular-nums">{{ rtrim(rtrim(number_format((float) $r->qty, 3), '0'), '.') }}</td>@endif
                    <td class="text-right tabular-nums">{{ $tk($r->revenue) }}</td>
                    @if ($seeCost)<td class="text-right tabular-nums">{{ $tk($r->cogs) }}</td>@endif
                    @unless ($lineLevel)<td class="text-right tabular-nums">{{ $tk($r->delivery + $r->cod_fee + $r->packaging) }}</td><td class="text-right tabular-nums">{{ $tk($r->ad_cost) }}</td>@endunless
                    @if ($seeProfit)<td @class(['text-right font-semibold tabular-nums', 'text-green-800' => $r->profit >= 0, 'text-red-700' => $r->profit < 0])>{{ $tk($r->profit) }}</td>@endif
                </tr>
            @endforeach
        </x-list.table>

        <x-list.cards>
            @foreach ($rows as $r)
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="flex items-center justify-between">
                        <p class="font-medium text-gray-800">{{ $label($r) }}</p>
                        @if ($seeProfit)<p @class(['font-semibold tabular-nums', 'text-green-800' => $r->profit >= 0, 'text-red-700' => $r->profit < 0])>{{ $tk($r->profit) }}</p>@endif
                    </div>
                    <p class="mt-1 text-xs text-gray-500">
                        {{ __(':n orders', ['n' => $r->orders]) }} · {{ __('Revenue') }} {{ $tk($r->revenue) }}
                        @if ($seeCost) · {{ __('Cost') }} {{ $tk($r->cogs) }} @endif
                    </p>
                </div>
            @endforeach
        </x-list.cards>

        <div class="mt-4">{{ $rows->links() }}</div>
    @endif
</x-layouts.app>
