<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Labels') }}</title>
    {{-- Self-contained print page, one label per page: 2 x 3 inch (51 x 76 mm, the 58 mm printer) or 4 x 3 inch (Settings > Packaging). --}}
    @php($small = settings('packaging.label_size') !== '4x3')
    <style>
        @page { size: {{ $small ? '2in 3in' : '4in 3in' }}; margin: 0; }
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
        /* The courier's CN: the biggest thing on the label, the whole number on one line, so the courier never misreads it. */
        .cn { margin-top: 3px; border: 2px solid #000; border-radius: 4px; padding: 1px 6px; display: flex; align-items: baseline; justify-content: space-between; gap: 6px; }
        .cn span { font-size: 10px; font-weight: 700; }
        .cn b { font-family: Arial, Helvetica, sans-serif; font-size: 27px; font-weight: 800; letter-spacing: 1px; line-height: 1.1; white-space: nowrap; }
        .to { margin-top: 4px; font-size: 12px; line-height: 1.25; }
        .to b { font-size: 13px; }
        .codes { margin-top: auto; text-align: center; }
        .codes svg { width: 100%; height: 0.46in; }
        .bc-text { font-family: monospace; font-size: 12px; font-weight: 700; letter-spacing: 1px; }
        /* 2 x 3 inch: the same parts, smaller; the CN and COD stay the biggest. */
        .s .label { width: 2in; height: 3in; padding: 0.16in 0.08in 0.08in; } /* off the top edge too */
        .s .store { font-size: 9px; } .s .small { font-size: 7px; }
        .s .cod { font-size: 20px; } .s .cod span { font-size: 8px; }
        .s .cn { margin-top: 5px; padding: 2px 4px; border-width: 2px; flex-direction: column; align-items: flex-start; gap: 0; } .s .cn span { font-size: 8px; } .s .cn b { font-size: 22px; letter-spacing: 0.5px; }
        .s .to { margin-top: 6px; font-size: 11px; line-height: 1.25; max-height: 0.95in; overflow: hidden; } .s .to b { font-size: 13px; }
        /* Off the bottom edge (printers cut and peel there), the label no and batch no big enough to read across a table. */
        .s .codes { margin-bottom: 0.14in; } .s .codes svg { height: 0.5in; } .s .bc-text { font-size: 15px; letter-spacing: 0.5px; line-height: 1.2; }
        .batch { font-size: 9px; font-weight: 700; } .s .batch { font-size: 13px; line-height: 1.2; }
        @media print { body { background: #fff; } .toolbar { display: none; } .label { margin: 0; } }
    </style>
</head>
<body @class(['s' => $small])>
    <div class="toolbar"><button onclick="window.print()">{{ __('Print :n labels', ['n' => $orders->count()]) }}</button>
        {{-- The A4 packing sheet is off until the shop has an A4 printer: labels of one batch link to its 80 mm list instead. --}}
        @php($oneBatch = $orders->pluck('batch_id')->filter()->unique())
        @if ($oneBatch->count() === 1 && $orders->every(fn ($o) => $o->batch_id))<a href="{{ route('packaging.batch-list', $oneBatch->first()) }}" target="_blank" style="margin-left:8px;display:inline-block;border:1px solid #14532d;color:#14532d;border-radius:8px;padding:9px 16px;font-size:14px;text-decoration:none">{{ __('Batch list (80 mm)') }}</a>@endif</div>

    @php($batchNos = \Illuminate\Support\Facades\DB::table('batches')->whereIn('id', $orders->pluck('batch_id')->filter()->unique())->pluck('batch_no', 'id'))
    @foreach ($orders as $o)
        @php($l = $labels[$o->id] ?? null)
        <div class="label">
            <div class="row">
                <div>
                    <div class="store">{{ settings('store.label_name') }}</div>
                    @if (settings('store.hotline'))<div class="small">{{ __('Rider hotline') }}: <b>{{ settings('store.hotline') }}</b></div>@endif
                    @unless ($l)<div class="small">{{ __('Not booked') }}</div>@endunless
                </div>
                <div class="cod"><span>COD</span>৳{{ number_format((float) ($l->cod_on_label ?? $o->cod_amount)) }}</div>
            </div>
            @if ($l)<div class="cn"><span>{{ ucfirst($l->courier) }} CN</span><b>{{ $l->consignment_id }}</b></div>@endif
            <div class="to">
                {{-- No customer phone on the sticker (owner 2026-11-26): the courier has it from the booking. --}}
                <b>{{ $o->ship_name }}</b><br>
                {{-- The full address: the district by name (not the website's BD-10 code), a part the address already says only once, any phone number taken out. --}}
                <?php
                    $addr = trim(preg_replace(['/(?:\+?88)?[0০][1১][\d০-৯](?:[\s-]?[\d০-৯]){8}/u', '/\s{2,}/u', '/\s*,\s*(,\s*)+/u'], ['', ' ', ', '], (string) $o->ship_address), " ,");
                    $parts = collect([$o->ship_thana, \App\Support\BdDistricts::name($o->ship_district)])->filter()
                        ->reject(fn ($p) => $addr !== '' && mb_stripos($addr, $p) !== false)->unique();
                ?>
                {{ collect([$addr])->filter()->merge($parts)->join(', ') }}
            </div>
            {{-- No item list on the sticker (owner 2026-11-28): the courier does not need it; the packer has the batch list and the phone. --}}
            @if ($l)
                <div class="codes">
                    {!! \App\Support\Barcode\Code128::svg($l->barcode, 2, 60) !!}
                    <div class="bc-text">{{ $l->barcode }}</div>
                    @if ($bn = $batchNos[$o->batch_id] ?? null)<div class="batch">{{ $bn }}</div>@endif
                </div>
            @endif
        </div>
    @endforeach
</body>
</html>
