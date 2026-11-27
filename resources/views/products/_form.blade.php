@php
    $blankPrices = $priceLists->mapWithKeys(fn ($l) => [$l->system_key => ['regular' => '', 'sale' => '']])->all();
    $initialVariants = old('variants', $variants ?: [[
        'id' => null, 'name' => 'Default', 'sku' => '', 'barcode' => '', 'shelf_code' => '', 'unit' => 'pcs', 'pack_qty' => 1,
        'weight_g' => '', 'cost_price' => '', 'is_active' => true, 'prices' => $blankPrices, 'size_qty' => '', 'size_unit' => '',
    ]]);
    $input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary focus:outline-none';
    $small = 'h-9 w-full rounded-lg border border-gray-300 bg-white px-2.5 text-sm focus:border-primary focus:outline-none';
    $label = 'mb-1 block text-xs font-medium text-gray-500';
    $canSeeCost = auth()->user()->canSeeField('cost_price');
@endphp

@csrf

@if ($errors->any())
    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
        <p class="font-medium">{{ __('Please fix the highlighted fields.') }}</p>
        <ul class="mt-1 list-inside list-disc text-xs">
            @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    </div>
@endif

{{-- Layout (2026-11-27): a computer has the work on the left and the side facts (picture, publishing, SEO) on the
     right, with Save in the side column; a phone has one column and Save fixed at the bottom. Description and SEO
     fold away. Field names are unchanged. --}}
