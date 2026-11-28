{{-- Printer test (Android Chrome, Bluetooth LE): can CRM print straight to the shop's printers, with no app?
     Sticker printer (Ruyi BT-58TSC, TSPL, 51 x 76 mm) and receipt printer (Rongta RP328, ESC/POS, 76 mm).
     Each test draws a small picture (so Bangla works) and sends it; a plain-text test only checks the link.
     A printer missing from the list = it does not speak Bluetooth LE (then: a computer, USB, or its own app). --}}
<x-layouts.app :heading="__('Printer test')">
<div class="mx-auto max-w-xl space-y-4" x-data="printerTest()">
    <div class="rounded-2xl border border-gray-200 bg-white p-4 text-sm text-gray-700">
        <p class="font-semibold text-gray-900">{{ __('Before you start') }}</p>
        <ol class="mt-2 list-decimal space-y-1 pl-5">
            <li>{{ __('Use Chrome on an Android phone.') }}</li>
            <li>{{ __('Turn on Bluetooth and Location on the phone.') }}</li>
            <li>{{ __('Turn the printer on. Do not pair it in the phone settings or in another app.') }}</li>
        </ol>
        <p x-show="!supported" x-cloak class="mt-3 rounded-lg bg-red-50 px-3 py-2 font-medium text-red-700">{{ __('This browser cannot reach Bluetooth printers. Open this page in Chrome on Android.') }}</p>
    </div>

    <template x-for="p in printers" :key="p.key">
        <div class="rounded-2xl border border-gray-200 bg-white p-4">
            <p class="text-base font-semibold text-gray-900" x-text="p.name"></p>
            <p class="text-xs text-gray-500" x-text="p.hint"></p>
            <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                <button type="button" @click="run(p, 'picture')" :disabled="busy || !supported" class="h-12 rounded-xl bg-blue-600 text-base font-semibold text-white disabled:opacity-50">{{ __('Print test') }}</button>
                <button type="button" @click="run(p, 'text')" :disabled="busy || !supported" class="h-12 rounded-xl border border-gray-300 text-sm font-medium text-gray-700 disabled:opacity-50">{{ __('Text only test') }}</button>
            </div>
            <button type="button" x-show="p.key === 'receipt'" @click="runUsb(p)" :disabled="busy || !usb" class="mt-2 h-12 w-full rounded-xl border-2 border-blue-600 text-sm font-semibold text-blue-700 disabled:opacity-50">{{ __('Print test over USB cable (OTG)') }}</button>
            <p x-show="p.key === 'receipt' && !usb" x-cloak class="mt-1 text-xs text-gray-500">{{ __('USB printing needs Chrome on Android or a computer.') }}</p>
            <label x-show="p.key === 'sticker'" class="mt-2 flex items-center gap-2 text-xs text-gray-600"><input type="checkbox" x-model="invert"> {{ __('Printed white on black? Tick this and print again.') }}</label>
            <p class="mt-3 whitespace-pre-line rounded-lg px-3 py-2 text-sm" x-show="p.msg" :class="p.ok ? 'bg-green-50 text-green-800' : 'bg-amber-50 text-amber-900'" x-text="p.msg"></p>
        </div>
    </template>
    <canvas x-ref="cv" class="hidden"></canvas>
</div>

