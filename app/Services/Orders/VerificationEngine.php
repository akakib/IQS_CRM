<?php

namespace App\Services\Orders;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\User;
use App\Services\Customers\FraudCheckService;
use Illuminate\Support\Facades\DB;

/**
 * Record Verified and "skip the call" are decided ONLY here, by the
 * admin-editable verification_rules (never by staff choice). Rules run by
 * priority; all conditions of a rule must pass; the first match decides.
 * Every run stores the exact inputs and the matched rule.
 */
class VerificationEngine
{
    public const FIELDS = [
        'provider_success_rate' => 'Courier success rate (%)',
        'provider_total_parcels' => 'Courier parcel count',
        'any_provider_success_rate' => 'Best success rate of any courier (%)',
        'all_providers_success_rate' => 'Worst success rate of all couriers (%)',
        'is_new_customer' => 'New customer (true/false)',
        'own_delivered_count' => 'Delivered orders with us',
        'own_return_count' => 'Returned orders with us',
        'order_total' => 'Order total (৳)',
        'cod_amount' => 'COD amount (৳)',
        'advance_paid_amount' => 'Advance paid (৳, verified or pending)',
        'advance_paid_percent' => 'Advance paid (% of total)',
        'phone_valid' => 'Phone valid (true/false)',
        'address_thana_matched' => 'Address has thana (true/false)',
        'is_duplicate_within_hours' => 'Possible duplicate (true/false)',
        'customer_is_blocked' => 'Customer blocked (true/false)',
        'has_backorder_item' => 'Has pre-order item (true/false)',
    ];

    public const PROVIDER_FIELDS = ['provider_success_rate', 'provider_total_parcels'];

    public function __construct(private FraudCheckService $fraud, private OrderStateMachine $machine, private OrderService $orders) {}

    /** @return array{inputs: array, rule: ?object, outcome: string} decision without changing anything */
    public function evaluate(Order $order): array
    {
        $inputs = $this->inputs($order);
        $rules = DB::table('verification_rules')->where('is_active', true)
            ->whereIn('applies_to_channel', ['all', $order->channel])->orderBy('priority')->orderBy('id')->get();
        $conditions = DB::table('verification_rule_conditions')->whereIn('rule_id', $rules->pluck('id'))->get()->groupBy('rule_id');

        foreach ($rules as $rule) {
            $ok = true;
            foreach ($conditions[$rule->id] ?? [] as $c) {
                $actual = in_array($c->field, self::PROVIDER_FIELDS, true)
                    ? ($inputs['providers'][$c->provider_id][$c->field === 'provider_success_rate' ? 'success_rate' : 'total_parcels'] ?? null)
                    : ($inputs[$c->field] ?? null);
                if (! $this->compare($actual, $c->operator, $c->value)) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return ['inputs' => $inputs, 'rule' => $rule, 'outcome' => $rule->outcome];
            }
        }

        return ['inputs' => $inputs, 'rule' => null, 'outcome' => 'manual_review'];
    }

    /** Run and apply (only while the order is still New). */
    public function run(Order $order, ?User $by = null): string
    {
        $decision = $this->evaluate($order);
        DB::table('verification_runs')->insert([
            'order_id' => $order->id,
            'matched_rule_id' => $decision['rule']?->id,
            'outcome' => $decision['outcome'],
            'inputs_snapshot' => json_encode($decision['inputs']),
            'ran_by' => $by ? 'user' : 'system',
            'user_id' => $by?->id,
            'created_at' => now(),
        ]);
        $order->forceFill(['verification_rule_id' => $decision['rule']?->id])->save();

        $ruleName = $decision['rule']->name ?? __('no rule matched');
        $this->orders->note($order, 'verification', __('Checks: :o (:r).', ['o' => str_replace('_', ' ', $decision['outcome']), 'r' => $ruleName]), $by, [
            'rule_id' => $decision['rule']?->id,
        ]);

        if (OrderStatus::map()[$order->status_id]['key'] !== 'new') {
            return $decision['outcome'];
        }

        match ($decision['outcome']) {
            'record_verified' => $this->machine->transition($order, 'record_verified', null, 'rule', null, $ruleName),
            'record_verified_and_confirmed' => (function () use ($order, $ruleName) {
                $this->machine->transition($order, 'record_verified', null, 'rule', null, $ruleName);
                $this->machine->transition($order, 'confirmed', null, 'rule', null, __('Auto-confirmed: :r', ['r' => $ruleName]));
            })(),
            'hold_for_advance' => (function () use ($order, $ruleName) {
                // The delivery charge in advance; an admin can let it go without (Ask admin).
                $order->forceFill(['advance_required' => max((float) $order->delivery_charge, 1)])->save();
                $this->machine->transition($order, 'hold', null, 'rule',
                    DB::table('status_reasons')->where('reason_type', 'hold')->where('system_key', 'advance_wait')->value('id'),
                    __(':r: delivery charge ৳:a in advance', ['r' => $ruleName, 'a' => number_format((float) $order->delivery_charge)]));
            })(),
            default => null, // manual_review: stays New for a person to look at
        };

        return $decision['outcome'];
    }

