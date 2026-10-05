<?php

namespace App\Http\Controllers;

use App\Models\OrderStatus;
use App\Services\ActivityLogger;
use App\Services\Orders\DeliveryCharges;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Settings > Orders: delivery zones and charges, reasons, status names and colours. */
class OrderSettingsController extends Controller
{
    public function __construct(private ActivityLogger $logger) {}

    public function charges(): View
    {
        return view('settings.charges', [
            'zones' => DB::table('delivery_zones')->orderBy('sort_order')->get(),
            'rules' => DB::table('delivery_charge_rules as r')->leftJoin('delivery_zones as z', 'z.id', '=', 'r.zone_id')
                ->orderBy('r.priority')->orderBy('z.sort_order')->orderBy('r.min_weight_g')->get(['r.*', 'z.name as zone']),
        ]);
    }

    public function storeZone(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100', Rule::unique('delivery_zones', 'name')]]);
        $id = DB::table('delivery_zones')->insertGetId($data + ['is_active' => true, 'sort_order' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->logger->log('delivery_zone.created', ['delivery_zone', $id], null, $data);

        return back()->with('success', __('Zone added.'));
    }

    public function storeRule(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'zone_id' => ['nullable', Rule::exists('delivery_zones', 'id')],
            'min_weight_g' => ['required', 'integer', 'min:0'],
            'max_weight_g' => ['nullable', 'integer', 'gte:min_weight_g'],
            'min_order_total' => ['required', 'numeric', 'min:0'],
            'charge' => ['required', 'numeric', 'min:0'],
            'priority' => ['required', 'integer', 'min:1', 'max:999'],
        ]);
        $id = DB::table('delivery_charge_rules')->insertGetId($data + ['is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DeliveryCharges::forget();
        $this->logger->log('delivery_rule.created', ['delivery_rule', $id], null, $data);

        return back()->with('success', __('Charge rule added.'));
    }

    public function toggleRule(int $rule): RedirectResponse
    {
        $row = DB::table('delivery_charge_rules')->where('id', $rule)->first();
        abort_unless($row, 404);
        DB::table('delivery_charge_rules')->where('id', $rule)->update(['is_active' => ! $row->is_active, 'updated_at' => now()]);
        DeliveryCharges::forget();
        $this->logger->log('delivery_rule.updated', ['delivery_rule', $rule], ['is_active' => (bool) $row->is_active], ['is_active' => ! $row->is_active]);

        return back()->with('success', $row->is_active ? __('Rule switched off.') : __('Rule switched on.'));
    }

    public function reasons(): View
    {
        return view('settings.reasons', [
            'reasons' => DB::table('status_reasons')->orderBy('reason_type')->orderBy('sort_order')->get()->groupBy('reason_type'),
            'statuses' => OrderStatus::orderBy('sort_order')->get(),
        ]);
    }

    public function storeReason(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'reason_type' => ['required', Rule::in(['cancel', 'hold', 'amendment', 'return', 'reassign', 'break', 'status'])],
            'label_en' => ['required', 'string', 'max:150'],
            'blame_stage' => ['required', Rule::in(['none', 'sales', 'verification', 'packaging', 'dispatch', 'courier', 'customer'])],
            'counts_as_break' => ['boolean'],
        ]);
        $data['counts_as_break'] = $data['reason_type'] !== 'break' || ! empty($data['counts_as_break']);
        $id = DB::table('status_reasons')->insertGetId($data + ['release_mode' => 'manual', 'is_active' => true, 'sort_order' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->logger->log('status_reason.created', ['status_reason', $id], null, $data);

        return back()->with('success', __('Reason added.'));
    }

    public function toggleReason(int $reason): RedirectResponse
    {
        $row = DB::table('status_reasons')->where('id', $reason)->first();
        abort_unless($row, 404);
        DB::table('status_reasons')->where('id', $reason)->update(['is_active' => ! $row->is_active, 'updated_at' => now()]);
        $this->logger->log('status_reason.updated', ['status_reason', $reason], ['is_active' => (bool) $row->is_active], ['is_active' => ! $row->is_active]);

        return back()->with('success', __('Saved.'));
    }

    /** Name and colour only: what a status does is fixed by its system key. */
    public function updateStatus(Request $request, int $status): RedirectResponse
    {
        $data = $request->validate(['name_en' => ['required', 'string', 'max:100'], 'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/']]);
        $model = OrderStatus::findOrFail($status);
        $before = $model->only(['name_en', 'color']);
        $model->update($data);
        $this->logger->log('order_status.updated', ['order_status', $status], $before, $data);

        return back()->with('success', __('Status saved.'));
    }
}
