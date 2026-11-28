{{-- The print station (PackagingController::printStation): one page open all day on the shop's Android phone.
     Connect the sticker printer (Bluetooth) and the receipt printer (USB cable) once; Print next batch then makes the
     batch and prints its stickers and its list right here. The printers are remembered (a dropped Bluetooth link and
     a reopened page connect again by themselves); only Disconnect lets go. The screen is kept on. --}}
<x-layouts.app :heading="__('Print station')">
<div class="mx-auto max-w-xl space-y-3" x-data="station()" x-init="init()">
    <p class="rounded-2xl bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900">{{ __('Connect once: the printers are remembered and come back by themselves. Only Disconnect lets them go. Keep this page open on the printing phone (the screen stays on). For Ongoing and Done use another tab or phone.') }}</p>

    {{-- The two printers: connected once. --}}
    <div class="grid grid-cols-2 gap-2">
        <template x-for="p in [{ key: 'sticker', name: @js(__('Label printer')), how: 'Bluetooth' }, { key: 'receipt', name: @js(__('Receipt printer')), how: @js(__('USB cable')) }]" :key="p.key">
            <div class="rounded-2xl border bg-white p-3" :class="st[p.key] ? 'border-green-300' : 'border-gray-200'">
                <p class="text-sm font-semibold text-gray-900" x-text="p.name"></p>
                <p class="text-xs text-gray-500" x-text="p.how"></p>
                <p class="mt-2 text-sm font-semibold" :class="st[p.key] ? 'text-green-700' : (p.key === 'sticker' && st.reconnecting ? 'text-amber-700' : 'text-gray-500')"
                    x-text="st[p.key] ? '✓ ' + (st[p.key + 'Name'] || @js(__('Connected'))) : (p.key === 'sticker' && st.reconnecting ? @js(__('Reconnecting…')) : @js(__('Not connected')))"></p>
                <button type="button" x-show="!st[p.key]" @click="connect(p.key, false)" :disabled="busy" class="mt-2 h-10 w-full rounded-xl border border-blue-600 bg-blue-600 text-sm font-semibold text-white disabled:opacity-50">{{ __('Connect') }}</button>
                <button type="button" x-show="st[p.key]" x-cloak @click="disconnect(p.key)" :disabled="busy" class="mt-2 h-10 w-full rounded-xl border border-red-300 text-sm font-semibold text-red-700 disabled:opacity-50">{{ __('Disconnect') }}</button>
            </div>
        </template>
    </div>

    {{-- One press: the next batch, its stickers, its list. --}}
    <button type="button" @click="printNext()" :disabled="busy || !next" class="flex w-full items-center justify-between rounded-2xl bg-blue-600 px-5 py-5 text-left text-white disabled:opacity-50">
        <span class="text-xl font-semibold">{{ __('Print next batch') }}</span>
        <span class="text-sm" x-text="next ? next + ' ' + @js(__('orders')) : @js(__('Nothing waiting'))"></span>
    </button>
    <p x-show="msg" x-cloak class="whitespace-pre-line rounded-2xl px-4 py-3 text-sm font-medium" :class="bad ? 'bg-red-50 text-red-800' : 'bg-green-50 text-green-800'" x-text="msg"></p>

    {{-- Today's batches: print again (a torn sticker, a lost list). --}}
    <div class="rounded-2xl border border-gray-200 bg-white">
        <p class="border-b border-gray-100 px-4 py-2 text-sm font-semibold text-gray-900">{{ __('Today\'s batches') }}</p>
        <template x-for="b in recent" :key="b.id">
            <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-4 py-2 last:border-0">
                <span class="text-sm"><b class="font-mono" x-text="b.batch_no"></b> <span class="text-gray-500" x-text="'· ' + b.orders + ' ' + @js(__('orders'))"></span></span>
                <span class="flex shrink-0 gap-1.5">
                    <button type="button" @click="again(b.id, 'stickers')" :disabled="busy" class="h-8 rounded-lg border border-gray-300 px-2.5 text-xs font-medium text-gray-700">{{ __('Labels') }}</button>
                    <button type="button" @click="again(b.id, 'list')" :disabled="busy" class="h-8 rounded-lg border border-gray-300 px-2.5 text-xs font-medium text-gray-700">{{ __('List') }}</button>
                </span>
            </div>
        </template>
        <p x-show="!recent.length" class="px-4 py-3 text-sm text-gray-500">{{ __('No batch yet today.') }}</p>
    </div>
</div>

@include('packaging._direct-print')
<script>
function station() {
    const url = @js(route('packaging.station-batch')), csrf = @js(csrf_token()), store = @js($store);
    return {
        st: {}, busy: false, msg: '', bad: false, next: @js(count($next['ids'])), recent: @js($recent),
        init() {
            const up = () => { this.st = IqsPrint.status(); }; up(); window.addEventListener('iqs-printers', up); setInterval(up, 3000);
            // The screen stays on while this page is open (a sleeping phone drops Bluetooth).
            const awake = () => { if (document.visibilityState === 'visible') navigator.wakeLock?.request('screen').catch(() => {}); };
            awake(); document.addEventListener('visibilitychange', awake);
        },
        async disconnect(key) { this.busy = true; await IqsPrint.disconnect(key); this.say(@js(__('Disconnected. Tap Connect to use a printer again.'))); this.st = IqsPrint.status(); this.busy = false; },
        say(t, bad = false) { this.msg = t; this.bad = bad; },
        fail(e) { this.say(e.name === 'NotFoundError' ? @js(__('No printer chosen. Is it on (and its cable in)?')) : (e.message || String(e)), true); },
        async connect(key, change) {
            this.busy = true;
            try { key === 'sticker' ? await IqsPrint.connectSticker(change) : await IqsPrint.connectReceipt(change); this.say(@js(__('Connected.'))); } catch (e) { this.fail(e); }
            this.st = IqsPrint.status(); this.busy = false;
        },
        async load(batch) {
            const r = await fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify(batch ? { batch } : {}) });
            if (!r.ok) throw new Error(@js(__('The CRM did not answer. Check the internet and try again.')));
            return r.json();
        },
        async printNext() {
            this.busy = true; this.say(@js(__('Making the batch…')));
            try {
                const b = await this.load(null);
                if (!b.ok) { this.say(b.message, true); this.next = 0; this.busy = false; return; }
                this.recent.unshift({ id: b.id, batch_no: b.batch, orders: b.stickers.length }); this.next = b.next;
                await IqsPrint.printStickers(b.stickers, store, false, t => this.say(b.batch + ': ' + t));
                await IqsPrint.marked(b.printed, csrf);
                this.say(b.batch + ': ' + @js(__('labels done, printing the list…')));
                await IqsPrint.printReceipt(b.receipt, false, t => {});
                this.say(b.batch + ': ' + b.stickers.length + ' ' + @js(__('labels and the list printed. Pack them in Ongoing.')));
            } catch (e) { this.fail(e); }
            this.st = IqsPrint.status(); this.busy = false;
        },
        async again(id, what) {
            this.busy = true;
            try {
                const b = await this.load(id);
                if (what === 'stickers') { await IqsPrint.printStickers(b.stickers, store, false, t => this.say(b.batch + ': ' + t)); await IqsPrint.marked(b.printed, csrf); }
                else await IqsPrint.printReceipt(b.receipt, false, t => {});
                this.say(b.batch + ': ' + @js(__('printed again.')));
            } catch (e) { this.fail(e); }
            this.st = IqsPrint.status(); this.busy = false;
        },
    };
}
</script>
</x-layouts.app>
