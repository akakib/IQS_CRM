{{-- Timer chip counting down (or up with count-up). Seconds come from the
     server, so a wrong clock on the PC cannot change it.
     <x-countdown :seconds="420" />           7:00 … 0:00, red in the last 2 minutes
     <x-countdown :seconds="95" count-up bare class="text-6xl" />   plain text, counting up (break screen) --}}
@props(['seconds', 'countUp' => false, 'warnAt' => 120, 'doneLabel' => null, 'bare' => false])

<span x-data="{
        start: Date.now(), base: {{ (int) $seconds }}, up: {{ $countUp ? 'true' : 'false' }}, now: Date.now(),
        init() { setInterval(() => this.now = Date.now(), 1000) },
        get value() { const passed = Math.floor((this.now - this.start) / 1000); return this.up ? this.base + passed : Math.max(0, this.base - passed) },
        get text() { const v = this.value, h = Math.floor(v / 3600), m = Math.floor((v % 3600) / 60), s = v % 60;
            return (h ? h + ':' + String(m).padStart(2, '0') : m) + ':' + String(s).padStart(2, '0') },
    }"
    {{ $attributes->merge(['class' => $bare ? 'tabular-nums' : 'inline-flex shrink-0 items-center gap-1 whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums']) }}
    :class="up || {{ $bare ? 'true' : 'false' }} ? '' : (value === 0 ? 'bg-red-600 text-white' : (value <= {{ (int) $warnAt }} ? 'bg-red-50 text-red-700' : 'bg-gray-100 text-gray-700'))">
    @unless ($bare)<svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l2.5 2.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>@endunless
    <span x-text="!up && value === 0 ? @js($doneLabel ?? __('Time up')) : text"></span>
</span>
