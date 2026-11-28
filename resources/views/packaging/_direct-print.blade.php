{{-- Print straight from the shop's Android phone, no app (Chrome): the stickers to the Bluetooth sticker printer
     (Ruyi, TSPL, 51 x 76 mm) and the batch list to the receipt printer on a USB (OTG) cable (Rongta, ESC/POS, 76 mm).
     Each is drawn as a picture (Bangla works) and sent. The printer chosen once is remembered.
     Needs: window.IQS_PRINT = { stickers: [...], receipt: {...}, printedUrl, csrf } (set by the batch list page). --}}
<script>
window.IqsPrint = (() => {
    const BLE = ['49535343-fe7d-4ae5-8fa9-9fafd205e455', 'e7810a71-73ae-499d-8c15-faa9aef0c3f2', 0x18f0, 0xff00, 0xfee7, 0xffe0, 0xae30, 0xfff0, 0xffb0];
    const wait = ms => new Promise(r => setTimeout(r, ms));
    const enc = new TextEncoder();
    // iPhone and iPad (every browser there) cannot talk to a printer from a web page.
    const NO_DIRECT = 'This phone cannot print here (iPhone and iPad cannot). Use the shop\'s Android phone in Chrome.';

    // ── Drawing ──
    function canvas(w, h) { const c = document.createElement('canvas'); c.width = w; c.height = h; const g = c.getContext('2d'); g.fillStyle = '#fff'; g.fillRect(0, 0, w, h); g.fillStyle = '#000'; g.textBaseline = 'top'; return [c, g]; }
    function wrap(g, text, maxW) {
        const words = String(text || '').split(/\s+/), lines = []; let line = '';
        for (const w of words) { const t = line ? line + ' ' + w : w; if (g.measureText(t).width > maxW && line) { lines.push(line); line = w; } else line = t; }
        if (line) lines.push(line); return lines;
    }
    // 1 bit per dot, a 1 = black (ESC/POS); TSPL wants the opposite, so its caller flips every byte.
    function bits(c) {
        const w = c.width, h = c.height, row = w / 8, px = c.getContext('2d').getImageData(0, 0, w, h).data, out = new Uint8Array(row * h);
        for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
            const i = (y * w + x) * 4;
            if (px[i] + px[i + 1] + px[i + 2] < 384) out[y * row + (x >> 3)] |= 0x80 >> (x & 7);
        }
        return { data: out, row, h };
    }
    // One 51 x 76 mm sticker at 203 dpi (408 x 608 dots): IQS, COD, CN, name, address, barcode, label no, batch no.
    function sticker(s, store) {
        // 48 mm wide (384 dots): the print head of a 58 mm label printer; a wider picture loses its right edge.
        // Smaller than the 51 x 76 mm sticker, with a margin all round: 46 x 66 mm (368 x 528 dots), placed 1 mm in and 3 mm down.
        const W = 368, H = 528, [c, g] = canvas(W, H), L = 8, R = W - 8;
        g.font = 'bold 26px Arial'; g.fillText(store || 'IQS', L, 34);
        g.textAlign = 'right'; g.font = 'bold 16px Arial'; g.fillText('COD', R, 28); g.font = 'bold 40px Arial'; g.fillText('৳' + s.cod, R, 46); g.textAlign = 'left';
        g.lineWidth = 4; g.strokeRect(L, 98, R - L, 86); g.font = 'bold 16px Arial'; g.fillText('Steadfast CN', L + 10, 106); g.font = 'bold 46px Arial'; g.fillText(s.cn || '-', L + 10, 128);
        g.font = 'bold 26px Arial'; let y = 198; for (const l of wrap(g, s.name, R - L).slice(0, 2)) { g.fillText(l, L, y); y += 30; }
        g.font = '22px Arial'; for (const l of wrap(g, s.address, R - L).slice(0, 4)) { g.fillText(l, L, y); y += 26; }
        // Barcode: whole dots per module, centred, off the bottom edge.
        const widths = String(s.bars).split('').map(Number), modules = widths.reduce((a, b) => a + b, 0), m = Math.max(1, Math.floor((R - L) / modules));
        let x = Math.round((W - modules * m) / 2); const top = 380, bh = 72;
        // The bars themselves are drawn by the printer (TSPL BARCODE in printStickers): sharp, and never lost with the picture.
        s._bar = { x: x + 8, y: top + 24, h: bh, m };
        g.textAlign = 'center'; g.font = 'bold 30px monospace'; g.fillText(s.barcode, W / 2, top + bh + 6); g.font = 'bold 24px Arial'; g.fillText(s.batch || '', W / 2, top + bh + 40); g.textAlign = 'left';
        return c;
    }
    // The batch list on 76 mm paper (576 dots): head, then each order with its items to tick.
    function receipt(r) {
        const W = 576, [c, g] = canvas(W, 600 + r.lines * 60 + r.orders.length * 100), L = 8, R = W - 8; let y = 10;
        g.textAlign = 'center'; g.font = 'bold 40px Arial'; g.fillText('BATCH ' + r.batch, W / 2, y); y += 50;
        g.font = '22px Arial'; for (const t of r.head) { g.fillText(t, W / 2, y); y += 28; } g.textAlign = 'left';
        g.fillRect(L, y + 4, R - L, 3); y += 18; g.font = 'bold 22px Arial'; g.fillText('PACK EACH ORDER', L, y); y += 32;
        r.orders.forEach((o, n) => {
            for (let x = L; x < R; x += 14) g.fillRect(x, y, 8, 2); y += 10;
            g.font = 'bold 30px Arial'; g.fillText(o.no, L, y); g.textAlign = 'right'; g.fillText((n + 1) + '/' + r.orders.length, R, y); g.textAlign = 'left'; y += 36;
            g.font = '20px Arial'; g.fillText(o.area, L, y); if (o.cn) { g.font = 'bold 20px Arial'; g.fillText('CN ' + o.cn, L + g.measureText(o.area + '   ').width, y); } y += 30;
            g.font = '22px Arial';
            // The quantity and the weight are what gets packed wrong: both big and bold; the name stays plain.
            for (const it of o.items) {
                const name = String(it.name || ''), sizes = [...name.matchAll(/\d+(?:[.,]\d+)?\s*(?:kg|gms?|g|ml|ltr|l|pcs?|piece)\b/gi)], cut = sizes.length ? sizes[sizes.length - 1].index : -1;
                const base = cut > 0 ? name.slice(0, cut).trim() : name, size = cut > 0 ? name.slice(cut).trim() : '', maxW = R - L - 120;
                g.strokeRect(L + 2, y + 6, 20, 20); g.textAlign = 'right'; g.font = 'bold 36px Arial'; g.fillText(it.qty, R, y - 2); g.textAlign = 'left';
                g.font = '22px Arial'; const lines = wrap(g, base, maxW);
                lines.forEach((l, k) => { g.font = '22px Arial'; g.fillText(l, L + 32, y + 4); if (k < lines.length - 1 || size) y += 30; });
                if (size) {
                    g.font = '22px Arial'; const last = lines.length ? lines[lines.length - 1] : '', w = g.measureText(last + ' ').width;
                    g.font = 'bold 28px Arial';
                    if (lines.length && w + g.measureText(size).width <= maxW) { y -= 30; g.fillText(size, L + 32 + w, y + 1); } else g.fillText(size, L + 32, y + 1);
                }
                y += 42;
            }
            y += 6;
        });
        g.fillRect(L, y, R - L, 3); y += 14; g.font = '22px Arial'; g.textAlign = 'center'; g.fillText('Packed by: ____________________', W / 2, y); y += 30; g.fillText(r.foot, W / 2, y); y += 40;
        const [out, og] = canvas(W, y); og.drawImage(c, 0, 0); return out;
    }

    // ── Bluetooth sticker printer ──
    async function bleDevice(choose) { // the list (or the printer Chrome remembers)
        if (!navigator.bluetooth) throw new Error(NO_DIRECT);
        let d = null;
        if (!choose && navigator.bluetooth.getDevices) {
            const name = localStorage.getItem('iqs.btPrinter.sticker');
            d = (await navigator.bluetooth.getDevices()).find(x => x.name && x.name === name) || null;
        }
        if (!d) d = await navigator.bluetooth.requestDevice({ acceptAllDevices: true, optionalServices: BLE });
        try { localStorage.setItem('iqs.btPrinter.sticker', d.name || ''); } catch (e) {}
        let server = null, last = null;
        for (let t = 1; t <= 3 && !server; t++) { try { server = await d.gatt.connect(); await wait(500); } catch (e) { last = e; await wait(1000 * t); } }
        if (!server) throw new Error('Label printer: could not connect (' + (last?.message || '') + '). Turn it off and on.');
        for (const u of BLE) { try { const s = await server.getPrimaryService(u); for (const ch of await s.getCharacteristics()) if (ch.properties.write || ch.properties.writeWithoutResponse) return { d, ch }; } catch (e) {} }
        for (const s of await server.getPrimaryServices()) for (const ch of await s.getCharacteristics()) if (ch.properties.write || ch.properties.writeWithoutResponse) return { d, ch };
        throw new Error('Label printer: connected, but no way to send.');
    }
    // Small pieces, each one confirmed by the printer when it can (a flood without answers loses bytes: the sticker comes out jumbled).
    async function bleSend(ch, bytes) {
        const sure = ch.properties.write;
        for (let i = 0; i < bytes.length; i += 128) { const p = bytes.slice(i, i + 128); sure ? await ch.writeValueWithResponse(p) : (await ch.writeValueWithoutResponse(p), await wait(30)); }
    }
    // Connected once, remembered: a Bluetooth drop reconnects by itself, a reopened page connects to the same printers.
    // Only Disconnect lets go (and forgets the printer).
    let ble = null, usb = null, bleDev = null, retrying = false;
    const flag = (k, v) => { try { if (v === undefined) return localStorage.getItem('iqs.printer.' + k) === '1'; localStorage.setItem('iqs.printer.' + k, v ? '1' : '0'); } catch (e) {} };
    const changed = () => window.dispatchEvent(new Event('iqs-printers'));
    async function bleWriter(d) {
        const server = d.gatt.connected ? d.gatt : await d.gatt.connect(); await wait(400);
        for (const u of BLE) { try { const s = await server.getPrimaryService(u); for (const ch of await s.getCharacteristics()) if (ch.properties.write || ch.properties.writeWithoutResponse) return { d, ch }; } catch (e) {} }
        for (const s of await server.getPrimaryServices()) for (const ch of await s.getCharacteristics()) if (ch.properties.write || ch.properties.writeWithoutResponse) return { d, ch };
        throw new Error('Label printer: connected, but no way to send.');
    }
    async function reconnectBle() {
        if (retrying || !bleDev || !flag('sticker')) return;
        retrying = true; changed();
        for (let t = 0; t < 40 && flag('sticker') && !(ble && ble.d.gatt.connected); t++) { // about two minutes, then a tap on Connect
            try { ble = await bleWriter(bleDev); } catch (e) { await wait(3000); }
        }
        retrying = false; changed();
    }
    async function sticker_(choose) {
        if (!choose && ble && ble.d.gatt.connected) return ble;
        if (!choose && bleDev) { try { ble = await bleWriter(bleDev); changed(); return ble; } catch (e) {} }
        ble = await bleDevice(choose);
        if (bleDev !== ble.d) { bleDev = ble.d; bleDev.addEventListener('gattserverdisconnected', () => { ble = null; changed(); reconnectBle(); }); }
        flag('sticker', true); changed();
        return ble;
    }
    async function receipt_(choose) {
        if (!choose && usb && usb.d.opened) return usb;
        usb = await usbPrinter(choose);
        flag('receipt', true); changed();
        return usb;
    }
    async function disconnect(key) {
        flag(key, false);
        if (key === 'sticker') { try { bleDev?.gatt.disconnect(); } catch (e) {} try { await bleDev?.forget?.(); } catch (e) {} ble = null; bleDev = null; try { localStorage.removeItem('iqs.btPrinter.sticker'); } catch (e) {} }
        else { try { await usb?.d.close(); } catch (e) {} try { await usb?.d.forget?.(); } catch (e) {} usb = null; }
        changed();
    }
    // A reopened page: the printers used last time, with no tap (when Chrome allows it).
    async function restore() {
        if (navigator.usb && flag('receipt')) { try { if ((await navigator.usb.getDevices()).length) await receipt_(false); } catch (e) {} }
        if (navigator.bluetooth?.getDevices && flag('sticker')) {
            try {
                const name = localStorage.getItem('iqs.btPrinter.sticker');
                const d = (await navigator.bluetooth.getDevices()).find(x => x.name && x.name === name);
                if (d) { bleDev = d; d.addEventListener('gattserverdisconnected', () => { ble = null; changed(); reconnectBle(); }); reconnectBle(); }
            } catch (e) {}
        }
    }
    async function printStickers(list, store, choose, progress) {
        const { d, ch } = await sticker_(choose);
        for (const [n, s] of list.entries()) {
            progress('Label ' + (n + 1) + ' of ' + list.length);
            const b = bits(sticker(s, store)); // TSPL: a 0 bit prints black, so flipped below
            const inv = b.data.map(v => ~v & 0xff);
            // The printer holds only a few KB at a time: the picture goes in strips of 32 rows (about 1.5 KB) with a pause,
            // and a strip with nothing on it is not sent at all.
            await bleSend(ch, enc.encode('SIZE 51 mm,76 mm\r\nGAP 2 mm,0 mm\r\nDIRECTION 0\r\nCLS\r\n')); await wait(150);
            for (let y = 0; y < b.h; y += 32) {
                const h = Math.min(32, b.h - y), part = inv.slice(y * b.row, (y + h) * b.row);
                if (part.every(v => v === 0xff)) continue;
                await bleSend(ch, new Uint8Array([...enc.encode('BITMAP 8,' + (24 + y) + ',' + b.row + ',' + h + ',0,'), ...part, ...enc.encode('\r\n')]));
                await wait(250);
            }
            await bleSend(ch, enc.encode('BARCODE ' + s._bar.x + ',' + s._bar.y + ',"128",' + s._bar.h + ',0,0,' + s._bar.m + ',' + s._bar.m + ',"' + String(s.barcode).replace(/"/g, '') + '"\r\nPRINT 1\r\n'));
            await wait(4000); // the printer finishes this sticker (and is ready) before the next one arrives
        }
    }

    // ── USB receipt printer ──
    async function usbPrinter(choose) {
        if (!navigator.usb) throw new Error(NO_DIRECT);
        const known = await navigator.usb.getDevices();
        const d = known.length && !choose ? known[0] : await navigator.usb.requestDevice({ filters: [] });
        if (!d.opened) await d.open();
        if (d.configuration === null) await d.selectConfiguration(1);
        let iface = null, ep = null;
        for (const i of d.configuration.interfaces) for (const a of i.alternates) for (const e of a.endpoints) if (ep === null && e.direction === 'out' && e.type === 'bulk') { iface = i.interfaceNumber; ep = e.endpointNumber; }
        if (ep === null) throw new Error('Receipt printer: no way to send.');
        await d.claimInterface(iface); return { d, ep };
    }
    async function printReceipt(r, choose, progress) {
        progress('Receipt');
        const { d, ep } = await receipt_(choose);
        const b = bits(receipt(r));
        await d.transferOut(ep, new Uint8Array([0x1b, 0x40]));
        for (let y = 0; y < b.h; y += 200) { // bands, so the printer's memory never overflows
            const h = Math.min(200, b.h - y), part = b.data.slice(y * b.row, (y + h) * b.row);
            await d.transferOut(ep, new Uint8Array([0x1d, 0x76, 0x30, 0, b.row & 0xff, b.row >> 8, h & 0xff, h >> 8, ...part]));
            await wait(30);
        }
        await d.transferOut(ep, new Uint8Array([0x1b, 0x64, 4, 0x1d, 0x56, 0x42, 0]));
    }

    async function marked(url, csrf) { try { await fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' } }); } catch (e) {} }

    // ── PDF (a phone that cannot reach the printers, e.g. an iPhone): the same pictures, one per page, saved as a file ──
    // A tiny PDF writer: each page is one JPEG at the paper's size. No library, Bangla kept (it is a picture).
    function pdf(pages) {
        const parts = [], offs = []; let len = 0;
        const add = x => { const b = typeof x === 'string' ? enc.encode(x) : x; parts.push(b); len += b.length; };
        const obj = (n, body) => { offs[n] = len; add(n + ' 0 obj\n'); body(); add('\nendobj\n'); };
        add('%PDF-1.4\n');
        const n = pages.length, kids = pages.map((_, i) => (3 + i * 3) + ' 0 R').join(' ');
        obj(1, () => add('<< /Type /Catalog /Pages 2 0 R >>'));
        obj(2, () => add('<< /Type /Pages /Kids [' + kids + '] /Count ' + n + ' >>'));
        pages.forEach((p, i) => {
            const pg = 3 + i * 3, w = p.wMm * 72 / 25.4, h = p.hMm * 72 / 25.4;
            const jpg = Uint8Array.from(atob(p.canvas.toDataURL('image/jpeg', 0.92).split(',')[1]), ch => ch.charCodeAt(0));
            const draw = 'q ' + w.toFixed(2) + ' 0 0 ' + h.toFixed(2) + ' 0 0 cm /Im Do Q';
            obj(pg, () => add('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' + w.toFixed(2) + ' ' + h.toFixed(2) + '] /Resources << /XObject << /Im ' + (pg + 2) + ' 0 R >> >> /Contents ' + (pg + 1) + ' 0 R >>'));
            obj(pg + 1, () => { add('<< /Length ' + draw.length + ' >>\nstream\n'); add(draw); add('\nendstream'); });
            obj(pg + 2, () => { add('<< /Type /XObject /Subtype /Image /Width ' + p.canvas.width + ' /Height ' + p.canvas.height + ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' + jpg.length + ' >>\nstream\n'); add(jpg); add('\nendstream'); });
        });
        const total = 3 + n * 3, xref = len;
        add('xref\n0 ' + total + '\n0000000000 65535 f \n');
        for (let k = 1; k < total; k++) add(String(offs[k]).padStart(10, '0') + ' 00000 n \n');
        add('trailer\n<< /Size ' + total + ' /Root 1 0 R >>\nstartxref\n' + xref + '\n%%EOF');
        return new Blob(parts, { type: 'application/pdf' });
    }
    function save(blob, name) { const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = name; document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(a.href), 60000); }
    // Labels: one 51 x 76 mm page each, laid out as printed (the bars drawn here, since the printer draws them on paper).
    function labelsPdf(list, store, name) {
        save(pdf(list.map(s => {
            const art = sticker(s, store), [c, g] = canvas(408, 608); g.drawImage(art, 8, 24);
            const widths = String(s.bars).split('').map(Number); let x = s._bar.x;
            widths.forEach((w, i) => { if (i % 2 === 0) g.fillRect(x, s._bar.y, w * s._bar.m, s._bar.h); x += w * s._bar.m; });
            return { canvas: c, wMm: 51, hMm: 76 };
        })), name);
    }
    // The batch list: one 76 mm wide page, as long as the list.
    function listPdf(r, name) { const c = receipt(r); save(pdf([{ canvas: c, wMm: 76, hMm: c.height * 76 / 576 }]), name); }

    if (navigator.usb) {
        navigator.usb.addEventListener('disconnect', () => { usb = null; changed(); });
        navigator.usb.addEventListener('connect', () => { if (flag('receipt')) receipt_(false).catch(() => {}); }); // the cable back in: open again
    }
    restore();

    return { sticker, receipt, printStickers, printReceipt, marked, connectSticker: sticker_, connectReceipt: receipt_, disconnect, labelsPdf, listPdf,
        canPrint: !!(navigator.bluetooth || navigator.usb),
        status: () => ({ sticker: !!(ble && ble.d.gatt.connected), stickerName: ble?.d.name || bleDev?.name || '', reconnecting: retrying,
            receipt: !!(usb && usb.d.opened), receiptName: usb?.d.productName || '' }),
        ok: { ble: !!navigator.bluetooth, usb: !!navigator.usb } };
})();
</script>
