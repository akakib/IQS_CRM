// In-page tabs for <x-tabs> with '#key' URLs: one panel at a time, the
// address keeps #key so a reload or a shared link opens the same tab.
export default function pageTabs(initial) {
    return {
        tab: initial,
        init() {
            const fromHash = location.hash.slice(1);
            if (fromHash && this.$root.querySelector(`a[href="#${CSS.escape(fromHash)}"]`)) this.tab = fromHash;
        },
        go(key) {
            this.tab = key;
            history.replaceState(null, '', '#' + key);
        },
    };
}
