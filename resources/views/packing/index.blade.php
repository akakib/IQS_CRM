<x-layouts.app :heading="__('Packing')">
    <div class="mb-6 grid gap-3 md:grid-cols-3">
        <x-stat-tile :label="__('Booked, not in a batch')" :value="$waiting" />
        <x-stat-tile :label="__('Missing-item reports')" :value="$openIssues" :href="route('packing.issues')" :trend="$openIssues ? 'down' : null" :hint="$openIssues ? __('Admin must decide') : null" />
        <a href="{{ route('packing.scan') }}" class="flex items-center justify-center rounded-xl bg-green-900 p-4 text-lg font-semibold text-white hover:bg-green-800">{{ __('Open scan station') }}</a>
    </div>

    @can('packing.create')
        <form method="POST" action="{{ route('packing.release') }}" class="mb-6">
            @csrf
            <x-button :disabled="! $waiting">{{ __('Release batch to the shop (:n orders)', ['n' => $waiting]) }}</x-button>
            <p class="mt-1 text-xs text-gray-500">{{ __('The bot posts one pick list (sorted by shelf, no customer details) to the shop Telegram group.') }}</p>
        </form>
    @endcan

    <x-card :title="__('Recent batches')">
        @forelse ($batches as $b)
            @php($c = $counts[$b->id] ?? null)
            <a href="{{ route('packing.batch', $b->id) }}" class="flex items-center justify-between border-b border-gray-50 py-2 text-sm last:border-0 hover:bg-gray-50">
                <span><b class="font-mono">{{ $b->batch_no }}</b> · {{ \Illuminate\Support\Carbon::parse($b->released_at)->format('d M, g:i A') }}
                    @if ($b->picker)<span class="text-xs text-gray-500">· {{ __('picked by :n', ['n' => $b->picker]) }}</span>@endif</span>
                <span class="flex items-center gap-2">
                    <span class="tabular-nums text-gray-600">{{ (int) ($c->packed ?? 0) }}/{{ (int) ($c->total ?? 0) }} {{ __('packed') }}</span>
                    <x-badge :color="['released' => 'blue', 'picked' => 'amber', 'done' => 'green'][$b->status]">{{ ucfirst($b->status) }}</x-badge>
                </span>
            </a>
        @empty
            <p class="text-sm text-gray-400">{{ __('No batch yet.') }}</p>
        @endforelse
    </x-card>
</x-layouts.app>
