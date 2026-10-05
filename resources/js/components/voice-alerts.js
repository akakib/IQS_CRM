// <x-voice-alerts>: spoken alerts for moderators, in English, with their own name.
// The page asks the server every 30 seconds what is new for this person and
// says it out loud with the browser's own voice (nothing is sent anywhere):
//   two minutes left on the current order, a timer that started by itself on an
//   order they never opened, a new order given to them, a No response order back
//   to call, new orders waiting while they have room for one. No order numbers.
//   Nothing is said for what they just did themselves (Take next, opening an order).
// What was already said is remembered for the browser tab (sessionStorage), so
// a page reload does not repeat it. The very first check only records the state.
//
// Voice: each device has different voices. The speaker menu lists the ones that
// fit (English, plus Bangla and Hindi voices, which read English with a South
// Asian accent) and remembers the choice per browser. Without a choice, an
// Indian accent is used: an English (India) voice, else a Hindi voice reading English.
//
// Browsers only allow sound after the person has clicked or typed on the page.
// An alert that arrives before that waits and is said on the next click.
const SEEN_KEY = 'iqs_voice_seen';
const PICK_KEY = 'iqs_voice_choice2'; // renamed so earlier picks (Hindi, Bangla) fall back to the Indian English default
const GENDER_KEY = 'iqs_voice_gender';

export default function voiceAlerts({ url, name, voice = true, interval = 30000 }) {
    return {
        name,
        on: true,
        menu: false,
        waiting: false, // an alert is held back until the next click
        queue: [],
        voices: [],
        pick: '',
        gender: 'male',
        warnTimer: null,

        init() {
            try {
                this.on = localStorage.getItem('iqs_voice') !== 'off';
                this.pick = localStorage.getItem(PICK_KEY) || '';
                this.gender = localStorage.getItem(GENDER_KEY) || 'male';
            } catch (e) {}
            const flush = () => this.flush();
            document.addEventListener('pointerdown', flush, { capture: true });
            document.addEventListener('keydown', flush, { capture: true });
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) this.poll();
            });
            if ('speechSynthesis' in window) {
                this.loadVoices();
                window.speechSynthesis.addEventListener?.('voiceschanged', () => this.loadVoices());
            }
            this.poll();
            setInterval(() => this.poll(), interval);
        },

        loadVoices() {
            this.voices = usableVoices().map((v) => ({ name: v.name, lang: v.lang, label: voiceLabel(v) }));
        },

        get current() {
            return chosenVoice(this.pick, this.gender)?.name || '';
        },

        setOn(value) {
            this.on = value;
            try {
                localStorage.setItem('iqs_voice', this.on ? 'on' : 'off');
            } catch (e) {}
            if (!this.on) {
                this.queue = [];
                this.waiting = false;
                window.speechSynthesis?.cancel();
            }
        },

        // Male / female: the best-sounding Indian English voice of that kind on this device.
        setGender(g) {
            this.gender = g;
            this.pick = '';
            try {
                localStorage.setItem(GENDER_KEY, g);
                localStorage.removeItem(PICK_KEY);
            } catch (e) {}
            this.test();
        },

                choose(voiceName) {
            this.pick = voiceName;
            try {
                localStorage.setItem(PICK_KEY, voiceName);
            } catch (e) {}
            this.test();
        },

        test() {
            // No cancel() here: on iPhone a cancel right before speaking drops the new line.
            this.say(`${name}, two minutes left on current order.`, true);
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

            // Order management listens: a new order in hand shows up without a reload.
            if (prev) {
                const appeared = [...d.mine.filter((o) => !prev.mine.includes(o.id)), ...d.returned.filter((o) => !prev.back.includes(o.id))].filter((o) => o.id !== open);
                if (appeared.length) window.dispatchEvent(new CustomEvent('desk-new-order', { detail: appeared[0] }));
            }

            if (prev && !d.on_break) {
                // A timer they did not start themselves: the order sat unopened for 15 minutes and its clock began.
                // (Take next or opening an order starts the clock on the order on screen: no voice, they know.)
                if (d.timed && d.timed.id !== prev.timed && d.timed.id !== open) {
                    this.say(`${name}, your time has started. Open your order now.`);
                } else if (d.mine.some((o) => !prev.mine.includes(o.id) && o.id !== open)) {
                    this.say(`${name}, you have a new order.`); // given to them, not opened yet
                }
                if (d.returned.some((o) => !prev.back.includes(o.id) && o.id !== open)) {
                    this.say(`${name}, time to call a customer again.`);
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
                        this.say(`${name}, two minutes left on current order.`);
                        const now = this.load();
                        if (now) this.store({ ...now, warned: key });
                    }, Math.max(0, (d.timed.left - 120) * 1000));
                }
            }
            this.store(seen);
        },

        say(text, evenWhenOff = false) {
            if (!voice || (!this.on && !evenWhenOff) || !('speechSynthesis' in window)) return;
            // Said right away; if the browser refuses (no click yet), the line is held for the next click (onerror below).
            const u = new SpeechSynthesisUtterance(text);
            // (Not named "voice": that is the on/off switch from the server, read on the first line above.)
            const picked = chosenVoice(this.pick, this.gender);
            u.lang = picked?.lang || 'en-IN';
            if (picked) u.voice = picked;
            u.rate = 0.92;
            u.onerror = (e) => {
                if (e.error === 'not-allowed') {
                    this.queue.push(text);
                    this.waiting = true;
                }
            };
            // iPhone: keep a reference (an unreferenced utterance can be dropped before it plays) and wake a stuck queue.
            window.__iqsUtterance = u;
            window.speechSynthesis.resume?.();
            window.speechSynthesis.speak(u);
        },

        flush() {
            if (!this.queue.length) return;
            const lines = this.queue;
            this.queue = [];
            this.waiting = false;
            // After this click the browser allows sound.
            // Spoken right inside the tap: iPhone only allows speech that starts in the tap itself.
            lines.forEach((l) => this.say(l));
        },
    };
}

