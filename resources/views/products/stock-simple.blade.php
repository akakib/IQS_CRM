{{-- A packer's Stock (StockController::index, $simple): two tabs, Out of stock first, then In stock. Look only:
     a photo, the name and size, how many are left when counted. No counting, no saving, no history. --}}
@php
    $tab = fn (string $key) => request()->fullUrlWithQuery(['show' => $key, 'page' => null]);
    $in = (int) $counts->all_n - (int) $counts->out_n;
@endphp
<x-layouts.app :heading="__('Stock')">
    <div class="mb-3 grid grid-cols-2 gap-1 rounded-2xl bg-gray-200/70 p-1">
        <a href="{{ $tab('out') }}" @class(['rounded-xl py-3 text-center text-sm sm:text-base', 'bg-white font-semibold text-red-700 shadow-sm' => $show === 'out', 'text-gray-600' => $show !== 'out'])>{{ __('Out of stock') }}<br><b class="tabular-nums">{{ (int) $counts->out_n }}</b></a>
        <a href="{{ $tab('in') }}" @class(['rounded-xl py-3 text-center text-sm sm:text-base', 'bg-white font-semibold text-green-700 shadow-sm' => $show === 'in', 'text-gray-600' => $show !== 'in'])>{{ __('In stock') }}<br><b class="tabular-nums">{{ $in }}</b></a>
    </div>

    <form method="GET" action="{{ route('products.stock') }}" class="mb-3 flex gap-2">
        <input type="hidden" name="show" value="{{ $show }}">
        <input type="search" name="q" value="{{ $list->search }}" placeholder="{{ __('Search name or SKU') }}" class="h-11 min-w-0 flex-1 rounded-xl border border-gray-300 px-3 text-base">
        @if ($list->search !== '')<a href="{{ route('products.stock', ['show' => $show]) }}" class="flex h-11 items-center rounded-xl border border-gray-300 px-3 text-sm text-gray-700">{{ __('Clear') }}</a>@endif
    </form>

    @if ($variants->isEmpty())
        <x-empty-state :message="$show === 'out' ? __('Nothing is out of stock.') : __('Nothing here.')" />
    @else
        <div class="divide-y divide-gray-100 rounded-2xl border border-gray-200 bg-white">
            @foreach ($variants as $v)
                <div class="flex items-center gap-3 px-3 py-2.5">
                    <x-product-thumb :src="$v->product?->image_url" size="lg" />
                    <div class="min-w-0 flex-1">
                        <p class="text-base font-medium leading-snug text-gray-900">{{ $v->product->name ?? '-' }}@if ($v->name && $v->name !== 'Default') <b class="font-semibold">· {{ $v->name }}</b>@endif</p>
                        @if ($v->availability_status === 'backorder')<p class="text-sm font-medium text-amber-700">{{ __('Pre-order') }}</p>@endif
                    </div>
                    @if ($show === 'in' && $v->stock_qty !== null)
                        <span class="shrink-0 text-right text-sm text-gray-500"><b class="block text-lg tabular-nums text-gray-900">{{ (int) $v->stock_qty }}</b>{{ __('left') }}</span>
                    @endif
                </div>
            @endforeach
        </div>
        <div class="mt-3">{{ $variants->links() }}</div>
    @endif
</x-layouts.app>
