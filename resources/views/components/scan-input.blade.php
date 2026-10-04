{{-- Barcode / QR input for USB scanners (they type the code + Enter). Keeps
     focus, clears after each scan, and emits `scan` with the code. The page
     answers with $dispatch('scan-result', { ok: true|false, message }) to get
     a green/red flash and a beep.
     <x-scan-input @scan="handle($event.detail)" /> --}}
@props(['placeholder' => __('Scan a label or type the code + Enter')])

<div {{ $attributes->merge(['class' => 'w-full']) }}
    x-data="{
        code: '', state: null, message: '',
        beep(ok) {
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const o = ctx.createOscillator(); o.frequency.value = ok ? 880 : 220;
                o.connect(ctx.destination); o.start(); setTimeout(() => { o.stop(); ctx.close(); }, ok ? 120 : 400);
            } catch (e) {}
        },
        submit() {
            const c = this.code.trim(); this.code = '';
            if (c) this.$dispatch('scan', c);
        },
    }"
    @scan-result.window="state = $event.detail.ok ? 'ok' : 'bad'; message = $event.detail.message || ''; beep($event.detail.ok); setTimeout(() => state = null, 2500)">
    <input type="text" x-model="code" @keydown.enter.prevent="submit()" autofocus autocomplete="off" inputmode="none"
        @blur="setTimeout(() => { if (document.activeElement === document.body) $el.focus() }, 150)" placeholder="{{ $placeholder }}"
        :class="state === 'ok' ? 'border-green-600 bg-green-50' : (state === 'bad' ? 'border-red-600 bg-red-50' : 'border-gray-300')"
        class="w-full rounded-xl border-2 px-4 py-4 text-center font-mono text-lg tracking-wider focus:outline-none">
    <p x-show="message" x-text="message" class="mt-2 text-center text-sm font-medium" :class="state === 'ok' ? 'text-green-800' : 'text-red-700'"></p>
</div>
