// <x-scan-input>: one box for three ways to read a label.
//   1. A USB / Bluetooth scanner types the code and presses Enter.
//   2. Someone types the code by hand.
//   3. The phone camera reads the barcode (camera button).
// All three end in the same `scan` event, so pages do not care which was used.
//
// Camera: Android Chrome reads barcodes natively (BarcodeDetector). iPhones
// do not, so the ZXing reader is loaded on first use and decodes the video
// frames in the browser. Nothing is sent anywhere.
export default function scanInput({ once = false } = {}) {
    return {
        code: '',
        state: null, // 'ok' | 'bad' flash after the page answers
        message: '',
        good: true,

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
            this.beep(this.good);
            setTimeout(() => (this.state = null), 2500);
        },

        beep(ok) {
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const o = ctx.createOscillator();
                o.frequency.value = ok ? 880 : 220;
                o.connect(ctx.destination);
                o.start();
                setTimeout(() => {
                    o.stop();
                    ctx.close();
                }, ok ? 120 : 400);
            } catch (e) {}
        },

        async startCamera() {
            if (this.starting) return;
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
                this.cameraError = e?.name === 'NotAllowedError' ? 'denied' : 'unavailable';
                this.starting = false;
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
            navigator.vibrate?.(60);
            this.$dispatch('scan', code);
            if (once) this.stopCamera();
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