<script>
function printerTest() {
    // Write services that Bluetooth LE label and receipt printers commonly use.
    const SERVICES = [0x18f0, 0xff00, 0xfee7, 0xffe0, 0xae30, 0xfff0, 0xffb0, 0x1101,
        '49535343-fe7d-4ae5-8fa9-9fafd205e455', 'e7810a71-73ae-499d-8c15-faa9aef0c3f2', '0000ff00-0000-1000-8000-00805f9b34fb'];
    return {
        supported: !!navigator.bluetooth, usb: !!navigator.usb, busy: false, invert: false,
        printers: [
            { key: 'sticker', name: 'Sticker printer (Ruyi BT-58TSC)', hint: '51 x 76 mm sticker, TSPL', msg: '', ok: false },
            { key: 'receipt', name: 'Receipt printer (Rongta RP328)', hint: '80 mm roll (76 mm print), ESC/POS', msg: '', ok: false },
        ],
        async writer() {
            const device = await navigator.bluetooth.requestDevice({ acceptAllDevices: true, optionalServices: SERVICES });
            // Android often drops the first link ("GATT Error Unknown"): try three times, a short wait each, and let the printer settle.
            let server = null, last = null;
            for (let t = 1; t <= 3 && !server; t++) {
                try { server = await device.gatt.connect(); await new Promise(r => setTimeout(r, 600)); }
                catch (e) { last = e; server = null; try { device.gatt.disconnect(); } catch (x) {} await new Promise(r => setTimeout(r, 1200 * t)); }
            }
            if (!server) throw new Error((last && last.message ? last.message : 'Could not connect') + '\nTurn the printer off and on, remove it from the phone Bluetooth settings if it is paired there, close its app, then try again.');
            for (const s of await server.getPrimaryServices()) {
                for (const c of await s.getCharacteristics()) {
                    if (c.properties.write || c.properties.writeWithoutResponse) return { device, c };
                }
            }
            throw new Error('Connected to ' + (device.name || 'the printer') + ', but it has no way to send print data.');
        },
        async send(c, bytes) {
            for (let i = 0; i < bytes.length; i += 180) {
                const part = bytes.slice(i, i + 180);
                c.properties.writeWithoutResponse ? await c.writeValueWithoutResponse(part) : await c.writeValue(part);
                await new Promise(r => setTimeout(r, 25));
            }
        },
        // A small test picture: IQS, a big CN, a Bangla name and address, the date.
        picture(wDots, hDots) {
            const cv = this.$refs.cv; cv.width = wDots; cv.height = hDots;
            const g = cv.getContext('2d');
            g.fillStyle = '#fff'; g.fillRect(0, 0, wDots, hDots); g.fillStyle = '#000';
            g.font = 'bold 22px Arial'; g.fillText('IQS', 10, 34);
            g.font = 'bold 44px Arial'; g.fillText('326102753', 10, 90);
            g.font = '24px Arial'; g.fillText('মোঃ রাসেল, ঢাকা', 10, 135);
            g.fillText('Printer test OK', 10, 170);
            g.font = '18px Arial'; g.fillText(new Date().toLocaleString(), 10, 200);
            g.lineWidth = 3; g.strokeRect(4, 4, wDots - 8, hDots - 8);
            const px = g.getImageData(0, 0, wDots, hDots).data, row = wDots / 8, bits = new Uint8Array(row * hDots);
            for (let y = 0; y < hDots; y++) for (let x = 0; x < wDots; x++) {
                const i = (y * wDots + x) * 4, dark = px[i] + px[i + 1] + px[i + 2] < 384;
                if (dark) bits[y * row + (x >> 3)] |= 0x80 >> (x & 7);
            }
            return { bits, row, hDots };
        },
        tspl(kind) {
            const enc = new TextEncoder();
            if (kind === 'text') return enc.encode('SIZE 51 mm,76 mm\r\nGAP 2 mm,0 mm\r\nCLS\r\nTEXT 20,30,"3",0,1,1,"IQS printer test"\r\nTEXT 20,80,"3",0,1,1,"326102753"\r\nPRINT 1\r\n');
            const { bits, row, hDots } = this.picture(400, 220);
            const data = this.invert ? bits : bits.map(b => ~b & 0xff); // TSPL: a 0 bit prints black
            const head = enc.encode(`SIZE 51 mm,76 mm\r\nGAP 2 mm,0 mm\r\nCLS\r\nBITMAP 0,20,${row},${hDots},0,`);
            const tail = enc.encode('\r\nPRINT 1\r\n');
            return new Uint8Array([...head, ...data, ...tail]);
        },
        escpos(kind) {
            const enc = new TextEncoder(), init = [0x1b, 0x40], feed = [0x1b, 0x64, 4], cut = [0x1d, 0x56, 0x42, 0];
            if (kind === 'text') return new Uint8Array([...init, ...enc.encode('IQS printer test\n326102753\n'), ...feed, ...cut]);
            const { bits, row, hDots } = this.picture(576, 220);
            const head = [0x1d, 0x76, 0x30, 0, row & 0xff, row >> 8, hDots & 0xff, hDots >> 8];
            return new Uint8Array([...init, ...head, ...bits, ...feed, ...cut]);
        },
        // The receipt printer on a USB (OTG) cable: Chrome talks to it straight, no app (for printers on classic Bluetooth only).
        async runUsb(p) {
            this.busy = true; p.msg = 'Choose the printer in the list…'; p.ok = false;
            try {
                const d = await navigator.usb.requestDevice({ filters: [] });
                await d.open();
                if (d.configuration === null) await d.selectConfiguration(1);
                let iface = null, ep = null;
                for (const i of d.configuration.interfaces) for (const a of i.alternates) for (const e of a.endpoints)
                    if (!ep && e.direction === 'out' && e.type === 'bulk') { iface = i.interfaceNumber; ep = e.endpointNumber; }
                if (ep === null) throw new Error('Connected to ' + (d.productName || 'the printer') + ', but it has no way to send print data.');
                await d.claimInterface(iface);
                await d.transferOut(ep, this.escpos('picture'));
                p.ok = true; p.msg = 'Sent over USB to ' + (d.productName || 'the printer') + '. Did it print? Send a photo of the paper.';
                await d.close();
            } catch (e) {
                p.msg = e.name === 'NotFoundError' ? 'No printer chosen, or it was not in the list. Is the cable in and the printer on?' : (e.message || String(e));
            }
            this.busy = false;
        },
        async run(p, kind) {
            this.busy = true; p.msg = 'Choose the printer in the list…'; p.ok = false;
            try {
                const { device, c } = await this.writer();
                p.msg = 'Sending to ' + (device.name || 'the printer') + '…';
                await this.send(c, p.key === 'sticker' ? this.tspl(kind) : this.escpos(kind));
                p.ok = true; p.msg = 'Sent to ' + (device.name || 'the printer') + '. Did it print? Send a photo of the paper.';
                device.gatt.disconnect();
            } catch (e) {
                p.msg = e.name === 'NotFoundError' ? 'No printer chosen, or it was not in the list. Not in the list = it does not speak Bluetooth LE.' : (e.message || String(e));
            }
            this.busy = false;
        },
    };
}
</script>
</x-layouts.app>
