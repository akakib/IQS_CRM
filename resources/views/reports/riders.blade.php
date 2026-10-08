@php
    $url = fn ($f, $t) => route('riders-report.index', ['from' => $f->toDateString(), 'to' => $t->toDateString()]);
    $presets = [__('7 days') => [today()->subDays(6), today()], __('30 days') => [today()->subDays(29), today()], __('This month') => [today()->startOfMonth(), today()]];
    $said = fn ($r) => $r['claims']->take(3)->map(fn ($n, $k) => __($claims[$k] ?? $k).' '.$n)->join(' · ');
@endphp

<x-layouts.app :heading="__('Riders')">
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
    <p class="mb-4 text-xs text-gray-500">{{ __('Calls from delivery riders logged in Communication. "Not true" = the customer, when called, said otherwise. A high share for one rider is worth raising with the courier.') }}</p>

    @if (! $total)
        <x-empty-state :message="__('No rider calls in these dates. They are logged from Communication (beside Break), Rider calls.')" />
    @else
        <h2 class="mb-2 text-sm font-semibold text-gray-800">{{ __('By rider') }}</h2>
        <x-list.table class="mb-6">
            <x-slot:head>
                <th>{{ __('Rider') }}</th>
                <th class="text-right">{{ __('Calls') }}</th>
                <th class="text-right">{{ __('Parcels') }}</th>
                <th class="text-right">{{ __('Not true') }}</th>
                <th class="text-right">{{ __('Could not tell') }}</th>
                <th>{{ __('What they said most') }}</th>
            </x-slot:head>
            @foreach ($riders as $r)
                <tr>
                    <td><span class="font-medium text-gray-800">{{ $r['name'] }}</span>@if ($r['phone'])<span class="block text-xs text-gray-500">{{ $r['phone'] }}</span>@endif</td>
                    <td class="text-right tabular-nums">{{ $r['calls'] }}</td>
                    <td class="text-right tabular-nums">{{ $r['parcels'] }}</td>
                    <td @class(['text-right tabular-nums', 'font-semibold text-red-600' => ($r['false_rate'] ?? 0) >= 30])>{{ $r['false'] }}@if ($r['false_rate'] !== null)<span class="block text-xs">{{ $r['false_rate'] }}%</span>@endif</td>
                    <td class="text-right tabular-nums">{{ $r['unclear'] }}</td>
                    <td class="text-xs text-gray-600">{{ $said($r) }}</td>
                </tr>
            @endforeach
        </x-list.table>
        <x-list.cards class="mb-6">
            @foreach ($riders as $r)
                <x-record-card :title="$r['name']" :subtitle="__(':c calls · :p parcels · :f not true', ['c' => $r['calls'], 'p' => $r['parcels'], 'f' => $r['false']])">
                    <x-slot:footer>{{ $said($r) }}</x-slot:footer>
                    <x-slot:actions><span @class(['font-semibold tabular-nums', 'text-red-600' => ($r['false_rate'] ?? 0) >= 30])>{{ $r['false_rate'] === null ? '-' : $r['false_rate'].'%' }}</span></x-slot:actions>
                </x-record-card>
            @endforeach
        </x-list.cards>

        <div class="grid gap-6 md:grid-cols-2">
            <x-card :title="__('What riders say')">
                <div class="space-y-2 text-sm">
                    @foreach ($byClaim as $k => $c)
                        <div class="flex items-center justify-between gap-3"><span class="text-gray-700">{{ __($claims[$k] ?? $k) }}</span><span class="tabular-nums text-gray-800">{{ $c['calls'] }} <span class="text-xs text-red-600">({{ __(':n not true', ['n' => $c['false']]) }})</span></span></div>
                    @endforeach
                </div>
            </x-card>
            <x-card :title="__('Who answered')">
                <div class="space-y-2 text-sm">
                    @foreach ($handlers as $h)
                        <div class="flex items-center justify-between gap-3"><span class="text-gray-700">{{ $h->name }}</span><span class="tabular-nums text-gray-800">{{ $h->n }} <span class="text-xs text-gray-500">({{ __(':n solved on the call', ['n' => $h->solved]) }})</span></span></div>
                    @endforeach
                </div>
            </x-card>
        </div>
    @endif
</x-layouts.app>
