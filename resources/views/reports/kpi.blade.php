@php($pct = fn ($v) => $v === null ? '-' : $v.'%')

<x-layouts.app :heading="__('KPI scorecard')">
    <p class="mb-4 text-sm text-gray-500">{{ __('Order volume, speed and quality per order owner. Separate from points. Weights: volume :v%, speed :s%, quality :q% (Settings > General).', ['v' => $weights['volume'], 's' => $weights['speed'], 'q' => $weights['quality']]) }}</p>

    <form method="GET" action="{{ route('kpi.index') }}" class="mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-gray-200 bg-white p-3">
        <x-date-range :from="$from" :to="$to" />
        <x-button size="sm">{{ __('Show') }}</x-button>
        @if (request()->hasAny(['from', 'to']))
            <a href="{{ route('kpi.index') }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-50">{{ __('Clear') }}</a>
        @endif
    </form>

    @if ($rows === [])
        <div class="rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center text-sm text-gray-500">{{ __('No order activity in this period.') }}</div>
    @else
        <x-list.table>
            <x-slot:head>
                <th>{{ __('Person') }}</th><th class="text-right">{{ __('Taken') }}</th><th class="text-right">{{ __('Confirmed') }}</th>
                <th class="text-right">{{ __('Delivered') }}</th><th class="text-right">{{ __('Returned') }}</th><th class="text-right">{{ __('Cancelled') }}</th>
                <th class="text-right">{{ __('Delivered rate') }}</th><th class="text-right">{{ __('Median confirm (min)') }}</th><th class="text-right">{{ __('Score') }}</th>
            </x-slot:head>
            @foreach ($rows as $r)
                <tr class="border-t border-gray-100 [&_td]:px-4 [&_td]:py-3">
                    <td class="font-medium text-gray-800">{{ $r['name'] }}</td>
                    <td class="text-right tabular-nums">{{ $r['taken'] }}</td>
                    <td class="text-right tabular-nums">{{ $r['confirmed'] }}</td>
                    <td class="text-right tabular-nums">{{ $r['delivered'] }}</td>
                    <td class="text-right tabular-nums">{{ $r['returned'] }}</td>
                    <td class="text-right tabular-nums">{{ $r['cancelled'] }}</td>
                    <td class="text-right tabular-nums">{{ $pct($r['delivered_rate']) }}</td>
                    <td class="text-right tabular-nums">{{ $r['median_minutes'] ?? '-' }}</td>
                    <td class="text-right">
                        <span class="font-semibold tabular-nums text-green-900">{{ $r['score'] }}</span>
                        <span class="block text-[11px] text-gray-400">{{ $r['volume'] }} / {{ $r['speed'] }} / {{ $r['quality'] }}</span>
                    </td>
                </tr>
            @endforeach
        </x-list.table>

        <x-list.cards>
            @foreach ($rows as $r)
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="flex items-center justify-between">
                        <p class="font-medium text-gray-800">{{ $r['name'] }}</p>
                        <p class="text-lg font-semibold tabular-nums text-green-900">{{ $r['score'] }}</p>
                    </div>
                    <p class="mt-1 text-xs text-gray-500">{{ __('Volume :v · Speed :s · Quality :q', ['v' => $r['volume'], 's' => $r['speed'], 'q' => $r['quality']]) }}</p>
                    <div class="mt-2 grid grid-cols-3 gap-2 text-xs text-gray-600">
                        <span>{{ __('Confirmed') }}: <b>{{ $r['confirmed'] }}</b></span>
                        <span>{{ __('Delivered') }}: <b>{{ $r['delivered'] }}</b></span>
                        <span>{{ __('Rate') }}: <b>{{ $pct($r['delivered_rate']) }}</b></span>
                        <span>{{ __('Returned') }}: <b>{{ $r['returned'] }}</b></span>
                        <span>{{ __('Cancelled') }}: <b>{{ $r['cancelled'] }}</b></span>
                        <span>{{ __('Median') }}: <b>{{ $r['median_minutes'] ?? '-' }}m</b></span>
                    </div>
                </div>
            @endforeach
        </x-list.cards>
    @endif
</x-layouts.app>
