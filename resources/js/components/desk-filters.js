// Order management: search and the owner's filters (everyone or mine, dates, person, channel) change the tabs,
// the list and the open order in place, without reloading the page; the address keeps the same link, so Back,
// refresh and the live updates go on working. If the fetch fails, the page is loaded the usual way.
export default ({ range, who, base }) => ({
    sheet: false,
    range,
    who,
    n: 0,
    pending: null,

    init() {
        this.count();
    },

    fields() {
        const data = new FormData(this.$refs.f);
        const out = new URLSearchParams();
        data.forEach((v, k) => {
            if (v === '' || (k === 'from' || k === 'to') && data.get('range') !== 'custom' || k === 'moderator' && data.get('who') === 'mine') return;
            out.append(k, v);
        });
        return out;
    },

    count() {
        const f = this.fields();
        this.n = ['moderator', 'channel'].filter((k) => f.get(k)).length; // what sits behind the phone's Filters button
    },

    setWho(w) {
        this.who = w;
        this.$nextTick(() => this.go());
    },

    async go() {
        // The path only: the page's own address decides the host, whatever the app's configured address says.
        const url = new URL(base, location.origin).pathname + '?' + this.fields().toString();
        this.count();
        this.pending?.abort();
        this.pending = new AbortController();
        let doc;
        try {
            const r = await fetch(url, { signal: this.pending.signal, headers: { Accept: 'text/html' }, credentials: 'same-origin' });
            if (!r.ok || r.redirected) throw new Error();
            doc = new DOMParser().parseFromString(await r.text(), 'text/html');
        } catch (e) {
            if (e.name !== 'AbortError') location.href = url;
            return;
        }
        // Someone typing in the open order (a note, a reason) keeps it; otherwise it follows the list.
        const active = document.activeElement;
        const typing = active && ['INPUT', 'TEXTAREA'].includes(active.tagName) && active.value && document.getElementById('desk-detail')?.contains(active);
        for (const id of ['desk-tabs', 'desk-aside', ...(typing ? [] : ['desk-detail'])]) {
            const fresh = doc.getElementById(id), here = document.getElementById(id);
            if (fresh && here) here.innerHTML = fresh.innerHTML;
        }
        if (!typing) {
            const m = doc.documentElement.innerHTML.match(/window\.iqsOpenOrder = (\d+|null); window\.iqsBusy = (true|false)/);
            if (m) { window.iqsOpenOrder = m[1] === 'null' ? null : Number(m[1]); window.iqsBusy = m[2] === 'true'; }
        }
        try { history.replaceState(history.state, '', url); } catch (e) {}
    },
});
