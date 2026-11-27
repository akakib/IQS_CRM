{{-- Pause or resume packaging, one line, for owner and managers only (PackagingPause::canControl); nothing for anyone else.
     Running: a quiet line with Pause. Paused: amber, since when and by whom, how many wait, and Resume.
     <x-packaging-pause />  (Packaging page and Dashboard) --}}
@php
    $svc = app(\App\Services\Packaging\PackagingPause::class);
    $can = auth()->user() && $svc->canControl(auth()->user());
    $p = $can ? $svc->active() : null;
@endphp
@if ($can)
    <form method="POST" action="{{ route('packaging.pause') }}" {{ $attributes->class(['flex items-center justify-between gap-3 rounded-xl border px-3 py-2.5 text-sm',
        'border-amber-300 bg-amber-50 text-amber-900' => $p, 'border-gray-200 bg-white text-gray-700' => ! $p]) }}>
        @csrf
        @if ($p)
            <span><b>{{ __('Packaging paused') }}</b> · {{ __('since :t by :n', ['t' => \Illuminate\Support\Carbon::parse($p->paused_at)->format('g:i A'), 'n' => $p->by ?? '-']) }} · {{ trans_choice(':n order waiting|:n orders waiting', $svc->heldCount(), ['n' => $svc->heldCount()]) }}</span>
            <x-button size="sm" class="shrink-0">{{ __('Resume') }}</x-button>
        @else
            <span>{{ __('Packaging is on: new booked orders go to the packers.') }}</span>
            <x-button size="sm" variant="secondary" class="shrink-0">{{ __('Pause packaging') }}</x-button>
        @endif
    </form>
@endif
