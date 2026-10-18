// <x-phone-dial>: copy the number, or show a QR code so a PC user can scan
// it with their phone and call. The QR library loads only when first opened.
// With an order: tapping, copying or opening the QR starts a call; the
// "After the call" panel opens over the page (the same one as the call list:
// what happened, how happy, the recording or screenshot, a note). How long it
// took comes from the recording, else from the clock. Until it is saved the
// call follows the caller to other pages (the yellow bar, iqs-call-live).
// Call verified on the desk (call-then-confirm): the panel opens with Talked picked; saved as Talked,
// the order is confirmed (the desk form, action confirm). Already talked on this order: confirmed at once.
// Any call saved on a desk order: No answer or Busy presses No response; Wrong number opens Cancel with that reason.
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
        thenConfirm: false,
        clockless: false,
        talked: !!urls.talked,

        init() {
            if (!this.orderId) return;
            this.$watch('sheet', (open) => { if (!open && !this.saving) this.thenConfirm = false; });
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
        // open = false (Copy, QR on a PC): the call starts quietly; "Call not saved · Finish it" opens the panel after.
        started(open = true) {
            if (!this.orderId) return;
            if (open) this.sheet = true;
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

        // Call verified: log the call first (Talked), then the order is confirmed.
        askConfirm() {
            if (this.talked) return this.confirmOrder();
            this.thenConfirm = true;
            if (!this.after) this.clockless = true; // the call was not started here: no made-up length, only the recording's
            this.started();
            if (!this.form.outcome) this.form.outcome = 'answered';
        },

        // The call was not really made (pressed by mistake) or cannot be finished: stop carrying it. Nothing is saved.
        discard() {
            this.after = false; this.sheet = false; this.callId = null; this.starting = null; this.startedAt = null;
            this.thenConfirm = false; this.clockless = false; this.form = blank();
            try { localStorage.removeItem(LIVE); } catch {}
        },

        confirmOrder() {
            const f = document.getElementById('desk-act');
            if (!f) return;
            f.querySelectorAll('input[name=action]').forEach((i) => i.remove());
            const a = document.createElement('input');
            a.type = 'hidden'; a.name = 'action'; a.value = 'confirm';
            f.appendChild(a);
            f.requestSubmit();
        },

        async save() {
            this.error = '';
            if (!this.form.outcome) { this.error = 'Pick what happened.'; return; }
            this.saving = true;
            try {
                if (!this.callId) await (this.starting || this.started());
                if (!this.callId) { this.error = 'Could not start the call log. Check the connection.'; return; }
                // With the recording or screenshot (multipart, so PATCH rides on POST).
                const fd = window.iqsFormData({ ...this.form, ...window.iqsMinSec(window.iqsCallSeconds(urls.proof, this.clockless ? null : this.startedAt)), _method: 'PATCH' });
                window.iqsAddProof(fd, urls.proof);
                const r = await fetch(urls.finish.replace(/\/0$/, '/' + this.callId), {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token() },
                    body: fd,
                });
                // The call (or its order) no longer exists: stop carrying it, it can never be saved.
                if (r.status === 404) { this.after = false; this.sheet = false; this.callId = null; this.starting = null; try { localStorage.removeItem(LIVE); } catch {} this.error = ''; this.saved = 'This call no longer exists.'; return; }
                const d = await r.json();
                if (!r.ok) { this.error = Object.values(d.errors || {})[0]?.[0] || d.message || 'Could not save.'; return; }
                const outcome = this.form.outcome;
                const confirm = this.thenConfirm && outcome === 'answered';
                if (this.form.outcome === 'answered') this.talked = true;
                this.saved = d.message;
                this.after = false;
                this.clockless = false;
                this.sheet = false;
                this.callId = null;
                this.starting = null;
                this.startedAt = null;
                this.form = blank();
                try { localStorage.removeItem(LIVE); } catch {}
                window.dispatchEvent(new CustomEvent('proof-clear', { detail: urls.proof }));
                this.thenConfirm = false;
                if (confirm) this.confirmOrder();
                else if (outcome === 'no_answer' || outcome === 'busy') document.querySelector('[form=desk-act][name=action][value=no_response]')?.click();
                else if (outcome === 'wrong') window.dispatchEvent(new CustomEvent('desk-cancel-wrong'));
            } catch {
                this.error = 'Could not save. Check the connection.';
            } finally {
                this.saving = false;
            }
        },

        async toggleQr() {
            if (!this.qr) this.qr = await window.iqsQr(this.phone);
            this.showQr = !this.showQr;
            if (this.showQr) this.started(false);
        },

        async copy() {
            try {
                await navigator.clipboard.writeText(this.phone);
                this.copied = true;
            } catch {
                // Clipboard blocked (not focused, older browser): the old way, a hidden text box.
                const t = document.createElement('textarea');
                t.value = this.phone; t.setAttribute('readonly', ''); t.style.position = 'fixed'; t.style.opacity = '0';
                document.body.appendChild(t); t.select();
                try { this.copied = document.execCommand('copy'); } catch { this.copied = false; }
                t.remove();
            }
            setTimeout(() => (this.copied = false), 1500);
            this.started(false);
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
