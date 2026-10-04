<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductRequest;
use App\Models\Category;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Catalog\ProductService;
use App\Support\Lists\ListState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function __construct(private ProductService $products) {}

    public function index(Request $request): View
    {
        $list = ListState::from($request, ['name', 'updated_at'], [
            'category' => 'int',
            'status' => ['active', 'draft', 'archived'],
            'availability' => array_keys(ProductVariant::AVAILABILITY),
        ]);
        $onlineId = $this->onlineListId();
        $q = mb_strtolower($list->search);

        // COUNT + SELECT + variants + their online price = 4 queries.
        $products = Product::query()
            ->select(['id', 'category_id', 'name', 'status', 'image_url', 'updated_at'])
            ->with([
                'category:id,name',
                'variants' => fn ($v) => $v->select(['id', 'product_id', 'name', 'sku', 'availability_status', 'is_active']),
                'variants.prices' => fn ($p) => $p->where('price_list_id', $onlineId)->select(['variant_id', 'price_list_id', 'regular_price', 'sale_price', 'sale_starts_at', 'sale_ends_at']),
            ])
            ->when($q !== '', fn ($query) => $query->whereIn('id', ProductVariant::query()->select('product_id')
                ->where('search_text', 'like', '%'.$q.'%')))
            ->when($list->filter('category'), fn ($query, $id) => $query->where('category_id', $id))
            ->when($list->filter('status'), fn ($query, $s) => $query->where('status', $s))
            ->when($list->filter('availability'), fn ($query, $a) => $query->whereIn('id', ProductVariant::query()->select('product_id')->where('availability_status', $a)))
            ->tap(fn ($query) => $list->applySort($query))
            ->paginate($list->perPage)
            ->withQueryString();

        return view('products.index', [
            'products' => $products,
            'list' => $list,
            'categoryOptions' => Category::orderBy('name')->pluck('name', 'id')->all(),
        ]);
    }

    public function create(): View
    {
        return view('products.create', $this->formData(new Product(['status' => 'active', 'base_unit' => 'pcs'])));
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $product = $this->products->save(new Product, $request->validated(), $request->validated('variants'), $request->user()->id);

        return redirect()->route('products.edit', $product)->with('success', __('Product ":name" created.', ['name' => $product->name]));
    }

    public function edit(Product $product, Request $request): View
    {
        $history = DB::table('price_history as h')
            ->join('product_variants as v', 'v.id', '=', 'h.variant_id')
            ->leftJoin('price_lists as l', 'l.id', '=', 'h.price_list_id')
            ->leftJoin('users as u', 'u.id', '=', 'h.user_id')
            ->where('v.product_id', $product->id)
            ->when(! $request->user()->canSeeField('cost_price'), fn ($q) => $q->where('h.field', '!=', 'cost'))
            ->orderByDesc('h.id')->limit(30)
            ->get(['h.field', 'h.old_value', 'h.new_value', 'h.source', 'h.created_at', 'v.name as variant', 'l.name as list', 'u.name as user']);

        return view('products.edit', $this->formData($product) + ['history' => $history]);
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $this->products->save($product, $request->validated(), $request->validated('variants'), $request->user()->id);

        return back()->with('success', __('Product ":name" saved.', ['name' => $product->name]));
    }

    public function destroy(Product $product): RedirectResponse
    {
        DB::transaction(function () use ($product) {
            $product->variants()->get()->each->delete();
            $product->delete();
        });

        return redirect()->route('products.index')->with('success', __('Product ":name" deleted.', ['name' => $product->name]));
    }

    /**
     * Quick search for order entry: at most 20 variants, one query.
     * Matches product name, variant name, SKU or barcode.
     */
    public function search(Request $request): JsonResponse
    {
        $q = mb_strtolower(trim((string) $request->query('q', '')));
        $onlineId = $this->onlineListId();

        $rows = DB::table('product_variants as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('variant_prices as vp', fn ($j) => $j->on('vp.variant_id', '=', 'v.id')->where('vp.price_list_id', $onlineId))
            ->whereNull('v.deleted_at')->whereNull('p.deleted_at')->where('v.is_active', true)->where('p.status', '!=', 'archived')
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('v.search_text', 'like', '%'.$q.'%')->orWhere('v.barcode', $q)))
            ->orderByRaw('CASE WHEN v.sku = ? OR v.barcode = ? THEN 0 WHEN v.search_text LIKE ? THEN 1 ELSE 2 END', [$q, $q, $q.'%'])
            ->orderBy('p.name')
            ->limit(20)
            ->get(['v.id', 'v.sku', 'v.name as variant', 'v.availability_status', 'p.name as product', 'vp.regular_price', 'vp.sale_price']);

        return response()->json($rows->map(fn ($r) => [
            'value' => $r->id,
            'label' => $r->product.($r->variant !== 'Default' ? ' · '.$r->variant : ''),
            'sub' => collect([
                $r->sku,
                $r->sale_price !== null ? '৳'.number_format((float) $r->sale_price, 2) : ($r->regular_price !== null ? '৳'.number_format((float) $r->regular_price, 2) : null),
                $r->availability_status !== 'in_stock' ? strtoupper(__(ProductVariant::AVAILABILITY[$r->availability_status])) : null,
            ])->filter()->join(' · '),
            'sellable' => $r->availability_status !== 'out_of_stock',
        ]));
    }

    private function formData(Product $product): array
    {
        $lists = PriceList::where('is_active', true)->orderBy('sort_order')->get(['id', 'system_key', 'name']);
        $variants = $product->exists
            ? $product->variants()->with('prices')->get()->map(fn (ProductVariant $v) => [
                'id' => $v->id, 'name' => $v->name, 'sku' => $v->sku, 'barcode' => $v->barcode, 'unit' => $v->unit,
                'pack_qty' => (float) $v->pack_qty, 'weight_g' => $v->weight_g, 'cost_price' => $v->cost_price,
                'is_active' => $v->is_active, 'availability' => $v->availabilityLabel(),
                'prices' => $lists->mapWithKeys(fn ($l) => [$l->system_key => [
                    'regular' => optional($v->prices->firstWhere('price_list_id', $l->id))->regular_price,
                    'sale' => optional($v->prices->firstWhere('price_list_id', $l->id))->sale_price,
                ]])->all(),
            ])->all()
            : [];

        return [
            'product' => $product,
            'variants' => $variants,
            'priceLists' => $lists,
            'categoryOptions' => Category::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
        ];
    }

    private function onlineListId(): ?int
    {
        return DB::table('price_lists')->where('system_key', PriceList::ONLINE)->value('id');
    }
}
