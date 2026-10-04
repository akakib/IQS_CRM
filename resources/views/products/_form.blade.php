@php
    $blankPrices = $priceLists->mapWithKeys(fn ($l) => [$l->system_key => ['regular' => '', 'sale' => '']])->all();
    $initialVariants = old('variants', $variants ?: [[
        'id' => null, 'name' => 'Default', 'sku' => '', 'barcode' => '', 'shelf_code' => '', 'unit' => 'pcs', 'pack_qty' => 1,
        'weight_g' => '', 'cost_price' => '', 'is_active' => true, 'prices' => $blankPrices,
    ]]);
    $input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-green-800 focus:outline-none';
    $small = 'w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm focus:border-green-800 focus:outline-none';
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

<div class="grid gap-6 xl:grid-cols-3">
    <div class="space-y-6 xl:col-span-2">
        <x-card :title="__('Product')">
            <x-form.input name="name" :label="__('Name')" :value="$product->name" required maxlength="255" />
            <div class="grid gap-x-4 md:grid-cols-2">
                <div class="mb-4">
                    <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('Category') }}</label>
                    <x-simple-select name="category_id" :options="['' => __('No category')] + $categoryOptions" :value="(string) old('category_id', $product->category_id ?? '')" full-width class="w-full" />
                </div>
                <div class="mb-4">
                    <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('Sold by') }}</label>
                    <x-simple-select name="base_unit" :options="['pcs' => __('Piece / packet'), 'g' => __('Weight (gram)')]" :value="old('base_unit', $product->base_unit)" full-width class="w-full" />
                </div>
            </div>
            <div class="mb-4">
                <label for="short_description" class="mb-2 block text-sm font-medium text-gray-700">{{ __('Short description') }}</label>
                <textarea id="short_description" name="short_description" rows="2" maxlength="5000" class="{{ $input }}">{{ old('short_description', $product->short_description) }}</textarea>
            </div>
            <div>
                <label for="description" class="mb-2 block text-sm font-medium text-gray-700">{{ __('Description') }}</label>
                <textarea id="description" name="description" rows="8" class="{{ $input }} font-mono text-xs">{{ old('description', $product->description) }}</textarea>
                <p class="mt-1 text-xs text-gray-400">{{ __('HTML is kept as it is and sent to the website.') }}</p>
            </div>
        </x-card>

        {{-- Variants: one row per sellable size/pack, each with the 3 price lists. --}}
        <x-card :title="__('Variants and prices')" :subtitle="__('Each variant is what the customer buys: e.g. 500 g packet, 1 kg packet, 5 kg box.')"
            x-data="{
                rows: {{ \Illuminate\Support\Js::from($initialVariants) }},
                blank: {{ \Illuminate\Support\Js::from($blankPrices) }},
                add() { this.rows.push({ id: null, name: '', sku: '', barcode: '', shelf_code: '', unit: 'pcs', pack_qty: 1, weight_g: '', cost_price: '', is_active: true, prices: JSON.parse(JSON.stringify(this.blank)) }) },
            }">
            <div class="space-y-4">
                <template x-for="(row, i) in rows" :key="i">
                    <div class="rounded-lg border border-gray-200 p-3">
                        <input type="hidden" :name="`variants[${i}][id]`" :value="row.id ?? ''">
                        <div class="mb-2 flex items-center justify-between">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                <span x-text="i === 0 ? @js(__('Main variant')) : @js(__('Variant')) + ' ' + (i + 1)"></span>
                                <span x-show="row.availability" class="ml-2 font-normal normal-case text-gray-400" x-text="row.availability"></span>
                            </p>
                            <div class="flex items-center gap-3">
                                <label class="flex items-center gap-1.5 text-xs text-gray-600">
                                    <input type="hidden" :name="`variants[${i}][is_active]`" :value="row.is_active ? 1 : 0">
                                    <input type="checkbox" x-model="row.is_active" class="rounded border-gray-300 text-green-900"> {{ __('Active') }}
                                </label>
                                <button type="button" x-show="rows.length > 1" @click="rows.splice(i, 1)" class="text-xs text-red-600 hover:underline">{{ __('Remove') }}</button>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-2 md:grid-cols-6">
                            <label class="col-span-2 text-xs text-gray-500">{{ __('Name') }}<input type="text" :name="`variants[${i}][name]`" x-model="row.name" required maxlength="150" placeholder="500 g" class="{{ $small }} mt-1"></label>
                            <label class="text-xs text-gray-500">SKU<input type="text" :name="`variants[${i}][sku]`" x-model="row.sku" required maxlength="60" class="{{ $small }} mt-1 font-mono"></label>
                            <label class="text-xs text-gray-500">{{ __('Barcode') }}<input type="text" :name="`variants[${i}][barcode]`" x-model="row.barcode" maxlength="60" class="{{ $small }} mt-1 font-mono"></label>
                            <label class="text-xs text-gray-500">{{ __('Shelf') }}<input type="text" :name="`variants[${i}][shelf_code]`" x-model="row.shelf_code" maxlength="30" placeholder="A1" class="{{ $small }} mt-1 font-mono"></label>
                            <div class="text-xs text-gray-500">{{ __('Unit') }}
                                <input type="hidden" :name="`variants[${i}][unit]`" :value="row.unit">
                                <div class="mt-1 grid grid-cols-3 overflow-hidden rounded-md border border-gray-300 text-center">
                                    @foreach (['pcs' => __('Pcs'), 'g' => __('Gram'), 'box' => __('Box')] as $unit => $unitLabel)
                                        <button type="button" @click="row.unit = @js($unit)" class="py-1.5 text-xs"
                                            :class="row.unit === @js($unit) ? 'bg-green-900 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'">{{ $unitLabel }}</button>
                                    @endforeach
                                </div>
                            </div>
                            <label class="text-xs text-gray-500"><span x-text="row.unit === 'pcs' ? @js(__('Pieces')) : @js(__('Grams'))"></span><input type="number" step="0.001" min="0.001" :name="`variants[${i}][pack_qty]`" x-model="row.pack_qty" required class="{{ $small }} mt-1"></label>
                            <label class="text-xs text-gray-500">{{ __('Ship weight (g)') }}<input type="number" min="0" :name="`variants[${i}][weight_g]`" x-model="row.weight_g" class="{{ $small }} mt-1"></label>
                            @if ($canSeeCost)
                                <label class="text-xs text-gray-500">{{ __('Cost price') }}<input type="number" step="0.01" min="0" :name="`variants[${i}][cost_price]`" x-model="row.cost_price" class="{{ $small }} mt-1"></label>
                            @endif
                        </div>

                        <div class="mt-3 grid gap-2 md:grid-cols-3">
                            @foreach ($priceLists as $list)
                                <div class="rounded-md bg-gray-50 p-2">
                                    <p class="mb-1 text-xs font-medium text-gray-600">{{ $list->name }}</p>
                                    <div class="grid grid-cols-2 gap-2">
                                        <label class="text-[11px] text-gray-500">{{ __('Regular') }}
                                            <input type="number" step="0.01" min="0" :name="`variants[${i}][prices][{{ $list->system_key }}][regular]`" x-model="row.prices['{{ $list->system_key }}'].regular" @if ($list->system_key === 'online') :required="i === 0" @endif class="{{ $small }} mt-0.5 bg-white">
                                        </label>
                                        <label class="text-[11px] text-gray-500">{{ __('Sale (discount)') }}
                                            <input type="number" step="0.01" min="0" :name="`variants[${i}][prices][{{ $list->system_key }}][sale]`" x-model="row.prices['{{ $list->system_key }}'].sale" class="{{ $small }} mt-0.5 bg-white">
                                        </label>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </template>
            </div>
            <button type="button" @click="add()" class="mt-3 text-sm font-medium text-green-900 hover:underline">+ {{ __('Add variant') }}</button>
        </x-card>
    </div>

    <div class="space-y-6">
        <x-card :title="__('Publishing')">
            <div class="mb-4">
                <label class="mb-2 block text-sm font-medium text-gray-700">{{ __('Status') }}</label>
                <x-simple-select name="status" :options="['active' => __('Active (on website)'), 'draft' => __('Draft'), 'archived' => __('Archived')]" :value="old('status', $product->status)" full-width class="w-full" />
            </div>
            <x-form.input name="image_url" type="url" :label="__('Main image URL')" :value="$product->image_url" maxlength="500" />
            @if ($product->image_url)
                <img src="{{ $product->image_url }}" alt="" class="mb-4 h-24 w-24 rounded-lg border border-gray-200 object-cover">
            @endif
            <x-form.input name="tags" :label="__('Tags (comma separated)')" :value="$product->tags" maxlength="500" />
        </x-card>

        {{-- Blade directives like @js do not run inside a component tag's attributes; use Js::from. --}}
        <x-card :title="__('SEO')" x-data="{ t: {{ \Illuminate\Support\Js::from(old('seo_title', $product->seo_title) ?? '') }}, d: {{ \Illuminate\Support\Js::from(old('seo_description', $product->seo_description) ?? '') }} }">
            <label class="mb-1 block text-sm font-medium text-gray-700" for="seo_title">{{ __('SEO title') }}</label>
            <input id="seo_title" name="seo_title" x-model="t" maxlength="255" class="{{ $input }}">
            <p class="mb-4 mt-1 text-xs" :class="t.length > 60 ? 'text-amber-700' : 'text-gray-400'"><span x-text="t.length"></span>/60</p>
            <label class="mb-1 block text-sm font-medium text-gray-700" for="seo_description">{{ __('Meta description') }}</label>
            <textarea id="seo_description" name="seo_description" x-model="d" rows="3" maxlength="500" class="{{ $input }}"></textarea>
            <p class="mb-4 mt-1 text-xs" :class="d.length > 160 ? 'text-amber-700' : 'text-gray-400'"><span x-text="d.length"></span>/160</p>
            <x-form.input name="focus_keyword" :label="__('Focus keyword')" :value="$product->focus_keyword" maxlength="150" />
            <x-form.input name="slug" :label="__('URL slug')" :value="$product->slug" maxlength="255" :placeholder="__('Made from the name if empty')" />
        </x-card>

        <div class="flex items-center gap-2">
            <x-button>{{ __('Save product') }}</x-button>
            <x-button variant="secondary" :href="route('products.index')">{{ __('Cancel') }}</x-button>
        </div>
    </div>
</div>
