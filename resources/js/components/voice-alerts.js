// <x-voice-alerts>: spoken alerts for moderators, in English, with their own name.
// The page asks the server every 30 seconds what is new for this person and
// says it out loud with the browser's own voice (nothing is sent anywhere):
//   a new order given to them, a No response order back to call,
//   a timer that started on an order they do not have open, two minutes left,
//   new orders waiting while they have room for one.
// What was already said is remembered for the browser tab (sessionStorage), so
// a page reload does not repeat it. The very first check only records the state.
//
// Browsers only allow sound after the person has clicked or typed on the page.
// An alert that arrives before that waits and is said on the next click.
const SEEN_KEY = 'iqs_voice_seen';

export default function voiceAlerts({ url, name, interval = 30000 }) {
    return {
        on: true,
        waiting: false, // an alert is held back until the next click
        queue: [],
        warnTimer: null,

        init() {
            try {
                this.on = localStorage.getItem('iqs_voice') !== 'off';
            } catch (e) {}
            const flush = () => this.flush();
            document.addEventListener('pointerdown', flush, { capture: true });
            document.addEventListener('keydown', flush, { capture: true });
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) this.poll();
            });
            this.poll();
            setInterval(() => this.poll(), interval);
        },

        toggle() {
            this.on = !this.on;
            try {
                localStorage.setItem('iqs_voice', this.on ? 'on' : 'off');
            } catch (e) {}
            if (this.on) {
                this.say(`Voice alerts on, ${name}.`);
            } else {
                this.queue = [];
                this.waiting = false;
                window.speechSynthesis?.cancel();
            }
        },

        load() {
            try {
                return JSON.parse(sessionStorage.getItem(SEEN_KEY));
            } catch (e) {
                return null;
            }
        },

        store(state) {
            try {
                sessionStorage.setItem(SEEN_KEY, JSON.stringify(state));
            } catch (e) {}
        },

        async poll() {
            let d;
            try {
                const r = await fetch(url, { headers: { Accept: 'application/json' } });
                if (!r.ok) return;
                d = await r.json();
            } catch (e) {
                return;
            }
            const open = window.iqsOpenOrder || null; // the order on screen (Order management sets it)
            const prev = this.load();
            const seen = {
                mine: d.mine.map((o) => o.id),
                back: d.returned.map((o) => o.id),
                timed: d.timed?.id ?? null,
                waiting: d.waiting,
                saidWaitingAt: prev?.saidWaitingAt || 0,
                warned: prev?.warned || null,
            };

            if (prev && !d.on_break) {
                const fresh = d.mine.filter((o) => !prev.mine.includes(o.id) && o.id !== open);
                if (fresh.length === 1) this.say(`${name}, you have a new order, ${spell(fresh[0].no)}.`);
                if (fresh.length > 1) this.say(`${name}, you have ${fresh.length} new orders.`);

                d.returned
                    .filter((o) => !prev.back.includes(o.id) && o.id !== open)
                    .forEach((o) => this.say(`${name}, time to call ${spell(o.no)} again.`));

                if (d.timed && d.timed.id !== prev.timed && d.timed.id !== open) {
                    this.say(`${name}, your timer has started on ${spell(d.timed.no)}.`);
                }
                if (d.can_take && d.waiting > prev.waiting && Date.now() - seen.saidWaitingAt > 180000) {
                    this.say(`${name}, new orders are waiting.`);
                    seen.saidWaitingAt = Date.now();
                }
            }

            // Two minutes left: said once per timer, right when it gets there.
            clearTimeout(this.warnTimer);
            if (d.timed && !d.on_break) {
                const key = `${d.timed.id}:${d.timed.due}`;
                if (seen.warned !== key && d.timed.left > 110) {
                    this.warnTimer = setTimeout(() => {
                        this.say(`${name}, two minutes left on ${spell(d.timed.no)}.`);
                        const now = this.load();
                        if (now) this.store({ ...now, warned: key });
                    }, Math.max(0, (d.timed.left - 120) * 1000));
                }
            }
            this.store(seen);
        },

        say(text) {
            if (!this.on || !('speechSynthesis' in window)) return;
            // No click on this page yet: the browser would refuse the sound. Hold it until the next click.
            if (navigator.userActivation && !navigator.userActivation.hasBeenActive) {
                this.queue.push(text);
                this.waiting = true;
                return;
            }
            const u = new SpeechSynthesisUtterance(text);
            u.lang = 'en-US';
            u.rate = 0.95;
            const voice = englishVoice();
            if (voice) u.voice = voice;
            u.onerror = (e) => {
                if (e.error === 'not-allowed') {
                    this.queue.push(text);
                    this.waiting = true;
                }
            };
            window.speechSynthesis.speak(u);
        },

        flush() {
            if (!this.queue.length) return;
            const lines = this.queue;
            this.queue = [];
            this.waiting = false;
            // After this click the browser allows sound.
            setTimeout(() => lines.forEach((l) => this.say(l)), 0);
        },
    };
}

// "IQ10009" read as "I Q, 1 0 0 0 9" instead of "ten thousand and nine".
function spell(orderNo) {
    const s = String(orderNo || '');
    const letters = s.replace(/[^A-Za-z]/g, '').split('').join(' ');
    const digits = s.replace(/\D/g, '').split('').join(' ');

    return [letters, digits].filter(Boolean).join(', ');
}

// A clear English voice when the device has one; otherwise the browser default.
function englishVoice() {
    const voices = window.speechSynthesis.getVoices();
    const preferred = ['Google US English', 'Microsoft Aria', 'Microsoft Jenny', 'Samantha', 'Google UK English Female'];

    return (
        voices.find((v) => preferred.some((p) => v.name.startsWith(p))) ||
        voices.find((v) => v.lang === 'en-US') ||
        voices.find((v) => v.lang?.startsWith('en')) ||
        null
    );
}
