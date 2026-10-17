{{-- One step's items of the Sales report, for paper or PDF, the same way as every list. --}}
@php
    use App\Services\Reports\SalesReport;
    use App\Support\Units;
    $label = __(SalesReport::STAGES[$stage]);
    $num = fn ($n) => rtrim(rtrim(number_format((float) $n, 3), '0'), '.');
@endphp
<x-print-page :title="__('Sales :s :f to :t', ['s' => $label, 'f' => $from, 't' => $to])" :back="route('sales-report.index', ['from' => $from, 'to' => $to, 'stage' => $stage])">
    <h1>{{ __('Sales: :s', ['s' => $label]) }}</h1>
    <div class="meta">{{ $from === $to ? \Illuminate\Support\Carbon::parse($from)->format('d M Y') : \Illuminate\Support\Carbon::parse($from)->format('d M Y').' to '.\Illuminate\Support\Carbon::parse($to)->format('d M Y') }} · {{ __('Printed :t by :n', ['t' => now()->format('d M Y, g:i A'), 'n' => auth()->user()->name]) }}</div>
    <div class="sum">
        <span>{{ __('Orders') }}: <b>{{ number_format($summary['orders']) }}</b></span>
        <span>{{ __('Money') }}: <b>৳{{ number_format($summary['money']) }}</b></span>
        <span>{{ __('Amount') }}: <b>{{ Units::amount($summary['grams'], $summary['ml']) }}</b></span>
    </div>
    @if ($products->isEmpty())
        <p>{{ __('Nothing in these dates.') }}</p>
    @else
        <table class="list">
            <thead><tr><th>#</th><th>{{ __('Item') }}</th><th>SKU</th><th class="n">{{ __('Qty') }}</th><th class="n">{{ __('Amount') }}</th><th class="n">{{ __('Money') }}</th></tr></thead>
            <tbody>
                @foreach ($products as $i => $p)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td>{{ $p->product }}{{ $p->variant !== 'Default' ? ' · '.$p->variant : '' }}</td>
                        <td>{{ $p->sku }}</td>
                        <td class="n">{{ $num($p->qty) }}</td>
                        <td class="n">{{ Units::amount((float) $p->grams, (float) $p->ml) }}</td>
                        <td class="n">৳{{ number_format((float) $p->money) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-print-page>
