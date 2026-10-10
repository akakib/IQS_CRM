@php
    $t = $report['totals'];
    $series = [
        'placed' => [__('Placed'), '#1f6f43'],
        'completed' => [__('Completed'), '#2563eb'],
        'cancelled' => [__('Cancelled'), '#dc2626'],
        'returned' => [__('Returned'), '#f59e0b'],
    ];
    $money = fn ($v) => '৳'.number_format((float) $v);
@endphp

<x-layouts.app :heading="__('Sales')">
    <p class="mb-4 text-sm text-gray-500">{{ __('Per day: orders placed, completed (delivered by the courier), cancelled and returned. Completed and cancelled count on the day they ended, not the day they were placed.') }}</p>

    <div class="mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-gray-200 bg-white p-3">
        <form method="GET" action="{{ route('reports.sales') }}" class="flex flex-wrap items-center gap-2">
            <x-date-range :from="$from" :to="$to" />
            <x-button size="sm">{{ __('Show') }}</x-button>
            @if (request()->hasAny(['from', 'to']))
                <a href="{{ route('reports.sales') }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-50">{{ __('Last 30 days') }}</a>
            @endif
        </form>
        <span class="ml-auto text-xs text-gray-500">{{ $report['from'] }} → {{ $report['to'] }}@if ($report['weekly']) · {{ __('shown per week') }}@endif</span>
    </div>

    <div class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        <x-stat-tile :label="__('Orders placed')" :value="number_format($t['placed'])" :hint="$money($t['placed_amount'])" />
        <x-stat-tile :label="__('Completed')" :value="number_format($t['completed'])" :hint="$money($t['completed_amount']).' '.__('delivered')" trend="up" />
        <x-stat-tile :label="__('Cancelled')" :value="number_format($t['cancelled'])" :trend="$t['cancelled'] ? 'down' : null" :hint="$t['cancelled'] ? __('of orders that ended') : null" />
        <x-stat-tile :label="__('Returned')" :value="number_format($t['returned'])" :trend="$t['returned'] ? 'down' : null" :hint="$t['returned'] ? __('of orders that ended') : null" />
        <x-stat-tile :label="__('Completion rate')" :value="$t['completion_rate'] === null ? '-' : rtrim(rtrim(number_format($t['completion_rate'], 1), '0'), '.').'%'" :hint="__('completed ÷ ended')" />
        <x-stat-tile :label="__('Per day')" :value="number_format($t['days'] ? $t['placed'] / $t['days'] : 0, 1)" :hint="__('orders placed on average')" />
    </div>

    <x-card :title="__('Orders per :p', ['p' => $report['weekly'] ? __('week') : __('day')])" class="mb-4">
        <x-line-chart :rows="$report['rows']" :series="$series" />
    </x-card>

    <x-list.table>
        <x-slot:head>
            <th>{{ $report['weekly'] ? __('Week') : __('Day') }}</th>
            <th class="text-right">{{ __('Placed') }}</th>
            <th class="text-right">{{ __('Placed ৳') }}</th>
            <th class="text-right">{{ __('Completed') }}</th>
            <th class="text-right">{{ __('Delivered ৳') }}</th>
            <th class="text-right">{{ __('Cancelled') }}</th>
            <th class="text-right">{{ __('Returned') }}</th>
        </x-slot:head>
        @foreach (array_reverse($report['rows']) as $r)
            <tr>
                <td class="text-gray-800">{{ $r['label'] }}</td>
                <td class="text-right tabular-nums">{{ number_format($r['placed']) }}</td>
                <td class="text-right tabular-nums text-gray-500">{{ $money($r['placed_amount']) }}</td>
                <td class="text-right tabular-nums text-blue-700">{{ number_format($r['completed']) }}</td>
                <td class="text-right tabular-nums text-gray-500">{{ $money($r['completed_amount']) }}</td>
                <td class="text-right tabular-nums {{ $r['cancelled'] ? 'text-red-600' : 'text-gray-400' }}">{{ number_format($r['cancelled']) }}</td>
                <td class="text-right tabular-nums {{ $r['returned'] ? 'text-amber-600' : 'text-gray-400' }}">{{ number_format($r['returned']) }}</td>
            </tr>
        @endforeach
        <tr class="bg-gray-50 font-semibold">
            <td>{{ __('Total') }}</td>
            <td class="text-right tabular-nums">{{ number_format($t['placed']) }}</td>
            <td class="text-right tabular-nums">{{ $money($t['placed_amount']) }}</td>
            <td class="text-right tabular-nums">{{ number_format($t['completed']) }}</td>
            <td class="text-right tabular-nums">{{ $money($t['completed_amount']) }}</td>
            <td class="text-right tabular-nums">{{ number_format($t['cancelled']) }}</td>
            <td class="text-right tabular-nums">{{ number_format($t['returned']) }}</td>
        </tr>
    </x-list.table>

    <x-list.cards>
        @foreach (array_reverse($report['rows']) as $r)
            <x-record-card :title="$r['label']" :subtitle="__('Placed :n · :m', ['n' => number_format($r['placed']), 'm' => $money($r['placed_amount'])])">
                <x-slot:badge><x-badge color="blue">{{ __(':n completed', ['n' => number_format($r['completed'])]) }}</x-badge></x-slot:badge>
                <x-slot:footer>{{ __('Delivered :m', ['m' => $money($r['completed_amount'])]) }} · <span class="{{ $r['cancelled'] ? 'text-red-600' : '' }}">{{ __(':n cancelled', ['n' => $r['cancelled']]) }}</span> · <span class="{{ $r['returned'] ? 'text-amber-600' : '' }}">{{ __(':n returned', ['n' => $r['returned']]) }}</span></x-slot:footer>
            </x-record-card>
        @endforeach
    </x-list.cards>
</x-layouts.app>
