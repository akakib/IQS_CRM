{{-- A call started and not saved yet follows the caller to every page, with its clock, until it is saved.
     The call's page writes it to this browser (localStorage iqs-call-live: { item, name, url, started }) and
     removes it on Save. Not shown on the call's own page, which has its own bar. Nothing goes to the server. --}}
<div x-data="{
        c: null, now: Date.now(),
        init() { this.read(); setInterval(() => { this.now = Date.now(); this.read() }, 1000) },
        read() {
            try {
                const v = JSON.parse(localStorage.getItem('iqs-call-live') || 'null');
                this.c = v && v.url && Date.now() - v.started < 4 * 3600000 && v.url !== location.pathname + location.search ? v : null;
            } catch (e) { this.c = null }
        },
        clock() { const s = this.c ? Math.max(0, Math.round((this.now - this.c.started) / 1000)) : 0; return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0') },
    }" x-show="c" x-cloak class="sticky top-[4.5rem] z-20 mb-3 flex items-center justify-between gap-2 rounded-xl bg-amber-100 px-3 py-2.5 text-sm font-semibold text-amber-900 shadow-sm" role="status">
    <span class="min-w-0 truncate tabular-nums">📞 <span x-text="c?.name"></span> · <span x-text="clock()"></span> · {{ __('not saved') }}</span>
    <a :href="c?.url" class="inline-flex min-h-8 shrink-0 items-center rounded-lg border border-amber-700 px-3 text-xs font-semibold">{{ __('Finish it') }}</a>
</div>
