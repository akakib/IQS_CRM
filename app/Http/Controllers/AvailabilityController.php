<?php

namespace App\Http\Controllers;

use App\Models\ProductVariant;
use App\Services\Catalog\ProductService;
use App\Support\Lists\ListState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * "Stock status" tab: In stock / Pre-order / Out of stock per variant.
 * Only roles with products.availability (Owner/Admin by default) change it.
 */
class AvailabilityController extends Controller
{
    public function index(Request $request): View
    {
        $list = ListState::from($request, ['oos_marked_at', 'expected_restock_date'], [
            'status' => ['out_of_stock', 'backorder', 'in_stock', 'review'],
        ], 'desc');
        $status = $list->filter('status') ?? 'out_of_stock';
        $q = mb_strtolower($list->search);

        $variants = ProductVariant::query()
            ->select(['id', 'product_id', 'sku', 'name', 'availability_status', 'expected_restock_date', 'oos_marked_by', 'oos_marked_at', 'oos_review_at'])
            ->with(['product:id,name', 'markedBy:id,name'])
            ->when($status === 'review', fn ($w) => $w->where('availability_status', '!=', 'in_stock')->where('oos_review_at', '<=', now()))
            ->when($status !== 'review', fn ($w) => $w->where('availability_status', $status))
            ->when($q !== '', fn ($w) => $w->where('search_text', 'like', '%'.$q.'%'))
            ->tap(fn ($w) => $list->applySort($w))
            ->paginate($list->perPage)
            ->withQueryString();

        $counts = ProductVariant::query()->selectRaw('availability_status, COUNT(*) as n')->groupBy('availability_status')->pluck('n', 'availability_status');
        $reviewDue = ProductVariant::where('availability_status', '!=', 'in_stock')->where('oos_review_at', '<=', now())->count();

        return view('products.availability', compact('variants', 'list', 'status', 'counts', 'reviewDue'));
    }

    public function update(Request $request, ProductService $products): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'max:500'],
            'ids.*' => ['integer'],
            'status' => ['required', Rule::in(array_keys(ProductVariant::AVAILABILITY))],
            'expected_restock_date' => ['nullable', 'date', 'after_or_equal:today'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $changed = $products->setAvailability($data['ids'], $data['status'], $request->user()->id, $data['expected_restock_date'] ?? null, $data['note'] ?? null);

        return back()->with('success', trans_choice(':count variant updated.|:count variants updated.', $changed, ['count' => $changed]));
    }
}
