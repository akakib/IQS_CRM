<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Orders') }} {{ $filters['from'] }} {{ __('to') }} {{ $filters['to'] }}</title>
    {{-- A page made for paper: A4 landscape, no app chrome. Sort by a heading, then Print. --}}
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
        .sum { display: flex; gap: 18px; margin: 6px 0 10px; font-size: 12px; }
        .sum b { font-size: 13px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #d1d5db; padding: 3px 5px; text-align: left; vertical-align: top; }
        th { background: #eaf3de; font-weight: 600; }
        td.n, th.n { text-align: right; white-space: nowrap; }
        tr { page-break-inside: avoid; }
        thead { display: table-header-group; }
        th[data-sort] { cursor: pointer; user-select: none; }
        th[data-sort]:hover { background: #d9ebc6; }
        th[data-dir="up"]::after { content: ' \25B2'; font-size: 9px; }
        th[data-dir="down"]::after { content: ' \25BC'; font-size: 9px; }
        @media print { th[data-dir]::after { content: ''; } }
        .warn { color: #b45309; margin: 6px 0; }
        @media print { .bar { display: none; } .page { padding: 0; } }
    </style>
</head>
<body>
    <div class="bar">
        <button type="button" onclick="window.close()">{{ __('Close') }}</button>
        <button type="button" class="go" onclick="window.print()">{{ __('Print') }}</button>
    </div>
    <div class="page">
        <h1>{{ __('Orders') }} · {{ \Illuminate\Support\Carbon::parse($filters['from'])->format('d M Y') }} {{ __('to') }} {{ \Illuminate\Support\Carbon::parse($filters['to'])->format('d M Y') }}</h1>
        <div class="meta">{{ __('Stage') }}: {{ $stageNames }} · {{ __('Person') }}: {{ $staffName }} · {{ __('Sorted by') }}: <span id="sorted">{{ $sortName }}</span> · {{ __('Printed :t by :n', ['t' => now()->format('d M Y, g:i A'), 'n' => auth()->user()->name]) }}</div>
        <div class="sum">
            <span>{{ __('Orders') }}: <b>{{ number_format($totals['orders']) }}</b></span>
            <span>{{ __('Total') }}: <b>৳{{ number_format($totals['total']) }}</b></span>
            <span>{{ __('COD') }}: <b>৳{{ number_format($totals['cod']) }}</b></span>
        </div>
        @if ($totals['orders'] > $rows->count())
            <p class="warn">{{ __('Only the first :n orders are listed. Pick fewer days or one stage.', ['n' => number_format($max)]) }}</p>
        @endif
        @if ($rows->isEmpty())
            <p>{{ __('No orders for these filters.') }}</p>
        @else
            {{-- Click a heading to sort the page before printing; again to reverse. --}}
            <table id="list">
                <thead>
                    <tr>
                        <th>#</th>
                        <th data-sort="text">{{ __('Order') }}</th><th data-sort="num">{{ __('Placed') }}</th><th data-sort="text">{{ __('Customer') }}</th><th>{{ __('Phone') }}</th>
                        <th data-sort="text">{{ __('Address') }}</th><th>{{ __('Items') }}</th><th class="n" data-sort="num">{{ __('Total') }}</th><th class="n" data-sort="num">{{ __('COD') }}</th>
                        <th data-sort="text">{{ __('Status') }}</th><th data-sort="text">{{ __('Moderator') }}</th><th data-sort="text">{{ __('CN') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $i => $r)
                        <tr>
                            <td>{{ $i + 1 }}</td><td>{{ $r['order_no'] }}</td><td data-v="{{ $r['placed_at'] }}">{{ $r['placed'] }}</td><td>{{ $r['customer'] }}</td><td>{{ $r['phone'] }}</td>
                            <td>{{ $r['address'] }}</td><td>{{ $r['items'] }}</td><td class="n" data-v="{{ $r['total'] }}">৳{{ number_format($r['total']) }}</td><td class="n" data-v="{{ $r['cod'] }}">৳{{ number_format($r['cod']) }}</td>
                            <td>{{ $r['status'] }}</td><td>{{ $r['moderator'] }}</td><td>{{ $r['cn'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
    <script>
        (() => {
            const table = document.getElementById('list');
            if (!table) return;
            const body = table.tBodies[0];
            let col = -1, dir = 1;
            table.querySelectorAll('th[data-sort]').forEach(th => {
                th.addEventListener('click', () => {
                    const i = th.cellIndex, num = th.dataset.sort === 'num';
                    dir = col === i ? -dir : 1; col = i;
                    const value = tr => { const td = tr.cells[i]; return num ? parseFloat(td.dataset.v || '0') : td.textContent.trim().toLowerCase() };
                    const rows = [...body.rows].sort((a, b) => {
                        const x = value(a), y = value(b);
                        if (!num && (x === '') !== (y === '')) return x === '' ? 1 : -1; // empty last, either way
                        return (num ? x - y : x.localeCompare(y, undefined, { numeric: true })) * dir;
                    });
                    rows.forEach((tr, n) => { tr.cells[0].textContent = n + 1; body.appendChild(tr) });
                    table.querySelectorAll('th[data-sort]').forEach(h => h.removeAttribute('data-dir'));
                    th.dataset.dir = dir > 0 ? 'up' : 'down';
                    document.getElementById('sorted').textContent = th.textContent.trim() + (dir > 0 ? ' (A to Z, low to high)' : ' (Z to A, high to low)');
                });
            });
        })();
    </script>
</body>
</html>
