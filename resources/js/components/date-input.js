// Calendar date picker used by <x-date-input>. Works with plain Y-m-d strings.
export default function dateInput({ value = null, min = null, max = null, placeholder = 'Select date' }) {
    const parse = (iso) => (iso ? new Date(iso + 'T00:00:00') : null);
    // Not d.toISOString(): that converts to UTC first and moves the date a
    // day back in Bangladesh (UTC+6). Build Y-m-d from local parts instead.
    const toIso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    const initial = parse(value) ?? new Date();

    return {
        open: false,
        value,
        min,
        max,
        placeholder,
        viewYear: initial.getFullYear(),
        viewMonth: initial.getMonth(),

        init() {
            // A parent can set the value (x-model, presets): show that month.
            this.$watch('value', (v) => {
                const d = parse(v);
                if (d) {
                    this.viewYear = d.getFullYear();
                    this.viewMonth = d.getMonth();
                }
            });
        },

        displayLabel() {
            const d = parse(this.value);
            return d ? d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : this.placeholder;
        },

        isDisabled(day) {
            const d = toIso(new Date(this.viewYear, this.viewMonth, day));
            return (this.min && d < this.min) || (this.max && d > this.max);
        },

        monthLabel() {
            return new Date(this.viewYear, this.viewMonth, 1).toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
        },

        leadingBlanks() {
            return new Date(this.viewYear, this.viewMonth, 1).getDay();
        },

        daysInMonth() {
            return new Date(this.viewYear, this.viewMonth + 1, 0).getDate();
        },

        prevMonth() {
            this.viewMonth--;
            if (this.viewMonth < 0) {
                this.viewMonth = 11;
                this.viewYear--;
            }
        },

        nextMonth() {
            this.viewMonth++;
            if (this.viewMonth > 11) {
                this.viewMonth = 0;
                this.viewYear++;
            }
        },

        isSelected(day) {
            const d = parse(this.value);
            return !!d && d.getFullYear() === this.viewYear && d.getMonth() === this.viewMonth && d.getDate() === day;
        },

        isToday(day) {
            const now = new Date();
            return now.getFullYear() === this.viewYear && now.getMonth() === this.viewMonth && now.getDate() === day;
        },

        pick(day) {
            if (this.isDisabled(day)) return;
            this.value = toIso(new Date(this.viewYear, this.viewMonth, day));
            this.open = false;
            this.$dispatch('date-change', this.value);
        },

        pickToday() {
            const now = new Date();
            this.viewYear = now.getFullYear();
            this.viewMonth = now.getMonth();
            this.pick(now.getDate());
        },

        clear() {
            this.value = null;
            this.open = false;
            this.$dispatch('date-change', null);
        },

        toggle() {
            this.open = !this.open;
            if (this.open) this.position();
        },

        // Desktop: the panel is position:fixed (never clipped by a card or a
        // scrolling table). Centre it under the trigger, keep it on screen,
        // open upwards when there is no room below. Phones: centred by CSS
        // (.picker-panel in app.css).
        position() {
            if (window.innerWidth < 640) return;
            this.$nextTick(() => {
                const panel = this.$refs.panel;
                const rect = this.$root.getBoundingClientRect();
                const margin = 8;
                if (!panel) return;

                let left = rect.left + rect.width / 2 - panel.offsetWidth / 2;
                left = Math.max(margin, Math.min(left, window.innerWidth - panel.offsetWidth - margin));
                panel.style.left = `${left}px`;

                const below = window.innerHeight - rect.bottom;
                panel.style.top = below < panel.offsetHeight + margin && rect.top > below
                    ? `${rect.top - panel.offsetHeight - 4}px`
                    : `${rect.bottom + 4}px`;
            });
        },
    };
}
