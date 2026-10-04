<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\ActivityLogger;
use App\Services\Orders\VerificationEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Settings > Verification rules: which orders are verified or confirmed automatically. */
class VerificationRuleController extends Controller
{
    public function __construct(private ActivityLogger $logger) {}

    public function index(Request $request): View
    {
        return view('settings.verification', [
            'rules' => DB::table('verification_rules')->orderBy('priority')->orderBy('id')->get(),
            'conditions' => DB::table('verification_rule_conditions as c')->leftJoin('fraud_check_providers as p', 'p.id', '=', 'c.provider_id')
                ->get(['c.*', 'p.name as provider'])->groupBy('rule_id'),
            'providers' => DB::table('fraud_check_providers')->where('is_active', true)->pluck('name', 'id')->all(),
            'fields' => VerificationEngine::FIELDS,
            'providerFields' => VerificationEngine::PROVIDER_FIELDS,
            'test' => $request->session()->get('verification_test'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'priority' => ['required', 'integer', 'min:1', 'max:999'],
            'outcome' => ['required', Rule::in(['record_verified', 'record_verified_and_confirmed', 'manual_review', 'hold_for_advance'])],
            'applies_to_channel' => ['required', Rule::in(['all', 'web', 'messenger', 'whatsapp', 'phone'])],
            'conditions' => ['array', 'max:8'],
            'conditions.*.field' => ['required', Rule::in(array_keys(VerificationEngine::FIELDS))],
            'conditions.*.provider_id' => ['nullable', 'integer'],
            'conditions.*.operator' => ['required', Rule::in(['>=', '<=', '=', '!=', '>', '<'])],
            'conditions.*.value' => ['required', 'string', 'max:50'],
        ]);

        DB::transaction(function () use ($data) {
            $id = DB::table('verification_rules')->insertGetId([
                'name' => $data['name'], 'priority' => $data['priority'], 'outcome' => $data['outcome'],
                'applies_to_channel' => $data['applies_to_channel'], 'stop_on_match' => true, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($data['conditions'] ?? [] as $c) {
                DB::table('verification_rule_conditions')->insert([
                    'rule_id' => $id, 'field' => $c['field'], 'operator' => $c['operator'], 'value' => $c['value'],
                    'provider_id' => in_array($c['field'], VerificationEngine::PROVIDER_FIELDS, true) ? ($c['provider_id'] ?? null) : null,
                ]);
            }
            $this->logger->log('verification_rule.created', ['verification_rule', $id], null, $data);
        });

        return back()->with('success', __('Rule added.'));
    }

    public function toggle(int $rule): RedirectResponse
    {
        $row = DB::table('verification_rules')->where('id', $rule)->first();
        abort_unless($row, 404);
        DB::table('verification_rules')->where('id', $rule)->update(['is_active' => ! $row->is_active, 'updated_at' => now()]);
        $this->logger->log('verification_rule.updated', ['verification_rule', $rule], ['is_active' => (bool) $row->is_active], ['is_active' => ! $row->is_active]);

        return back()->with('success', __('Saved.'));
    }

    public function destroy(int $rule): RedirectResponse
    {
        $row = (array) DB::table('verification_rules')->where('id', $rule)->first();
        abort_unless($row, 404);
        DB::table('verification_rules')->where('id', $rule)->delete();
        $this->logger->log('verification_rule.deleted', ['verification_rule', $rule], $row);

        return back()->with('success', __('Rule deleted.'));
    }

    /** "Test on an order": which rule would match and why, without changing the order. */
    public function test(Request $request, VerificationEngine $engine): RedirectResponse
    {
        $data = $request->validate(['order_no' => ['required', 'string']]);
        $order = Order::where('order_no', strtoupper(trim($data['order_no'])))->first();
        if (! $order) {
            return back()->with('error', __('No order with that number.'));
        }
        $d = $engine->evaluate($order);

        return back()->with('verification_test', [
            'order_no' => $order->order_no,
            'rule' => $d['rule']->name ?? __('none'),
            'outcome' => $d['outcome'],
            'inputs' => collect($d['inputs'])->except('providers')->all() + ['couriers' => $d['inputs']['providers']],
        ]);
    }

    /** Order page: run the checks again (e.g. after a phone or amount change). */
    public function rerun(Order $order, Request $request, VerificationEngine $engine): RedirectResponse
    {
        $outcome = $engine->run($order, $request->user());

        return back()->with('success', __('Checks run again: :o.', ['o' => str_replace('_', ' ', $outcome)]));
    }
}
