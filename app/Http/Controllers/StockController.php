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
        $list = ListState::from($request, ['name', 'stock_qty', 'stock_counted_at'], ['show' => ['all', 'not_counted', 'low', 'zero']], 'asc');
        $show = $list->filter('show') ?? 'all';
        $q = mb_strtolower($list->search);

        $variants = ProductVariant::query()
            ->select(['id', 'product_id', 'sku', 'name', 'shelf_code', 'availability_status', 'stock_qty', 'stock_counted_at'])
            ->with('product:id,name')
            ->when($show === 'not_counted', fn ($w) => $w->whereNull('stock_qty'))
            ->when($show === 'low', fn ($w) => $w->whereBetween('stock_qty', [1, 5]))
            ->when($show === 'zero', fn ($w) => $w->where('stock_qty', '<=', 0))
            ->when($q !== '', fn ($w) => $w->where('search_text', 'like', '%'.$q.'%'))
            ->tap(fn ($w) => $list->applySort($w))
            ->paginate($list->perPage)
            ->withQueryString();

        $counts = ProductVariant::query()->selectRaw('COUNT(*) as all_n, SUM(CASE WHEN stock_qty IS NULL THEN 1 ELSE 0 END) as not_counted,
            SUM(CASE WHEN stock_qty BETWEEN 1 AND 5 THEN 1 ELSE 0 END) as low, SUM(CASE WHEN stock_qty <= 0 THEN 1 ELSE 0 END) as zero')->first();

        return view('products.stock', compact('variants', 'list', 'show', 'counts'));
    }

    /** The number counted on the shelf. */
    public function count(Request $request, int $variant, StockService $stock): JsonResponse
    {
        $data = $request->validate(['qty' => ['required', 'integer', 'min:0', 'max:1000000'], 'note' => ['nullable', 'string', 'max:255']]);
        $stock->count($variant, (int) $data['qty'], $request->user(), $data['note'] ?? null);

        return response()->json(['qty' => (int) $data['qty'], 'counted' => now()->format('d M, g:i A'),
            'status' => DB::table('product_variants')->where('id', $variant)->value('availability_status')]);
    }

    /** The last changes of one variant. */
    public function history(int $variant): JsonResponse
    {
        $rows = DB::table('stock_movements as m')->leftJoin('users as u', 'u.id', '=', 'm.user_id')->leftJoin('orders as o', 'o.id', '=', 'm.order_id')
            ->where('m.variant_id', $variant)->orderByDesc('m.id')->limit(50)
            ->get(['m.qty_change', 'm.qty_after', 'm.reason', 'm.note', 'm.created_at', 'u.name as person', 'o.order_no']);

        return response()->json(['rows' => $rows->map(fn ($r) => [
            'when' => \Illuminate\Support\Carbon::parse($r->created_at)->format('d M, g:i A'),
            'change' => $r->qty_change, 'after' => $r->qty_after, 'why' => __(StockService::REASONS[$r->reason] ?? $r->reason),
            'who' => $r->person, 'order' => $r->order_no, 'note' => $r->note,
        ])]);
    }
}
