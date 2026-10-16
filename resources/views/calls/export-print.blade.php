<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    {{-- A call list's numbers for paper: A4 landscape, no app chrome. Print, or Save as PDF. --}}
    <style>
        @page { size: A4 landscape; margin: 10mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, 'Segoe UI', 'Noto Sans Bengali', Arial, sans-serif; font-size: 10.5px; color: #111; background: #fff; }
        .bar { display: flex; gap: 8px; justify-content: flex-end; padding: 10px 16px; border-bottom: 1px solid #e5e7eb; }
        .bar button { font: inherit; font-size: 13px; padding: 6px 14px; border-radius: 8px; border: 1px solid #d1d5db; background: #fff; cursor: pointer; }
        .bar .go { background: #0d542b; border-color: #0d542b; color: #fff; }
        .page { padding: 12px 16px; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        .meta { color: #4b5563; margin-bottom: 8px; }
        .sum { display: flex; flex-wrap: wrap; gap: 6px 18px; margin: 6px 0 10px; font-size: 12px; }
        .sum b { font-size: 13px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #d1d5db; padding: 3px 5px; text-align: left; vertical-align: top; }
        th { background: #eaf3de; font-weight: 600; }
        td.n, th.n { text-align: right; white-space: nowrap; }
        .bad { color: #b91c1c; } .good { color: #166534; }
        tr { page-break-inside: avoid; }
        thead { display: table-header-group; }
        .warn { color: #b45309; margin: 6px 0; }
        @media print { .bar { display: none; } .page { padding: 0; } }
    </style>
</head>
<body>
    <div class="bar">
        <button type="button" onclick="window.close()">{{ __('Close') }}</button>
        <button type="button" class="go" onclick="window.print()">{{ __('Print or save as PDF') }}</button>
    </div>
    <div class="page">
        <h1>{{ $list->name }}</h1>
        <div class="meta">{{ $describe }} · {{ __('Printed :t by :n', ['t' => now()->format('d M Y, g:i A'), 'n' => auth()->user()->name]) }}</div>
        <div class="sum">
            <span>{{ __('Numbers') }}: <b>{{ number_format($rows->count()) }}</b></span>
            <span>{{ __('Calls') }}: <b>{{ number_format($overview['calls']) }}</b></span>
            <span>{{ __('Talked') }}: <b>{{ number_format($overview['talked']) }}</b></span>
            <span>{{ __('Happy') }}: <b class="good">{{ $overview['happy'] }}</b></span>
            <span>{{ __('Okay') }}: <b>{{ $overview['okay'] }}</b></span>
            <span>{{ __('Unhappy') }}: <b class="bad">{{ $overview['unhappy'] }}</b></span>
            <span>{{ __('Satisfaction') }}: <b>{{ $overview['satisfaction'] === null ? '-' : $overview['satisfaction'].'%' }}</b></span>
            <span>{{ __('Orders') }}: <b>{{ $overview['orders'] }} · ৳{{ number_format($overview['sold']) }}</b></span>
        </div>
        @if ($rows->count() >= $max)
            <p class="warn">{{ __('Only the first :n numbers are listed. Pick fewer days or a filter.', ['n' => number_format($max)]) }}</p>
        @endif
        @if ($rows->isEmpty())
            <p>{{ __('No number matches these filters.') }}</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>#</th><th>{{ __('Customer') }}</th><th>{{ __('Phone') }}</th><th>{{ __('State') }}</th><th>{{ __('Result') }}</th><th class="n">{{ __('Satisfied') }}</th>
                        <th>{{ __('Good about') }}</th><th>{{ __('Bad about') }}</th><th>{{ __('Note') }}</th><th class="n">{{ __('Tries') }}</th><th>{{ __('Last call') }}</th><th>{{ __('Order') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $i => $n)
                        <tr>
                            <td>{{ $i + 1 }}</td>
                            <td>{{ $n->name }}</td>
                            <td>{{ $seePhone ? $n->phone : \App\Support\Permissions\Mask::value($n->phone, 'customer_contact') }}</td>
                            <td>{{ $stateLabel($n) }}</td>
                            <td>{{ $n->outcome ? __(\App\Services\Calls\CallListService::OUTCOMES[$n->outcome] ?? $n->outcome) : '' }}</td>
                            <td class="n {{ $n->satisfaction !== null && $n->satisfaction < 50 ? 'bad' : '' }}">{{ $n->satisfaction === null ? '' : $n->satisfaction.'%' }}</td>
                            <td class="good">{{ $topicText($n->topics, 'good') }}</td>
                            <td class="bad">{{ $topicText($n->topics, 'bad') }}</td>
                            <td>{{ $n->note }}</td>
                            <td class="n">{{ $n->attempts }}</td>
                            <td>@if ($n->attempts){{ $n->by }} · {{ \Illuminate\Support\Carbon::parse($n->updated_at)->format('d M, g:i A') }}@endif</td>
                            <td>@if ($n->order_no){{ $n->order_no }} · ৳{{ number_format((float) $n->grand_total) }}@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
    <script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
</body>
</html>
