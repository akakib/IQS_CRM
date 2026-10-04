{{-- Small fixed-option dropdown (no search), pill + chevron + panel style,
     used instead of a native select. options: [value => label]. name goes on
     a hidden input so it submits with a normal form. Emits select-change with
     the picked value. size md = form-field height, sm = compact filter pill. --}}
@props(['options' => [], 'value' => null, 'name' => null, 'dataField' => null, 'size' => 'md', 'fullWidth' => false])

@php
    $optionList = collect($options)->map(fn ($label, $key) => ['value' => (string) $key, 'label' => $label])->values();
@endphp

<div {{ $attributes->merge(['class' => 'relative inline-block']) }}
    x-data="{
        open: false,
        options: {{ Illuminate\Support\Js::from($optionList) }},
        value: {{ Illuminate\Support\Js::from($value === null ? null : (string) $value) }},
        selectedLabel() {
            const match = this.options.find(o => o.value === this.value);
            return match ? match.label : '';
        },
        choose(v) {
            this.value = v;
            this.open = false;
            this.$dispatch('select-change', v);
        },
        setValue(v) {
            this.value = (v === null || v === undefined) ? null : String(v);
        },
    }"
    @click.outside="open = false"
    @keydown.escape="open = false">
    @if ($name || $dataField)
        <input type="hidden" x-ref="hidden" @if ($name) name="{{ $name }}" @endif @if ($dataField) data-field="{{ $dataField }}" @endif :value="value ?? ''">
    @endif

    <button type="button" @click="open = !open"
        @class([
            'w-full' => $fullWidth,
            'flex items-center justify-between gap-1.5 rounded-lg border border-gray-300 bg-white whitespace-nowrap px-3',
            'text-xs py-1.5 text-gray-600' => $size === 'sm',
            'text-sm py-2 text-gray-700' => $size === 'md',
        ])>
        <span x-text="selectedLabel()" class="truncate"></span>
        <svg class="h-3.5 w-3.5 text-gray-400 shrink-0 transition-transform duration-150" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
    </button>

    <div x-show="open" x-cloak
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        class="absolute z-30 mt-1 w-full min-w-[120px] bg-white border border-gray-200 rounded-lg shadow-lg py-1">
        <template x-for="option in options" :key="option.value">
            <button type="button" @click="choose(option.value)"
                @class(['w-full text-left px-3 py-1.5 hover:bg-gray-50', 'text-xs' => $size === 'sm', 'text-sm' => $size === 'md'])
                :class="option.value === value ? 'font-medium text-green-900' : 'text-gray-700'"
                x-text="option.label"></button>
        </template>
    </div>
</div>
