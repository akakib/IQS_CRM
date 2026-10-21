{{-- Sales report > My list: the products and categories this person keeps an eye on. For each: orders, pieces and
     money today, in the last 7 days and the last 30 days (today included), per channel and in total, and the stock now.
     Confirmed / Packed / Delivered switch which step counts as a sale, as on All sales. --}}
@php
    use App\Models\Order;
    use App\Services\Reports\SalesReport;
    $money = fn ($n) => '৳'.number_format((float) $n);
    $num = fn ($n) => rtrim(rtrim(number_format((float) $n, 2), '0'), '.');
    $cols = ['today' => __('Today'), 'week' => __('Last 7 days'), 'month' => __('Last 30 days')];
@endphp

<x-layouts.app :heading="__('Sales report')">
    <x-tabs :tabs="['all' => [__('All sales'), route('sales-report.index', ['stage' => $stage])], 'mine' => [__('My list'), route('sales-report.index', ['tab' => 'mine', 'stage' => $stage])]]" active="mine" />

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap gap-2">
            @foreach (SalesReport::STAGES as $key => $label)
                <a href="{{ route('sales-report.index', ['tab' => 'mine', 'stage' => $key]) }}" @class(['inline-flex min-h-10 items-center rounded-full border px-4 text-sm', 'border-primary bg-primary text-white' => $stage === $key, 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' => $stage !== $key])>{{ __($label) }}</a>
            @endforeach
        </div>
        <form method="POST" action="{{ route('sales-report.watch.add') }}" x-ref="add" class="w-full sm:w-80">
            @csrf
            <x-search-select name="item" :url="route('sales-report.watch.options')" :placeholder="__('Add a product or category')" @select-change="$nextTick(() => $event.detail && $refs.add.requestSubmit())" />
        </form>
    </div>

    @if ($rows === [])
        <div class="rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center text-sm text-gray-500">
            {{ __('Your list is empty. Add the products or categories you want to keep an eye on; they show here with today, 7 days and 30 days of sales.') }}
        </div>
    @endif

    <div class="space-y-4">
        @foreach ($rows as $r)
            @php
                $s = $r['stock'];
                $stockText = $r['kind'] === 'category'
                    ? trans_choice(':count product|:count products', $s['products']).' · '.__(':o out of stock', ['o' => $s['out']])
                    : ($s['sizes'] === 0 ? __('No active size') : ($s['out'] === $s['sizes'] ? __('Out of stock') : ($s['out'] ? __(':o of :n sizes out of stock', ['o' => $s['out'], 'n' => $s['sizes']]) : __('In stock'))));
            @endphp
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <div class="flex flex-wrap items-start justify-between gap-2 border-b border-gray-100 px-4 py-3">
                    <div class="min-w-0">
                        <p class="font-semibold text-gray-900">{{ $r['name'] }}</p>
                        <p class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                            <x-badge :color="$r['kind'] === 'category' ? 'purple' : 'gray'">{{ $r['kind'] === 'category' ? __('Category') : __('Product') }}</x-badge>
                            <span @class(['font-medium', 'text-red-700' => $s['out'] > 0, 'text-green-700' => $s['out'] === 0 && $s['sizes'] > 0])>{{ $stockText }}</span>
                            @if ($s['pre'])<span>· {{ __(':n on pre-order', ['n' => $s['pre']]) }}</span>@endif
                            @if ($s['counted'] !== null)<span>· {{ __(':n counted in stock', ['n' => $num($s['counted'])]) }}</span>@endif
                        </p>
                    </div>
                    <form method="POST" action="{{ route('sales-report.watch.remove', $r['id']) }}">@csrf @method('DELETE')
                        <button class="text-xs text-gray-500 hover:text-red-600">{{ __('Remove') }}</button>
                    </form>
                </div>

                <table class="w-full table-fixed text-sm">
                    <thead class="bg-gray-50 text-xs text-gray-500">
                        <tr>
                            <th class="w-[28%] px-3 py-2 text-left font-medium sm:px-4">{{ __('Channel') }}</th>
                            @foreach ($cols as $label)<th class="px-2 py-2 text-right font-medium sm:px-4">{{ $label }}</th>@endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($r['channels'] as $channel => $c)
                            <tr>
                                <td class="truncate px-3 py-2 text-gray-700 sm:px-4">{{ Order::channelLabel($channel) }}</td>
                                @foreach (array_keys($cols) as $b)
                                    <td class="px-2 py-2 text-right tabular-nums sm:px-4">
                                        @if ($c[$b.'_orders'])
                                            <span class="block text-gray-900">{{ $money($c[$b.'_money']) }}</span>
                                            <span class="block text-xs text-gray-500">{{ trans_choice(':count order|:count orders', (int) $c[$b.'_orders']) }}@if ($c[$b.'_pcs'] > 0) · {{ $num($c[$b.'_pcs']) }} {{ __('pcs') }}@endif</span>
                                        @else
                                            <span class="text-gray-300">0</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                        <tr class="bg-primary-soft/40 font-semibold">
                            <td class="px-3 py-2 text-gray-900 sm:px-4">{{ __('Total') }}</td>
                            @foreach (array_keys($cols) as $b)
                                <td class="px-2 py-2 text-right tabular-nums sm:px-4">
                                    <span class="block text-gray-900">{{ $money($r['total'][$b.'_money']) }}</span>
                                    <span class="block text-xs font-normal text-gray-500">{{ trans_choice(':count order|:count orders', (int) $r['total'][$b.'_orders']) }}@if ($r['total'][$b.'_pcs'] > 0) · {{ $num($r['total'][$b.'_pcs']) }} {{ __('pcs') }}@endif</span>
                                </td>
                            @endforeach
                        </tr>
                    </tbody>
                </table>
            </div>
        @endforeach
    </div>
</x-layouts.app>
