{{-- One batch on the 80 mm receipt printer (PackagingController::batchList): the head (batch no, time, count),
     then each order with its items to tick (no added-up shelf list: it got orders mixed). No customer name or phone:
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
        @page { size: 76mm auto; margin: 0; } /* the 80 mm printer's paper is 76 mm wide */
        * { box-sizing: border-box; }
        body { margin: 0; background: #f3f4f6; font-family: Arial, Helvetica, sans-serif; color: #000; }
        .bar { display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; padding: 12px; }
        .bar a, .bar button { font: 600 14px Arial, sans-serif; min-height: 32px; padding: 7px 16px; border-radius: 8px; border: 1px solid #14532d; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; }
        .bar .main { background: #14532d; color: #fff; }
        .bar .alt { background: #fff; color: #14532d; }
        .bar p { width: 100%; margin: 0; text-align: center; font-size: 12px; color: #4b5563; }
        .paper { width: 76mm; margin: 0 auto 24px; background: #fff; padding: 2.5mm 3mm; font-size: 12px; line-height: 1.35; }
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
    {{-- Straight to the shop printers from the Android phone: stickers over Bluetooth, the list over the USB (OTG) cable. --}}
    <div class="bar">
        <button type="button" class="main" id="pr-stickers" onclick="iqsRun('stickers')">{{ __('Print :n stickers', ['n' => $orders->count()]) }}</button>
        <button type="button" class="main" id="pr-list" onclick="iqsRun('list')">{{ __('Print the list') }}</button>
        <a class="alt" href="{{ route('packaging.index') }}">← {{ __('Back to packaging') }}</a>
        <label style="width:100%;text-align:center;font-size:12px;color:#4b5563"><input type="checkbox" id="pr-choose"> {{ __('Choose the printer again (a new or another printer)') }}</label>
        <p id="pr-msg" style="font-weight:600"></p>
        <p>{{ __('Stickers go to the sticker printer over Bluetooth, the list to the receipt printer on its cable. Other ways:') }}
            <a href="#" onclick="window.print(); return false">{{ __('phone print') }}</a> ·
            <a href="{{ route('packaging.labels', ['orders' => $orders->pluck('id')->join(',')]) }}" target="_blank">{{ __('sticker page') }}</a></p>
    </div>
    {{-- $receipt comes from PackagingController::batchPrintData (the print station uses the same). --}}
    @include('packaging._direct-print')
    <script>
        async function iqsRun(what) {
            const msg = document.getElementById('pr-msg'), choose = document.getElementById('pr-choose').checked, btn = document.getElementById(what === 'stickers' ? 'pr-stickers' : 'pr-list');
            const say = t => { msg.textContent = t; msg.style.color = '#111'; };
            btn.disabled = true;
            try {
                if (what === 'stickers') {
                    if (!IqsPrint.ok.ble) throw new Error(@js(__('Open this page in Chrome on the Android phone.')));
                    await IqsPrint.printStickers(@js($stickers), @js($store), choose, say);
                    await IqsPrint.marked(@js(route('packaging.batch-printed', $batch->id)), @js(csrf_token()));
                    say(@js(__('Stickers printed. Now print the list, then pack in Ongoing.')));
                } else {
                    if (!IqsPrint.ok.usb) throw new Error(@js(__('Open this page in Chrome on the Android phone.')));
                    await IqsPrint.printReceipt(@js($receipt), choose, say);
                    say(@js(__('List printed.')));
                }
            } catch (e) {
                msg.style.color = '#b91c1c';
                msg.textContent = e.name === 'NotFoundError' ? @js(__('No printer chosen. Is it on (and its cable in)?')) : (e.message || String(e));
            }
            btn.disabled = false;
        }
    </script>

    <div class="paper">
        <div class="head">
            <div class="no">BATCH {{ $batch->batch_no }}</div>
            <div>{{ $at->format('d M Y · g:i A') }} · {{ $batch->by ?? '-' }}</div>
            <div><b>{{ trans_choice(':count order|:count orders', $orders->count(), ['count' => $orders->count()]) }}</b> · {{ trans_choice(':count item|:count items', $pieces, ['count' => $pieces]) }} · {{ __('Batch :n of the day', ['n' => $dayNo]) }}</div>
            <div>{{ __('Today so far: :n orders', ['n' => $todayTotal]) }}</div>
        </div>

        {{-- Order by order only: one list of every item added up got packers mixing orders (owner 2026-11-28). --}}
        <div class="sec">{{ __('PACK EACH ORDER') }}</div>
        @foreach ($orders as $i => $o)
            <div class="order">
                <div class="top"><span>{{ $o->order_no }}</span><span>{{ $i + 1 }}/{{ $orders->count() }}</span></div>
                <div class="area">{{ collect([$o->ship_thana, \App\Support\BdDistricts::name($o->ship_district)])->filter()->unique()->join(', ') ?: '-' }}@if ($cn[$o->active_shipment_id] ?? null) · <b>CN {{ $cn[$o->active_shipment_id] }}</b>@endif</div>
                @foreach ($o->items as $it)
                    <div class="row"><span class="n"><span class="box"></span>{{ $it->name_snapshot }}{{ ($s = \App\Support\Units::itemSize($it)) && ! str_contains(mb_strtolower(str_replace(' ', '', $it->name_snapshot)), mb_strtolower(str_replace(' ', '', $s))) ? ' '.$s : '' }}</span><span class="q">{{ $qty($it->qty, $it->unit) }}</span></div>
                @endforeach
            </div>
        @endforeach

        <div class="foot">{{ __('Packed by') }}: ____________________<br>{{ $batch->batch_no }} · {{ __('printed :t', ['t' => now()->format('d M, g:i A')]) }}</div>
    </div>
</body>
</html>
