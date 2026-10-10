@php
    use App\Services\Reports\WorkTime;
    $span = fn ($s) => WorkTime::span($s);
    // Over several days a time needs its date too.
    $at = fn ($t) => $t ? $t->format($single ? 'g:i A' : 'd M, g:i A') : '-';
    $rangeUrl = fn ($f, $t, $p = null) => route('work-time.index', array_filter(['from' => $f->toDateString(), 'to' => $t->toDateString(), 'person' => $p]));
    $personUrl = fn ($p) => $rangeUrl($from, $to, $p);
    $presets = [
        __('Today') => [today(), today()],
        __('Yesterday') => [today()->subDay(), today()->subDay()],
        __('7 days') => [today()->subDays(6), today()],
        __('30 days') => [today()->subDays(29), today()],
    ];
    $ended = ['idle' => __('Given back: nothing done'), 'timeout' => __('Given back: timer'), 'break' => __('Back to New: break'), 'reassigned' => __('Handed to someone else'), 'finished' => null];
@endphp

<x-layouts.app :heading="__('Team Activity')">
    <div class="mb-4 flex flex-wrap items-center gap-2">
        @foreach ($presets as $label => [$f, $t])
            <a href="{{ $rangeUrl($f, $t, $person['id'] ?? null) }}" @class(['rounded-full border px-3 py-1.5 text-sm', 'border-primary bg-primary text-white' => $from->isSameDay($f) && $to->isSameDay($t), 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' => ! ($from->isSameDay($f) && $to->isSameDay($t))])>{{ $label }}</a>
        @endforeach
        {{-- Any range: picking either date reloads. --}}
        <form method="GET" x-ref="range" x-data class="flex flex-wrap items-center gap-2">
            @if ($person)<input type="hidden" name="person" value="{{ $person['id'] }}">@endif
            <x-date-input name="from" :value="$from->toDateString()" :max="today()->toDateString()" :clearable="false" @date-change="$nextTick(() => $refs.range.requestSubmit())" />
            <span class="text-sm text-gray-400">{{ __('to') }}</span>
            <x-date-input name="to" :value="$to->toDateString()" :max="today()->toDateString()" :clearable="false" @date-change="$nextTick(() => $refs.range.requestSubmit())" />
        </form>
    </div>
    <p class="mb-4 text-xs text-gray-500">{{ $single ? __('Orders given to each person on this day.') : __('Orders given to each person from :a to :b. Free time and breaks are added up over the days.', ['a' => $from->format('d M'), 'b' => $to->format('d M')]) }} {{ __('Times are the middle value (median). Net time leaves out the time the customer made us wait: No answer until the order came back, and On hold. Communication counts as work, not free time. Worked = time on opened orders and in Communication (breaks left out); overtime = the part of it outside the shift.') }}</p>

    @if ($people->isEmpty())
        <x-empty-state :message="__('Nobody had orders or a shift in these dates.')" />
    @else
        <x-list.table>
            <x-slot:head>
                <th>{{ __('Person') }}</th>
                <th class="text-right">{{ __('Worked') }}</th>
                <th class="text-right">{{ __('Overtime') }}</th>
                <th class="text-right">{{ __('Orders') }}</th>
                <th class="text-right">{{ __('Confirmed') }}</th>
                <th class="text-right">{{ __('Cancelled') }}</th>
                <th class="text-right">{{ __('Opened in') }}</th>
                <th class="text-right">{{ __('First action in') }}</th>
                <th class="text-right">{{ __('To packaging (net)') }}</th>
                <th class="text-right">{{ __('Given back') }}</th>
                <th class="text-right">{{ __('Break') }}</th>
                <th class="text-right">{{ __('Communication') }}</th>
                <th class="text-right">{{ __('Free, orders waiting') }}</th>
                <th class="text-right">{{ __('Free, nothing waiting') }}</th>
            </x-slot:head>
            @foreach ($people as $p)
                <tr @class(['cursor-pointer', 'bg-primary-soft' => $person && $person['id'] === $p['id']]) onclick="location.href='{{ $personUrl($p['id']) }}'">
                    <td><span class="flex items-center gap-2"><x-avatar :name="$p['name']" :photo="$p['photo']" size="sm" /><span class="font-medium text-gray-800">{{ $p['name'] }}</span>@if ($p['left'] ?? false)<span class="text-xs text-gray-400">({{ __('left') }})</span>@endif</span></td>
                    <td class="text-right font-medium tabular-nums">{{ $span($p['worked'] ?: null) }}</td>
                    <td @class(['text-right tabular-nums', 'font-medium text-amber-700' => $p['overtime'] >= 600, 'text-gray-500' => $p['overtime'] < 600])>{{ $span($p['overtime'] ?: null) }}</td>
                    <td class="text-right tabular-nums">{{ $p['turns'] }}</td>
                    <td class="text-right tabular-nums">{{ $p['confirmed'] }}</td>
                    <td class="text-right tabular-nums">{{ $p['cancelled'] }}</td>
                    <td class="text-right tabular-nums">{{ $span($p['open']) }}</td>
                    <td class="text-right tabular-nums">{{ $span($p['start']) }}</td>
                    <td class="text-right font-medium tabular-nums">{{ $span($p['net']) }}</td>
                    <td @class(['text-right tabular-nums', 'font-semibold text-red-600' => $p['given_back']])>{{ $p['given_back'] }}</td>
                    <td class="text-right tabular-nums">{{ $p['break_minutes'] }} {{ __('min') }}</td>
                    <td class="text-right tabular-nums">{{ $span($p['chat_seconds'] ?: null) }}@if ($p['chat_messages'] || $p['chat_orders'])<span class="block text-xs text-gray-500">{{ __(':m msg · :o orders', ['m' => $p['chat_messages'], 'o' => $p['chat_orders']]) }}</span>@endif</td>
                    <td @class(['text-right tabular-nums', 'font-semibold text-red-600' => $p['free_waiting'] >= 600])>{{ $span($p['free_waiting'] ?: null) }}</td>
                    <td class="text-right tabular-nums text-gray-500">{{ $span($p['free_nothing'] ?: null) }}</td>
                </tr>
            @endforeach
        </x-list.table>

        <x-list.cards>
            @foreach ($people as $p)
                <a href="{{ $personUrl($p['id']) }}" class="block">
                    <x-record-card :title="$p['name']" :subtitle="__(':n orders · :c confirmed · :x cancelled', ['n' => $p['turns'], 'c' => $p['confirmed'], 'x' => $p['cancelled']])">
                        <x-slot:footer>{{ __('Worked :w · overtime :o', ['w' => $span($p['worked'] ?: null), 'o' => $span($p['overtime'] ?: null)]) }}<span class="block">{{ __('Opened in :a · first action in :b · break :m min', ['a' => $span($p['open']), 'b' => $span($p['start']), 'm' => $p['break_minutes']]) }}</span>@if ($p['given_back']) · <span class="text-red-600">{{ __(':n given back', ['n' => $p['given_back']]) }}</span>@endif
                            <span class="block">{{ __('Chat :t · :m msg · :o orders', ['t' => $span($p['chat_seconds'] ?: null), 'm' => $p['chat_messages'], 'o' => $p['chat_orders']]) }}</span>
                            <span @class(['block', 'font-medium text-red-600' => $p['free_waiting'] >= 600])>{{ __('Free while orders waited: :w', ['w' => $span($p['free_waiting'] ?: null)]) }}</span></x-slot:footer>
                        <x-slot:actions><span class="text-right"><span class="block text-xs text-gray-500">{{ __('Net') }}</span><span class="font-semibold tabular-nums">{{ $span($p['net']) }}</span></span></x-slot:actions>
                    </x-record-card>
                </a>
            @endforeach
        </x-list.cards>

        @if ($person)
        <x-modal id="person" :title="$person['name'].' · '.($single ? $from->format('d M Y') : $from->format('d M').' to '.$to->format('d M Y'))" width="max-w-5xl" persistent show>
        @if (! $single)
            <p class="mb-4 rounded-xl border border-dashed border-gray-300 bg-white px-4 py-3 text-sm text-gray-600">{{ __('Activity shows one day at a time:') }}
                @foreach (\Carbon\CarbonPeriod::create($from, $to) as $d)
                    <a href="{{ $rangeUrl($d, $d, $person['id']) }}" class="ml-1 text-primary hover:underline">{{ $d->format('d M') }}</a>@if (! $loop->last),@endif
                @endforeach
            </p>
        @endif
        {{-- Closing: the address drops the person, so a reload shows just the table. --}}
        <div x-data="tabs(@js($activity ? 'activity' : 'orders'))" x-on:modal-closed.window="if ($event.detail === 'person') history.replaceState(null, '', @js($personUrl(null)))">
        @if ($activity)
            <x-tabs :tabs="['activity' => [__('Activity'), '#activity', count($activity['entries'])], 'orders' => [__('Order by order'), '#orders', $turns->count()]]" active="activity" />
            {{-- Activity: everything they did that day, in time order. --}}
            <section x-show="tab === 'activity'" x-data="{ page: 1, per: 25, total: {{ count($activity['entries']) }} }">
            <div class="rounded-xl border border-gray-200 bg-white">
                @forelse ($activity['entries'] as $e)
                    <div x-show="Math.ceil({{ $loop->iteration }} / per) === page" @class(['flex gap-3 border-b border-gray-100 px-4 py-2 text-sm last:border-0', 'bg-red-50/60' => $e['flag'] === 'red' || ($e['flag'] && $e['flag'] !== 'red'), 'bg-gray-50' => $e['kind'] === 'free' && ! $e['flag']])>
                        <span class="w-24 shrink-0 tabular-nums text-gray-500">{{ $e['at']->format('g:i A') }}@if ($e['until'])<span class="block text-xs">{{ __('to :t', ['t' => $e['until']->format('g:i A')]) }}</span>@endif</span>
                        <span class="min-w-0 flex-1">
                            @if ($e['order_id'])<a href="{{ route('orders.show', $e['order_id']) }}" class="text-gray-800 hover:text-primary hover:underline">{{ $e['text'] }}</a>@else<span @class(['text-gray-800', 'font-medium text-red-700' => $e['kind'] === 'free' && $e['flag'], 'text-gray-500' => $e['kind'] === 'free' && ! $e['flag']])>{{ $e['text'] }}</span>@endif
                            @if ($e['flag'] && $e['flag'] !== 'red')<span class="mt-0.5 block text-xs font-medium text-red-600">⚠ {{ $e['flag'] }}</span>@endif
                        </span>
                    </div>
                @empty
                    <p class="px-4 py-6 text-center text-sm text-gray-500">{{ __('Nothing recorded on this day.') }}</p>
                @endforelse
            </div>
            <x-list.pager />
            </section>

        @endif
            <section x-show="tab === 'orders'" x-cloak x-data="{ page: 1, per: 20, total: {{ $turns->count() }} }">
            <x-list.table>
                <x-slot:head>
                    <th>{{ __('Order') }}</th>
                    <th>{{ __('Given') }}</th>
                    <th>{{ __('Opened') }}</th>
                    <th>{{ __('First action') }}</th>
                    <th>{{ __('Left the desk') }}</th>
                    <th class="text-right">{{ __('Net time') }}</th>
                </x-slot:head>
                @foreach ($turns as $t)
                    <tr x-show="Math.ceil({{ $loop->iteration }} / per) === page" class="cursor-pointer" onclick="location.href='{{ route('orders.show', $t['order_id']) }}'">
                        <td><span class="font-mono font-medium text-gray-800">{{ $t['order_no'] }}</span><span class="block text-xs text-gray-500">{{ $t['customer'] }}</span></td>
                        <td class="tabular-nums">{{ $at($t['given']) }}<span class="block text-xs text-gray-500">{{ $t['how'] === 'auto' ? __('given by the system') : ($t['how'] === 'reassigned' ? __('handed over') : __('took it')) }}</span></td>
                        <td class="tabular-nums">{{ $at($t['opened']) }}@if ($t['opened'])<span class="block text-xs text-gray-500">+{{ $span($t['open_seconds']) }}</span>@endif</td>
                        <td class="tabular-nums">{{ $at($t['acted']) }}@if ($t['acted'])<span class="block text-xs text-gray-500">+{{ $span($t['start_seconds']) }}</span>@endif</td>
                        <td>
                            @if ($t['sent'])
                                <span class="tabular-nums">{{ $at($t['sent']) }}</span><span class="block text-xs {{ $t['sent_to'] === 'confirmed' ? 'text-green-700' : 'text-gray-500' }}">{{ $t['sent_to'] === 'confirmed' ? __('Confirmed') : __('Cancelled') }}</span>
                            @elseif ($ended[$t['ended_reason']] ?? null)
                                <span class="text-xs {{ in_array($t['ended_reason'], ['idle', 'timeout'], true) ? 'font-medium text-red-600' : 'text-gray-500' }}">{{ $ended[$t['ended_reason']] }}</span>
                            @else
                                <span class="text-xs text-gray-500">{{ __('Still with them') }} · {{ $t['status'] }}</span>
                            @endif
                        </td>
                        <td class="text-right font-medium tabular-nums">{{ $span($t['net_seconds']) }}</td>
                    </tr>
                @endforeach
            </x-list.table>

            <x-list.cards>
                @foreach ($turns as $t)
                    <a href="{{ route('orders.show', $t['order_id']) }}" x-show="Math.ceil({{ $loop->iteration }} / per) === page" class="block">
                        <x-record-card :title="$t['order_no'].' · '.$t['customer']" :subtitle="__('Given :a · opened :b · first action :c', ['a' => $at($t['given']), 'b' => $at($t['opened']), 'c' => $at($t['acted'])])">
                            <x-slot:footer>
                                @if ($t['sent']){{ ($t['sent_to'] === 'confirmed' ? __('Confirmed') : __('Cancelled')).' '.$at($t['sent']) }}@elseif ($ended[$t['ended_reason']] ?? null){{ $ended[$t['ended_reason']] }}@else{{ __('Still with them') }} · {{ $t['status'] }}@endif
                            </x-slot:footer>
                            <x-slot:actions><span class="font-semibold tabular-nums">{{ $span($t['net_seconds']) }}</span></x-slot:actions>
                        </x-record-card>
                    </a>
                @endforeach
            </x-list.cards>
            <x-list.pager />
            </section>
        </div>
        </x-modal>
        @endif
    @endif
</x-layouts.app>
