@php
    $hm = fn (int $m) => $m >= 60 ? intdiv($m, 60).'h '.($m % 60).'m' : $m.'m';
    $months = collect(range(0, 11))->mapWithKeys(fn ($i) => [now()->startOfMonth()->subMonths($i)->format('Y-m') => now()->startOfMonth()->subMonths($i)->format('F Y')])->all();
@endphp

<x-layouts.app :heading="__('Attendance & breaks')">
    <x-tabs :tabs="['day' => [__('Day'), route('attendance.index')], 'month' => [__('Month'), route('attendance.index', ['month' => $month])]]" active="month" />

    <form method="GET" action="{{ route('attendance.index') }}" x-ref="f" @select-change="setTimeout(() => $refs.f.requestSubmit(), 0)" class="mb-4">
        <x-simple-select name="month" :options="$months" :value="$month" />
    </form>

    @if ($rows->isEmpty())
        <div class="rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center text-sm text-gray-500">{{ __('No records for this month.') }}</div>
    @else
        <x-list.table>
            <x-slot:head>
                <th>{{ __('Person') }}</th><th class="text-right">{{ __('Days worked') }}</th><th class="text-right">{{ __('Extra days (approved)') }}</th>
                <th class="text-right">{{ __('Break time') }}</th><th class="text-right">{{ __('Average / day') }}</th><th class="text-right">{{ __('Days over :l min', ['l' => $limit]) }}</th>
                <th class="text-right">{{ __('Away on work') }}</th><th class="text-right">{{ __('Did not come back') }}</th>
            </x-slot:head>
            @foreach ($rows as $r)
                <tr>
                    <td class="font-medium text-gray-800">{{ $r['name'] }}</td>
                    <td class="text-right tabular-nums">{{ $r['days'] }}</td>
                    <td class="text-right tabular-nums">{{ $r['extra'] }} <span class="text-xs text-gray-400">({{ $r['extra_approved'] }})</span></td>
                    <td class="text-right tabular-nums">{{ $hm($r['rest']) }} <span class="text-xs text-gray-400">({{ $r['breaks'] }})</span></td>
                    <td class="text-right tabular-nums">{{ $hm($r['avg']) }}</td>
                    <td class="text-right tabular-nums {{ $r['over'] ? 'font-semibold text-red-600' : '' }}">{{ $r['over'] }}</td>
                    <td class="text-right tabular-nums">{{ $hm($r['away']) }}</td>
                    <td class="text-right tabular-nums {{ $r['not_closed'] ? 'font-semibold text-red-600' : '' }}">{{ $r['not_closed'] }}</td>
                </tr>
            @endforeach
        </x-list.table>
        <x-list.cards>
            @foreach ($rows as $r)
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <p class="font-medium text-gray-800">{{ $r['name'] }}</p>
                    <div class="mt-2 grid grid-cols-2 gap-2 text-xs text-gray-600">
                        <span>{{ __('Days worked') }}: <b>{{ $r['days'] }}</b></span>
                        <span>{{ __('Extra days') }}: <b>{{ $r['extra'] }}</b> ({{ $r['extra_approved'] }})</span>
                        <span>{{ __('Break time') }}: <b>{{ $hm($r['rest']) }}</b></span>
                        <span>{{ __('Average / day') }}: <b>{{ $hm($r['avg']) }}</b></span>
                        <span class="{{ $r['over'] ? 'text-red-600' : '' }}">{{ __('Days over limit') }}: <b>{{ $r['over'] }}</b></span>
                        <span>{{ __('Away on work') }}: <b>{{ $hm($r['away']) }}</b></span>
                    </div>
                </div>
            @endforeach
        </x-list.cards>
        <p class="mt-3 text-xs text-gray-500">{{ __('Open a day to see each break, correct one, or approve an extra day.') }}</p>
    @endif
</x-layouts.app>
