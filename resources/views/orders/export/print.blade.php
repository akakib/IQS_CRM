{{-- Orders (or a handover) for paper or PDF, the same way as every list. Click a heading to sort the page first. --}}
<x-print-page :title="$title ?? __('Orders').' '.$filters['from'].' '.__('to').' '.$filters['to']">
    <x-slot:styles>
        th[data-sort] { cursor: pointer; user-select: none; }
        th[data-sort]:hover { background: #d9ebc6; }
        th[data-dir="up"]::after { content: ' \25B2'; font-size: 9px; }
        th[data-dir="down"]::after { content: ' \25BC'; font-size: 9px; }
        @media print { th[data-dir]::after { content: ''; } }
    </x-slot:styles>
        <h1>{{ $title ?? __('Orders').' · '.\Illuminate\Support\Carbon::parse($filters['from'])->format('d M Y').' '.__('to').' '.\Illuminate\Support\Carbon::parse($filters['to'])->format('d M Y') }}</h1>
        <div class="meta">@if (isset($subtitle)){{ $subtitle }} · @else{{ __('Stage') }}: {{ $stageNames }} · {{ __('Person') }}: {{ $staffName }} · @endif{{ __('Sorted by') }}: <span id="sorted">{{ $sortName }}</span> · {{ __('Printed :t by :n', ['t' => now()->format('d M Y, g:i A'), 'n' => auth()->user()->name]) }}</div>
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
            <table id="list" class="list">
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
    <x-slot:scripts>
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
    </x-slot:scripts>
</x-print-page>
