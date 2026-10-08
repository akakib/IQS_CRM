@php
    $url = fn ($f, $t) => route('calls-report.index', ['from' => $f->toDateString(), 'to' => $t->toDateString()]);
    $presets = [__('7 days') => [today()->subDays(6), today()], __('30 days') => [today()->subDays(29), today()], __('This month') => [today()->startOfMonth(), today()]];
    $mmss = fn ($s) => $s === null ? '-' : intdiv((int) $s, 60).':'.str_pad((string) ((int) $s % 60), 2, '0', STR_PAD_LEFT);
@endphp

<x-layouts.app :heading="__('Communication')">
    @include('reports._communication-tabs', ['active' => 'calls'])

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
    <p class="mb-4 text-xs text-gray-500">{{ __('Out: calls staff made from an order (tap to call, copy or QR) and what happened. In: customers who phoned, logged in Communication, Customer calls. Recordings are the Drive links staff added.') }}</p>

    @if ($people->isEmpty())
        <x-empty-state :message="__('No customer calls in these dates.')" />
    @else
        <h2 class="mb-2 text-sm font-semibold text-gray-800">{{ __('By person') }}</h2>
        <x-list.table class="mb-6">
            <x-slot:head>
                <th>{{ __('Person') }}</th>
                <th class="text-right">{{ __('Calls made') }}</th>
                <th class="text-right">{{ __('Answered') }}</th>
                <th class="text-right">{{ __('Calls in') }}</th>
                <th class="text-right">{{ __('Talk time') }}</th>
                <th class="text-right">{{ __('With recording') }}</th>
            </x-slot:head>
            @foreach ($people as $p)
                <tr>
                    <td class="font-medium text-gray-800">{{ $p->name }}</td>
                    <td class="text-right tabular-nums">{{ $p->out_n }}</td>
                    <td class="text-right tabular-nums">{{ $p->answered }}</td>
                    <td class="text-right tabular-nums">{{ $p->in_n }}</td>
                    <td class="text-right tabular-nums">{{ \App\Services\Reports\WorkTime::span((int) $p->seconds ?: null) }}</td>
                    <td class="text-right tabular-nums">{{ $p->recorded }}</td>
                </tr>
            @endforeach
        </x-list.table>
        <x-list.cards class="mb-6">
            @foreach ($people as $p)
                <x-record-card :title="$p->name" :subtitle="__(':o made · :a answered · :i in', ['o' => $p->out_n, 'a' => $p->answered, 'i' => $p->in_n])">
                    <x-slot:footer>{{ __('Talk time :t · :r with recording', ['t' => \App\Services\Reports\WorkTime::span((int) $p->seconds ?: null), 'r' => $p->recorded]) }}</x-slot:footer>
                </x-record-card>
            @endforeach
        </x-list.cards>

        <h2 class="mb-2 text-sm font-semibold text-gray-800">{{ __('Calls') }}</h2>
        <x-list.table>
            <x-slot:head>
                <th>{{ __('When') }}</th>
                <th>{{ __('Person') }}</th>
                <th>{{ __('Customer') }}</th>
                <th>{{ __('What happened') }}</th>
                <th class="text-right">{{ __('Length') }}</th>
                <th>{{ __('Recording') }}</th>
            </x-slot:head>
            @foreach ($calls as $c)
                <tr>
                    <td class="whitespace-nowrap tabular-nums text-gray-600">{{ \Illuminate\Support\Carbon::parse($c->started_at)->format('d M, g:i A') }}</td>
                    <td class="text-gray-800">{{ $c->person }}</td>
                    <td><span class="font-mono text-gray-800">{{ $c->phone }}</span>@if ($c->order_no)<a href="{{ route('orders.show', $c->order_id) }}" class="block text-xs text-primary hover:underline">{{ $c->order_no }}</a>@endif</td>
                    <td>
                        <span @class(['rounded-full px-2 py-0.5 text-xs font-medium', 'bg-blue-50 text-blue-700' => $c->direction === 'in', 'bg-gray-100 text-gray-700' => $c->direction === 'out'])>{{ $c->direction === 'in' ? __('In') : __('Out') }}</span>
                        <span class="text-sm text-gray-800">{{ $c->outcome ? __($labels[$c->outcome] ?? $c->outcome) : __('Not filled in') }}</span>
                        @if ($c->note)<span class="block text-xs text-gray-500">{{ $c->note }}</span>@endif
                    </td>
                    <td class="text-right tabular-nums">{{ $mmss($c->duration_seconds) }}</td>
                    <td>@if ($c->recording_url)<a href="{{ $c->recording_url }}" target="_blank" rel="noopener" class="text-sm text-primary hover:underline">{{ __('Open') }}</a>@else<span class="text-gray-400">-</span>@endif</td>
                </tr>
            @endforeach
        </x-list.table>
        <x-list.cards>
            @foreach ($calls as $c)
                <x-record-card :title="($c->direction === 'in' ? __('In') : __('Out')).' · '.$c->phone" :subtitle="$c->person.' · '.\Illuminate\Support\Carbon::parse($c->started_at)->format('d M, g:i A')">
                    <x-slot:footer>{{ $c->outcome ? __($labels[$c->outcome] ?? $c->outcome) : __('Not filled in') }} · {{ $mmss($c->duration_seconds) }}@if ($c->order_no) · {{ $c->order_no }}@endif
                        @if ($c->recording_url)<a href="{{ $c->recording_url }}" target="_blank" rel="noopener" class="block text-primary hover:underline">{{ __('Open recording') }}</a>@endif</x-slot:footer>
                </x-record-card>
            @endforeach
        </x-list.cards>
        <div class="mt-4">{{ $calls->links() }}</div>
    @endif
</x-layouts.app>
