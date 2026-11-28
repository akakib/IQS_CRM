{{-- A packer's Stock (StockController::index, $simple): two tabs, Out of stock first, then In stock: a photo, the name
     and size, how many are left when counted. Who may change stock ($canChange) taps a row to turn it the other way
     (in stock -> out of stock, out -> in); a product with more sizes lists them and asks: all sizes, or only this one. --}}
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
        <div class="divide-y divide-gray-100 rounded-2xl border border-gray-200 bg-white"
            x-data="{
                pick: null, busy: false, error: '',
                async change(ids) {
                    this.busy = true; this.error = '';
                    try {
                        const r = await fetch(@js(route('products.availability.update')), { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify({ ids, status: this.pick.out ? 'in_stock' : 'out_of_stock' }) });
                        if (!r.ok) throw 0;
                        location.reload();
                    } catch (e) { this.error = @js(__('Not saved. Try again.')); this.busy = false; }
                },
            }">
            @foreach ($variants as $v)
                @php
                    $size = fn ($n) => $n && $n !== 'Default' ? $n : '';
                    $all = $canChange ? ($sizes[$v->product_id] ?? collect()) : collect();
                @endphp
                <{{ $canChange ? 'button type=button' : 'div' }} @if ($canChange)
                    @click="pick = { id: {{ $v->id }}, name: @js($v->product->name ?? '-'), size: @js($size($v->name)), out: @js($v->availability_status === 'out_of_stock'),
                        sizes: @js($all->map(fn ($x) => ['id' => $x->id, 'name' => $size($x->name) ?: '-', 'out' => $x->availability_status === 'out_of_stock'])->values()) }; error = ''"
                    @endif class="flex w-full items-center gap-3 px-3 py-2.5 text-left">
                    <x-product-thumb :src="$v->product?->image_url" size="lg" />
                    <div class="min-w-0 flex-1">
                        <p class="text-base font-medium leading-snug text-gray-900">{{ $v->product->name ?? '-' }}@if ($v->name && $v->name !== 'Default') <b class="font-semibold">· {{ $v->name }}</b>@endif</p>
                        @if ($v->availability_status === 'backorder')<p class="text-sm font-medium text-amber-700">{{ __('Pre-order') }}</p>@endif
                    </div>
                    @if ($show === 'in' && $v->stock_qty !== null)
                        <span class="shrink-0 text-right text-sm text-gray-500"><b class="block text-lg tabular-nums text-gray-900">{{ (int) $v->stock_qty }}</b>{{ __('left') }}</span>
                    @endif
                </{{ $canChange ? 'button' : 'div' }}>
            @endforeach

            @if ($canChange)
                {{-- The change: the opposite of what the tapped size is now. More sizes: all of them, or only this one. --}}
                <div x-show="pick" x-cloak class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 sm:items-center" @click.self="busy || (pick = null)" @keydown.escape.window="busy || (pick = null)">
                    <div class="w-full max-w-md rounded-t-2xl bg-white p-4 pb-[calc(1rem+env(safe-area-inset-bottom))] sm:rounded-2xl">
                        <p class="text-lg font-semibold text-gray-900"><span x-text="pick?.name"></span> <b x-show="pick?.size" x-text="'· ' + pick?.size"></b></p>
                        <p class="mt-1 text-sm" :class="pick?.out ? 'text-red-700' : 'text-green-700'" x-text="pick?.out ? @js(__('Now: Out of stock')) : @js(__('Now: In stock'))"></p>

                        <template x-if="pick && pick.sizes.length > 1">
                            <div class="mt-3">
                                <div class="divide-y divide-gray-100 rounded-xl border border-gray-200">
                                    <template x-for="z in pick.sizes" :key="z.id">
                                        <div class="flex items-center justify-between px-3 py-2 text-base" :class="z.id === pick.id && 'bg-gray-50 font-semibold'">
                                            <span x-text="z.name"></span>
                                            <span class="text-sm font-medium" :class="z.out ? 'text-red-700' : 'text-green-700'" x-text="z.out ? @js(__('Out of stock')) : @js(__('In stock'))"></span>
                                        </div>
                                    </template>
                                </div>
                                <p class="mt-3 text-base font-medium text-gray-900" x-text="(pick.out ? @js(__('Mark all sizes in stock?')) : @js(__('Mark all sizes out of stock?')))"></p>
                                <div class="mt-2 grid grid-cols-2 gap-2">
                                    <button type="button" :disabled="busy" @click="change(pick.sizes.map(z => z.id))" class="h-12 rounded-xl text-base font-semibold text-white disabled:opacity-50" :class="pick.out ? 'bg-green-600' : 'bg-red-600'">{{ __('Yes, all sizes') }}</button>
                                    <button type="button" :disabled="busy" @click="change([pick.id])" class="h-12 rounded-xl border-2 text-base font-semibold disabled:opacity-50" :class="pick.out ? 'border-green-600 text-green-700' : 'border-red-600 text-red-700'" x-text="pick.size ? @js(__('No, only')) + ' ' + pick.size : @js(__('No, only this one'))"></button>
                                </div>
                            </div>
                        </template>
                        <template x-if="pick && pick.sizes.length <= 1">
                            <button type="button" :disabled="busy" @click="change([pick.id])" class="mt-3 h-12 w-full rounded-xl text-base font-semibold text-white disabled:opacity-50" :class="pick?.out ? 'bg-green-600' : 'bg-red-600'" x-text="pick?.out ? @js(__('Mark in stock')) : @js(__('Mark out of stock'))"></button>
                        </template>
                        <p x-show="error" class="mt-2 text-sm text-red-700" x-text="error"></p>
                        <button type="button" :disabled="busy" @click="pick = null" class="mt-2 h-11 w-full rounded-xl border border-gray-300 text-base text-gray-700">{{ __('Cancel') }}</button>
                    </div>
                </div>
            @endif
        </div>
        <div class="mt-3">{{ $variants->links() }}</div>
    @endif
</x-layouts.app>
