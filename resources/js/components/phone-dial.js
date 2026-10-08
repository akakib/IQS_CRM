// <x-phone-dial>: copy the number, or show a QR code so a PC user can scan
// it with their phone and call. The QR library loads only when first opened.
// With an order: tapping, copying or opening the QR logs a call starting, and
// "After the call" saves how it went, how long, and the recording's link.
export default function phoneDial(phone, orderId = null, urls = {}) {
    const token = () => document.querySelector('meta[name=csrf-token]').content;

    return {
        phone,
        orderId,
        qr: null,
        showQr: false,
        copied: false,
        callId: null,
        after: false,
        saving: false,
        starting: null,
        saved: '',
        error: '',
        form: { outcome: '', minutes: '', seconds: '', recording_url: '', note: '' },

        // keepalive: the request still goes out when the tel: link hands over to the dialer.
        started() {
            if (!this.orderId) return;
            this.after = true;
            this.saved = '';
            this.starting = fetch(urls.start, {
                method: 'POST',
                keepalive: true,
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token() },
                body: JSON.stringify({ order_id: this.orderId }),
            }).then((r) => (r.ok ? r.json() : null)).then((d) => { if (d) this.callId = d.id; }).catch(() => {});
            return this.starting;
        },

        async save() {
            this.error = '';
            if (!this.form.outcome) { this.error = 'Pick how the call went.'; return; }
            this.saving = true;
            try {
                if (!this.callId) await (this.starting || this.started());
                if (!this.callId) { this.error = 'Could not start the call log. Check the connection.'; return; }
                const r = await fetch(urls.finish.replace(/\/0$/, '/' + this.callId), {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token() },
                    body: JSON.stringify(this.form),
                });
                const d = await r.json();
                if (!r.ok) { this.error = Object.values(d.errors || {})[0]?.[0] || d.message || 'Could not save.'; return; }
                this.saved = d.message;
                this.after = false;
                this.callId = null;
                this.starting = null;
                this.form = { outcome: '', minutes: '', seconds: '', recording_url: '', note: '' };
            } catch {
                this.error = 'Could not save. Check the connection.';
            } finally {
                this.saving = false;
            }
        },

        async toggleQr() {
            if (!this.qr) {
                const { default: qrcode } = await import('qrcode-generator');
                const code = qrcode(0, 'M');
                code.addData('tel:' + this.phone);
                code.make();
                this.qr = code.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
            }
            this.showQr = !this.showQr;
            if (this.showQr) this.started();
        },

        async copy() {
            try {
                await navigator.clipboard.writeText(this.phone);
            } catch {
                const el = document.createElement('textarea');
                el.value = this.phone;
                document.body.appendChild(el);
                el.select();
                document.execCommand('copy');
                el.remove();
            }
            this.copied = true;
            setTimeout(() => (this.copied = false), 1500);
            this.started();
        },
    };
}
