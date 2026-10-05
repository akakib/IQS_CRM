{{-- After Confirm: a few seconds to Undo before the courier is booked. When the
     time runs out the bar asks the server to book now (pages and cron also do it). --}}
@php
    $undo = session('undo');
    $left = $undo ? max(0, (int) $undo['until'] - now()->getTimestamp()) : 0;
@endphp
@if ($undo && $left > 0)
    <div x-data="{
            left: {{ $left }},
            open: true,
            init() {
                const t = setInterval(() => {
                    if (--this.left > 0) return;
                    clearInterval(t);
                    this.open = false;
                    fetch(@js(route('desk.book-due')), { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '', 'Accept': 'application/json' } });
                }, 1000);
            },
        }"
        x-show="open"
        @keydown.window="if ($event.key === 'u' && !['INPUT', 'TEXTAREA', 'SELECT'].includes($event.target.tagName)) $refs.undo.requestSubmit()"
        class="fixed inset-x-3 bottom-4 z-[96] mx-auto flex max-w-md items-center gap-3 rounded-xl bg-primary-dark px-4 py-3 text-sm text-white shadow-xl">
        <p class="min-w-0 flex-1">{{ __(':no confirmed.', ['no' => $undo['no']]) }} <span class="text-white/70">{{ __('Booking the courier in') }} <span class="tabular-nums" x-text="left + 's'"></span></span></p>
        <form x-ref="undo" method="POST" action="{{ route('desk.undo', $undo['id']) }}">
            @csrf
            @if (request()->boolean('embed'))<input type="hidden" name="embed" value="1">@endif
            <button class="rounded-lg bg-white px-3 py-1.5 text-sm font-semibold text-primary-dark hover:bg-gray-100">{{ __('Undo') }} <kbd class="ml-1 hidden rounded bg-black/10 px-1 text-[11px] sm:inline">U</kbd></button>
        </form>
    </div>
@endif
