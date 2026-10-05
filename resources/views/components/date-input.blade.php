{{-- Calendar date picker (replaces the browser's native date box).
     <x-date-input name="paid_on" :value="today()->toDateString()" size="md" full-width />
     name: renders a hidden input so the date submits with the form.
     Inside another Alpine scope it also works with x-model="someVar".
     clearable=false hides Clear for required fields. Fires "date-change". --}}
@props(['value' => null, 'min' => null, 'max' => null, 'placeholder' => null, 'name' => null, 'clearable' => true, 'size' => 'md', 'fullWidth' => false])

<div {{ $attributes->merge(['class' => ($fullWidth ? 'relative block w-full' : 'relative inline-block')]) }}
    x-data="dateInput({ value: {{ \Illuminate\Support\Js::from($value ?: null) }}, min: {{ \Illuminate\Support\Js::from($min) }}, max: {{ \Illuminate\Support\Js::from($max) }}, placeholder: {{ \Illuminate\Support\Js::from($placeholder ?? __('Select date')) }} })"
    x-modelable="value"
    @click.outside="open = false" @keydown.escape="open = false">
    @if ($name)
        <input type="hidden" name="{{ $name }}" :value="value || ''">
    @endif
    <button type="button" @click="toggle()"
        @class([
            'flex items-center gap-2 whitespace-nowrap rounded-lg border border-gray-300 bg-white text-left focus:border-primary focus:outline-none',
            'w-full' => $fullWidth,
            'px-2.5 py-1.5 text-xs' => $size === 'sm',
            'px-3 py-2 text-sm' => $size === 'md',
        ])
        :class="value ? 'text-gray-800' : 'text-gray-400'">
        <svg class="h-4 w-4 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3M16 7V3M7 11h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
        </svg>
        <span x-text="displayLabel()"></span>
    </button>

    <div x-show="open" x-cloak x-ref="panel" x-transition.opacity.duration.100ms
        class="picker-panel fixed z-50 w-64 max-w-[85vw] rounded-xl border border-gray-200 bg-white p-3 shadow-lg">
        <div class="mb-2 flex items-center justify-between">
            <button type="button" @click="prevMonth()" class="rounded px-2 py-0.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700" aria-label="{{ __('Previous month') }}">&larr;</button>
            <span class="text-sm font-medium text-gray-700" x-text="monthLabel()"></span>
            <button type="button" @click="nextMonth()" class="rounded px-2 py-0.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700" aria-label="{{ __('Next month') }}">&rarr;</button>
        </div>
        <div class="mb-1 grid grid-cols-7 gap-0.5 text-center text-[11px] text-gray-400">
            <div>Su</div><div>Mo</div><div>Tu</div><div>We</div><div>Th</div><div>Fr</div><div>Sa</div>
        </div>
        <div class="grid grid-cols-7 gap-0.5">
            <template x-for="n in leadingBlanks()"><div></div></template>
            <template x-for="day in daysInMonth()" :key="day">
                <button type="button" @click="pick(day)" :disabled="isDisabled(day)" x-text="day"
                    class="mx-auto flex h-8 w-8 items-center justify-center rounded-full text-xs"
                    :class="isDisabled(day) ? 'cursor-not-allowed text-gray-300' : (isSelected(day) ? 'bg-primary text-white' : (isToday(day) ? 'font-semibold text-primary hover:bg-primary-soft' : 'text-gray-700 hover:bg-gray-100'))"></button>
            </template>
        </div>
        <div class="mt-2 flex items-center justify-between border-t border-gray-100 pt-2">
            <button type="button" @click="pickToday()" class="text-xs font-medium text-primary hover:underline">{{ __('Today') }}</button>
            @if ($clearable)
                <button type="button" @click="clear()" class="text-xs text-gray-400 hover:text-gray-600">{{ __('Clear') }}</button>
            @endif
        </div>
    </div>
</div>
