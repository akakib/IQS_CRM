<x-layouts.app :heading="__('Rider handover')">
    {{-- One bar to start (name, phone, button side by side on a wide screen), the list below it. --}}
    <div class="space-y-6">
        <x-card :title="__('Start a handover')">
            <p class="mb-3 text-sm text-gray-600">{{ trans_choice(':count parcel is ready for pickup.|:count parcels are ready for pickup.', $waiting, ['count' => $waiting]) }}</p>
            {{-- Rider: pick a known one (the phone fills itself) or type a new name, which is remembered next time. --}}
            <form method="POST" action="{{ route('handover.start') }}" class="flex flex-col gap-2 md:flex-row md:items-center"
                x-data="{
                    name: '', phone: '', open: false,
                    riders: {{ \Illuminate\Support\Js::from($riders) }},
                    get matches() { const q = this.name.trim().toLowerCase(); return this.riders.filter(r => !q || r.name.toLowerCase().includes(q)) },
                    get isNew() { const q = this.name.trim().toLowerCase(); return q !== '' && !this.riders.some(r => r.name.toLowerCase() === q) },
                    pick(r) { this.name = r.name; this.phone = r.phone; this.open = false },
                }">
                @csrf
                <div class="relative w-full min-w-0 md:flex-1" @click.outside="open = false" @keydown.escape="open = false">
                    <input name="rider_name" maxlength="100" autocomplete="off" x-model="name" @focus="open = true" @input="open = true"
                        placeholder="{{ __('Rider name') }}" class="w-full rounded-lg border border-gray-300 py-2 pl-3 pr-9 text-sm focus:border-primary focus:outline-none">
                    <button type="button" tabindex="-1" @click="open = !open" class="absolute inset-y-0 right-0 flex w-9 items-center justify-center text-gray-400" aria-label="{{ __('Show riders') }}">
                        <svg class="h-4 w-4 transition-transform" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open && (matches.length || isNew)" x-cloak class="absolute left-0 top-full z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-lg border border-gray-200 bg-white py-1 shadow-lg">
                        <template x-for="r in matches" :key="r.name">
                            <button type="button" @click="pick(r)" class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-gray-50">
                                <span class="truncate font-medium text-gray-900" x-text="r.name"></span>
                                <span class="shrink-0 text-xs tabular-nums text-gray-500" x-text="r.phone"></span>
                            </button>
                        </template>
                        <p x-show="isNew" class="border-t border-gray-100 px-3 py-2 text-xs text-gray-500">{{ __('New rider:') }} <b class="text-gray-800" x-text="name.trim()"></b>. {{ __('Will be saved for next time.') }}</p>
                    </div>
                </div>
                <input name="rider_phone" inputmode="tel" x-model="phone" placeholder="{{ __('Rider phone') }}" class="w-full min-w-0 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:outline-none md:flex-1">
                <x-button class="shrink-0 whitespace-nowrap">{{ __('Start scanning') }}</x-button>
            </form>
        </x-card>
        <x-card :title="__('Recent handovers')">
            @forelse ($sessions as $s)
                <a href="{{ $s->closed_at ? route('handover.manifest', $s->id) : route('handover.show', $s->id) }}" class="flex items-center justify-between gap-3 border-b border-gray-100 px-2 py-3 text-sm last:border-0 hover:bg-gray-50">
                    <span>{{ \Illuminate\Support\Carbon::parse($s->started_at)->format('d M, g:i A') }} · {{ $s->rider_name ?? __('Rider') }}</span>
                    <x-badge :color="$s->closed_at ? 'green' : 'blue'">{{ $s->closed_at ? __('Manifest') : __('Open') }}</x-badge>
                </a>
            @empty
                <p class="text-sm text-gray-400">{{ __('None yet.') }}</p>
            @endforelse
        </x-card>
    </div>
</x-layouts.app>
