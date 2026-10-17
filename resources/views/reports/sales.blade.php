{{-- Sales report: confirmed, packed and delivered side by side for the dates; one step's items below. --}}
@php
    use App\Services\Reports\SalesReport;
    use App\Support\Units;
    $q = fn (array $more = []) => array_merge(['from' => $from, 'to' => $to, 'stage' => $stage, 'by' => $by], $more);
    $presets = [__('Today') => [today(), today()], __('7 days') => [today()->subDays(6), today()], __('This month') => [today()->startOfMonth(), today()]];
    $money = fn ($n) => '৳'.number_format((float) $n);
    $num = fn ($n) => rtrim(rtrim(number_format((float) $n, 3), '0'), '.');
    $color = ['confirmed' => 'border-blue-500', 'packed' => 'border-purple-500', 'delivered' => 'border-green-600'];
@endphp

<x-layouts.app :heading="__('Sales report')">
    <div class="mb-4 flex flex-wrap items-center gap-2">
        @foreach ($presets as $label => [$f, $t])
            @php $on = $from === $f->toDateString() && $to === $t->toDateString(); @endphp
            <a href="{{ route('sales-report.index', $q(['from' => $f->toDateString(), 'to' => $t->toDateString()])) }}" @class(['inline-flex min-h-10 items-center rounded-full border px-4 text-sm', 'border-primary bg-primary text-white' => $on, 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' => ! $on])>{{ $label }}</a>
        @endforeach
        <form method="GET" x-ref="range" x-data class="flex flex-wrap items-center gap-2">
            <input type="hidden" name="stage" value="{{ $stage }}"><input type="hidden" name="by" value="{{ $by }}">
            <x-date-input name="from" :value="$from" :max="today()->toDateString()" :clearable="false" @date-change="$nextTick(() => $refs.range.requestSubmit())" />
            <span class="text-sm text-gray-400">{{ __('to') }}</span>
            <x-date-input name="to" :value="$to" :max="today()->toDateString()" :clearable="false" @date-change="$nextTick(() => $refs.range.requestSubmit())" />
        </form>
    </div>

    {{-- The three steps side by side: tap one to list its items below. --}}
    <div class="mb-6 grid gap-3 md:grid-cols-3">
        @foreach (SalesReport::STAGES as $key => $label)
            @php $s = $summary[$key]; @endphp
            <a href="{{ route('sales-report.index', $q(['stage' => $key])) }}" @class(['block rounded-2xl border bg-white p-4 hover:border-primary', 'border-l-4 '.$color[$key].' ring-1 ring-primary/30' => $stage === $key, 'border-gray-200' => $stage !== $key])>
                <p class="text-sm font-semibold text-gray-700">{{ __($label) }}</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900">{{ number_format($s['orders']) }} <span class="text-sm font-normal text-gray-500">{{ trans_choice('order|orders', $s['orders']) }}</span></p>
                <p class="text-base font-semibold tabular-nums text-gray-800">{{ $money($s['money']) }}</p>
                <p class="mt-1 text-sm tabular-nums text-gray-600">{{ Units::amount($s['grams'], $s['ml']) }}</p>
                @if ($s['gone'] && $key !== 'delivered')<p class="mt-1 text-xs text-gray-500">{{ __('of which cancelled or returned since: :n', ['n' => $s['gone']]) }}</p>@endif
            </a>
        @endforeach
    </div>

    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <div class="grid grid-cols-3 gap-1 rounded-xl bg-gray-100 p-1">
            @foreach (SalesReport::STAGES as $key => $label)
                <a href="{{ route('sales-report.index', $q(['stage' => $key])) }}" @class(['inline-flex min-h-9 items-center justify-center rounded-lg px-3 text-sm font-medium', 'bg-white text-gray-900 shadow-sm' => $stage === $key, 'text-gray-500' => $stage !== $key])>{{ __($label) }}</a>
            @endforeach
        </div>
        <x-export-buttons :print="route('sales-report.print', $q())" :excel="route('sales-report.excel', $q())" size="md" />
    </div>

    @if ($products->isEmpty())
        <x-empty-state :message="__('Nothing :s in these dates.', ['s' => mb_strtolower(__(SalesReport::STAGES[$stage]))])" />
    @else
        {{-- The items of this step. --}}
        <x-list.table class="mb-6">
            <x-slot:head><th>{{ __('Item') }}</th><th class="text-right">{{ __('Qty') }}</th><th class="text-right">{{ __('Amount') }}</th><th class="text-right">{{ __('Money') }}</th></x-slot:head>
            @foreach ($products as $p)
                <tr>
                    <td class="font-medium text-gray-900">{{ $p->product }}{{ $p->variant !== 'Default' ? ' · '.$p->variant : '' }}</td>
                    <td class="text-right tabular-nums">{{ $num($p->qty) }}</td>
                    <td class="text-right tabular-nums text-gray-700">{{ Units::amount((float) $p->grams, (float) $p->ml) }}</td>
                    <td class="text-right tabular-nums">{{ $money($p->money) }}</td>
                </tr>
            @endforeach
        </x-list.table>
        <x-list.cards class="mb-6">
            @foreach ($products as $p)
                <x-record-card :title="$p->product.($p->variant !== 'Default' ? ' · '.$p->variant : '')" :subtitle="__('Qty :n', ['n' => $num($p->qty)]).' · '.Units::amount((float) $p->grams, (float) $p->ml)">
                    <x-slot:badge><span class="text-sm font-semibold tabular-nums text-gray-900">{{ $money($p->money) }}</span></x-slot:badge>
                </x-record-card>
            @endforeach
        </x-list.cards>

        {{-- The same step by day or by month. --}}
        <div class="mb-2 flex items-center justify-between gap-2">
            <p class="text-sm font-semibold text-gray-900">{{ $by === 'month' ? __('By month') : __('By day') }}</p>
            <div class="grid grid-cols-2 gap-1 rounded-xl bg-gray-100 p-1">
                @foreach (['day' => __('By day'), 'month' => __('By month')] as $key => $label)
                    <a href="{{ route('sales-report.index', $q(['by' => $key])) }}" @class(['inline-flex min-h-8 items-center justify-center rounded-lg px-3 text-xs font-medium', 'bg-white text-gray-900 shadow-sm' => $by === $key, 'text-gray-500' => $by !== $key])>{{ $label }}</a>
                @endforeach
            </div>
        </div>
        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500"><tr><th class="px-4 py-2.5">{{ $by === 'month' ? __('Month') : __('Day') }}</th><th class="px-4 py-2.5 text-right">{{ __('Orders') }}</th><th class="px-4 py-2.5 text-right">{{ __('Amount') }}</th><th class="px-4 py-2.5 text-right">{{ __('Money') }}</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($periods as $r)
                        <tr>
                            <td class="px-4 py-2.5 text-gray-800">{{ $by === 'month' ? \Illuminate\Support\Carbon::parse($r->period.'-01')->format('M Y') : \Illuminate\Support\Carbon::parse($r->period)->format('d M, D') }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ number_format($r->orders) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-gray-700">{{ Units::amount($r->grams, $r->ml) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ $money($r->money) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-layouts.app>
