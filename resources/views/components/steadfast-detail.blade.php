{{-- The rest of the Steadfast score, small (D, C and parcels are in x-steadfast-line): fraud
     reports by kind, doubtful reports and the risk level (when Steadfast scoring
     is on). Reads the snapshot taken when the checks ran, so no extra query.
     <x-steadfast-detail :detail="$provider['detail'] ?? null" /> --}}
@props(['detail' => null])

@if (is_array($detail) && $detail)
    <div {{ $attributes->merge(['class' => 'mt-1 flex flex-wrap gap-1 text-[11px]']) }}>
        @if (! empty($detail['total_reports']))
            <span class="rounded bg-red-50 px-1.5 py-0.5 font-medium text-red-700">{{ __('Reports :n', ['n' => $detail['total_reports']]) }}</span>
        @endif
        @foreach (($detail['fraud_categories'] ?? []) as $kind => $n)
            <span class="rounded bg-red-50 px-1.5 py-0.5 font-medium text-red-700">{{ ucfirst(str_replace('_', ' ', (string) $kind)) }} ×{{ $n }}</span>
        @endforeach
        @if (! empty($detail['doubtful_reports']))
            <span class="rounded bg-red-50 px-1.5 py-0.5 font-medium text-red-700">{{ __('Doubtful reports') }}</span>
        @endif
        @if (! empty($detail['level']))
            <span class="rounded bg-amber-50 px-1.5 py-0.5 font-medium text-amber-800">{{ __('Risk: :l', ['l' => $detail['level']]) }}@if (isset($detail['score'])) ({{ $detail['score'] }})@endif</span>
        @endif
        @foreach (array_slice((array) ($detail['reasons'] ?? []), 0, 3) as $reason)
            <span class="rounded bg-amber-50 px-1.5 py-0.5 text-amber-800">{{ is_scalar($reason) ? $reason : json_encode($reason) }}</span>
        @endforeach
    </div>
@endif
