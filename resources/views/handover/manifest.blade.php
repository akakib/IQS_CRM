{{-- The pickup sheet of one handover: the parcels the rider took, to print and sign. The same page as every list download. --}}
<x-print-page :title="__('Pickup sheet').' · '.\Illuminate\Support\Carbon::parse($session->started_at)->format('d M Y')" :landscape="false" :autoprint="false" :back="route('handover.index')">
    <x-slot:styles>
        .sign { display: flex; gap: 40px; margin-top: 48px; font-size: 12px; }
        .sign div { flex: 1; border-top: 1px solid #333; padding-top: 4px; }
        .note { margin-top: 12px; font-size: 12px; }
    </x-slot:styles>
    <x-slot:actions>
        {{-- Print is the main button; on the PDF page Save as PDF is, so no PDF link there. --}}
        @unless (request('as') === 'pdf')<a href="{{ route('handover.manifest', ['session' => $session->id, 'as' => 'pdf']) }}">{{ __('PDF') }}</a>@endunless
        <a href="{{ route('handover.excel', $session->id) }}">{{ __('Excel') }}</a>
    </x-slot:actions>

    <h1>{{ __('Pickup sheet') }}</h1>
    <div class="meta">
        {{ \Illuminate\Support\Carbon::parse($session->started_at)->format('d M Y, g:i A') }} · {{ ucfirst($session->courier) }} ·
        {{ __('Rider') }}: {{ $session->rider_name ?? '-' }} {{ $session->rider_phone ?? '' }}
    </div>
    <div class="sum">
        <span>{{ __('Parcels') }}: <b>{{ $handed->count() }}</b></span>
        <span>{{ __('COD') }}: <b>৳{{ number_format((float) $handed->sum('cod_amount')) }}</b></span>
    </div>

    <table class="list">
        <thead><tr><th>#</th><th>{{ __('Order') }}</th><th>CN</th><th>{{ __('Customer') }}</th><th>{{ __('Area') }}</th><th class="n">{{ __('COD') }}</th></tr></thead>
        <tbody>
            @foreach ($handed as $i => $h)
                <tr><td>{{ $i + 1 }}</td><td>{{ $h->order_no }}{{ $h->manual ? ' *' : '' }}</td><td>{{ $h->consignment_id }}</td><td>{{ $h->ship_name }}</td><td>{{ $h->ship_thana }}</td><td class="n">৳{{ number_format((float) $h->cod_amount) }}</td></tr>
            @endforeach
            <tr><th colspan="5">{{ __('Total: :n parcels', ['n' => $handed->count()]) }}</th><th class="n">৳{{ number_format((float) $handed->sum('cod_amount')) }}</th></tr>
        </tbody>
    </table>
    @if ($handed->contains('manual', true))<p class="note">* {{ __('ticked by hand, label not scanned') }}</p>@endif
    @if ($missing->isNotEmpty())
        <p class="note"><b>{{ __('Ready for pickup but NOT handed over') }}:</b> {{ $missing->pluck('order_no')->join(', ') }}</p>
    @endif

    <div class="sign"><div>{{ __('Handed over by') }}</div><div>{{ __('Received by rider') }}</div></div>
</x-print-page>
