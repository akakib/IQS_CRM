@php
    use App\Services\Reports\WorkTime;
    $span = fn ($s) => WorkTime::span($s);
    $at = fn ($t) => $t ? $t->format('g:i A') : '-';
    $dayUrl = fn ($d, $p = null) => route('work-time.index', array_filter(['date' => $d->toDateString(), 'person' => $p]));
    $isToday = $day->isToday();
    $isYesterday = $day->isYesterday();
    $ended = ['idle' => __('Given back: nothing done'), 'timeout' => __('Given back: timer'), 'break' => __('Back to New: break'), 'reassigned' => __('Handed to someone else'), 'finished' => null];
@endphp

<x-layouts.app :heading="__('Work time')">
    <div class="mb-4 flex flex-wrap items-center gap-2">
        <a href="{{ $dayUrl(today()) }}" @class(['rounded-full border px-3 py-1.5 text-sm', 'border-primary bg-primary text-white' => $isToday, 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' => ! $isToday])>{{ __('Today') }}</a>
        <a href="{{ $dayUrl(today()->subDay()) }}" @class(['rounded-full border px-3 py-1.5 text-sm', 'border-primary bg-primary text-white' => $isYesterday, 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' => ! $isYesterday])>{{ __('Yesterday') }}</a>
        <form method="GET" x-ref="day" x-data>
            <x-date-input name="date" :value="$day->toDateString()" :max="today()->toDateString()" :clearable="false" @date-change="$nextTick(() => $refs.day.requestSubmit())" />
        </form>
    </div>
    <p class="mb-4 text-xs text-gray-500">{{ __('Orders given to each person on this day. Times are the middle value (median). Net time leaves out the time the customer made us wait: No answer until the order came back, and On hold.') }}</p>

    @if ($people->isEmpty())
        <x-empty-state :message="__('No orders were given to anyone on this day.')" />
    @else
        <x-list.table>
            <x-slot:head>
                <th>{{ __('Person') }}</th>
                <th class="text-right">{{ __('Orders') }}</th>
                <th class="text-right">{{ __('Confirmed') }}</th>
                <th class="text-right">{{ __('Cancelled') }}</th>
                <th class="text-right">{{ __('Opened in') }}</th>
                <th class="text-right">{{ __('First action in') }}</th>
                <th class="text-right">{{ __('To packaging (net)') }}</th>
                <th class="text-right">{{ __('Given back') }}</th>
                <th class="text-right">{{ __('Break') }}</th>
            </x-slot:head>
            @foreach ($people as $p)
                <tr @class(['cursor-pointer', 'bg-primary-soft' => $person && $person['id'] === $p['id']]) onclick="location.href='{{ $dayUrl($day, $p['id']) }}'">
                    <td><span class="flex items-center gap-2"><x-avatar :name="$p['name']" :photo="$p['photo']" size="sm" /><span class="font-medium text-gray-800">{{ $p['name'] }}</span></span></td>
                    <td class="text-right tabular-nums">{{ $p['turns'] }}</td>
                    <td class="text-right tabular-nums">{{ $p['confirmed'] }}</td>
                    <td class="text-right tabular-nums">{{ $p['cancelled'] }}</td>
                    <td class="text-right tabular-nums">{{ $span($p['open']) }}</td>
                    <td class="text-right tabular-nums">{{ $span($p['start']) }}</td>
                    <td class="text-right font-medium tabular-nums">{{ $span($p['net']) }}</td>
                    <td @class(['text-right tabular-nums', 'font-semibold text-red-600' => $p['given_back']])>{{ $p['given_back'] }}</td>
                    <td class="text-right tabular-nums">{{ $p['break_minutes'] }} {{ __('min') }}</td>
                </tr>
            @endforeach
        </x-list.table>

        <x-list.cards>
            @foreach ($people as $p)
                <a href="{{ $dayUrl($day, $p['id']) }}" class="block">
                    <x-record-card :title="$p['name']" :subtitle="__(':n orders · :c confirmed · :x cancelled', ['n' => $p['turns'], 'c' => $p['confirmed'], 'x' => $p['cancelled']])">
                        <x-slot:footer>{{ __('Opened in :a · first action in :b · break :m min', ['a' => $span($p['open']), 'b' => $span($p['start']), 'm' => $p['break_minutes']]) }}@if ($p['given_back']) · <span class="text-red-600">{{ __(':n given back', ['n' => $p['given_back']]) }}</span>@endif</x-slot:footer>
                        <x-slot:actions><span class="text-right"><span class="block text-xs text-gray-500">{{ __('Net') }}</span><span class="font-semibold tabular-nums">{{ $span($p['net']) }}</span></span></x-slot:actions>
                    </x-record-card>
                </a>
            @endforeach
        </x-list.cards>

        @if ($person)
            <h2 class="mb-2 mt-6 text-sm font-semibold text-gray-800">{{ __(':n, order by order', ['n' => $person['name']]) }}</h2>
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
                    <tr class="cursor-pointer" onclick="location.href='{{ route('orders.show', $t['order_id']) }}'">
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
                    <a href="{{ route('orders.show', $t['order_id']) }}" class="block">
                        <x-record-card :title="$t['order_no'].' · '.$t['customer']" :subtitle="__('Given :a · opened :b · first action :c', ['a' => $at($t['given']), 'b' => $at($t['opened']), 'c' => $at($t['acted'])])">
                            <x-slot:footer>
                                @if ($t['sent']){{ ($t['sent_to'] === 'confirmed' ? __('Confirmed') : __('Cancelled')).' '.$at($t['sent']) }}@elseif ($ended[$t['ended_reason']] ?? null){{ $ended[$t['ended_reason']] }}@else{{ __('Still with them') }} · {{ $t['status'] }}@endif
                            </x-slot:footer>
                            <x-slot:actions><span class="font-semibold tabular-nums">{{ $span($t['net_seconds']) }}</span></x-slot:actions>
                        </x-record-card>
                    </a>
                @endforeach
            </x-list.cards>
        @endif
    @endif
</x-layouts.app>
