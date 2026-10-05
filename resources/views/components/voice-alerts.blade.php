{{-- Spoken alerts for moderators (header, beside the dark-mode switch). The
     speaker opens a small menu: on/off, which voice this device uses, and a
     test. A dot shows an alert is waiting for a click, because browsers only
     play sound after one. The name is said as set in Staff ("Name for voice
     alerts"), else the first word of the name. --}}
@if (auth()->user()?->can('orders.take') && settings('desk.voice_alerts'))
    @php
        $spoken = trim((string) auth()->user()->voice_name) ?: \Illuminate\Support\Str::of(auth()->user()->name)->trim()->before(' ')->toString();
    @endphp
    <div class="relative"
        x-data="voiceAlerts({ url: @js(route('desk.pulse')), name: @js($spoken) })"
        @click.outside="menu = false" @keydown.escape.window="menu = false">
        <button type="button" @click="menu = !menu; if (menu) loadVoices()"
            :aria-label="@js(__('Voice alerts'))"
            :title="waiting ? @js(__('Click anywhere to hear the alert')) : (on ? @js(__('Voice alerts on')) : @js(__('Voice alerts off')))"
            class="relative flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-100">
            <svg x-show="on" class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M11 5L6 9H3v6h3l5 4V5zM15.5 8.5a5 5 0 010 7M18.5 5.5a9 9 0 010 13" />
            </svg>
            <svg x-show="!on" x-cloak class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M11 5L6 9H3v6h3l5 4V5zM16 9l5 6M21 9l-5 6" />
            </svg>
            <span x-show="waiting && on" x-cloak class="absolute -right-0.5 -top-0.5 h-2.5 w-2.5 animate-pulse rounded-full bg-amber-500 ring-2 ring-white"></span>
        </button>

        <div x-show="menu" x-cloak x-transition.origin.top.right
            class="fixed inset-x-4 top-16 z-50 rounded-xl border border-gray-200 bg-white shadow-lg sm:absolute sm:inset-x-auto sm:right-0 sm:top-full sm:mt-2 sm:w-80">
            <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-4 py-3">
                <p class="text-sm font-semibold text-gray-800">{{ __('Voice alerts') }}</p>
                <button type="button" role="switch" :aria-checked="on" @click="setOn(!on)"
                    class="relative h-6 w-11 shrink-0 rounded-full transition-colors" :class="on ? 'bg-primary' : 'bg-gray-300'">
                    <span class="absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition-all" :class="on ? 'left-[22px]' : 'left-0.5'"></span>
                </button>
            </div>
            <div class="px-4 pt-3">
                <p class="text-xs font-medium uppercase text-gray-500">{{ __('Voice on this device') }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ __('Tap one to hear it. Bangla and India voices sound closest to a Bangladeshi accent.') }}</p>
            </div>
            <div class="mt-2 max-h-64 overflow-y-auto px-2 pb-2">
                <template x-for="v in voices" :key="v.name">
                    <button type="button" @click="choose(v.name)"
                        class="flex w-full items-center justify-between gap-2 rounded-lg px-2 py-2 text-left text-sm hover:bg-gray-50"
                        :class="current === v.name ? 'bg-primary-soft font-medium text-primary' : 'text-gray-700'">
                        <span class="min-w-0 truncate" x-text="v.label"></span>
                        <svg x-show="current === v.name" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    </button>
                </template>
                <p x-show="!voices.length" class="px-2 py-3 text-sm text-gray-500">{{ __('This browser has no voice. Try Chrome or Edge.') }}</p>
            </div>
            <div class="border-t border-gray-100 p-3">
                <button type="button" @click="test()" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Test the voice') }}</button>
            </div>
        </div>
    </div>
@endif
