{{-- Today's packed / Packed (by date): orders already packed, newest first. A packer sees their own, a manager everyone's.
     Each: order no, CN, customer, area, the items with photos, who packed and when, and whether the rider has it.
     Before the rider has it, the label prints again (the same label, so the box still scans). No customer phone for the shop.
     Data: PackagingController::packedList(). --}}
@php
    $statusMap = \App\Models\OrderStatus::map();
    $canPrint = auth()->user()->can('packaging.create');
    $list = $packed['list'];
    $rows = $packed['rows'];
@endphp

<div class="mt-3">
    <x-list.filter-bar :list="$list" :action="route('packaging.index')" :placeholder="$packed['phone'] ? __('Order no, CN, name or phone') : __('Order no, CN or name')" :results="$rows" :noun="[__('order'), __('orders')]">
        <input type="hidden" name="show" value="{{ $filter }}">
        @if ($filter === 'packed')
            <x-date-range :from="$list->filter('from')" :to="$list->filter('to')" />
        @endif
        <x-simple-select name="rider" :options="['' => __('Rider: any'), 'waiting' => __('Waiting for rider'), 'handed' => __('Handed over')]" :value="$list->filter('rider') ?? ''" />
        @if ($packed['packers'])
            <x-simple-select name="packer" :options="['' => __('All packers')] + $packed['packers']" :value="(string) ($list->filter('packer') ?? '')" />
        @endif
    </x-list.filter-bar>

    <div class="space-y-2">
        @forelse ($rows as $o)
            @php
                $key = $statusMap[$o->status_id]['key'] ?? '';
                $withShop = in_array($key, ['packed', 'ready_for_pickup'], true) && ! $o->handed_at;
                $items = $packed['items'][$o->id] ?? collect();
            @endphp
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="font-mono text-base font-bold text-gray-900">{{ $o->order_no }}@if ($o->consignment_id)<span class="ml-2 text-sm font-medium text-gray-500">CN {{ $o->consignment_id }}</span>@endif</p>
                        <p class="text-sm text-gray-700">{{ $o->ship_name }} · {{ collect([$o->ship_thana, $o->ship_district])->filter()->join(', ') ?: '-' }}@if ($o->ship_phone) · <span class="tabular-nums">{{ $o->ship_phone }}</span>@endif</p>
                    </div>
                    @if ($o->handed_at)
                        <span class="shrink-0 rounded-full bg-green-50 px-2.5 py-1 text-xs font-medium text-green-800">{{ __('Handed over') }}@if ($o->rider) · {{ $o->rider }}@endif · {{ \Illuminate\Support\Carbon::parse($o->handed_at)->format('d M, g:i A') }}</span>
                    @elseif ($withShop)
                        <span class="shrink-0 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-900">{{ __('Waiting for rider') }}</span>
                    @else
                        <span class="shrink-0 rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700">{{ __($statusMap[$o->status_id]['name'] ?? '-') }}</span>
                    @endif
                </div>

                <div class="mt-3 divide-y divide-gray-100 rounded-lg border border-gray-100">
                    @foreach ($items as $i)
                        <div class="flex items-center gap-3 px-3 py-2 text-sm">
                            <span class="h-10 w-10 shrink-0 overflow-hidden rounded-md border border-gray-200 bg-gray-100">
                                @if ($i->image_url)<img src="{{ $i->image_url }}" alt="" loading="lazy" class="h-full w-full object-cover" onerror="this.remove()">@endif
                            </span>
                            <span class="min-w-0 flex-1 text-gray-800">{{ $i->name_snapshot }}</span>
                            <b class="shrink-0 tabular-nums">{{ \App\Support\Units::qty($i->qty, $i->unit) }}</b>
                        </div>
                    @endforeach
                </div>

                <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                    <p class="text-xs text-gray-500">{{ __('Packed by :n · :t', ['n' => $o->packer ?? '-', 't' => \Illuminate\Support\Carbon::parse($o->packed_at)->format('d M, g:i A')]) }}</p>
                    @if ($canPrint && $withShop)
                        <a href="{{ route('packaging.labels', ['orders' => $o->id]) }}" target="_blank" class="inline-flex h-8 items-center rounded-lg border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700 hover:border-primary hover:text-primary">{{ __('Print label again') }}</a>
                    @endif
                </div>
            </div>
        @empty
            <div class="rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center text-sm text-gray-500">{{ $filter === 'today' ? __('Nothing packed yet today.') : __('No packed order here.') }}</div>
        @endforelse
    </div>
    <div class="mt-4">{{ $rows->links() }}</div>
</div>
