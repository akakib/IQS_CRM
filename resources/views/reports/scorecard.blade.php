@php
    use App\Services\Reports\WorkTime;
    $span = fn ($s) => WorkTime::span($s === null ? null : (int) $s);
    $pct = fn ($v) => $v === null ? '-' : rtrim(rtrim(number_format((float) $v, 1), '0'), '.').'%';
    $money = fn ($v) => $v === null ? '-' : '৳'.number_format((float) $v);
    $dot = ['green' => 'bg-green-500', 'yellow' => 'bg-amber-400', 'red' => 'bg-red-500'];
    $cell = fn ($r, $m) => ($r['lights'][$m] ?? null) ? '<span class="mr-1.5 inline-block h-2 w-2 rounded-full '.$dot[$r['lights'][$m]].'"></span>' : '';
    $monthUrl = fn ($m) => route('scorecard.index', ['month' => $m->format('Y-m')]);
    $overallText = ['green' => __('Doing well'), 'yellow' => __('Around the team'), 'red' => __('Needs attention')];
@endphp

<x-layouts.app :heading="__('Scorecard')">
    <div class="mb-4 flex flex-wrap items-center gap-2">
        <a href="{{ $monthUrl($month->copy()->subMonth()) }}" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50" aria-label="{{ __('Previous month') }}">‹</a>
        <span class="rounded-full bg-primary px-4 py-1.5 text-sm font-medium text-white">{{ $month->format('F Y') }}</span>
        @if ($month->lt(today()->startOfMonth()))
            <a href="{{ $monthUrl($month->copy()->addMonth()) }}" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50" aria-label="{{ __('Next month') }}">›</a>
        @endif
        @if (! $month->isSameMonth(today()))
            <a href="{{ $monthUrl(today()) }}" class="text-sm text-primary hover:underline">{{ __('This month') }}</a>
        @endif
    </div>
    <p class="mb-4 text-xs text-gray-500">
        {{ __('Each number against the team\'s middle value: green better, red worse (by more than 15%), yellow around it. Delivered and rates count orders that ended this month.') }}
        @if ($seePay){{ __('Profit is before ads, from the orders each person confirmed (a later reassign does not move them), after refunds and returns. Per ৳1 = profit for every taka of salary and bonus.') }}@endif
        @if ($seePay && ($unassigned ?? 0)){{ __('Profit on orders nobody held when they were confirmed: :a.', ['a' => '৳'.number_format($unassigned)]) }}@endif
        <span class="ml-1 inline-flex items-center gap-3 align-middle">
            <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-green-500"></span>{{ __('better') }}</span>
            <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-amber-400"></span>{{ __('around') }}</span>
            <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-red-500"></span>{{ __('worse') }}</span>
        </span>
    </p>

    @if ($rows->isEmpty())
        <x-empty-state :message="__('Nobody worked orders in this month.')" />
    @else
        <x-list.table>
            <x-slot:head>
                <th>{{ __('Person') }}</th>
                <th class="text-right">{{ __('Orders') }}</th>
                <th class="text-right">{{ __('Delivered') }}</th>
                <th class="text-right">{{ __('Delivery %') }}</th>
                <th class="text-right">{{ __('Return %') }}</th>
                <th class="text-right">{{ __('Net time') }}</th>
                <th class="text-right">{{ __('Free, orders waiting') }}</th>
                <th class="text-right">{{ __('Given back') }}</th>
                <th class="text-right">{{ __('Points') }}</th>
                @if ($seePay)
                    <th class="text-right">{{ __('Profit') }}</th>
                    <th class="text-right">{{ __('Salary') }}</th>
                    <th class="text-right">{{ __('Bonus') }}</th>
                    <th class="text-right">{{ __('Per ৳1') }}</th>
                @endif
            </x-slot:head>
            <tr class="bg-gray-50 text-gray-500">
                <td class="text-xs font-medium uppercase">{{ __('Team middle') }}</td>
                <td></td>
                <td class="text-right tabular-nums">{{ $team['delivered'] === null ? '-' : (int) $team['delivered'] }}</td>
                <td class="text-right tabular-nums">{{ $pct($team['delivery_rate']) }}</td>
                <td class="text-right tabular-nums">{{ $pct($team['return_rate']) }}</td>
                <td class="text-right tabular-nums">{{ $span($team['net']) }}</td>
                <td class="text-right tabular-nums">{{ $span($team['free_waiting']) }}</td>
                <td class="text-right tabular-nums">{{ $team['given_back'] === null ? '-' : (int) $team['given_back'] }}</td>
                <td class="text-right tabular-nums">{{ $team['points'] === null ? '-' : $team['points'] + 0 }}</td>
                @if ($seePay)<td></td><td></td><td></td><td class="text-right tabular-nums">{{ $team['per_taka'] === null ? '-' : '৳'.$team['per_taka'] }}</td>@endif
            </tr>
            @foreach ($rows as $r)
                <tr>
                    <td>
                        <span class="flex items-center gap-2"><span class="h-2.5 w-2.5 shrink-0 rounded-full {{ $dot[$r['overall']] }}" title="{{ $overallText[$r['overall']] }}"></span>
                            <a href="{{ route('work-time.index', ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'person' => $r['id']]) }}" class="font-medium text-gray-800 hover:text-primary">{{ $r['name'] }}</a></span>
                        <span class="ml-4.5 block text-xs text-gray-500">{{ $overallText[$r['overall']] }}</span>
                    </td>
                    <td class="text-right tabular-nums">{{ $r['orders'] }}</td>
                    <td class="text-right tabular-nums">{!! $cell($r, 'delivered') !!}{{ $r['delivered'] }}@if ($r['target_count'])<span class="block text-xs {{ $r['delivered'] >= $r['target_count'] ? 'text-green-700' : 'text-gray-400' }}">{{ __('target :t', ['t' => (int) $r['target_count']]) }}</span>@endif</td>
                    <td class="text-right tabular-nums">{!! $cell($r, 'delivery_rate') !!}{{ $pct($r['delivery_rate']) }}</td>
                    <td class="text-right tabular-nums">{!! $cell($r, 'return_rate') !!}{{ $pct($r['return_rate']) }}</td>
                    <td class="text-right tabular-nums">{!! $cell($r, 'net') !!}{{ $span($r['net']) }}</td>
                    <td class="text-right tabular-nums">{!! $cell($r, 'free_waiting') !!}{{ $span($r['free_waiting'] ?: null) }}</td>
                    <td class="text-right tabular-nums">{!! $cell($r, 'given_back') !!}{{ $r['given_back'] }}</td>
                    <td class="text-right tabular-nums">{!! $cell($r, 'points') !!}{{ $r['points'] + 0 }}</td>
                    @if ($seePay)
                        <td class="text-right tabular-nums">{{ $money($r['profit']) }}</td>
                        <td class="text-right tabular-nums">{{ $money($r['salary']) }}</td>
                        <td class="text-right">
                            @if ($canSetBonus)
                                <form method="POST" action="{{ route('scorecard.bonus', $r['id']) }}" class="flex items-center justify-end gap-1">
                                    @csrf
                                    <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
                                    <input name="amount" type="number" min="0" step="1" value="{{ $r['bonus'] === null ? '' : $r['bonus'] + 0 }}" placeholder="0" aria-label="{{ __('Bonus') }}"
                                        class="w-20 rounded-lg border border-gray-300 px-2 py-1 text-right text-sm tabular-nums focus:border-primary focus:outline-none">
                                    <button class="rounded-lg border border-gray-300 px-2 py-1 text-xs text-gray-700 hover:bg-gray-50">{{ __('Save') }}</button>
                                </form>
                            @else
                                <span class="tabular-nums">{{ $money($r['bonus']) }}</span>
                            @endif
                        </td>
                        <td class="text-right font-medium tabular-nums">{!! $cell($r, 'per_taka') !!}{{ $r['per_taka'] === null ? '-' : '৳'.$r['per_taka'] }}</td>
                    @endif
                </tr>
            @endforeach
        </x-list.table>

        <x-list.cards>
            @foreach ($rows as $r)
                <a href="{{ route('work-time.index', ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'person' => $r['id']]) }}" class="block">
                    <x-record-card :title="$r['name']" :subtitle="__(':o orders · :d delivered · :p delivery · :r return', ['o' => $r['orders'], 'd' => $r['delivered'], 'p' => $pct($r['delivery_rate']), 'r' => $pct($r['return_rate'])])">
                        <x-slot:badge><span class="inline-flex items-center gap-1.5 text-xs font-medium text-gray-700"><span class="h-2.5 w-2.5 rounded-full {{ $dot[$r['overall']] }}"></span>{{ $overallText[$r['overall']] }}</span></x-slot:badge>
                        <x-slot:footer>{{ __('Net :n · free while waiting :f · given back :g · points :p', ['n' => $span($r['net']), 'f' => $span($r['free_waiting'] ?: null), 'g' => $r['given_back'], 'p' => $r['points'] + 0]) }}@if ($seePay)<span class="block">{{ __('Profit :a · pay :b', ['a' => $money($r['profit']), 'b' => $money($r['pay'] ?: null)]) }}</span>@endif</x-slot:footer>
                        <x-slot:actions>@if ($seePay)<span class="text-right"><span class="block text-xs text-gray-500">{{ __('Per ৳1') }}</span><span class="font-semibold tabular-nums">{{ $r['per_taka'] === null ? '-' : '৳'.$r['per_taka'] }}</span></span>@endif</x-slot:actions>
                    </x-record-card>
                </a>
            @endforeach
        </x-list.cards>
    @endif
</x-layouts.app>
