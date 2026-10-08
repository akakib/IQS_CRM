@php
    $url = fn ($f, $t) => route('chat-report.index', ['from' => $f->toDateString(), 'to' => $t->toDateString()]);
    $presets = [__('7 days') => [today()->subDays(6), today()], __('30 days') => [today()->subDays(29), today()], __('This month') => [today()->startOfMonth(), today()]];
    $rate = fn ($r) => $r === null ? '-' : $r.'%';
    $why = fn ($row) => $row['reasons']->take(3)->map(fn ($n, $id) => __($reasons[$id] ?? 'Other').' '.$n)->join(' · ') ?: '-';
@endphp

<x-layouts.app :heading="__('Chats')">
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
    <p class="mb-4 text-xs text-gray-500">{{ __('From what staff count in Chat: messages answered, orders made from a chat, and chats that ended with no order (with the reason they picked). Turned into an order = orders out of all chats that ended (order or no order).') }}</p>

    <div class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-4">
        <x-stat-tile :label="__('Messages answered')" :value="number_format($total['messages'])" />
        <x-stat-tile :label="__('Orders from chats')" :value="number_format($total['orders'])" />
        <x-stat-tile :label="__('Ended with no order')" :value="number_format($total['lost'])" />
        <x-stat-tile :label="__('Turned into an order')" :value="$rate($total['rate'])" />
    </div>

    @if ($byChannel->isEmpty())
        <x-empty-state :message="__('No chats counted in these dates. Channels are set in Settings > Communication channels; staff count in Communication beside Break.')" />
    @else
        {{-- Why chats are lost: the reasons, most first, for all channels together. --}}
        @if ($total['lost'])
            <x-card :title="__('Why chats ended with no order')" class="mb-6">
                <div class="space-y-2">
                    @foreach ($total['reasons'] as $id => $n)
                        <div class="flex items-center gap-3 text-sm">
                            <span class="w-28 shrink-0 text-gray-700 sm:w-44">{{ __($reasons[$id] ?? 'Other') }}</span>
                            <span class="h-2.5 flex-1 overflow-hidden rounded-full bg-gray-100"><span class="block h-full rounded-full bg-red-500" style="width: {{ round($n * 100 / $total['lost']) }}%"></span></span>
                            <span class="w-16 shrink-0 text-right tabular-nums text-gray-800 sm:w-20">{{ $n }} · {{ round($n * 100 / $total['lost']) }}%</span>
                        </div>
                    @endforeach
                </div>
            </x-card>
        @endif

        <h2 class="mb-2 text-sm font-semibold text-gray-800">{{ __('By channel') }}</h2>
        <x-list.table class="mb-6">
            <x-slot:head>
                <th>{{ __('Channel') }}</th>
                <th class="text-right">{{ __('Messages') }}</th>
                <th class="text-right">{{ __('Orders') }}</th>
                <th class="text-right">{{ __('Sales') }}</th>
                <th class="text-right">{{ __('No order') }}</th>
                <th class="text-right">{{ __('Turned into an order') }}</th>
                <th>{{ __('Main reasons for no order') }}</th>
            </x-slot:head>
            @foreach ($byChannel as $c)
                <tr>
                    <td><span class="font-medium text-gray-800">{{ $c['name'] }}</span><span class="block text-xs text-gray-500">{{ $c['type'] }}</span></td>
                    <td class="text-right tabular-nums">{{ number_format($c['messages']) }}</td>
                    <td class="text-right tabular-nums">{{ number_format($c['orders']) }}</td>
                    <td class="text-right tabular-nums">৳{{ number_format($c['sales']) }}</td>
                    <td class="text-right tabular-nums">{{ number_format($c['lost']) }}</td>
                    <td class="text-right font-medium tabular-nums">{{ $rate($c['rate']) }}</td>
                    <td class="text-xs text-gray-600">{{ $why($c) }}</td>
                </tr>
            @endforeach
        </x-list.table>
        <x-list.cards class="mb-6">
            @foreach ($byChannel as $c)
                <x-record-card :title="$c['name']" :subtitle="__(':m messages · :o orders · :l no order', ['m' => $c['messages'], 'o' => $c['orders'], 'l' => $c['lost']])">
                    <x-slot:footer>{{ $why($c) }}</x-slot:footer>
                    <x-slot:actions><span class="font-semibold tabular-nums">{{ $rate($c['rate']) }}</span></x-slot:actions>
                </x-record-card>
            @endforeach
        </x-list.cards>

        <h2 class="mb-2 text-sm font-semibold text-gray-800">{{ __('By person') }}</h2>
        <x-list.table>
            <x-slot:head>
                <th>{{ __('Person') }}</th>
                <th class="text-right">{{ __('Messages') }}</th>
                <th class="text-right">{{ __('Orders') }}</th>
                <th class="text-right">{{ __('Sales') }}</th>
                <th class="text-right">{{ __('No order') }}</th>
                <th class="text-right">{{ __('Turned into an order') }}</th>
                <th>{{ __('Main reasons for no order') }}</th>
            </x-slot:head>
            @foreach ($byPerson as $p)
                <tr>
                    <td class="font-medium text-gray-800">{{ $p['name'] }}</td>
                    <td class="text-right tabular-nums">{{ number_format($p['messages']) }}</td>
                    <td class="text-right tabular-nums">{{ number_format($p['orders']) }}</td>
                    <td class="text-right tabular-nums">৳{{ number_format($p['sales']) }}</td>
                    <td class="text-right tabular-nums">{{ number_format($p['lost']) }}</td>
                    <td class="text-right font-medium tabular-nums">{{ $rate($p['rate']) }}</td>
                    <td class="text-xs text-gray-600">{{ $why($p) }}</td>
                </tr>
            @endforeach
        </x-list.table>
        <x-list.cards>
            @foreach ($byPerson as $p)
                <x-record-card :title="$p['name']" :subtitle="__(':m messages · :o orders · :l no order', ['m' => $p['messages'], 'o' => $p['orders'], 'l' => $p['lost']])">
                    <x-slot:footer>{{ $why($p) }}</x-slot:footer>
                    <x-slot:actions><span class="font-semibold tabular-nums">{{ $rate($p['rate']) }}</span></x-slot:actions>
                </x-record-card>
            @endforeach
        </x-list.cards>
    @endif
</x-layouts.app>
