{{-- One batch on the 80 mm receipt printer (PackagingController::batchList): the head (batch no, time, count),
     what to take from the shelves by item, then each order with its items to tick. No customer name or phone:
     the shop goes by the order no. On screen: Print this list and Print the labels; the paper shows only the list. --}}
@php
    $at = \Illuminate\Support\Carbon::parse($batch->released_at ?? $batch->created_at);
    $qty = fn ($n, $u) => \App\Support\Units::qty($n, $u);
    $pieces = $orders->sum(fn ($o) => $o->items->count());
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $batch->batch_no }}</title>
    <style>
        @page { size: 80mm auto; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f3f4f6; font-family: Arial, Helvetica, sans-serif; color: #000; }
        .bar { display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; padding: 12px; }
        .bar a, .bar button { font: 600 14px Arial, sans-serif; min-height: 32px; padding: 7px 16px; border-radius: 8px; border: 1px solid #14532d; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; }
        .bar .main { background: #14532d; color: #fff; }
        .bar .alt { background: #fff; color: #14532d; }
        .bar p { width: 100%; margin: 0; text-align: center; font-size: 12px; color: #4b5563; }
        .paper { width: 80mm; margin: 0 auto 24px; background: #fff; padding: 3mm 4mm; font-size: 12px; line-height: 1.35; }
        .head { text-align: center; border-bottom: 2px solid #000; padding-bottom: 2mm; }
        .head .no { font-size: 20px; font-weight: 800; letter-spacing: .5px; }
        .sec { margin-top: 2.5mm; font-size: 11px; font-weight: 800; letter-spacing: .5px; border-bottom: 1px solid #000; padding-bottom: .5mm; }
        .row { display: flex; justify-content: space-between; gap: 2mm; padding: .6mm 0; }
        .row .n { flex: 1; min-width: 0; overflow-wrap: anywhere; }
        .row .q { font-weight: 700; white-space: nowrap; }
        .order { border-top: 1px dashed #000; margin-top: 2mm; padding-top: 1.5mm; break-inside: avoid; page-break-inside: avoid; }
        .order .top { display: flex; justify-content: space-between; gap: 2mm; font-weight: 800; font-size: 14px; }
        .order .area { font-size: 11px; }
        .box { display: inline-block; width: 3.2mm; height: 3.2mm; border: 1px solid #000; margin-right: 1.5mm; vertical-align: -0.4mm; }
        .foot { border-top: 2px solid #000; margin-top: 2.5mm; padding-top: 1.5mm; font-size: 11px; text-align: center; }
        @media print { body { background: #fff; } .bar { display: none; } .paper { margin: 0; } }
    </style>
</head>
<body>
    <div class="bar">
        <button type="button" class="main" onclick="window.print()">{{ __('Print this list') }}</button>
        <a class="alt" href="{{ route('packaging.labels', ['orders' => $orders->pluck('id')->join(',')]) }}" target="_blank">{{ __('Print :n labels', ['n' => $orders->count()]) }}</a>
        <a class="alt" href="{{ route('packaging.index') }}">{{ __('Back to packaging') }}</a>
        <p>{{ __('The list goes to the 80 mm receipt printer, the labels to the sticker printer.') }}</p>
    </div>

    <div class="paper">
        <div class="head">
            <div class="no">BATCH {{ $batch->batch_no }}</div>
            <div>{{ $at->format('d M Y · g:i A') }} · {{ $batch->by ?? '-' }}</div>
            <div><b>{{ trans_choice(':count order|:count orders', $orders->count(), ['count' => $orders->count()]) }}</b> · {{ trans_choice(':count item|:count items', $pieces, ['count' => $pieces]) }} · {{ __('Batch :n of the day', ['n' => $dayNo]) }}</div>
            <div>{{ __('Today so far: :n orders', ['n' => $todayTotal]) }}</div>
        </div>

        <div class="sec">{{ __('TAKE FROM THE SHELVES') }}</div>
        @foreach ($pick as $l)
            <div class="row"><span class="n">@if ($l->shelf_code)<b>[{{ $l->shelf_code }}]</b> @endif{{ $l->name }}</span><span class="q">{{ $qty($l->qty, $l->unit) }}</span></div>
        @endforeach

        <div class="sec">{{ __('PACK EACH ORDER') }}</div>
        @foreach ($orders as $i => $o)
            <div class="order">
                <div class="top"><span>{{ $o->order_no }}</span><span>{{ $i + 1 }}/{{ $orders->count() }}</span></div>
                <div class="area">{{ collect([$o->ship_thana, $o->ship_district])->filter()->join(', ') ?: '-' }}@if ($cn[$o->active_shipment_id] ?? null) · CN {{ $cn[$o->active_shipment_id] }}@endif</div>
                @foreach ($o->items as $it)
                    <div class="row"><span class="n"><span class="box"></span>{{ $it->name_snapshot }}{{ ($s = \App\Support\Units::itemSize($it)) ? ' '.$s : '' }}</span><span class="q">{{ $qty($it->qty, $it->unit) }}</span></div>
                @endforeach
            </div>
        @endforeach

        <div class="foot">{{ __('Packed by') }}: ____________________<br>{{ $batch->batch_no }} · {{ __('printed :t', ['t' => now()->format('d M, g:i A')]) }}</div>
    </div>
</body>
</html>