// English voices only (Indian English first). The device decides which exist.
function usableVoices() {
    const all = 'speechSynthesis' in window ? window.speechSynthesis.getVoices() : [];
    const rank = (v) => (v.lang === 'en-IN' ? 0 : v.lang === 'en-GB' ? 1 : 2) * 10 - quality(v);

    return all.filter((v) => /^en/i.test(v.lang)).sort((a, b) => rank(a) - rank(b) || a.name.localeCompare(b.name));
}

// Higher = sounds more natural: downloaded "Enhanced"/"Premium" voices (iPhone, Mac),
// "Natural"/"Online" voices (Edge), Google's network voices.
function quality(v) {
    return /premium/i.test(v.name) ? 3 : /enhanced|natural|neural/i.test(v.name) ? 2 : /online|google/i.test(v.name) ? 1 : 0;
}

// Known Indian English voices by gender: iPhone (Rishi, Veena), Edge (Prabhat, Aarav, Kunal, Rehaan / Neerja, Aashi, Ananya, Kavya),
// Windows (Ravi / Heera).
const MALE = /\b(Rishi|Prabhat|Aarav|Kunal|Rehaan|Ravi|Hemant|Madhur)\b/i;
const FEMALE = /\b(Veena|Neerja|Aashi|Ananya|Kavya|Heera|Lekha|Isha)\b/i;

export function genderOf(v) {
    return MALE.test(v.name) ? 'male' : FEMALE.test(v.name) ? 'female' : null;
}

function voiceLabel(v) {
    const where = { 'en-IN': 'India', 'en-GB': 'UK', 'en-US': 'US', 'en-AU': 'Australia' }[v.lang] || v.lang;
    const short = v.name.replace(/^(Microsoft|Google)\s+/, '').replace(/\s*-\s*.*$/, '');
    const g = genderOf(v);

    return `${short} · ${where}${g ? ' · ' + (g === 'male' ? 'Male' : 'Female') : ''}`;
}

// The voice picked in this browser; otherwise the best-sounding Indian English voice of the chosen gender,
// then any Indian English voice, then UK, then any English.
function chosenVoice(pick, gender = 'male') {
    const voices = usableVoices();
    const indian = voices.filter((v) => v.lang === 'en-IN');

    return (
        (pick && voices.find((v) => v.name === pick)) ||
        indian.find((v) => genderOf(v) === gender) ||
        indian[0] ||
        voices.find((v) => v.lang === 'en-GB') ||
        voices[0] ||
        null
    );
}
