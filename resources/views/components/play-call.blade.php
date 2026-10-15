{{-- "Play call": opens the call's recording (or screenshot) in the player popup on the same page.
     <x-play-call :call="$c" :title="$c->name" :sub="$c->by.' · '.$when" />   $c has id, proof_path, proof_name, proof_old
     <x-play-call :url="route('calls.proof', $id)" />                       kind unknown: the player finds out --}}
@props(['call' => null, 'url' => null, 'rider' => false, 'title' => '', 'sub' => ''])

@php
    $url ??= route($rider ? 'rider-calls.proof' : 'calls.proof', $call->id);
    $mime = $call?->proof_path ? \App\Services\Calls\CallListService::proofMime($call->proof_path) : '';
    $kind = str_starts_with($mime, 'image/') ? 'image' : (str_starts_with($mime, 'audio/') ? 'audio' : 'auto');
    $detail = ['url' => $url, 'kind' => $kind, 'title' => (string) $title, 'sub' => (string) $sub,
        'picked' => $call->proof_name ?? null, 'old' => (bool) ($call->proof_old ?? false)];
@endphp

<button type="button" x-data @click="window.dispatchEvent(new CustomEvent('play-call', { detail: @js($detail) }))"
    {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-full border border-primary/30 bg-primary-soft px-2.5 py-0.5 text-xs font-medium text-primary hover:bg-primary hover:text-white']) }}>
    @if ($kind === 'image')
        {{ __('View screenshot') }}
    @else
        <svg class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6 4l10 6-10 6V4z"/></svg> {{ __('Play call') }}
    @endif
</button>
