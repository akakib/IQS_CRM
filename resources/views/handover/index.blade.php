<x-layouts.app :heading="__('Rider handover')">
    <div class="grid gap-6 lg:grid-cols-2">
        <x-card :title="__('Start a handover')">
            <p class="mb-3 text-sm text-gray-600">{{ trans_choice(':count parcel is ready for pickup.|:count parcels are ready for pickup.', $waiting, ['count' => $waiting]) }}</p>
            <form method="POST" action="{{ route('handover.start') }}" class="space-y-2">
                @csrf
                <input name="rider_name" maxlength="100" placeholder="{{ __('Rider name') }}" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                <input name="rider_phone" inputmode="tel" placeholder="{{ __('Rider phone') }}" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                <x-button>{{ __('Start scanning') }}</x-button>
            </form>
        </x-card>
        <x-card :title="__('Recent handovers')">
            @forelse ($sessions as $s)
                <a href="{{ $s->closed_at ? route('handover.manifest', $s->id) : route('handover.show', $s->id) }}" class="flex justify-between border-b border-gray-50 py-2 text-sm last:border-0 hover:bg-gray-50">
                    <span>{{ \Illuminate\Support\Carbon::parse($s->started_at)->format('d M, g:i A') }} · {{ $s->rider_name ?? __('Rider') }}</span>
                    <x-badge :color="$s->closed_at ? 'green' : 'blue'">{{ $s->closed_at ? __('Manifest') : __('Open') }}</x-badge>
                </a>
            @empty
                <p class="text-sm text-gray-400">{{ __('None yet.') }}</p>
            @endforelse
        </x-card>
    </div>
</x-layouts.app>
