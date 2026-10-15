<?php

namespace App\Services\Customers;

use App\Models\User;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Orders brought in by an import, changed by hand on the import's page: one
 * edited, or some deleted. A customer the import made who is left with
 * nothing is removed with their last order, as Undo would.
 */
class ImportedOrders
{
    public function __construct(private HistoryImport $history) {}

    /**
     * @param  array{name: string, phone: string, cn?: ?string, address?: ?string, items?: ?string, amount?: ?string, date?: ?string, status: string}  $data
     */
    public function edit(object $import, int $pastId, array $data, User $user): void
    {
        $past = DB::table('customer_past_orders')->where('customer_import_id', $import->id)->find($pastId) ?? abort(404, __('That order is not in this import.'));
        $phone = Phone::extract($data['phone'])[0] ?? null;
        if (! $phone) {
            throw ValidationException::withMessages(['phone' => __('This phone is not a full mobile number (11 digits starting 01).')]);
        }
        $cn = trim((string) ($data['cn'] ?? '')) === '' ? null : HistoryImport::cn($data['cn']);
        if (trim((string) ($data['cn'] ?? '')) !== '' && ! $cn) {
            throw ValidationException::withMessages(['cn' => __('This is not a CN (letters and digits, with at least one digit).')]);
        }
        if ($cn && DB::table('customer_past_orders')->where('cn', $cn)->where('id', '!=', $past->id)->exists()) {
            throw ValidationException::withMessages(['cn' => __('Another imported order already has this CN.')]);
        }
        $date = trim((string) ($data['date'] ?? '')) === '' ? $past->ordered_at : $this->history->readDate((string) $data['date']);
        if (! $date) {
            throw ValidationException::withMessages(['date' => __('The date is not readable (write 2026-07-24 or 24/07/2026).')]);
        }
        $name = HistoryImport::unlabel(trim($data['name']));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => __('The name is a must.')]);
        }

        DB::transaction(function () use ($import, $past, $data, $phone, $cn, $date, $name, $user) {
            $now = now();
            $customerId = $this->owner($phone);
            if (! $customerId) {
                // A number nobody has: this order belongs to a new customer (made by this import, so Undo takes them back).
                $customerId = DB::table('customers')->insertGetId(['name' => mb_substr($name, 0, 150), 'primary_phone' => $phone, 'created_by' => $user->id,
                    'customer_import_id' => $import->id, 'created_at' => $now, 'updated_at' => $now]);
                DB::table('customer_phones')->insertOrIgnore(['customer_id' => $customerId, 'phone' => $phone, 'is_primary' => true, 'created_at' => $now, 'updated_at' => $now]);
            } elseif ((int) DB::table('customers')->where('id', $customerId)->value('customer_import_id') === (int) $import->id) {
                // The name is changed only on a customer this import made (never on one IQS already had).
                DB::table('customers')->where('id', $customerId)->update(['name' => mb_substr($name, 0, 150), 'updated_at' => $now]);
            }
            DB::table('customer_past_orders')->where('id', $past->id)->update([
                'customer_id' => $customerId, 'cn' => $cn, 'ordered_at' => $date,
                'amount' => preg_match('/\d[\d,]*(?:\.\d+)?/', Phone::englishDigits((string) ($data['amount'] ?? '')), $m) ? round((float) str_replace(',', '', $m[0]), 2) : 0,
                'status' => $data['status'], 'items' => mb_substr(trim((string) ($data['items'] ?? '')), 0, 1000) ?: null,
                'address' => mb_substr(HistoryImport::unlabel(trim((string) ($data['address'] ?? ''))), 0, 500) ?: null, 'updated_at' => $now,
            ]);
            if ((int) $past->customer_id !== $customerId) {
                $this->dropEmptyCustomers($import, [(int) $past->customer_id]);
            }
        });
    }

    /** Deletes these orders of the import; returns how many went. */
    public function delete(object $import, array $ids): int
    {
        return DB::transaction(function () use ($import, $ids) {
            $rows = DB::table('customer_past_orders')->where('customer_import_id', $import->id)->whereIn('id', $ids)->get(['id', 'customer_id']);
            DB::table('customer_past_orders')->whereIn('id', $rows->pluck('id'))->delete();
            $this->dropEmptyCustomers($import, $rows->pluck('customer_id')->unique()->all());

            return $rows->count();
        });
    }

    /** The customer who has this number, following merges. */
    private function owner(string $phone): ?int
    {
        $id = DB::table('customer_phones')->where('phone', $phone)->value('customer_id') ?? DB::table('customers')->where('primary_phone', $phone)->value('id');
        for ($hop = 0; $id && $hop < 10 && ($m = DB::table('customers')->where('id', $id)->value('merged_into_id')); $hop++) {
            $id = $m;
        }

        return $id ? (int) $id : null;
    }

    /** Customers this import made who have nothing left in IQS go, as on Undo. */
    private function dropEmptyCustomers(object $import, array $ids): void
    {
        DB::table('customers')->whereIn('id', $ids)->where('customer_import_id', $import->id)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('orders')->whereColumn('orders.customer_id', 'customers.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('customer_past_orders as p')->whereColumn('p.customer_id', 'customers.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('customers as m')->whereColumn('m.merged_into_id', 'customers.id'))
            ->delete();
    }
}
