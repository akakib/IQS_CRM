@php
    $url = fn ($f, $t) => route('returns-report.index', ['from' => $f->toDateString(), 'to' => $t->toDateString()]);
    $presets = [__('7 days') => [today()->subDays(6), today()], __('30 days') => [today()->subDays(29), today()], __('This month') => [today()->startOfMonth(), today()]];
    $money = fn ($n) => '৳'.number_format((float) $n);
    $pct = fn ($r) => $r === null ? '-' : $r.'%';
    $num = fn ($n) => rtrim(rtrim(number_format((float) $n, 3), '0'), '.');
@endphp

<x-layouts.app :heading="__('Return report')">
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
    <p class="mb-4 text-xs text-gray-500">{{ __('Parcels the courier sent back in these dates. Rate = returned out of returned and delivered. Loss = the courier charge for each return (Settings, Returns) plus damaged and missing items at cost.') }}</p>

    <div class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-4">
        <x-stat-tile :label="__('Returned')" :value="number_format($total['returned'])" />
        <x-stat-tile :label="__('Return rate')" :value="$pct($total['rate'])" />
        <x-stat-tile :label="__('Loss')" :value="$money($total['loss'])" />
        <x-stat-tile :label="__('Not back at the shop')" :value="number_format($total['notBack'])" />
    </div>
    <p class="mb-6 text-sm text-gray-600">{{ __('Loss: courier charges :c · damaged :d · missing :m', ['c' => $money($total['charges']), 'd' => $money($total['damaged']), 'm' => $money($total['missing'])]) }}</p>

    @if (! $total['returned'] && $damagedItems->isEmpty())
        <x-empty-state :message="__('No returns in these dates.')" />
    @else
        {{-- One list at a time. --}}
        <div x-data="tabs('damaged')">
            <x-tabs :tabs="[
                'damaged' => [__('Damaged items'), '#damaged', $damagedItems->count()],
                'reason' => [__('By reason'), '#reason'],
                'product' => [__('By product'), '#product'],
                'person' => [__('By person'), '#person'],
                'area' => [__('By area'), '#area'],
            ]" active="damaged" />

            <section x-show="tab === 'damaged'">
                @forelse ($damagedItems as $d)
                    <div class="mb-2 flex flex-col gap-1 rounded-xl border border-gray-200 bg-white p-4 sm:flex-row sm:items-center sm:gap-3">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900">{{ $d['name'] }}</p>
                            <p class="text-xs text-gray-500">
                                @foreach ($d['orders'] as $o)<a href="{{ route('orders.show', $o['id']) }}" class="font-mono text-primary hover:underline">{{ $o['no'] }}</a>@if (! $loop->last), @endif @endforeach
                            </p>
                        </div>
                        <p class="text-sm">
                            @if ($d['damaged'])<span class="font-medium text-red-700">{{ __(':n damaged', ['n' => $num($d['damaged'])]) }}</span>@endif
                            @if ($d['damaged'] && $d['missing']) · @endif
                            @if ($d['missing'])<span class="font-medium text-red-700">{{ __(':n missing', ['n' => $num($d['missing'])]) }}</span>@endif
                        </p>
                        <p class="font-semibold tabular-nums text-gray-900">{{ $money($d['cost']) }}</p>
                    </div>
                @empty
                    <x-empty-state :message="__('No damaged or missing items in these dates.')" />
                @endforelse
            </section>

            @foreach (['reason' => $byReason, 'product' => $byProduct, 'person' => $byPerson, 'area' => $byArea] as $key => $rows)
                <section x-show="tab === '{{ $key }}'" x-cloak>
                    <div class="divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white">
                        @forelse ($rows as $r)
                            @php $r = (array) $r; @endphp
                            <div class="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                                <span class="min-w-0 truncate text-gray-800">{{ $r['label'] ?? $r['name'] }}</span>
                                <span class="shrink-0 text-right tabular-nums">
                                    @if ($key === 'product')
                                        {{ trans_choice(':count parcel|:count parcels', (int) $r['parcels'], ['count' => (int) $r['parcels']]) }} · {{ $num($r['qty']) }}
                                    @elseif ($key === 'reason')
                                        <b>{{ $r['n'] }}</b>
                                    @else
                                        <b>{{ $r['returned'] }}</b> {{ __('returned') }} · {{ $r['delivered'] }} {{ __('delivered') }} · <span @class(['font-semibold', 'text-red-700' => ($r['rate'] ?? 0) >= 20])>{{ $pct($r['rate']) }}</span>
                                    @endif
                                </span>
                            </div>
                        @empty
                            <p class="px-4 py-6 text-center text-sm text-gray-500">{{ __('Nothing here.') }}</p>
                        @endforelse
                    </div>
                </section>
            @endforeach
        </div>
    @endif
</x-layouts.app>
