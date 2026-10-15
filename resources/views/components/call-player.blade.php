{{-- The call player popup (once per page, in the layout). Opened by <x-play-call> through a "play-call" event.
     Plays the recording on the same page (speed 1x, 1.5x, 2x), or shows the screenshot. --}}
<div x-data="{
        open: false, d: {}, kind: 'audio', speed: 1, failed: false, loading: true,
        show(detail) {
            this.d = detail; this.kind = detail.kind === 'image' ? 'image' : 'audio'; this.failed = false; this.loading = true; this.open = true;
            this.$nextTick(() => {
                const a = this.$refs.audio;
                if (this.kind === 'audio' && a) { a.src = detail.url; a.playbackRate = this.speed; a.play().catch(() => {}) }
            });
        },
        // Kind not known (order history): not a recording, so it is shown as a picture.
        audioFailed() { if (this.d.kind === 'auto') { this.kind = 'image'; return } this.failed = true; this.loading = false },
        rate(s) { this.speed = s; if (this.$refs.audio) this.$refs.audio.playbackRate = s },
        close() { const a = this.$refs.audio; if (a) { a.pause(); a.removeAttribute('src'); a.load() } this.open = false },
    }"
    @play-call.window="show($event.detail)" @keydown.escape.window="open && close()"
    x-show="open" x-cloak class="fixed inset-0 z-[95] flex items-end justify-center sm:items-center sm:px-4">
    <div class="absolute inset-0 bg-black/50" @click="close()"></div>
    <div class="relative w-full max-w-lg rounded-t-2xl bg-white p-5 shadow-xl sm:rounded-2xl" x-transition role="dialog" aria-modal="true">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="truncate text-base font-semibold text-gray-900" x-text="d.title || @js(__('Call'))"></p>
                <p class="text-xs text-gray-500" x-show="d.sub" x-text="d.sub"></p>
            </div>
            <button type="button" @click="close()" class="-mr-1 rounded-lg p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-700" aria-label="{{ __('Close') }}">
                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M6.3 5.3a1 1 0 011.4 0L10 7.6l2.3-2.3a1 1 0 111.4 1.4L11.4 9l2.3 2.3a1 1 0 01-1.4 1.4L10 10.4l-2.3 2.3a1 1 0 01-1.4-1.4L8.6 9 6.3 6.7a1 1 0 010-1.4z"/></svg>
            </button>
        </div>

        <div class="mt-4" x-show="kind === 'audio'">
            <audio x-ref="audio" controls preload="auto" class="w-full" x-on:error="audioFailed()" x-on:canplay="loading = false"></audio>
            <p x-show="loading && ! failed" class="mt-1 text-xs text-gray-500">{{ __('Loading the recording...') }}</p>
            <div class="mt-3 flex items-center gap-2">
                <span class="text-xs text-gray-500">{{ __('Speed') }}</span>
                <template x-for="s in [1, 1.5, 2]" :key="s">
                    <button type="button" @click="rate(s)" class="rounded-full border px-3 py-1 text-xs font-medium"
                        :class="speed === s ? 'border-primary bg-primary text-white' : 'border-gray-300 text-gray-700 hover:border-primary'" x-text="s + 'x'"></button>
                </template>
            </div>
        </div>
        <template x-if="open && kind === 'image'">
            <a :href="d.url" target="_blank" rel="noopener" class="mt-4 block">
                <img :src="d.url" alt="{{ __('Call screenshot') }}" class="max-h-[60vh] w-full rounded-lg border border-gray-200 object-contain" x-on:error="failed = true">
            </a>
        </template>
        <p x-show="failed" x-cloak class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ __('The file did not open. It may be in Google Drive while Drive is not connected; try again in a minute.') }}</p>

        <div class="mt-4 space-y-1 border-t border-gray-100 pt-3 text-xs text-gray-500">
            <p x-show="d.picked" class="break-all">{{ __('Picked on the phone:') }} <span class="text-gray-700" x-text="d.picked"></span></p>
            <p x-show="d.old"><span class="rounded bg-amber-100 px-1.5 py-0.5 font-medium text-amber-800">{{ __('Earlier file') }}</span> {{ __('The file was not from the day of the call; the caller said it is the right one.') }}</p>
            <a :href="d.url" target="_blank" rel="noopener" class="inline-block text-primary hover:underline">{{ __('Open in a new tab') }}</a>
        </div>
    </div>
</div>
