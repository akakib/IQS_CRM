{{-- Print straight from the shop's Android phone, no app (Chrome): the stickers to the Bluetooth sticker printer
     (Ruyi, TSPL, 51 x 76 mm) and the batch list to the receipt printer on a USB (OTG) cable (Rongta, ESC/POS, 76 mm).
     Each is drawn as a picture (Bangla works) and sent. The printer chosen once is remembered.
     Needs: window.IQS_PRINT = { stickers: [...], receipt: {...}, printedUrl, csrf } (set by the batch list page). --}}
<script>
window.IqsPrint = (() => {
    const BLE = ['49535343-fe7d-4ae5-8fa9-9fafd205e455', 'e7810a71-73ae-499d-8c15-faa9aef0c3f2', 0x18f0, 0xff00, 0xfee7, 0xffe0, 0xae30, 0xfff0, 0xffb0];
    const wait = ms => new Promise(r => setTimeout(r, ms));
    const enc = new TextEncoder();

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
        const W = 408, H = 608, [c, g] = canvas(W, H), L = 16, R = W - 16;
        g.font = 'bold 26px Arial'; g.fillText(store || 'IQS', L, 34);
        g.textAlign = 'right'; g.font = 'bold 16px Arial'; g.fillText('COD', R, 28); g.font = 'bold 40px Arial'; g.fillText('৳' + s.cod, R, 46); g.textAlign = 'left';
        g.lineWidth = 4; g.strokeRect(L, 98, R - L, 86); g.font = 'bold 16px Arial'; g.fillText('Steadfast CN', L + 10, 106); g.font = 'bold 46px Arial'; g.fillText(s.cn || '-', L + 10, 128);
        g.font = 'bold 26px Arial'; let y = 198; for (const l of wrap(g, s.name, R - L).slice(0, 2)) { g.fillText(l, L, y); y += 30; }
        g.font = '22px Arial'; for (const l of wrap(g, s.address, R - L).slice(0, 5)) { g.fillText(l, L, y); y += 26; }
        // Barcode: whole dots per module, centred, off the bottom edge.
        const widths = String(s.bars).split('').map(Number), modules = widths.reduce((a, b) => a + b, 0), m = Math.max(1, Math.floor((R - L) / modules));
        let x = Math.round((W - modules * m) / 2); const top = 440, bh = 80;
        widths.forEach((w, i) => { if (i % 2 === 0) g.fillRect(x, top, w * m, bh); x += w * m; });
        g.textAlign = 'center'; g.font = 'bold 30px monospace'; g.fillText(s.barcode, W / 2, top + bh + 6); g.font = 'bold 24px Arial'; g.fillText(s.batch || '', W / 2, top + bh + 40); g.textAlign = 'left';
        return c;
    }
    // The batch list on 76 mm paper (576 dots): head, then each order with its items to tick.
    function receipt(r) {
        const W = 576, [c, g] = canvas(W, 400 + r.lines * 34 + r.orders.length * 90), L = 8, R = W - 8; let y = 10;
        g.textAlign = 'center'; g.font = 'bold 40px Arial'; g.fillText('BATCH ' + r.batch, W / 2, y); y += 50;
        g.font = '22px Arial'; for (const t of r.head) { g.fillText(t, W / 2, y); y += 28; } g.textAlign = 'left';
        g.fillRect(L, y + 4, R - L, 3); y += 18; g.font = 'bold 22px Arial'; g.fillText('PACK EACH ORDER', L, y); y += 32;
        r.orders.forEach((o, n) => {
            for (let x = L; x < R; x += 14) g.fillRect(x, y, 8, 2); y += 10;
            g.font = 'bold 30px Arial'; g.fillText(o.no, L, y); g.textAlign = 'right'; g.fillText((n + 1) + '/' + r.orders.length, R, y); g.textAlign = 'left'; y += 36;
            g.font = '20px Arial'; g.fillText(o.area, L, y); if (o.cn) { g.font = 'bold 20px Arial'; g.fillText('CN ' + o.cn, L + g.measureText(o.area + '   ').width, y); } y += 30;
            g.font = '22px Arial';
            for (const it of o.items) {
                g.strokeRect(L + 2, y + 3, 18, 18); g.textAlign = 'right'; g.font = 'bold 22px Arial'; g.fillText(it.qty, R, y); g.textAlign = 'left'; g.font = '22px Arial';
                for (const [k, l] of wrap(g, it.name, R - L - 110).entries()) { g.fillText(l, L + 30, y); y += 28; }
                y += 4;
            }
            y += 6;
        });
        g.fillRect(L, y, R - L, 3); y += 14; g.font = '22px Arial'; g.textAlign = 'center'; g.fillText('Packed by: ____________________', W / 2, y); y += 30; g.fillText(r.foot, W / 2, y); y += 40;
        const [out, og] = canvas(W, y); og.drawImage(c, 0, 0); return out;
    }

    // ── Bluetooth sticker printer ──
    async function bleDevice(choose) {
        let d = null;
        if (!choose && navigator.bluetooth.getDevices) {
            const name = localStorage.getItem('iqs.btPrinter.sticker');
            d = (await navigator.bluetooth.getDevices()).find(x => x.name && x.name === name) || null;
        }
        if (!d) d = await navigator.bluetooth.requestDevice({ acceptAllDevices: true, optionalServices: BLE });
        try { localStorage.setItem('iqs.btPrinter.sticker', d.name || ''); } catch (e) {}
        let server = null, last = null;
        for (let t = 1; t <= 3 && !server; t++) { try { server = await d.gatt.connect(); await wait(500); } catch (e) { last = e; await wait(1000 * t); } }
        if (!server) throw new Error('Sticker printer: could not connect (' + (last?.message || '') + '). Turn it off and on.');
        for (const u of BLE) { try { const s = await server.getPrimaryService(u); for (const ch of await s.getCharacteristics()) if (ch.properties.write || ch.properties.writeWithoutResponse) return { d, ch }; } catch (e) {} }
        for (const s of await server.getPrimaryServices()) for (const ch of await s.getCharacteristics()) if (ch.properties.write || ch.properties.writeWithoutResponse) return { d, ch };
        throw new Error('Sticker printer: connected, but no way to send.');
    }
    async function bleSend(ch, bytes) {
        for (let i = 0; i < bytes.length; i += 180) { const p = bytes.slice(i, i + 180); ch.properties.writeWithoutResponse ? await ch.writeValueWithoutResponse(p) : await ch.writeValue(p); await wait(20); }
    }
    async function printStickers(list, store, choose, progress) {
        const { d, ch } = await bleDevice(choose);
        for (const [n, s] of list.entries()) {
            progress('Sticker ' + (n + 1) + ' of ' + list.length);
            const b = bits(sticker(s, store)); // TSPL: a 0 bit prints black, so flipped below
            const inv = b.data.map(v => ~v & 0xff);
            await bleSend(ch, new Uint8Array([...enc.encode('SIZE 51 mm,76 mm\r\nGAP 2 mm,0 mm\r\nDIRECTION 0\r\nCLS\r\nBITMAP 0,0,' + b.row + ',' + b.h + ',0,'), ...inv, ...enc.encode('\r\nPRINT 1\r\n')]));
            await wait(400);
        }
        try { d.gatt.disconnect(); } catch (e) {}
    }

    // ── USB receipt printer ──
    async function usbPrinter(choose) {
        const known = await navigator.usb.getDevices();
        const d = known.length && !choose ? known[0] : await navigator.usb.requestDevice({ filters: [] });
        await d.open(); if (d.configuration === null) await d.selectConfiguration(1);
        let iface = null, ep = null;
        for (const i of d.configuration.interfaces) for (const a of i.alternates) for (const e of a.endpoints) if (ep === null && e.direction === 'out' && e.type === 'bulk') { iface = i.interfaceNumber; ep = e.endpointNumber; }
        if (ep === null) throw new Error('Receipt printer: no way to send.');
        await d.claimInterface(iface); return { d, ep };
    }
    async function printReceipt(r, choose, progress) {
        progress('Receipt');
        const { d, ep } = await usbPrinter(choose);
        const b = bits(receipt(r));
        await d.transferOut(ep, new Uint8Array([0x1b, 0x40]));
        for (let y = 0; y < b.h; y += 200) { // bands, so the printer's memory never overflows
            const h = Math.min(200, b.h - y), part = b.data.slice(y * b.row, (y + h) * b.row);
            await d.transferOut(ep, new Uint8Array([0x1d, 0x76, 0x30, 0, b.row & 0xff, b.row >> 8, h & 0xff, h >> 8, ...part]));
            await wait(30);
        }
        await d.transferOut(ep, new Uint8Array([0x1b, 0x64, 4, 0x1d, 0x56, 0x42, 0]));
        try { await d.close(); } catch (e) {}
    }

    async function marked(url, csrf) { try { await fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' } }); } catch (e) {} }

    return { sticker, receipt, printStickers, printReceipt, marked, ok: { ble: !!navigator.bluetooth, usb: !!navigator.usb } };
})();
</script>
