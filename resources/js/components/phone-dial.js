// <x-phone-dial>: copy the number, or show a QR code so a PC user can scan
// it with their phone and call. The QR library loads only when first opened.
// With an order: tapping, copying or opening the QR starts a call; the
// "After the call" panel opens over the page (the same one as the call list:
// what happened, how happy, the recording or screenshot, a note). How long it
// took comes from the recording, else from the clock. Until it is saved the
// call follows the caller to other pages (the yellow bar, iqs-call-live).
export default function phoneDial(phone, orderId = null, urls = {}) {
    const token = () => document.querySelector('meta[name=csrf-token]').content;
    const LIVE = 'iqs-call-live';
    const blank = () => ({ outcome: '', satisfaction: null, note: '' });

    return {
        phone,
        orderId,
        qr: null,
        showQr: false,
        copied: false,
        callId: null,
        after: false,
        sheet: false,
        saving: false,
        starting: null,
        startedAt: null,
        saved: '',
        error: '',
        form: blank(),

        init() {
            if (!this.orderId) return;
            // Back on this order with its call not saved yet: the panel comes back.
            try {
                const live = JSON.parse(localStorage.getItem(LIVE) || 'null');
                if (live && live.item === 'o' + this.orderId) {
                    this.after = true; this.sheet = true; this.callId = live.callId || null; this.startedAt = live.started;
                }
            } catch {}
        },

        live() {
            try {
                localStorage.setItem(LIVE, JSON.stringify({ item: 'o' + this.orderId, name: urls.name || this.phone, url: location.pathname + location.search, started: this.startedAt, callId: this.callId }));
            } catch {}
        },

        // keepalive: the request still goes out when the tel: link hands over to the dialer.
        started() {
            if (!this.orderId) return;
            this.sheet = true;
            this.saved = '';
            if (this.after) return this.starting;
            this.after = true;
            this.startedAt = Date.now();
            this.live();
            this.starting = fetch(urls.start, {
                method: 'POST',
                keepalive: true,
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token() },
                body: JSON.stringify({ order_id: this.orderId }),
            }).then((r) => (r.ok ? r.json() : null)).then((d) => { if (d) { this.callId = d.id; this.live(); } }).catch(() => {});
            return this.starting;
        },

        async save() {
            this.error = '';
            if (!this.form.outcome) { this.error = 'Pick what happened.'; return; }
            this.saving = true;
            try {
                if (!this.callId) await (this.starting || this.started());
                if (!this.callId) { this.error = 'Could not start the call log. Check the connection.'; return; }
                // With the recording or screenshot (multipart, so PATCH rides on POST).
                const fd = window.iqsFormData({ ...this.form, ...window.iqsMinSec(window.iqsCallSeconds(urls.proof, this.startedAt)), _method: 'PATCH' });
                window.iqsAddProof(fd, urls.proof);
                const r = await fetch(urls.finish.replace(/\/0$/, '/' + this.callId), {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token() },
                    body: fd,
                });
                const d = await r.json();
                if (!r.ok) { this.error = Object.values(d.errors || {})[0]?.[0] || d.message || 'Could not save.'; return; }
                this.saved = d.message;
                this.after = false;
                this.sheet = false;
                this.callId = null;
                this.starting = null;
                this.startedAt = null;
                this.form = blank();
                try { localStorage.removeItem(LIVE); } catch {}
                window.dispatchEvent(new CustomEvent('proof-clear', { detail: urls.proof }));
            } catch {
                this.error = 'Could not save. Check the connection.';
            } finally {
                this.saving = false;
            }
        },

        async toggleQr() {
            if (!this.qr) this.qr = await window.iqsQr(this.phone);
            this.showQr = !this.showQr;
            if (this.showQr) this.started();
        },

        async copy() {
            try {
                await navigator.clipboard.writeText(this.phone);
                this.copied = true;
            } catch {
                this.copied = false;
            }
            setTimeout(() => (this.copied = false), 1500);
            this.started();
        },
    };
}

// A phone number as a QR picture (svg) to scan with a phone from a PC screen.
window.iqsQr = async (phone) => {
    const { default: qrcode } = await import('qrcode-generator');
    const code = qrcode(0, 'M');
    code.addData('tel:' + phone);
    code.make();
    return code.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
};
