@php
    $tabs = [
        'all' => [__('All'), request()->fullUrlWithQuery(['show' => 'all', 'page' => null]), (int) $counts->all_n],
        'not_counted' => [__('Not counted yet'), request()->fullUrlWithQuery(['show' => 'not_counted', 'page' => null]), (int) $counts->not_counted],
        'low' => [__('Low (1 to 5)'), request()->fullUrlWithQuery(['show' => 'low', 'page' => null]), (int) $counts->low],
        'zero' => [__('None left'), request()->fullUrlWithQuery(['show' => 'zero', 'page' => null]), (int) $counts->zero],
    ];
    $canCount = auth()->user()->can('products.availability');
    $color = ['in_stock' => 'green', 'backorder' => 'amber', 'out_of_stock' => 'red'];
@endphp

<x-layouts.app :heading="__('Stock count')">
    <x-products.subnav active="stock" />

    <p class="mb-3 text-sm text-gray-500">{{ __('Type how many are on the shelf. From then on packing takes them away and a box put back brings them back. At 0 the item goes Out of stock by itself. Items not counted yet are not tracked.') }}</p>

    <x-tabs :tabs="$tabs" :active="$show" />

    <x-list.filter-bar :list="$list" :action="route('products.stock')" :placeholder="__('Search name or SKU')">
        <input type="hidden" name="show" value="{{ $show }}">
    </x-list.filter-bar>

    {{-- One history popup for the page; each row fills it. --}}
    <div x-data="{ hist: null, title: '', async open(url, t) { this.title = t; this.hist = null; $dispatch('open-modal', 'stock-history'); this.hist = (await (await fetch(url, { headers: { Accept: 'application/json' } })).json()).rows } }"
        @stock-history.window="open($event.detail.url, $event.detail.title)">
        <x-modal id="stock-history" :title="__('Stock changes')" width="max-w-2xl">
            <p class="mb-3 text-sm font-medium text-gray-800" x-text="title"></p>
            <template x-if="!hist"><p class="py-6 text-center text-sm text-gray-400">{{ __('Loading…') }}</p></template>
            <template x-if="hist && !hist.length"><p class="py-6 text-center text-sm text-gray-500">{{ __('No changes yet.') }}</p></template>
            <div class="divide-y divide-gray-100">
                <template x-for="(r, i) in hist || []" :key="i">
                    <div class="flex items-start justify-between gap-3 py-2 text-sm">
                        <div class="min-w-0">
                            <p class="text-gray-800"><span x-text="r.why"></span><span x-show="r.order" class="text-gray-500" x-text="' · ' + r.order"></span></p>
                            <p class="text-xs text-gray-500"><span x-text="r.when"></span><span x-show="r.who" x-text="' · ' + r.who"></span><span x-show="r.note" x-text="' · ' + r.note"></span></p>
                        </div>
                        <div class="shrink-0 text-right tabular-nums">
                            <p class="font-semibold" :class="r.change < 0 ? 'text-red-600' : 'text-green-700'" x-text="(r.change > 0 ? '+' : '') + r.change"></p>
                            <p class="text-xs text-gray-500" x-text="@js(__('now')) + ' ' + r.after"></p>
                        </div>
                    </div>
                </template>
            </div>
        </x-modal>
    </div>

    @if ($variants->isEmpty())
        <x-empty-state :message="__('Nothing here.')" />
    @else
        <div class="space-y-2">
            @foreach ($variants as $v)
                @php $label = trim(($v->product->name ?? '').($v->name ? ' · '.$v->name : '')); @endphp
                <div class="flex flex-col gap-3 rounded-xl border border-gray-200 bg-white p-3 sm:flex-row sm:items-center"
                    x-data="{ qty: @js($v->stock_qty), typed: @js($v->stock_qty), counted: @js($v->stock_counted_at?->format('d M, g:i A')), status: @js($v->availability_status), busy: false, err: null,
                        async save() {
                            this.err = null; this.busy = true;
                            try {
                                const r = await fetch(@js(route('products.stock.count', $v->id)), { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify({ qty: this.typed }) });
                                const j = await r.json();
                                if (!r.ok) { this.err = Object.values(j.errors || {})[0]?.[0] || @js(__('Could not save.')); return }
                                this.qty = j.qty; this.counted = j.counted; this.status = j.status;
                            } catch (e) { this.err = @js(__('No connection. Try again.')) } finally { this.busy = false }
                        } }">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-gray-900">{{ $label }}</p>
                        <p class="text-xs text-gray-500">{{ $v->sku ?: '-' }}@if ($v->shelf_code) · {{ __('Shelf') }} {{ $v->shelf_code }}@endif
                            · <span :class="{ 'text-green-700': status === 'in_stock', 'text-amber-700': status === 'backorder', 'text-red-600': status === 'out_of_stock' }" x-text="({{ \Illuminate\Support\Js::from(array_map('__', \App\Models\ProductVariant::AVAILABILITY)) }})[status]"></span></p>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="text-right">
                            <p class="text-lg font-semibold tabular-nums" :class="qty === null ? 'text-gray-400' : (qty <= 0 ? 'text-red-600' : (qty <= 5 ? 'text-amber-700' : 'text-gray-900'))" x-text="qty === null ? @js(__('Not counted')) : qty"></p>
                            <p class="text-[11px] text-gray-400" x-show="counted" x-text="@js(__('counted')) + ' ' + counted"></p>
                        </div>
                        @if ($canCount)
                            <form @submit.prevent="save()" class="flex items-center gap-1.5">
                                <input type="number" min="0" x-model.number="typed" required class="w-20 rounded-lg border border-gray-300 px-2 py-1.5 text-right text-sm tabular-nums focus:border-primary focus:outline-none" aria-label="{{ __('Counted') }}">
                                <button :disabled="busy || typed === qty || typed === '' || typed === null" class="rounded-lg bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-dark disabled:opacity-40">{{ __('Save') }}</button>
                            </form>
                        @endif
                        <button type="button" @click="$dispatch('stock-history', { url: @js(route('products.stock.history', $v->id)), title: @js($label) })" class="text-sm text-primary hover:underline">{{ __('History') }}</button>
                    </div>
                    <p x-show="err" x-cloak class="text-xs text-red-600 sm:basis-full" x-text="err"></p>
                </div>
            @endforeach
        </div>
        <div class="mt-4">{{ $variants->links() }}</div>
    @endif
</x-layouts.app>
