<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Pickup manifest') }}</title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; margin: 24px; color: #111; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; font-size: 12px; }
        th, td { border: 1px solid #ccc; padding: 5px 6px; text-align: left; }
        th { background: #f3f4f6; }
        .r { text-align: right; }
        .sign { display: flex; gap: 40px; margin-top: 40px; font-size: 12px; }
        .sign div { flex: 1; border-top: 1px solid #333; padding-top: 4px; }
        .no-print { margin-bottom: 12px; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
    <div class="no-print"><button onclick="window.print()">{{ __('Print') }}</button> <a href="{{ route('handover.index') }}">{{ __('Back') }}</a></div>
    <h1>{{ settings('store.name') }} · {{ __('Pickup manifest') }}</h1>
    <div style="font-size:12px">
        {{ \Illuminate\Support\Carbon::parse($session->started_at)->format('d M Y, g:i A') }} · {{ ucfirst($session->courier) }} ·
        {{ __('Rider') }}: {{ $session->rider_name ?? '-' }} {{ $session->rider_phone ?? '' }}
    </div>

    <table>
        <thead><tr><th>#</th><th>{{ __('Order') }}</th><th>CN</th><th>{{ __('Customer') }}</th><th>{{ __('Area') }}</th><th class="r">{{ __('COD') }}</th></tr></thead>
        <tbody>
            @foreach ($handed as $i => $h)
                <tr><td>{{ $i + 1 }}</td><td>{{ $h->order_no }}</td><td>{{ $h->consignment_id }}</td><td>{{ $h->ship_name }}</td><td>{{ $h->ship_thana }}</td><td class="r">{{ number_format((float) $h->cod_amount, 2) }}</td></tr>
            @endforeach
            <tr><th colspan="5">{{ __('Total: :n parcels', ['n' => $handed->count()]) }}</th><th class="r">{{ number_format((float) $handed->sum('cod_amount'), 2) }}</th></tr>
        </tbody>
    </table>

    @if ($missing->isNotEmpty())
        <p style="margin-top:16px;font-size:12px"><b>{{ __('Ready for pickup but NOT handed over') }}:</b> {{ $missing->pluck('order_no')->join(', ') }}</p>
    @endif

    <div class="sign"><div>{{ __('Handed over by') }}</div><div>{{ __('Received by rider') }}</div></div>
</body>
</html>
