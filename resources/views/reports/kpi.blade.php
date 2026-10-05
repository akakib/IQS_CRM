@php
    $pct = fn ($v) => $v === null ? '-' : rtrim(rtrim(number_format($v, 1), '0'), '.').'%';
    $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 1), '0'), '.');
    $query = fn (array $extra) => route('kpi.index', array_filter(['from' => $from, 'to' => $to] + $extra));
    $input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-green-800 focus:outline-none';
    // Client-side sort for the (short) team table: click a heading.
    $cols = ['delivered' => __('Delivered'), 'delivery_rate' => __('Delivery %'), 'cancel_rate' => __('Cancel %'), 'return_rate' => __('Return %'), 'saved' => __('Saved'), 'pending' => __('No response now'), 'released' => __('Timed out')];
@endphp

<x-layouts.app :heading="__('KPI')">
    <p class="mb-4 text-sm text-gray-500">
        {{ $mode === 'cohort'
            ? __('Orders taken in this period and where they are now. Orders still on the way are shown as "open".')
            : __('What ended in this period: delivered (confirmed by the courier), cancelled and returned.') }}
        {{ __('Separate from points.') }}
    </p>

    <div class="mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-gray-200 bg-white p-3">
        <form method="GET" action="{{ route('kpi.index') }}" class="flex flex-wrap items-center gap-2">
            <input type="hidden" name="view" value="{{ $mode }}">
            <x-date-range :from="$from" :to="$to" />
            <x-button size="sm">{{ __('Show') }}</x-button>
            @if (request()->hasAny(['from', 'to']))
                <a href="{{ route('kpi.index', ['view' => $mode === 'cohort' ? 'cohort' : null]) }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-50">{{ __('Clear') }}</a>
            @endif
        </form>
    </div>

    <x-tabs :tabs="['day' => [__('Ended in the period'), $query([])], 'cohort' => [__('Taken in the period (cohort)'), $query(['view' => 'cohort'])]]" :active="$mode" />

    @if ($rows === [])
        <div class="rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center text-sm text-gray-500">{{ __('No order activity in this period.') }}</div>
    @else
        <div x-data="{ key: 'delivered', dir: -1,
                sort(k) { this.dir = this.key === k ? -this.dir : -1; this.key = k;
                    const body = this.$root.querySelector('tbody'), avg = body.querySelector('[data-avg]');
                    [...body.querySelectorAll('tr[data-row]')].sort((a, b) => (parseFloat(a.dataset[k] || -1) - parseFloat(b.dataset[k] || -1)) * this.dir).forEach(r => body.appendChild(r));
                    body.appendChild(avg) } }">
            <x-list.table>
                <x-slot:head>
                    <th>{{ __('Moderator') }}</th>
                    @foreach ($cols as $k => $label)
                        <th class="text-right"><button type="button" @click="sort('{{ $k }}')" class="uppercase hover:text-green-900">{{ $label }} <span x-show="key === '{{ $k }}'" x-text="dir < 0 ? '↓' : '↑'"></span></button></th>
                    @endforeach
                    @if ($mode === 'cohort')<th class="text-right">{{ __('Open') }}</th>@endif
                    @if ($showAmount)<th class="text-right">{{ __('Delivered ৳') }}</th>@endif
                </x-slot:head>
                @foreach ($rows as $r)
                    <tr data-row class="border-t border-gray-100 [&_td]:px-4 [&_td]:py-3" @foreach (array_keys($cols) as $k) data-{{ $k }}="{{ $r[$k] ?? -1 }}" @endforeach>
                        <td class="font-medium text-gray-800">{{ $r['name'] }}</td>
                        <td class="text-right tabular-nums">
                            <b>{{ $r['delivered'] }}</b>
                            @if ($r['target_count'] !== null)<span class="block text-[11px] {{ $r['delivered'] >= $r['target_count'] ? 'text-green-700' : 'text-gray-400' }}">{{ __('target :t / month', ['t' => $num($r['target_count'])]) }}</span>@endif
                        </td>
                        <td class="text-right tabular-nums">
                            {{ $pct($r['delivery_rate']) }}
                            @if ($r['target_rate'] !== null)<span class="block text-[11px] {{ ($r['delivery_rate'] ?? 0) >= $r['target_rate'] ? 'text-green-700' : 'text-red-600' }}">{{ __('target :t%', ['t' => $num($r['target_rate'])]) }}</span>@endif
                        </td>
                        <td class="text-right tabular-nums">{{ $pct($r['cancel_rate']) }} <span class="text-xs text-gray-400">({{ $r['cancelled'] }})</span></td>
                        <td class="text-right tabular-nums">{{ $pct($r['return_rate']) }} <span class="text-xs text-gray-400">({{ $r['returned'] }})</span></td>
                        <td class="text-right tabular-nums">{{ $r['saved'] }}</td>
                        <td class="text-right tabular-nums">{{ $r['pending'] }}</td>
                        <td class="text-right tabular-nums {{ $r['released'] ? 'text-red-600' : '' }}">{{ $r['released'] }}</td>
                        @if ($mode === 'cohort')<td class="text-right tabular-nums">{{ $r['open'] }}</td>@endif
                        @if ($showAmount)<td class="text-right tabular-nums">৳{{ number_format($r['amount']) }}</td>@endif
                    </tr>
                @endforeach
                <tr data-avg class="border-t-2 border-gray-200 bg-gray-50 text-gray-600 [&_td]:px-4 [&_td]:py-3">
                    <td class="font-medium">{{ __('Team average') }}</td>
                    <td class="text-right tabular-nums">{{ $num($team['delivered']) }}</td>
                    <td class="text-right tabular-nums">{{ $pct($team['delivery_rate']) }}</td>
                    <td class="text-right tabular-nums">{{ $pct($team['cancel_rate']) }}</td>
                    <td class="text-right tabular-nums">{{ $pct($team['return_rate']) }}</td>
                    <td class="text-right tabular-nums">{{ $num($team['saved']) }}</td>
                    <td class="text-right tabular-nums">{{ $num($team['pending']) }}</td>
                    <td class="text-right tabular-nums">{{ $num($team['released']) }}</td>
                    @if ($mode === 'cohort')<td class="text-right tabular-nums">{{ $num($team['open']) }}</td>@endif
                    @if ($showAmount)<td class="text-right tabular-nums">৳{{ number_format($team['amount']) }}</td>@endif
                </tr>
            </x-list.table>
        </div>

        <x-list.cards>
            @foreach ($rows as $r)
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="flex items-center justify-between">
                        <p class="font-medium text-gray-800">{{ $r['name'] }}</p>
                        <p class="text-lg font-semibold tabular-nums text-green-900">{{ $r['delivered'] }} <span class="text-xs font-normal text-gray-500">{{ __('delivered') }}</span></p>
                    </div>
                    <div class="mt-2 grid grid-cols-3 gap-2 text-xs text-gray-600">
                        <span>{{ __('Delivery') }}: <b>{{ $pct($r['delivery_rate']) }}</b></span>
                        <span>{{ __('Cancel') }}: <b>{{ $pct($r['cancel_rate']) }}</b></span>
                        <span>{{ __('Return') }}: <b>{{ $pct($r['return_rate']) }}</b></span>
                        <span>{{ __('Saved') }}: <b>{{ $r['saved'] }}</b></span>
                        <span>{{ __('No response') }}: <b>{{ $r['pending'] }}</b></span>
                        <span>{{ __('Timed out') }}: <b>{{ $r['released'] }}</b></span>
                    </div>
                    @if ($r['target_count'] !== null || $r['target_rate'] !== null)
                        <p class="mt-2 text-[11px] text-gray-500">{{ __('Target') }}: {{ $r['target_count'] !== null ? $num($r['target_count']).' / '.__('month') : '' }} {{ $r['target_rate'] !== null ? $num($r['target_rate']).'%' : '' }}</p>
                    @endif
                </div>
            @endforeach
            <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 text-xs text-gray-600">
                <p class="font-medium text-gray-700">{{ __('Team average') }}</p>
                <p class="mt-1">{{ __('Delivered :d · delivery :a · cancel :b · return :c', ['d' => $num($team['delivered']), 'a' => $pct($team['delivery_rate']), 'b' => $pct($team['cancel_rate']), 'c' => $pct($team['return_rate'])]) }}</p>
            </div>
        </x-list.cards>
    @endif

    @if ($canSetTargets)
        <x-card :title="__('Targets')" :subtitle="__('Per month. Leave the person empty to set the default for everyone; a personal target wins over the default.')" class="mt-6 max-w-2xl">
            <form method="POST" action="{{ route('kpi.targets') }}" class="grid gap-2 sm:grid-cols-4">
                @csrf
                <x-simple-select name="user_id" :options="['' => __('Everyone (default)')] + $staff" value="" full-width class="w-full sm:col-span-2" />
                <input type="number" name="delivered_count" min="0" step="1" placeholder="{{ __('Delivered / month') }}" class="{{ $input }}">
                <input type="number" name="delivery_rate" min="0" max="100" step="0.1" placeholder="{{ __('Delivery %') }}" class="{{ $input }}">
                <x-button size="sm" class="sm:col-span-4 sm:justify-self-start">{{ __('Save target') }}</x-button>
            </form>
            @if ($targets)
                <div class="mt-4 space-y-1 border-t border-gray-100 pt-3 text-sm text-gray-700">
                    @foreach ($targets as $userId => $t)
                        <p><b>{{ $userId ? ($staff[$userId] ?? '#'.$userId) : __('Everyone (default)') }}</b>:
                            {{ isset($t['delivered_count']) ? __(':n delivered / month', ['n' => $num($t['delivered_count'])]) : '' }}
                            {{ isset($t['delivery_rate']) ? '· '.$num($t['delivery_rate']).'%' : '' }}</p>
                    @endforeach
                    <p class="text-xs text-gray-400">{{ __('To remove a target, save it again with the box empty.') }}</p>
                </div>
            @endif
        </x-card>
    @endif
</x-layouts.app>
