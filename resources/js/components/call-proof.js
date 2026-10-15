// <x-call-proof>: the one box for a call's recording or call-log screenshot, everywhere a call is saved.
// A file from an earlier day (by its name, else its time on the phone) needs "It is the right file" ticked.
// Tells the page with a "proof-changed" event: { id, name } where name is empty until the file can be used.
const DATE_IN_NAME = /(?<!\d)(20\d\d)[-_.]?(0[1-9]|1[0-2])[-_.]?(0[1-9]|[12]\d|3[01])/;
const FRESH_HOURS = 12;

export function fileDate(name, lastModified) {
    const m = name.replace(/\.[^.]+$/, '').match(DATE_IN_NAME);
    if (m) return { when: new Date(+m[1], +m[2] - 1, +m[3]), dayOnly: true };
    return lastModified ? { when: new Date(lastModified), dayOnly: false } : null;
}

export default function callProof(id) {
    return {
        file: '',
        old: '',
        ok: false,
        modified: '',

        init() {
            window.addEventListener('proof-clear', (e) => { if (e.detail === id) this.reset(); });
        },

        pick(e) {
            const f = e.target.files[0];
            this.ok = false;
            this.file = f ? f.name : '';
            this.modified = f && f.lastModified ? String(f.lastModified) : '';
            this.old = '';
            const d = f ? fileDate(f.name, f.lastModified) : null;
            if (d) {
                const today = new Date(); today.setHours(0, 0, 0, 0);
                const stale = d.dayOnly ? d.when < today : d.when < new Date(Date.now() - FRESH_HOURS * 3600000);
                if (stale) this.old = d.when.toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
            }
            this.emit();
        },

        ready() { return this.file && (!this.old || this.ok); },

        emit() { this.$dispatch('proof-changed', { id, name: this.ready() ? this.file : '' }); },

        reset() {
            this.file = ''; this.old = ''; this.ok = false; this.modified = '';
            const input = this.$root.querySelector('input[type=file]');
            if (input) input.value = '';
            this.emit();
        },
    };
}

// For the boxes saved by fetch: puts the picked file (and what goes with it) into the FormData.
window.iqsAddProof = (fd, id) => {
    const root = document.getElementById(id);
    const f = root?.querySelector('input[type=file]')?.files[0];
    if (!f) return;
    fd.append('proof', f);
    if (f.lastModified) fd.append('proof_modified', String(f.lastModified));
    if (root.querySelector('input[name=proof_old_ok]')?.checked) fd.append('proof_old_ok', '1');
};

// A plain object as FormData (empty values left out), for the boxes saved by fetch.
window.iqsFormData = (obj) => {
    const fd = new FormData();
    Object.entries(obj).forEach(([k, v]) => { if (v !== null && v !== undefined && v !== '') fd.append(k, v); });
    return fd;
};
