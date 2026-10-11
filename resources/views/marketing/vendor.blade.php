@php
    $tk = fn ($v) => '৳'.number_format(abs((float) $v));
    $usd = fn ($v) => '$'.number_format((float) $v, 2);
    // Plus = we owe them, minus = they hold our advance.
    $stand = fn ($b) => $b > 0.005 ? __('we owe :a', ['a' => $tk($b)]) : ($b < -0.005 ? __('advance :a', ['a' => $tk($b)]) : __('settled'));
    $kinds = ['opening' => __('Opening balance'), 'lot' => __('Dollars bought'), 'payment' => __('Paid')];
@endphp

<x-layouts.app :heading="$vendor->name">
    <a href="{{ route('usd-lots.index') }}" class="mb-3 inline-block text-sm text-primary hover:underline">&larr; {{ __('USD lots') }}</a>

    <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="rounded-xl border border-gray-200 bg-white p-3"><p class="text-xs text-gray-500">{{ __('Now') }}</p>
            <p @class(['text-lg font-semibold tabular-nums', 'text-red-700' => $vendor->due > 0, 'text-blue-700' => $vendor->advance > 0])>{{ $vendor->due > 0 ? __('We owe :a', ['a' => $tk($vendor->due)]) : ($vendor->advance > 0 ? __('Advance :a', ['a' => $tk($vendor->advance)]) : __('Paid up')) }}</p>
            @if ($vendor->next_due)<p class="text-xs text-gray-500">{{ __('due by :d', ['d' => date('d M Y', strtotime($vendor->next_due))]) }}</p>@endif</div>
        <div class="rounded-xl border border-gray-200 bg-white p-3"><p class="text-xs text-gray-500">{{ __('Dollars bought') }}</p><p class="text-lg font-semibold tabular-nums">{{ $tk($vendor->bought) }}</p></div>
        <div class="rounded-xl border border-gray-200 bg-white p-3"><p class="text-xs text-gray-500">{{ __('Paid') }}</p><p class="text-lg font-semibold tabular-nums">{{ $tk($vendor->paid) }}</p></div>
        <div class="rounded-xl border border-gray-200 bg-white p-3"><p class="text-xs text-gray-500">{{ __('Opening balance') }}</p><p class="text-lg font-semibold tabular-nums">{{ $vendor->opening ? $stand($vendor->opening) : '-' }}</p></div>
    </div>

    <p class="mb-2 text-xs text-gray-500">{{ __('Newest first. The last column is where we stood after each line.') }}</p>
    @if ($rows->isEmpty())
        <x-empty-state :message="__('Nothing with this vendor yet.')" />
    @else
        <x-list.table>
            <x-slot:head>
                <th>{{ __('Date') }}</th><th>{{ __('What') }}</th><th class="text-right">{{ __('Dollars') }}</th>
                <th class="text-right">{{ __('Amount') }}</th><th class="text-right">{{ __('After this') }}</th>
            </x-slot:head>
            @foreach ($rows as $r)
                <tr>
                    <td class="whitespace-nowrap">{{ date('d M Y', strtotime($r['date'])) }}</td>
                    <td>{{ $kinds[$r['kind']] }}@if ($r['ref'] || $r['note'])<span class="block text-xs text-gray-500">{{ trim(($r['ref'] ?? '').' '.($r['note'] ?? '')) }}</span>@endif</td>
                    <td class="text-right tabular-nums">@if ($r['usd']){{ $usd($r['usd']) }} <span class="text-xs text-gray-500">@ {{ number_format($r['rate'], 2) }}</span>@else - @endif</td>
                    <td @class(['text-right tabular-nums', 'text-green-700' => $r['amount'] < 0])>{{ ($r['amount'] < 0 ? '-' : '+').$tk($r['amount']) }}</td>
                    <td @class(['text-right tabular-nums font-medium', 'text-red-700' => $r['balance'] > 0, 'text-blue-700' => $r['balance'] < 0])>{{ $stand($r['balance']) }}</td>
                </tr>
            @endforeach
        </x-list.table>
        <x-list.cards>
            @foreach ($rows as $r)
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-gray-800">{{ $kinds[$r['kind']] }}@if ($r['usd']) <span class="text-sm font-normal text-gray-600">{{ $usd($r['usd']) }} @ {{ number_format($r['rate'], 2) }}</span>@endif</p>
                        <p @class(['font-semibold tabular-nums', 'text-green-700' => $r['amount'] < 0])>{{ ($r['amount'] < 0 ? '-' : '+').$tk($r['amount']) }}</p>
                    </div>
                    <p class="mt-1 text-xs text-gray-500">{{ date('d M Y', strtotime($r['date'])) }}@if ($r['ref'] || $r['note']) · {{ trim(($r['ref'] ?? '').' '.($r['note'] ?? '')) }}@endif</p>
                    <p @class(['mt-1 text-xs font-medium', 'text-red-700' => $r['balance'] > 0, 'text-blue-700' => $r['balance'] < 0, 'text-gray-500' => abs($r['balance']) < 0.005])>{{ __('After this: :s', ['s' => $stand($r['balance'])]) }}</p>
                </div>
            @endforeach
        </x-list.cards>
    @endif
</x-layouts.app>
