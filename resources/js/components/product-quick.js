// Products list: tap a product for its quick edit (each variant's stock, saved at once) and a link to the full form.
// Who may not change stock sees the statuses only; the list behind is refreshed when the sheet closes after a change.
export default ({ url, canStock }) => ({
    item: null,
    canStock,
    busy: false,
    saved: false,
    error: '',
    changed: false,

    open(data) {
        this.item = data;
        this.saved = false;
        this.error = '';
    },

    close() {
        this.item = null;
        if (this.changed) {
            this.changed = false;
            window.dispatchEvent(new CustomEvent('list-refresh'));
        }
    },

    async set(v, status) {
        if (!this.canStock || this.busy || v.status === status) return;
        this.busy = true;
        this.saved = false;
        this.error = '';
        const body = new FormData();
        body.append('_token', document.querySelector('meta[name=csrf-token]').content);
        body.append('ids[]', v.id);
        body.append('status', status);
        try {
            const r = await fetch(url, { method: 'POST', body, headers: { Accept: 'application/json' } });
            if (!r.ok) throw new Error();
            v.status = status;
            this.saved = true;
            this.changed = true;
        } catch (e) {
            this.error = 'Could not save. Check the connection and try again.';
        } finally {
            this.busy = false;
        }
    },
});
