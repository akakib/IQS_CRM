<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Services\ActivityLogger;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerService
{
    public function __construct(private ActivityLogger $logger) {}

    /** The customer owning this number (any of their numbers), or null. */
    public function findByPhone(?string $phone): ?Customer
    {
        $phone = Phone::normalize($phone);
        if (! $phone) {
            return null;
        }
        $id = DB::table('customer_phones')->where('phone', $phone)->value('customer_id');

        return $id ? Customer::find($id) : null;
    }

    /**
     * @param  array  $data  name, primary_phone, whatsapp_number?, messenger_psid?, risk_level?, blocked_reason?, note?, marketing_consent?
     * @param  list<string>  $extraPhones
     * @param  list<array>  $addresses  id?, address_line, district?, thana?, zone_id?, is_default?
     */
    public function save(Customer $customer, array $data, array $extraPhones, array $addresses, ?int $userId): Customer
    {
        $primary = Phone::normalize($data['primary_phone'] ?? null);
        $extras = collect($extraPhones)->map(fn ($p) => Phone::normalize($p))->filter()->reject(fn ($p) => $p === $primary)->unique()->values();

        // A number belongs to exactly one customer.
        $taken = DB::table('customer_phones as cp')->join('customers as c', 'c.id', '=', 'cp.customer_id')
            ->whereIn('cp.phone', $extras->push($primary)->all())
            ->when($customer->exists, fn ($q) => $q->where('cp.customer_id', '!=', $customer->id))
            ->first(['cp.phone', 'c.id', 'c.name']);
        if ($taken) {
            throw ValidationException::withMessages(['primary_phone' => __(':phone already belongs to :name (#:id).', ['phone' => $taken->phone, 'name' => $taken->name, 'id' => $taken->id])]);
        }
        $extras->pop();

        return DB::transaction(function () use ($customer, $data, $primary, $extras, $addresses, $userId) {
            $consentChanged = (bool) ($data['marketing_consent'] ?? false) !== (bool) $customer->marketing_consent;
            $customer->fill([
                'name' => trim($data['name']),
                'primary_phone' => $primary,
                'whatsapp_number' => Phone::normalize($data['whatsapp_number'] ?? null),
                'messenger_psid' => ($data['messenger_psid'] ?? null) ?: null,
                'risk_level' => $data['risk_level'] ?? $customer->risk_level ?? 'normal',
                'blocked_reason' => ($data['risk_level'] ?? 'normal') === 'normal' ? null : (($data['blocked_reason'] ?? null) ?: null),
                'note' => ($data['note'] ?? null) ?: null,
                'marketing_consent' => (bool) ($data['marketing_consent'] ?? false),
            ]);
            if ($consentChanged) {
                $customer->consent_at = $customer->marketing_consent ? now() : null;
            }
            if (! $customer->exists) {
                $customer->created_by = $userId;
            }
            $customer->save();

            // Phones: replace the set (primary first).
            DB::table('customer_phones')->where('customer_id', $customer->id)->whereNotIn('phone', [$primary, ...$extras])->delete();
            foreach ([$primary, ...$extras] as $phone) {
                DB::table('customer_phones')->updateOrInsert(
                    ['phone' => $phone],
                    ['customer_id' => $customer->id, 'is_primary' => $phone === $primary, 'updated_at' => now(), 'created_at' => now()],
                );
            }

            $this->saveAddresses($customer, $addresses);

            // Pick up column defaults (counts, risk) set by the database.
            return $customer->refresh();
        });
    }

    /** @param list<array> $addresses */
    public function saveAddresses(Customer $customer, array $addresses): void
    {
        $addresses = array_values(array_filter($addresses, fn ($a) => trim((string) ($a['address_line'] ?? '')) !== ''));
        $defaultIndex = collect($addresses)->search(fn ($a) => ! empty($a['is_default'])) ?: 0;
        $kept = [];

        foreach ($addresses as $i => $a) {
            $values = [
                'address_line' => trim($a['address_line']),
                'district' => ($a['district'] ?? null) ?: null,
                'thana' => ($a['thana'] ?? null) ?: null,
                'zone_id' => ($a['zone_id'] ?? null) ?: null,
                'is_default' => $i === $defaultIndex,
            ];
            $address = ! empty($a['id']) ? $customer->addresses()->whereKey($a['id'])->first() : null;
            $address ? $address->update($values) : $address = $customer->addresses()->create($values);
            $kept[] = $address->id;
        }

        $customer->addresses()->whereNotIn('id', $kept)->delete();
    }

    /**
     * Merge $duplicate into $keep: numbers, addresses, counts and (from Step 2)
     * orders move over; the duplicate is soft-deleted and points at $keep.
     */
    public function merge(Customer $keep, Customer $duplicate, ?int $userId): Customer
    {
        abort_if($keep->is($duplicate), 422, 'Cannot merge a customer into itself.');

        return DB::transaction(function () use ($keep, $duplicate) {
            $before = ['duplicate' => $duplicate->only(['id', 'name', 'primary_phone', 'orders_count'])];

            DB::table('customer_phones')->where('customer_id', $duplicate->id)->update(['customer_id' => $keep->id, 'is_primary' => false]);
            DB::table('customer_addresses')->where('customer_id', $duplicate->id)->update(['customer_id' => $keep->id, 'is_default' => false]);
            DB::table('customer_fraud_checks')->where('customer_id', $duplicate->id)->update(['customer_id' => $keep->id]);
            if (\Illuminate\Support\Facades\Schema::hasTable('orders')) {
                DB::table('orders')->where('customer_id', $duplicate->id)->update(['customer_id' => $keep->id]);
            }

            $keep->update([
                'orders_count' => $keep->orders_count + $duplicate->orders_count,
                'delivered_count' => $keep->delivered_count + $duplicate->delivered_count,
                'returned_count' => $keep->returned_count + $duplicate->returned_count,
                'first_order_at' => collect([$keep->first_order_at, $duplicate->first_order_at])->filter()->min(),
                'risk_level' => collect(['normal' => 0, 'watch' => 1, 'blocked' => 2])
                    ->only([$keep->risk_level, $duplicate->risk_level])->sortDesc()->keys()->first(),
            ]);

            $duplicate->update(['merged_into_id' => $keep->id]);
            $duplicate->delete();

            $this->logger->log('customer.merged', $keep, $before, ['kept' => $keep->id]);

            return $keep;
        });
    }
}
