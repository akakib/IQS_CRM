{{-- The packer's simple page (PackagingController::isSimple): two tabs, To pack and Packed. In To pack each order has
     one big button, the next thing to do: Print label, then Done (everything in the box and the label on it, one yes).
     Changed after packing: the row is red, Repack. Packed: their own, today first, any day by date. --}}
@php
    $size = function ($i) {
        $s = \App\Support\Units::itemSize((object) ['unit' => $i->unit, 'variant' => (object) ['size_qty' => $i->size_qty, 'size_unit' => $i->size_unit]]);

        return $s && ! str_contains(mb_strtolower(str_replace(' ', '', $i->name_snapshot)), mb_strtolower(str_replace(' ', '', $s))) ? $s : null;
    };
    $day = fn ($d) => route('packaging.index', ['show' => 'packed', 'from' => $d, 'to' => $d]);
@endphp

<x-layouts.app :heading="__('Packaging')">
<div class="mx-auto max-w-2xl" x-data="{ pop: null }" @keydown.escape.window="pop = null">

    {{-- Print the next batch of stickers (the oldest orders waiting). --}}
    @if ($nextBatch && $nextBatch['ids'] && $filter === 'all')
        <form method="POST" action="{{ route('packaging.next-batch') }}" class="mb-3">
            @csrf
            {{-- Blue (owner 2026-11-28): a call to act, apart from the green Done; not red, which reads as an error. --}}
            <button class="lockable flex w-full items-center justify-between gap-3 rounded-2xl bg-blue-600 px-5 py-4 text-left text-white hover:bg-blue-700">
                <span class="text-lg font-semibold">{{ __('Print next batch') }}</span>
                <span class="text-sm">{{ __(':n orders', ['n' => count($nextBatch['ids'])]) }}</span>
            </button>
        </form>
    @endif

    {{-- Two tabs. --}}
    <div class="mb-3 grid grid-cols-2 gap-1 rounded-2xl bg-gray-200/70 p-1">
        <a href="{{ route('packaging.index') }}" @class(['rounded-xl py-3 text-center text-base', 'bg-white font-semibold text-gray-900 shadow-sm' => $filter === 'all', 'text-gray-600' => $filter !== 'all'])>{{ __('To pack') }} <b class="tabular-nums">({{ $counts['all'] }})</b></a>
        <a href="{{ $day(today()->toDateString()) }}" @class(['rounded-xl py-3 text-center text-base', 'bg-white font-semibold text-gray-900 shadow-sm' => $filter === 'packed', 'text-gray-600' => $filter !== 'packed'])>{{ __('Packed') }}</a>
    </div>

    @if ($filter === 'packed')
        @php $from = $packed['list']?->filter('from'); @endphp
        <div class="mb-1 flex gap-2">
            @foreach ([today()->toDateString() => __('Today'), today()->subDay()->toDateString() => __('Yesterday')] as $d => $label)
                <a href="{{ $day($d) }}" @class(['rounded-full border px-4 py-1.5 text-sm', 'border-primary bg-primary text-white' => $from === $d && $packed['list']->filter('to') === $d, 'border-gray-300 bg-white text-gray-700' => ! ($from === $d && $packed['list']->filter('to') === $d)])>{{ $label }}</a>
            @endforeach
        </div>
        @include('packaging._packed')
    @else
        <div class="space-y-3">
            @forelse ($queue as $o)
                @php
                    $label = $labels[$o->id] ?? null;
                    $v = $versions[$o->id] ?? null;
                    $its = $items[$o->id] ?? collect();
                    $printed = $label && $label->printed_at && $v && (int) $v->label_version === (int) $v->current_version;
                    $repack = (bool) $o->is_red;
                    $popItems = $its->map(fn ($i) => ['name' => $i->name_snapshot, 'size' => $size($i), 'qty' => \App\Support\Units::qty($i->qty, $i->unit), 'image' => $i->image_url])->values();
                @endphp
                <div @class(['rounded-2xl border bg-white p-4', 'border-red-400 bg-red-50' => $repack, 'border-gray-200' => ! $repack])>
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-mono text-xl font-bold text-gray-900">{{ $label->barcode ?? $o->order_no }}</p>
                            <p class="text-sm text-gray-500">{{ collect([$o->ship_thana, \App\Support\BdDistricts::name($o->ship_district)])->filter()->unique()->join(', ') ?: '-' }} · {{ trans_choice(':count item|:count items', $its->count(), ['count' => $its->count()]) }}</p>
                            @if ($repack)<p class="mt-1 text-sm font-semibold text-red-700">{{ __('Changed after packing: open the box and pack it again') }}</p>@endif
                        </div>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($its as $i)
                            <span class="inline-flex max-w-full items-center gap-2 rounded-xl border border-gray-200 bg-white py-1 pl-1 pr-3 text-sm text-gray-800"><x-product-thumb :src="$i->image_url" /><span class="min-w-0 truncate">{{ $i->name_snapshot }}{{ ($s = $size($i)) ? ' '.$s : '' }}</span><b class="shrink-0">{{ \App\Support\Units::qty($i->qty, $i->unit) }}</b></span>
                        @endforeach
                    </div>
                    <div class="mt-3">
                        @if (! $printed)
                            <a href="{{ route('packaging.labels', ['orders' => $o->id]) }}" target="_blank" class="flex h-12 w-full items-center justify-center rounded-xl border-2 border-primary bg-white text-base font-semibold text-primary">{{ __('Print label') }}</a>
                        @else
                            <button type="button" @click="pop = { no: @js($o->order_no), label: @js($label->barcode), items: @js($popItems), url: @js(route('packaging.mark-done', $o->id)), repack: @js($repack) }"
                                @class(['lockable flex h-12 w-full items-center justify-center rounded-xl text-base font-semibold text-white', 'bg-red-600 hover:bg-red-700' => $repack, 'bg-primary hover:bg-primary-dark' => ! $repack])>{{ $repack ? __('Repack done') : __('Done') }}</button>
                        @endif
                    </div>
                </div>
            @empty
                <div class="rounded-2xl border border-dashed border-gray-300 bg-white p-10 text-center text-base text-gray-500">{{ __('Nothing to pack right now.') }}</div>
            @endforelse
        </div>
        <div class="mt-4">{{ $queue->links() }}</div>
    @endif

    {{-- Done: the items to see and the label to match, one yes. --}}
    <div x-show="pop" x-cloak class="fixed inset-0 z-[70] flex items-end justify-center bg-black/40 sm:items-center sm:p-4">
        <div class="absolute inset-0" @click="pop = null"></div>
        <form method="POST" :action="pop?.url" class="relative max-h-[90vh] w-full max-w-md overflow-y-auto rounded-t-2xl bg-white p-5 shadow-xl sm:rounded-2xl">
            @csrf
            <template x-if="pop">
                <div>
                    <p class="font-mono text-2xl font-bold text-gray-900" x-text="pop.no"></p>
                    <p class="mt-1 text-sm text-gray-600">{{ __('Is all of this in the box?') }}</p>
                    <div class="mt-3 divide-y divide-gray-100 rounded-xl border border-gray-200">
                        <template x-for="(i, n) in pop.items" :key="n">
                            <div class="flex items-center gap-3 px-3 py-2 text-base">
                                <x-product-thumb x-src="i.image" size="lg" />
                                <span class="min-w-0 flex-1 text-gray-800"><span x-text="i.name"></span> <span class="text-gray-500" x-show="i.size" x-text="i.size"></span></span>
                                <b class="shrink-0 text-lg tabular-nums" x-text="i.qty"></b>
                            </div>
                        </template>
                    </div>
                    <div class="mt-4 rounded-xl bg-gray-50 p-3 text-center">
                        <p class="text-sm text-gray-600">{{ __('Is this label on the box?') }}</p>
                        <p class="font-mono text-2xl font-bold tracking-wider text-gray-900" x-text="pop.label"></p>
                    </div>
                </div>
            </template>
            <div class="mt-4 grid grid-cols-2 gap-2">
                <button type="button" @click="pop = null" class="h-12 rounded-xl border border-gray-300 text-base text-gray-700">{{ __('Cancel') }}</button>
                <button class="lockable h-12 rounded-xl bg-primary text-base font-semibold text-white hover:bg-primary-dark">{{ __('Yes, done') }}</button>
            </div>
        </form>
    </div>
</div>
</x-layouts.app>
