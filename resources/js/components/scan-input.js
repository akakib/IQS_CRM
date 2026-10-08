// <x-scan-input>: one box for three ways to read a label.
//   1. A USB / Bluetooth scanner types the code and presses Enter.
//   2. Someone types the code by hand.
//   3. The phone camera reads the barcode (camera button).
// All three end in the same `scan` event, so pages do not care which was used.
//
// Camera: Android Chrome reads barcodes natively (BarcodeDetector). iPhones
// do not, so the ZXing reader is loaded on first use and decodes the video
// frames in the browser. Nothing is sent anywhere.
/*
 * remember: a name for "camera mode" (e.g. 'packaging'). Once the camera is
 * opened by hand it comes back by itself after each piece of work (the page
 * reloads), until the person presses Close or nothing is left (left = 0).
 * With remember, a read closes the camera only when the page says so (the
 * result has no keep: true), so a refused label lets the next one be scanned.
 */
export default function scanInput({ once = false, remember = null, left = null, doneMessage = '' } = {}) {
    const key = remember ? 'scan-camera:' + remember : null;
    const store = {
        get: () => { try { return key && sessionStorage.getItem(key) === '1' } catch (e) { return false } },
        set: (on) => { try { if (key) on ? sessionStorage.setItem(key, '1') : sessionStorage.removeItem(key) } catch (e) {} },
    };

    return {
        cameraMode: false,
        needsTap: false,

        init() {
            if (!store.get()) return;
            if (left !== null && left <= 0) {
                // The last parcel is done: camera mode ends by itself.
                store.set(false);
                if (doneMessage) setTimeout(() => window.toast?.(doneMessage), 300);
                return;
            }
            this.cameraMode = true;
            setTimeout(() => this.startCamera(true), 250);
        },
        code: '',
        state: null, // 'ok' | 'bad' flash after the page answers
        message: '',
        good: true,
        level: null, // the page's kind of result (ok, red, orange, cod...): colours the camera bar the same as the page

        camera: false,
        cameraError: null,
        starting: false,
        torch: false,
        torchOk: false,
        stream: null,
        controls: null,
        timer: null,
        lastCode: '',
        lastAt: 0,

        submit() {
            const c = this.code.trim();
            this.code = '';
            if (c) this.$dispatch('scan', c);
        },

        // The page answered a scan: flash, beep, and (camera) show the result over the video.
        result(detail) {
            this.good = !!detail.ok;
            this.state = this.good ? 'ok' : 'bad';
            this.message = detail.message || '';
            this.level = detail.level || (this.good ? 'ok' : 'red');
            // Camera mode: close only to show the page's work (a checklist); a refused label keeps it open for the next.
            if (remember && once && this.camera && !detail.keep) this.stopCamera();
            this.beep(this.good);
            setTimeout(() => (this.state = null), 2500);
        },

        // Two sounds nobody can mix up: OK = a short bright "ding-ding" going up;
        // problem = a low buzz, twice. Phones also vibrate differently.
        beep(ok) {
            try {
                const ctx = (window.__iqsAudio ??= new (window.AudioContext || window.webkitAudioContext)());
                if (ctx.state === 'suspended') ctx.resume();
                const tone = (freq, start, length, type, volume) => {
                    const o = ctx.createOscillator();
                    const g = ctx.createGain();
                    o.type = type;
                    o.frequency.value = freq;
                    const t = ctx.currentTime + start;
                    g.gain.setValueAtTime(0.0001, t);
                    g.gain.exponentialRampToValueAtTime(volume, t + 0.01);
                    g.gain.exponentialRampToValueAtTime(0.0001, t + length);
                    o.connect(g).connect(ctx.destination);
                    o.start(t);
                    o.stop(t + length + 0.02);
                };
                if (ok) {
                    tone(1046, 0, 0.12, 'sine', 0.35);
                    tone(1568, 0.13, 0.18, 'sine', 0.35);
                } else {
                    tone(150, 0, 0.22, 'square', 0.25);
                    tone(150, 0.3, 0.32, 'square', 0.25);
                }
            } catch (e) {}
            navigator.vibrate?.(ok ? 60 : [120, 80, 220]);
        },

        async startCamera(auto = false) {
            if (this.starting) return;
            if (!auto && remember) {
                store.set(true); // opened by hand: camera mode on
                this.cameraMode = true;
            }
            this.needsTap = false;
            this.camera = true;
            this.cameraError = null;
            this.starting = true;
            await this.$nextTick();
            const video = this.$refs.video;

            if (!navigator.mediaDevices?.getUserMedia) {
                this.cameraError = 'unsupported';
                this.starting = false;
                return;
            }
            try {
                this.stream = await navigator.mediaDevices.getUserMedia({
                    audio: false,
                    video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } },
                });
            } catch (e) {
                this.starting = false;
                if (auto) {
                    // Opening by itself was not allowed (some phones want a tap): offer one big button instead.
                    this.camera = false;
                    this.needsTap = true;
                    return;
                }
                this.cameraError = e?.name === 'NotAllowedError' ? 'denied' : 'unavailable';
                return;
            }
            video.srcObject = this.stream;
            try {
                await video.play();
            } catch (e) {}
            this.torchOk = !!this.stream.getVideoTracks()[0]?.getCapabilities?.().torch;
            this.starting = false;

            // Native reader where the browser has one for Code 128.
            if ('BarcodeDetector' in window) {
                try {
                    if ((await window.BarcodeDetector.getSupportedFormats()).includes('code_128')) {
                        const detector = new window.BarcodeDetector({ formats: ['code_128'] });
                        const loop = async () => {
                            if (!this.camera) return;
                            try {
                                const found = await detector.detect(video);
                                if (found[0]) this.found(found[0].rawValue);
                            } catch (e) {}
                            this.timer = setTimeout(loop, 150);
                        };
                        loop();
                        return;
                    }
                } catch (e) {}
            }

            // Everything else (iPhone, Firefox…): ZXing, loaded only now.
            try {
                const reader = await this.zxing();
                this.controls = await reader.decodeFromVideoElement(video, (result) => {
                    if (result) this.found(result.getText());
                });
            } catch (e) {
                this.cameraError = 'unavailable';
            }
        },

        async zxing() {
            const [{ BrowserMultiFormatReader }, { DecodeHintType, BarcodeFormat }] = await Promise.all([import('@zxing/browser'), import('@zxing/library')]);
            const hints = new Map();
            hints.set(DecodeHintType.POSSIBLE_FORMATS, [BarcodeFormat.CODE_128]);
            hints.set(DecodeHintType.TRY_HARDER, true);

            return new BrowserMultiFormatReader(hints, { delayBetweenScanAttempts: 120 });
        },

        found(text) {
            const code = String(text).trim().toUpperCase();
            const now = Date.now();
            // The camera sees the same label many times a second: count it once.
            if (!code || (code === this.lastCode && now - this.lastAt < 3000)) return;
            this.lastCode = code;
            this.lastAt = now;
            this.$dispatch('scan', code);
            if (once && !remember) this.stopCamera();
        },

        // The Close button: the person stops, so camera mode ends too.
        closeCamera() {
            store.set(false);
            this.cameraMode = false;
            this.stopCamera();
        },

        stopCamera() {
            this.camera = false;
            this.torch = false;
            clearTimeout(this.timer);
            try {
                this.controls?.stop();
            } catch (e) {}
            this.controls = null;
            this.stream?.getTracks().forEach((t) => t.stop());
            this.stream = null;
        },

        async toggleTorch() {
            const track = this.stream?.getVideoTracks()[0];
            if (!track) return;
            this.torch = !this.torch;
            try {
                await track.applyConstraints({ advanced: [{ torch: this.torch }] });
            } catch (e) {
                this.torch = false;
                this.torchOk = false;
            }
        },

        // Self-check used on the components page: can the reader decode the barcode we print?
        async decodeSvg(svgElement) {
            const reader = await this.zxing();
            const url = URL.createObjectURL(new Blob([new XMLSerializer().serializeToString(svgElement)], { type: 'image/svg+xml' }));
            try {
                return (await reader.decodeFromImageUrl(url)).getText();
            } finally {
                URL.revokeObjectURL(url);
            }
        },
    };
}