    private function inputs(Order $order): array
    {
        $customer = Customer::find($order->customer_id);
        $checks = $customer ? $this->fraud->check($customer) : collect();
        $providers = DB::table('fraud_check_providers')->where('is_active', true)->get()->keyBy('system_key');

        $byProvider = [];
        foreach ($checks as $key => $check) {
            $byProvider[$providers[$key]->id ?? 0] = [
                'key' => $key,
                'success_rate' => $check->success_rate === null ? null : (float) $check->success_rate,
                'total_parcels' => (int) $check->total_parcels,
                // The rest of the score, shown small on the order (cancel/return rates, fraud reports, risk level).
                'detail' => array_intersect_key((array) $check->raw_response, array_flip(['cancellation_ratio', 'return_ratio', 'fraud_categories', 'doubtful_reports', 'level', 'score', 'reasons'])),
            ];
        }
        $rates = collect($byProvider)->pluck('success_rate')->filter(fn ($r) => $r !== null);
        $anyHistory = collect($byProvider)->sum('total_parcels') > 0;

        $advance = (float) DB::table('order_payments')->where('order_id', $order->id)->whereIn('payment_type', ['advance', 'adjustment'])
            ->whereIn('status', ['verified', 'pending_verification'])->sum('amount');
        $total = (float) $order->grand_total;

        return [
            'providers' => $byProvider,
            'any_provider_success_rate' => $rates->max(),
            'all_providers_success_rate' => $rates->min(),
            'is_new_customer' => ! $anyHistory && (int) $customer?->delivered_count === 0 && (int) $customer?->returned_count === 0,
            'own_delivered_count' => (int) $customer?->delivered_count,
            'own_return_count' => (int) $customer?->returned_count,
            'order_total' => $total,
            'cod_amount' => (float) $order->cod_amount,
            'advance_paid_amount' => $advance,
            'advance_paid_percent' => $total > 0 ? round($advance * 100 / $total, 2) : 0,
            'phone_valid' => (bool) preg_match('/^01[3-9]\d{8}$/', (string) $order->ship_phone),
            'address_thana_matched' => filled($order->ship_thana),
            'is_duplicate_within_hours' => (bool) $order->is_duplicate_flag,
            'customer_is_blocked' => $customer?->risk_level === 'blocked',
            'has_backorder_item' => DB::table('order_items as i')->join('product_variants as v', 'v.id', '=', 'i.variant_id')
                ->where('i.order_id', $order->id)->where('v.availability_status', 'backorder')->exists(),
        ];
    }

    private function compare(mixed $actual, string $op, string $expected): bool
    {
        if ($actual === null) {
            return false; // no data never satisfies a condition
        }
        if (is_bool($actual)) {
            $want = in_array(strtolower($expected), ['1', 'true', 'yes'], true);

            return match ($op) { '=' => $actual === $want, '!=' => $actual !== $want, default => false };
        }
        $a = (float) $actual;
        $b = (float) $expected;

        return match ($op) {
            '>=' => $a >= $b, '<=' => $a <= $b, '>' => $a > $b, '<' => $a < $b,
            '=' => abs($a - $b) < 0.0001, '!=' => abs($a - $b) >= 0.0001,
        };
    }
}
