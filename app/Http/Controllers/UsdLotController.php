<?php

namespace App\Http\Controllers;

use App\Services\ActivityLogger;
use App\Services\Marketing\AdCostService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Marketing > USD lots: dollars bought from vendors, what is left (FIFO) and what we still owe. */
class UsdLotController extends Controller
{
    public function __construct(private AdCostService $costs, private ActivityLogger $logger) {}

    public function index(Request $request): View
    {
        $perPage = in_array((int) $request->query('per_page'), [25, 50, 100], true) ? (int) $request->query('per_page') : 25;
        $vendor = $request->integer('vendor') ?: null;

        $lots = DB::table('usd_lots as l')->join('ad_vendors as v', 'v.id', '=', 'l.vendor_id')
            ->when($vendor, fn ($q) => $q->where('l.vendor_id', $vendor))
            ->when($request->query('open') === '1', fn ($q) => $q->where('l.usd_remaining', '>', 0))
            ->orderByDesc('l.purchased_on')->orderByDesc('l.id')
            ->select('l.*', 'v.name as vendor')->paginate($perPage)->withQueryString();

        return view('marketing.lots', [
            'lots' => $lots,
            'vendors' => $this->costs->vendorBalances(),
            'methods' => DB::table('payment_methods')->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
            'payments' => DB::table('vendor_payments as p')->join('ad_vendors as v', 'v.id', '=', 'p.vendor_id')
                ->leftJoin('payment_methods as m', 'm.id', '=', 'p.payment_method_id')
                ->orderByDesc('p.id')->limit(10)->get(['p.*', 'v.name as vendor', 'm.name as method']),
            'filters' => ['vendor' => $vendor, 'open' => $request->query('open') === '1'],
            'perPage' => $perPage,
        ]);
    }

    public function storeVendor(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120', 'unique:ad_vendors,name'], 'phone' => ['nullable', 'regex:/^01[3-9]\d{8}$/'], 'note' => ['nullable', 'string', 'max:255']]);
        $id = DB::table('ad_vendors')->insertGetId($data + ['is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->logger->log('ad_vendor.created', ['ad_vendor', $id], null, $data);

        return back()->with('success', __('Vendor added.'));
    }

    public function storeLot(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'vendor_id' => ['required', 'integer', 'exists:ad_vendors,id'],
            'purchased_on' => ['required', 'date', 'before_or_equal:today'],
            'usd' => ['required', 'numeric', 'min:1', 'max:1000000'],
            'rate' => ['required', 'numeric', 'min:50', 'max:500'],
            'paid_bdt' => ['nullable', 'numeric', 'min:0'],
            'due_date' => ['nullable', 'date'],
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'transaction_ref' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        if ((float) ($data['paid_bdt'] ?? 0) > 0 && empty($data['payment_method_id'])) {
            return back()->withInput()->withErrors(['payment_method_id' => __('Choose how it was paid.')]);
        }
        $this->costs->addLot($data, $request->user());

        return back()->with('success', __('Lot added. Ad spend was re-costed oldest dollars first.'));
    }

    public function storePayment(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'vendor_id' => ['required', 'integer', 'exists:ad_vendors,id'],
            'amount_bdt' => ['required', 'numeric', 'min:1'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'transaction_ref' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $this->costs->pay((int) $data['vendor_id'], $data, $request->user());

        return back()->with('success', __('Payment recorded.'));
    }
}
