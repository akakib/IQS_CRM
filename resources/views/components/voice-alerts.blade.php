{{-- Spoken alerts for moderators (header, beside the dark-mode switch). The
     speaker turns them on or off for this browser. A dot shows an alert is
     waiting for a click, because browsers only play sound after one. --}}
@if (auth()->user()?->can('orders.take') && settings('desk.voice_alerts'))
    <button type="button"
        x-data="voiceAlerts({ url: @js(route('desk.pulse')), name: @js(\Illuminate\Support\Str::of(auth()->user()->name)->trim()->before(' ')->toString()) })"
        @click="toggle()"
        :aria-label="on ? @js(__('Turn voice alerts off')) : @js(__('Turn voice alerts on'))"
        :title="waiting ? @js(__('Click anywhere to hear the alert')) : (on ? @js(__('Voice alerts on')) : @js(__('Voice alerts off')))"
        {{ $attributes->merge(['class' => 'relative flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-100']) }}>
        <svg x-show="on" class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M11 5L6 9H3v6h3l5 4V5zM15.5 8.5a5 5 0 010 7M18.5 5.5a9 9 0 010 13" />
        </svg>
        <svg x-show="!on" x-cloak class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M11 5L6 9H3v6h3l5 4V5zM16 9l5 6M21 9l-5 6" />
        </svg>
        <span x-show="waiting && on" x-cloak class="absolute -right-0.5 -top-0.5 h-2.5 w-2.5 animate-pulse rounded-full bg-amber-500 ring-2 ring-white"></span>
    </button>
@endif
