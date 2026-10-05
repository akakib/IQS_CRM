// <x-order-presence>: who else has this order open, and who is editing it.
// Checks in every 8 seconds. mode "view" (Order management, the activity popup)
// or "edit" (the edit form). While someone else edits:
//   - view screens show a yellow bar and their action buttons (.lockable) are off;
//   - an edit form shows the bar and its Save is off; a manager can take over.
// When the other person is done, the page reloads so nobody works on old data.
export default function orderPresence({ url, mode, me }) {
    return {
        others: [],
        editor: null,
        editing: false,
        timer: null,

        init() {
            this.checkIn();
            this.timer = setInterval(() => this.checkIn(), 8000);
            const leave = () => {
                const body = new FormData();
                body.append('_token', document.querySelector('meta[name=csrf-token]').content);
                body.append('mode', 'leave');
                navigator.sendBeacon?.(url, body);
            };
            window.addEventListener('pagehide', leave);
        },

        async checkIn(takeOver = false) {
            let d;
            try {
                const r = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify({ mode, take_over: takeOver }),
                });
                if (!r.ok) return;
                d = await r.json();
            } catch (e) {
                return;
            }
            const wasBlocked = !!this.editor;
            this.others = d.others;
            this.editor = d.editor && d.editor.id !== me ? d.editor : null;
            this.editing = d.editing;
            document.documentElement.toggleAttribute('data-order-locked', !!this.editor);
            // The other person finished (or left): show what they saved.
            if (wasBlocked && !this.editor) window.location.reload();
        },

        takeOver() {
            this.checkIn(true);
        },

        get viewers() {
            return this.others.filter((o) => !this.editor || o.id !== this.editor.id).map((o) => o.name);
        },
    };
}