<div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start">
    <div class="min-w-0 space-y-5">
        <x-card :title="__('Product')" :subtitle="__('Name and where it sits in the shop.')">
            <x-form.input name="name" :label="__('Name')" :value="$product->name" required maxlength="255" />
            <div class="grid gap-x-4 sm:grid-cols-2">
                <div class="mb-4 sm:mb-0">
                    <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('Category') }}</label>
                    <x-simple-select name="category_id" :options="['' => __('No category')] + $categoryOptions" :value="(string) old('category_id', $product->category_id ?? '')" full-width class="w-full" />
                </div>
                <div>
                    <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('Sold by') }}</label>
                    <x-simple-select name="base_unit" :options="['pcs' => __('Piece / packet'), 'g' => __('Weight (gram)')]" :value="old('base_unit', $product->base_unit)" full-width class="w-full" />
                </div>
            </div>
        </x-card>

        {{-- Variants: one card per sellable size/pack, its stock, its details and a small price table. --}}
        <section class="rounded-xl border border-gray-200 bg-white p-5"
            x-data="{
                rows: {{ \Illuminate\Support\Js::from($initialVariants) }},
                blank: {{ \Illuminate\Support\Js::from($blankPrices) }},
                add() { this.rows.push({ id: null, name: '', sku: '', barcode: '', shelf_code: '', unit: 'pcs', pack_qty: 1, weight_g: '', cost_price: '', size_qty: '', size_unit: '', is_active: true, prices: JSON.parse(JSON.stringify(this.blank)) }) },
            }">
            <div class="mb-4 flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="text-sm font-semibold text-gray-800">{{ __('Variants and prices') }}</h2>
                    <p class="text-xs text-gray-500">{{ __('Each variant is what the customer buys: 500 g packet, 1 kg packet, 5 kg box.') }}</p>
                </div>
                <button type="button" @click="add()" class="inline-flex h-8 shrink-0 items-center whitespace-nowrap rounded-lg border border-gray-300 px-3 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    + <span class="ml-1 hidden sm:inline">{{ __('Add variant') }}</span><span class="ml-1 sm:hidden">{{ __('Variant') }}</span>
                </button>
            </div>

            <div class="space-y-4">
                <template x-for="(row, i) in rows" :key="i">
                    <div class="rounded-xl border border-gray-200 p-4">
                        <input type="hidden" :name="`variants[${i}][id]`" :value="row.id ?? ''">
                        {{-- Head: which variant, its stock (saved at once, as on the Products quick edit), Active, Remove. --}}
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-3">
                            <div class="min-w-0 flex-1 basis-40">
                                <p class="truncate font-semibold text-gray-900" x-text="row.name || @js(__('New variant'))"></p>
                                <p class="truncate text-xs text-gray-500" x-text="(i === 0 ? @js(__('Main variant')) : @js(__('Variant')) + ' ' + (i + 1)) + (row.sku ? ' · ' + row.sku : '')"></p>
                            </div>
                            @can('products.availability')
                                <div x-show="row.id" class="grid w-full grid-cols-3 overflow-hidden rounded-lg border border-gray-300 text-center text-sm sm:w-auto">
                                    @foreach (['in_stock' => 'bg-primary', 'backorder' => 'bg-amber-600', 'out_of_stock' => 'bg-red-600'] as $key => $on)
                                        <button type="button" class="whitespace-nowrap px-3 py-1.5 {{ $loop->first ? '' : 'border-l border-gray-200' }}"
                                            :class="row.status === @js($key) ? '{{ $on }} font-medium text-white' : 'bg-white text-gray-600 hover:bg-gray-50'"
                                            @click="if (row.status === @js($key)) return; (async () => { const f = new FormData(); f.append('_token', document.querySelector('meta[name=csrf-token]').content); f.append('ids[]', row.id); f.append('status', @js($key));
                                                const r = await fetch(@js(route('products.availability.update')), { method: 'POST', body: f, headers: { Accept: 'application/json' } }); if (r.ok) row.status = @js($key); })()">{{ __(\App\Models\ProductVariant::AVAILABILITY[$key]) }}</button>
                                    @endforeach
                                </div>
                            @endcan
                            <div class="flex items-center gap-4">
                                <label class="flex items-center gap-1.5 text-sm text-gray-700">
                                    <input type="hidden" :name="`variants[${i}][is_active]`" :value="row.is_active ? 1 : 0">
                                    <input type="checkbox" x-model="row.is_active" class="rounded border-gray-300 text-primary"> {{ __('Active') }}
                                </label>
                                <button type="button" x-show="rows.length > 1" @click="rows.splice(i, 1)" class="text-sm text-red-600 hover:underline">{{ __('Remove') }}</button>
                            </div>
                        </div>

                        <div class="mt-4 grid grid-cols-2 gap-3 md:grid-cols-4">
                            <label><span class="{{ $label }}">{{ __('Name') }}</span><input type="text" :name="`variants[${i}][name]`" x-model="row.name" required maxlength="150" placeholder="500 g" class="{{ $small }}"></label>
                            <label><span class="{{ $label }}">SKU</span><input type="text" :name="`variants[${i}][sku]`" x-model="row.sku" required maxlength="60" class="{{ $small }} font-mono"></label>
                            <label><span class="{{ $label }}">{{ __('Barcode') }}</span><input type="text" :name="`variants[${i}][barcode]`" x-model="row.barcode" maxlength="60" class="{{ $small }} font-mono"></label>
                            <label><span class="{{ $label }}">{{ __('Shelf') }}</span><input type="text" :name="`variants[${i}][shelf_code]`" x-model="row.shelf_code" maxlength="30" placeholder="A1" class="{{ $small }} font-mono"></label>
                        </div>

                        <div class="mt-3 grid grid-cols-2 gap-3 xl:grid-cols-[auto_minmax(0,1fr)_minmax(0,1.4fr)_minmax(0,1fr)]">
                            <div class="col-span-2 xl:col-span-1">
                                <span class="{{ $label }}">{{ __('Unit') }}</span>
                                <input type="hidden" :name="`variants[${i}][unit]`" :value="row.unit">
                                {{-- Counted: Pcs, Packet, Box. Measured (the price is per one of the unit): Gram, KG, ML, Litre.
                                     A phone: two rows; a wide screen: one. --}}
                                <div class="grid grid-cols-4 overflow-hidden rounded-lg border border-gray-300 text-center text-sm xl:flex">
                                    @foreach (\App\Support\Units::LABELS as $unit => $unitLabel)
                                        <button type="button" @click="row.unit = @js($unit)"
                                            class="whitespace-nowrap px-2.5 py-1.5 {{ $loop->index % 4 ? 'border-l' : '' }} {{ $loop->index >= 4 ? 'border-t xl:border-t-0' : '' }} {{ $loop->index === 4 ? 'xl:border-l' : '' }} border-gray-200 {{ $loop->last ? [1 => 'col-span-4', 2 => 'col-span-3', 3 => 'col-span-2', 0 => ''][$loop->count % 4] : '' }}"
                                            :class="row.unit === @js($unit) ? 'bg-primary font-medium text-white' : 'bg-white text-gray-600 hover:bg-gray-50'">{{ $unitLabel }}</button>
                                    @endforeach
                                </div>
                            </div>
                            <label><span class="{{ $label }}" x-text="row.unit === 'pcs' ? @js(__('Pieces')) : @js(__('Grams'))"></span><input type="number" step="0.001" min="0.001" :name="`variants[${i}][pack_qty]`" x-model="row.pack_qty" required class="{{ $small }}"></label>
                            {{-- How much one holds, for the Sales report in kg and litres. Empty on a new item: read from the name. --}}
                            <div>
                                <span class="{{ $label }}">{{ __('Size (one holds)') }}</span>
                                <input type="hidden" :name="`variants[${i}][size_unit]`" :value="row.size_unit">
                                <div class="flex gap-1">
                                    <input type="number" step="0.001" min="0" :name="`variants[${i}][size_qty]`" x-model="row.size_qty" x-show="row.size_unit === 'g' || row.size_unit === 'ml'"
                                        :placeholder="row.size_unit === 'ml' ? '500' : '1000'" class="{{ $small }} min-w-0 flex-1">
                                    <div class="flex h-9 shrink-0 overflow-hidden rounded-lg border border-gray-300 text-xs">
                                        @foreach (['g' => 'g', 'ml' => 'ml', 'none' => __('pcs')] as $su => $sl)
                                            <button type="button" @click="row.size_unit = @js($su)" class="px-2" :class="row.size_unit === @js($su) ? 'bg-primary text-white' : 'bg-white text-gray-600 hover:bg-gray-50'">{{ $sl }}</button>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                            <label><span class="{{ $label }}">{{ __('Ship weight (g)') }}</span><input type="number" min="0" :name="`variants[${i}][weight_g]`" x-model="row.weight_g" class="{{ $small }}"></label>
                        </div>

                        {{-- Prices: one small table, a price list per row. --}}
                        <div class="mt-4 border-t border-gray-100 pt-3">
                            <div class="grid grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)_minmax(0,1fr)] items-center gap-x-3 gap-y-2 sm:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_minmax(0,1fr)]">
                                <span class="text-xs font-medium text-gray-500">{{ __('Price') }}</span>
                                <span class="text-xs font-medium text-gray-500">{{ __('Regular') }}</span>
                                <span class="text-xs font-medium text-gray-500">{{ __('Sale (discount)') }}</span>
                                @foreach ($priceLists as $list)
                                    <span class="text-sm text-gray-700">{{ $list->name }}</span>
                                    <input type="number" step="0.01" min="0" aria-label="{{ $list->name }} {{ __('Regular') }}" :name="`variants[${i}][prices][{{ $list->system_key }}][regular]`" x-model="row.prices['{{ $list->system_key }}'].regular" @if ($list->system_key === 'online') :required="i === 0" @endif class="{{ $small }}">
                                    <input type="number" step="0.01" min="0" aria-label="{{ $list->name }} {{ __('Sale (discount)') }}" :name="`variants[${i}][prices][{{ $list->system_key }}][sale]`" x-model="row.prices['{{ $list->system_key }}'].sale" class="{{ $small }}">
                                @endforeach
                                @if ($canSeeCost)
                                    <span class="text-sm text-gray-700">{{ __('Cost price') }}</span>
                                    <input type="number" step="0.01" min="0" aria-label="{{ __('Cost price') }}" :name="`variants[${i}][cost_price]`" x-model="row.cost_price" class="{{ $small }}">
                                    <span></span>
                                @endif
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </section>

        {{-- Description: folded (long HTML); open when it has an error. --}}
        <section class="rounded-xl border border-gray-200 bg-white" x-data="{ open: @js($errors->hasAny(['short_description', 'description'])) }">
            <button type="button" @click="open = ! open" class="flex w-full items-center justify-between gap-3 p-5 text-left">
                <span class="text-sm font-semibold text-gray-800">{{ __('Description') }}</span>
                <span class="text-sm text-gray-500" x-text="open ? @js(__('Hide')) : @js(__('Show'))"></span>
            </button>
            <div x-show="open" x-cloak class="px-5 pb-5">
                <label for="short_description" class="mb-2 block text-sm font-medium text-gray-700">{{ __('Short description') }}</label>
                <textarea id="short_description" name="short_description" rows="2" maxlength="5000" class="{{ $input }} mb-4">{{ old('short_description', $product->short_description) }}</textarea>
                <label for="description" class="mb-2 block text-sm font-medium text-gray-700">{{ __('Description') }}</label>
                <textarea id="description" name="description" rows="10" class="{{ $input }} font-mono text-xs">{{ old('description', $product->description) }}</textarea>
                <p class="mt-1 text-xs text-gray-400">{{ __('HTML is kept as it is and sent to the website.') }}</p>
            </div>
        </section>

    </div>

    {{-- Side column: Save, picture, publishing, SEO. On a computer it stays in view while the left side scrolls. --}}
    <div class="space-y-5 lg:sticky lg:top-4">
        {{-- One Save bar: fixed at the bottom of a phone, the first box of the side column on a computer. --}}
        <div class="fixed inset-x-0 bottom-0 z-30 flex gap-2 border-t border-gray-200 bg-white px-4 py-3 lg:static lg:rounded-xl lg:border lg:p-4">
            <x-button variant="secondary" :href="route('products.index')">{{ __('Cancel') }}</x-button>
            <x-button class="flex-1 justify-center">{{ __('Save product') }}</x-button>
        </div>

        <x-card :title="__('Picture')">
            @if ($product->image_url)
                <img src="{{ $product->image_url }}" alt="" class="mb-4 aspect-square w-full rounded-lg border border-gray-200 object-contain">
            @endif
            <x-form.input name="image_url" type="url" :label="__('Main image URL')" :value="$product->image_url" maxlength="500" />
        </x-card>

        <x-card :title="__('Publishing')" :subtitle="__('Who sees it on the website.')">
            <div class="mb-4">
                <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('Status') }}</label>
                <x-simple-select name="status" :options="['active' => __('Active'), 'draft' => __('Draft'), 'archived' => __('Archived')]" :value="old('status', $product->status)" full-width class="w-full" />
            </div>
            <x-form.input name="tags" :label="__('Tags (comma separated)')" :value="$product->tags" maxlength="500" />
        </x-card>

        {{-- SEO: folded; open when it has an error. Blade directives like @js do not run inside a component tag's attributes. --}}
        <section class="rounded-xl border border-gray-200 bg-white"
            x-data="{ open: @js($errors->hasAny(['seo_title', 'seo_description', 'focus_keyword', 'slug'])), t: {{ \Illuminate\Support\Js::from(old('seo_title', $product->seo_title) ?? '') }}, d: {{ \Illuminate\Support\Js::from(old('seo_description', $product->seo_description) ?? '') }} }">
            <button type="button" @click="open = ! open" class="flex w-full items-center justify-between gap-3 p-5 text-left">
                <span class="text-sm font-semibold text-gray-800">{{ __('SEO') }}</span>
                <span class="text-sm text-gray-500" x-text="open ? @js(__('Hide')) : @js(__('Show'))"></span>
            </button>
            <div x-show="open" x-cloak class="px-5 pb-5">
                <label class="mb-1 block text-sm font-medium text-gray-700" for="seo_title">{{ __('SEO title') }}</label>
                <input id="seo_title" name="seo_title" x-model="t" maxlength="255" class="{{ $input }}">
                <p class="mb-4 mt-1 text-xs" :class="t.length > 60 ? 'text-amber-700' : 'text-gray-400'"><span x-text="t.length"></span>/60</p>
                <label class="mb-1 block text-sm font-medium text-gray-700" for="seo_description">{{ __('Meta description') }}</label>
                <textarea id="seo_description" name="seo_description" x-model="d" rows="3" maxlength="500" class="{{ $input }}"></textarea>
                <p class="mb-4 mt-1 text-xs" :class="d.length > 160 ? 'text-amber-700' : 'text-gray-400'"><span x-text="d.length"></span>/160</p>
                <x-form.input name="focus_keyword" :label="__('Focus keyword')" :value="$product->focus_keyword" maxlength="150" />
                <x-form.input name="slug" :label="__('URL slug')" :value="$product->slug" maxlength="255" :placeholder="__('Made from the name if empty')" />
            </div>
        </section>
    </div>
</div>
