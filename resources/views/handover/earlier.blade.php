{{-- A packer's earlier handovers (HandoverController::index, show=earlier): newest first, a day to jump to. Each row opens the
     handover's own page, where its slip prints again (thermal, PDF, A4). Today's are on the Handover page itself. --}}
<x-layouts.app :heading="__('Earlier handovers')">
    <form method="GET" x-ref="f" x-data class="mb-3 flex items-center gap-2">
        <input type="hidden" name="show" value="earlier">
        <x-date-input name="day" :value="$day" :max="today()->toDateString()" :placeholder="__('Pick a day')" size="md" @date-change="$nextTick(() => $refs.f.requestSubmit())" />
        @if ($day)<a href="{{ route('handover.index', ['show' => 'earlier']) }}" class="flex h-10 items-center rounded-xl border border-gray-300 px-3 text-sm text-gray-700">{{ __('Clear') }}</a>@endif
    </form>

    @if ($sessions->isEmpty())
        <x-empty-state :message="$day ? __('No handover on this day.') : __('No earlier handover yet.')" />
    @else
        <div class="divide-y divide-gray-100 rounded-2xl border border-gray-200 bg-white">
            @foreach ($sessions as $h)
                <a href="{{ route('handover.show', $h->id) }}" class="flex items-center justify-between gap-3 px-3 py-3">
                    <span class="min-w-0">
                        <span class="block truncate text-base font-medium text-gray-900">{{ $h->rider_name ?? __('Rider') }} <span class="text-sm font-normal text-gray-500">{{ $h->rider_phone }}</span></span>
                        <span class="block text-sm text-gray-500">{{ \Illuminate\Support\Carbon::parse($h->closed_at)->format('d M Y, g:i A') }}{{ $h->by_name ? ' · '.__('by :n', ['n' => $h->by_name]) : '' }}</span>
                    </span>
                    <span class="shrink-0 text-right text-sm text-gray-600">{{ trans_choice(':count parcel|:count parcels', $h->parcels) }}<span class="block text-blue-700">{{ __('Print') }} ›</span></span>
                </a>
            @endforeach
        </div>
        @if ($sessions->hasPages())<div class="mt-3">{{ $sessions->links() }}</div>@endif
    @endif
    <a href="{{ route('handover.index') }}" class="mt-4 flex h-12 w-full items-center justify-center rounded-xl border border-gray-300 bg-white text-base font-medium text-gray-700">{{ __('Back to handover') }}</a>
</x-layouts.app>
