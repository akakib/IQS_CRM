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
        /* The courier's CN: the biggest thing on the label, the whole number on one line, so the courier never misreads it. */
        .cn { margin-top: 3px; border: 2px solid #000; border-radius: 4px; padding: 1px 6px; display: flex; align-items: baseline; justify-content: space-between; gap: 6px; }
        .cn span { font-size: 10px; font-weight: 700; }
        .cn b { font-family: Arial, Helvetica, sans-serif; font-size: 27px; font-weight: 800; letter-spacing: 1px; line-height: 1.1; white-space: nowrap; }
        .to { margin-top: 4px; font-size: 12px; line-height: 1.25; }
        .to b { font-size: 13px; }
        .items { font-size: 9px; margin-top: 3px; color: #222; max-height: 0.42in; overflow: hidden; }
        .codes { margin-top: auto; text-align: center; }
        .codes svg { width: 100%; height: 0.46in; }
        .bc-text { font-family: monospace; font-size: 12px; font-weight: 700; letter-spacing: 1px; }
        @media print { body { background: #fff; } .toolbar { display: none; } .label { margin: 0; } }
    </style>
</head>
<body>
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
                {{ collect([$o->ship_address, $o->ship_thana, $o->ship_district])->filter()->join(', ') }}
            </div>
            <div class="items" data-more="{{ ($bn = $batchNos[$o->batch_id] ?? null) ? __('see batch list :b', ['b' => $bn]) : __('see packing sheet') }}">@foreach ($o->items as $i)<span>{{ $i->name_snapshot }}{{ ($s = \App\Support\Units::itemSize($i)) ? ' '.$s : '' }} <b>{{ \App\Support\Units::qty($i->qty, $i->unit) }}</b>{{ $loop->last ? '' : ' · ' }}</span>@endforeach</div>
            @if ($l)
                <div class="codes">
                    {!! \App\Support\Barcode\Code128::svg($l->barcode, 2, 60) !!}
                    <div class="bc-text">{{ $l->barcode }}</div>
                </div>
            @endif
        </div>
    @endforeach
<script>
    // A long item list that does not fit on the sticker: drop the last items until it fits and say how many more (the batch list, or the A4 sheet, has them all).
    document.querySelectorAll('.items').forEach(function (box) {
        var spans = Array.from(box.children), cut = 0;
        if (box.scrollHeight <= box.clientHeight) return;
        var more = document.createElement('b');
        box.appendChild(more);
        while (spans.length > 1 && box.scrollHeight > box.clientHeight) {
            spans.pop().remove();
            cut++;
            more.textContent = ' +' + cut + ' more, ' + box.dataset.more;
        }
    });
</script>
</body>
</html>
