// Index-page filter bar: the list follows the search box while typing (a short pause after the last key),
// without reloading the page, so the box keeps focus and no key is lost. Only the part of the page around
// the bar is swapped; the address bar gets the same link, so Back, refresh and sharing still work.
// Enter and the Search button still send the form the usual way.
export default () => ({
    sheet: false,
    timer: null,
    pending: null,

    typed() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.refresh(), 400);
    },

    submitNow() {
        clearTimeout(this.timer);
        this.pending?.abort();
        this.$refs.filters.requestSubmit();
    },

    async refresh(force = false) {
        const form = this.$refs.filters;
        const url = new URL(form.action, location.origin);
        new FormData(form).forEach((v, k) => { if (v !== '') url.searchParams.append(k, v); });
        if (url.href === location.href && !force) return;

        this.pending?.abort();
        this.pending = new AbortController();
        let html;
        try {
            const r = await fetch(url, { signal: this.pending.signal, headers: { Accept: 'text/html' }, credentials: 'same-origin' });
            if (!r.ok || r.redirected) { form.requestSubmit(); return; } // signed out, an error: the normal way shows why
            html = await r.text();
        } catch (e) {
            if (e.name !== 'AbortError') form.requestSubmit();
            return;
        }

        const fresh = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-list-bar]');
        const box = form.parentElement;
        if (!fresh || !box) { form.requestSubmit(); return; }

        // Everything beside the bar (count, table, cards, pager) is replaced; the bar itself, with the typing in it, stays.
        [...box.children].forEach((el) => { if (el !== form) el.remove(); });
        let before = true;
        [...fresh.parentElement.children].forEach((el) => {
            if (el === fresh) { before = false; return; }
            const copy = document.importNode(el, true);
            before ? box.insertBefore(copy, form) : box.appendChild(copy);
        });
        history.replaceState(history.state, '', url);
    },
});
