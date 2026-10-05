{{-- The columns. Swapped as a whole by Refresh and the 30-second refresh. --}}
@php
    // Only stages that have orders right now (with these filters).
    $columns = array_filter($columns, fn ($key) => $counts[$key] > 0, ARRAY_FILTER_USE_KEY);
    $firstKey = array_key_first($columns);
    $tones = [
        'gray' => 'bg-gray-400', 'blue' => 'bg-blue-500', 'amber' => 'bg-amber-500', 'orange' => 'bg-orange-500', 'red' => 'bg-red-500',
        'green' => 'bg-green-600', 'purple' => 'bg-purple-500', 'teal' => 'bg-teal-600',
    ];
@endphp
<div data-refreshed="{{ $refreshedAt->format('g:i:s A') }}" x-init="if (!@js(array_keys($columns)).includes(col)) col = @js($firstKey)">
    @if (! $columns)
        <div class="rounded-xl border border-dashed border-gray-300 bg-white p-12 text-center text-sm text-gray-500">{{ __('No orders moving in these dates.') }}</div>
    @endif
    {{-- Phone: one stage at a time, picked from this row. --}}
    <div class="mb-3 flex gap-2 overflow-x-auto pb-1 md:hidden">
        @foreach ($columns as $key => $col)
            <button type="button" @click="col = @js($key)"
                class="shrink-0 rounded-full border px-3 py-1.5 text-xs font-medium"
                :class="col === @js($key) ? 'border-primary bg-primary text-white' : 'border-gray-300 bg-white text-gray-700'">
                {{ $col['label'] }} <span class="ml-0.5 opacity-75">{{ $counts[$key] }}</span>
            </button>
        @endforeach
    </div>

    {{-- Grab the board with the mouse and pull it sideways (hand cursor); touch scrolls as usual. --}}
    <div data-drag-scroll class="flex cursor-grab gap-4 overflow-x-auto pb-4">
        @foreach ($columns as $key => $col)
            <section class="w-full shrink-0 md:w-72" x-show="wide || col === @js($key)" @if ($key !== $firstKey) x-cloak @endif>
                <header class="mb-2 flex items-center justify-between px-1">
                    <span class="flex items-center gap-2 text-sm font-semibold text-gray-800">
                        <span class="h-2 w-2 rounded-full {{ $tones[$col['tone']] }}"></span>{{ $col['label'] }}
                    </span>
                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium tabular-nums text-gray-600">{{ $counts[$key] }}</span>
                </header>
                <div class="space-y-2 rounded-xl bg-gray-100/70 p-2" data-column="{{ $key }}">
                    @forelse ($cards[$key] as $o)
                        @include('orders.activity._card', ['o' => $o])
                    @empty
                        <p class="py-6 text-center text-xs text-gray-400">{{ __('Nothing here') }}</p>
                    @endforelse
                    @if ($counts[$key] > $perColumn)
                        <button type="button" data-more="{{ $key }}" data-page="2" class="w-full rounded-lg border border-dashed border-gray-300 py-2 text-xs font-medium text-gray-600 hover:border-primary hover:text-primary">{{ __('Load more') }}</button>
                    @endif
                </div>
            </section>
        @endforeach
    </div>
</div>
