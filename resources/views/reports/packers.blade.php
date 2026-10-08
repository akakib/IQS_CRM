@php
    $url = fn ($f, $t) => route('packers-report.index', ['from' => $f->toDateString(), 'to' => $t->toDateString()]);
    $presets = [__('Today') => [today(), today()], __('7 days') => [today()->subDays(6), today()], __('30 days') => [today()->subDays(29), today()], __('This month') => [today()->startOfMonth(), today()]];
    $span = fn ($s) => \App\Services\Reports\WorkTime::span($s);
@endphp

<x-layouts.app :heading="__('Packers')">
    <div class="mb-4 flex flex-wrap items-center gap-2">
        @foreach ($presets as $label => [$f, $t])
            <a href="{{ $url($f, $t) }}" @class(['rounded-full border px-3 py-1.5 text-sm', 'border-primary bg-primary text-white' => $from->isSameDay($f) && $to->isSameDay($t), 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' => ! ($from->isSameDay($f) && $to->isSameDay($t))])>{{ $label }}</a>
        @endforeach
        <form method="GET" x-ref="range" x-data class="flex flex-wrap items-center gap-2">
            <x-date-input name="from" :value="$from->toDateString()" :max="today()->toDateString()" :clearable="false" @date-change="$nextTick(() => $refs.range.requestSubmit())" />
            <span class="text-sm text-gray-400">{{ __('to') }}</span>
            <x-date-input name="to" :value="$to->toDateString()" :max="today()->toDateString()" :clearable="false" @date-change="$nextTick(() => $refs.range.requestSubmit())" />
        </form>
    </div>
    <p class="mb-4 text-xs text-gray-500">{{ __('Days on duty and helpers come from "Set today\'s packers" on the Packaging page. Per head = parcels packed for every person-day at the table (packer and helpers). Time to pack = from the label scan to Packed, middle value. Returned for packing = returns whose reason blames packing.') }}</p>

    @if ($rows->isEmpty())
        <x-empty-state :message="__('Nothing packed in these dates.')" />
    @else
        <x-list.table>
            <x-slot:head>
                <th>{{ __('Packer') }}</th>
                <th class="text-right">{{ __('Packed') }}</th>
                <th class="text-right">{{ __('Days') }}</th>
                <th class="text-right">{{ __('Helpers a day') }}</th>
                <th class="text-right">{{ __('Per day') }}</th>
                <th class="text-right">{{ __('Per head') }}</th>
                <th class="text-right">{{ __('Time to pack') }}</th>
                <th class="text-right">{{ __('Scan errors') }}</th>
                <th class="text-right">{{ __('Returned for packing') }}</th>
            </x-slot:head>
            @foreach ($rows as $r)
                <tr>
                    <td class="font-medium text-gray-800">{{ $r['name'] }}</td>
                    <td class="text-right tabular-nums">{{ $r['packed'] }}</td>
                    <td class="text-right tabular-nums">{{ $r['days'] ?: '-' }}</td>
                    <td class="text-right tabular-nums">{{ $r['helpers_avg'] ?: '-' }}</td>
                    <td class="text-right tabular-nums">{{ $r['per_day'] ?? '-' }}</td>
                    <td class="text-right font-medium tabular-nums">{{ $r['per_head'] ?? '-' }}</td>
                    <td class="text-right tabular-nums">{{ $span($r['median']) }}</td>
                    <td @class(['text-right tabular-nums', 'text-red-600' => $r['errors']])>{{ $r['errors'] }}</td>
                    <td @class(['text-right tabular-nums', 'font-semibold text-red-600' => $r['returned']])>{{ $r['returned'] }}</td>
                </tr>
            @endforeach
        </x-list.table>
        <x-list.cards>
            @foreach ($rows as $r)
                <x-record-card :title="$r['name']" :subtitle="__(':p packed · :d days · :h helpers a day', ['p' => $r['packed'], 'd' => $r['days'], 'h' => $r['helpers_avg']])">
                    <x-slot:footer>{{ __('Per day :a · time to pack :t · scan errors :e · returned for packing :r', ['a' => $r['per_day'] ?? '-', 't' => $span($r['median']), 'e' => $r['errors'], 'r' => $r['returned']]) }}</x-slot:footer>
                    <x-slot:actions><span class="text-right"><span class="block text-xs text-gray-500">{{ __('Per head') }}</span><span class="font-semibold tabular-nums">{{ $r['per_head'] ?? '-' }}</span></span></x-slot:actions>
                </x-record-card>
            @endforeach
        </x-list.cards>
    @endif
</x-layouts.app>
