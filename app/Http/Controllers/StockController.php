<?php

namespace App\Http\Controllers;

use App\Models\ProductVariant;
use App\Services\Catalog\StockService;
use App\Support\Lists\ListState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Stock count: how many of each variant are on the shelf. Type the number you
 * counted; from then on packing takes away and returns bring back. Each
 * variant's changes are listed with who, when and why.
 */
class StockController extends Controller
{
    public function index(Request $request): View
    {
        $list = ListState::from($request, ['name', 'stock_qty', 'stock_counted_at'], ['show' => ['all', 'out', 'in', 'not_counted', 'low']], 'asc');
        // A packer (packaging, no order work: the same people who get the packer's bottom bar), or anyone who may not
        // change stock: the simple page, Out of stock first, then In stock, look only.
        $u = $request->user();
        $simple = ! $u->isOwner() && (($u->can('packaging.view') && ! $u->can('orders.edit')) || ! $u->can('products.availability'));
        $show = $list->filter('show') ?? ($simple ? 'out' : 'all');
        if ($simple && ! in_array($show, ['out', 'in'], true)) {
            $show = 'out';
        }
        $q = mb_strtolower($list->search);

        $variants = ProductVariant::query()
            ->select(['id', 'product_id', 'sku', 'name', 'shelf_code', 'availability_status', 'stock_qty', 'stock_counted_at'])
            ->with('product:id,name,image_url')
            ->when($show === 'not_counted', fn ($w) => $w->whereNull('stock_qty'))
            ->when($show === 'low', fn ($w) => $w->where('stock_qty', '>', 0)->where('stock_qty', '<=', 5))
            // Out of stock: counted to 0 or set by hand (the Stock status page's list, here for everyone who sees stock).
            ->when($show === 'out', fn ($w) => $w->where('availability_status', 'out_of_stock'))
            ->when($show === 'in', fn ($w) => $w->where('availability_status', '<>', 'out_of_stock'))
            ->when($q !== '', fn ($w) => \App\Support\ProductSearch::apply($w, $q))
            ->tap(fn ($w) => $list->applySort($w))
            ->paginate($list->perPage)
            ->withQueryString();

        $counts = ProductVariant::query()->selectRaw('COUNT(*) as all_n, SUM(CASE WHEN stock_qty IS NULL THEN 1 ELSE 0 END) as not_counted,
            SUM(CASE WHEN stock_qty > 0 AND stock_qty <= 5 THEN 1 ELSE 0 END) as low, SUM(CASE WHEN availability_status = \'out_of_stock\' THEN 1 ELSE 0 END) as out_n')->first();

        if ($simple) {
            return view('products.stock-simple', compact('variants', 'list', 'show', 'counts'));
        }

        $last = app(StockService::class)->lastChanges($variants->pluck('id')->all());

        return view('products.stock',compact('variants', 'list', 'show', 'counts', 'last'));
    }

    /** The number counted on the shelf. */
    public function count(Request $request, int $variant, StockService $stock): JsonResponse
    {
        $data = $request->validate(['qty' => ['required', 'numeric', 'min:0', 'max:1000000'], 'note' => ['nullable', 'string', 'max:255']]);
        $stock->count($variant, (float) $data['qty'], $request->user(), $data['note'] ?? null);

        return response()->json(['qty' => round((float) $data['qty'], 3) + 0, 'counted' => now()->format('d M, g:i A'),
            'status' => DB::table('product_variants')->where('id', $variant)->value('availability_status')]);
    }

    /** The last changes of one variant. */
    public function history(int $variant, StockService $stock): JsonResponse
    {
        return response()->json(['rows' => array_map(fn ($r) => ['when' => $r['at']->format('d M, g:i A')] + \Illuminate\Support\Arr::except($r, 'at'), $stock->history($variant))]);
    }
}
