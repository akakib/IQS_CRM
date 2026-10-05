<x-layouts.app :heading="__('Rider handover')">
    {{-- One bar to start (name, phone, button side by side on a wide screen), the list below it. --}}
    <div class="space-y-6">
        <x-card :title="__('Start a handover')">
            <p class="mb-3 text-sm text-gray-600">{{ trans_choice(':count parcel is ready for pickup.|:count parcels are ready for pickup.', $waiting, ['count' => $waiting]) }}</p>
            <form method="POST" action="{{ route('handover.start') }}" class="flex flex-col gap-2 md:flex-row md:items-center">
                @csrf
                <input name="rider_name" maxlength="100" placeholder="{{ __('Rider name') }}" class="w-full min-w-0 md:flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm">
                <input name="rider_phone" inputmode="tel" placeholder="{{ __('Rider phone') }}" class="w-full min-w-0 md:flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm">
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
