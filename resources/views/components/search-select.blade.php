{{-- Server-side searchable dropdown for big lists (7,000 products, customers).
     Typing (debounced 250 ms) calls `url?q=…`, which returns at most ~20
     [{value, label, sub?}]. Keyboard: ↑ ↓ Enter Esc. Emits select-change.
     <x-search-select name="variant_id" :url="route('products.search')" :value="$id" :label="$name" /> --}}
@props(['name', 'url', 'value' => null, 'label' => null, 'placeholder' => __('Type to search…')])

<div {{ $attributes->merge(['class' => 'relative']) }}
    x-data="{
        open: false, q: '', items: [], active: 0, loading: false, timer: null,
        value: @js($value), label: @js($label),
        search() {
            clearTimeout(this.timer);
            this.timer = setTimeout(async () => {
                this.loading = true;
                try {
                    const r = await fetch(@js($url) + (@js($url).includes('?') ? '&' : '?') + 'q=' + encodeURIComponent(this.q), { headers: { Accept: 'application/json' } });
                    this.items = r.ok ? await r.json() : [];
                } catch (e) { this.items = []; }
                this.active = 0; this.loading = false;
            }, 250);
        },
        openPanel() { this.open = true; this.$nextTick(() => this.$refs.q.focus()); if (!this.items.length) this.search(); },
        choose(item) {
            if (!item) return;
            this.value = item.value; this.label = item.label; this.open = false; this.q = '';
            this.$dispatch('select-change', item.value);
        },
        clear() { this.value = null; this.label = null; this.$dispatch('select-change', null); },
    }"
    @click.outside="open = false" @keydown.escape="open = false">
    <input type="hidden" name="{{ $name }}" :value="value ?? ''">

    <button type="button" @click="openPanel()" class="flex w-full items-center justify-between gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-left text-sm">
        <span class="truncate" :class="label ? 'text-gray-800' : 'text-gray-400'" x-text="label || @js($placeholder)"></span>
        <span class="flex items-center gap-1">
            <span x-show="value" @click.stop="clear()" class="px-1 text-gray-400 hover:text-red-600" aria-label="{{ __('Clear') }}">&times;</span>
            <svg class="h-3.5 w-3.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
        </span>
    </button>

    <div x-show="open" x-cloak class="absolute z-30 mt-1 w-full rounded-lg border border-gray-200 bg-white shadow-lg">
        <div class="border-b border-gray-100 p-2">
            <input x-ref="q" x-model="q" @input="search()" type="search" placeholder="{{ $placeholder }}"
                @keydown.arrow-down.prevent="active = Math.min(active + 1, items.length - 1)"
                @keydown.arrow-up.prevent="active = Math.max(active - 1, 0)"
                @keydown.enter.prevent="choose(items[active])"
                class="w-full rounded-md border border-gray-300 px-2.5 py-1.5 text-sm focus:border-green-800 focus:outline-none">
        </div>
        <div class="max-h-64 overflow-y-auto py-1">
            <p x-show="loading" class="px-3 py-2 text-xs text-gray-400">{{ __('Searching…') }}</p>
            <p x-show="!loading && !items.length" class="px-3 py-2 text-xs text-gray-400">{{ __('No match.') }}</p>
            <template x-for="(item, i) in items" :key="item.value">
                <button type="button" @click="choose(item)" @mouseenter="active = i"
                    class="block w-full px-3 py-1.5 text-left text-sm" :class="i === active ? 'bg-green-50 text-green-900' : 'text-gray-700'">
                    <span x-text="item.label"></span>
                    <span x-show="item.sub" class="block text-xs text-gray-400" x-text="item.sub"></span>
                </button>
            </template>
        </div>
    </div>
</div>
