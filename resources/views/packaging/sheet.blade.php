{{-- A4 packing sheet: the orders of one label print (or everything waiting to be packed), one row each, in the same
     order as the stickers. Big item lines; two boxes to tick by hand: packed, label on. The label ID matches the sticker.
     A row whose label was replaced (the order changed) says so with the new label ID and the current items. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Packing sheet') }}</title>
    <style>
        @page { size: A4; margin: 10mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, Helvetica, sans-serif; color: #000; background: #fff; }
        .toolbar { padding: 12px; text-align: center; }
        .toolbar button { background: #14532d; color: #fff; border: 0; border-radius: 8px; padding: 10px 18px; font-size: 14px; cursor: pointer; }
        @media print { .toolbar { display: none; } }
        .head { display: flex; justify-content: space-between; align-items: baseline; padding: 0 4mm 3mm; border-bottom: 2px solid #000; margin-bottom: 2mm; }
        .head h1 { font-size: 20px; margin: 0; }
        .head span { font-size: 13px; }
        table { width: 100%; border-collapse: collapse; }
        th { font-size: 12px; text-align: left; border-bottom: 1.5px solid #000; padding: 2mm; }
        td { border-bottom: 1px solid #999; padding: 2.5mm 2mm; vertical-align: top; font-size: 15px; }
        tr { page-break-inside: avoid; }
        .id { font-size: 18px; font-weight: bold; white-space: nowrap; }
        .area { font-size: 12px; color: #333; }
        .items div { margin: 0.5mm 0; }
        .items b { font-size: 16px; }
        .items .size { font-size: 14px; font-weight: bold; border: 1.5px solid #000; border-radius: 4px; padding: 0 4px; white-space: nowrap; }
        .items .qty { font-size: 16px; font-weight: bold; white-space: nowrap; }
        .box { width: 9mm; height: 9mm; border: 2px solid #000; margin: auto; }
        .changed td { background: #fff3cd; }
        .warn { font-size: 13px; font-weight: bold; margin-bottom: 1mm; }
        .note { font-size: 13px; font-style: italic; border: 1px dashed #000; padding: 1mm 2mm; margin-bottom: 1mm; }
        .num { width: 7mm; color: #555; font-size: 12px; }
        .totals { display: flex; flex-wrap: wrap; gap: 6mm; padding: 0 4mm 3mm; font-size: 14px; }
        .totals b { font-size: 18px; }
        .done { font-size: 12px; font-weight: bold; text-align: center; }
    </style>
</head>
<body>
    <div class="toolbar"><button onclick="window.print()">{{ __('Print the packing sheet') }}</button></div>
    <div class="head">
        <h1>{{ $summary ? __('Today\'s packing sheet') : __('Packing sheet') }}</h1>
        <span>{{ now()->format('d M Y, g:i A') }} · {{ trans_choice(':n order|:n orders', $rows->count(), ['n' => $rows->count()]) }}</span>
    </div>
    @if ($summary)
        <div class="totals">
            <span>{{ __('Total') }} <b>{{ $summary['total'] }}</b></span>
            <span>{{ __('Packed') }} <b>{{ $summary['packed'] }}</b></span>
            <span>{{ __('Still to pack') }} <b>{{ $summary['to_pack'] }}</b></span>
            <span>{{ __('Waiting for the rider') }} <b>{{ $summary['rider'] }}</b></span>
        </div>
    @endif
    <table>
        <thead><tr><th class="num">#</th><th>{{ __('Label') }}</th><th>{{ __('Items') }}</th><th style="text-align:right">COD</th><th style="text-align:center">{{ __('Packed') }}</th><th style="text-align:center">{{ __('Label on') }}</th></tr></thead>
        <tbody>
            @foreach ($rows as $n => $r)
                <tr @class(['changed' => $r->changed])>
                    <td class="num">{{ $n + 1 }}</td>
                    <td>
                        <div class="id">{{ $r->label }}</div>
                        <div class="area">{{ collect([$r->order->ship_thana, $r->order->ship_district])->filter()->join(', ') ?: '-' }}</div>
                    </td>
                    <td class="items">
                        @if ($r->changed)<div class="warn">{{ __('CHANGED: use the new label :l', ['l' => $r->current ?? '-']) }}</div>@endif
                        @if ($note = \App\Support\Phone::hideIn($r->order->customer_note))<div class="note">{{ __('Note') }}: {{ $note }}</div>@endif
                        @foreach ($r->order->items as $i)
                            @php($size = \App\Support\Units::itemSize($i))
                            <div><b>{{ $i->name_snapshot }}</b>@if ($size) <span class="size">{{ $size }}</span>@endif <span class="qty">{{ \App\Support\Units::qty($i->qty, $i->unit) }}</span></div>
                        @endforeach
                    </td>
                    <td style="text-align:right;white-space:nowrap">৳{{ number_format((float) $r->order->cod_amount) }}</td>
                    <td>@if ($r->order->packed_at)<div class="done">{{ __('Packed') }}</div>@else<div class="box"></div>@endif</td>
                    <td><div class="box"></div></td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
