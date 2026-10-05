<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Labels') }}</title>
    {{-- Self-contained print page: 4 x 3 inch labels, one per page. --}}
    <style>
        @page { size: 4in 3in; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, Helvetica, sans-serif; color: #000; background: #f3f4f6; }
        .toolbar { padding: 12px; text-align: center; }
        .toolbar button { background: #14532d; color: #fff; border: 0; border-radius: 8px; padding: 10px 18px; font-size: 14px; cursor: pointer; }
        .label { width: 4in; height: 3in; margin: 12px auto; padding: 0.12in; background: #fff; display: flex; flex-direction: column; page-break-after: always; overflow: hidden; }
        .row { display: flex; justify-content: space-between; align-items: flex-start; gap: 6px; }
        .store { font-weight: 700; font-size: 13px; }
        .small { font-size: 10px; }
        .cod { font-size: 22px; font-weight: 800; text-align: right; line-height: 1; }
        .cod span { display: block; font-size: 9px; font-weight: 600; }
        .to { margin-top: 4px; font-size: 12px; line-height: 1.25; }
        .to b { font-size: 13px; }
        .items { font-size: 9px; margin-top: 3px; color: #222; max-height: 0.42in; overflow: hidden; }
        .codes { margin-top: auto; text-align: center; }
        .codes svg { width: 100%; height: 0.62in; }
        .bc-text { font-family: monospace; font-size: 12px; font-weight: 700; letter-spacing: 1px; }
        @media print { body { background: #fff; } .toolbar { display: none; } .label { margin: 0; } }
    </style>
</head>
<body>
    <div class="toolbar"><button onclick="window.print()">{{ __('Print :n labels', ['n' => $orders->count()]) }}</button></div>

    @foreach ($orders as $o)
        @php($l = $labels[$o->id] ?? null)
        <div class="label">
            <div class="row">
                <div>
                    <div class="store">{{ settings('store.name') }}</div>
                    @if (settings('store.hotline'))<div class="small">{{ __('Rider hotline') }}: <b>{{ settings('store.hotline') }}</b></div>@endif
                    <div class="small">{{ $l ? ucfirst($l->courier).' CN '.$l->consignment_id : __('Not booked') }}</div>
                </div>
                <div class="cod"><span>COD</span>৳{{ number_format((float) ($l->cod_on_label ?? $o->cod_amount)) }}</div>
            </div>
            <div class="to">
                <b>{{ $o->ship_name }}</b> · {{ $o->ship_phone }}{{ $o->ship_alt_phone ? ' / '.$o->ship_alt_phone : '' }}<br>
                {{ collect([$o->ship_address, $o->ship_thana, $o->ship_district])->filter()->join(', ') }}
            </div>
            <div class="items">{{ $o->items->map(fn ($i) => $i->name_snapshot.' '.\App\Support\Units::qty($i->qty, $i->unit))->join(' · ') }}</div>
            @if ($l)
                <div class="codes">
                    {!! \App\Support\Barcode\Code128::svg($l->barcode, 2, 60) !!}
                    <div class="bc-text">{{ $l->barcode }}</div>
                </div>
            @endif
        </div>
    @endforeach
</body>
</html>
