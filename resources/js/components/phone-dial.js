// <x-phone-dial>: copy the number, or show a QR code so a PC user can scan
// it with their phone and call. The QR library loads only when first opened.
export default function phoneDial(phone) {
    return {
        phone,
        qr: null,
        showQr: false,
        copied: false,

        async toggleQr() {
            if (!this.qr) {
                const { default: qrcode } = await import('qrcode-generator');
                const code = qrcode(0, 'M');
                code.addData('tel:' + this.phone);
                code.make();
                this.qr = code.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
            }
            this.showQr = !this.showQr;
        },

        async copy() {
            try {
                await navigator.clipboard.writeText(this.phone);
            } catch {
                const el = document.createElement('textarea');
                el.value = this.phone;
                document.body.appendChild(el);
                el.select();
                document.execCommand('copy');
                el.remove();
            }
            this.copied = true;
            setTimeout(() => (this.copied = false), 1500);
        },
    };
}
