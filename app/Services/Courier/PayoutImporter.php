<?php

namespace App\Services\Courier;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reads a courier payment statement (CSV saved from the Steadfast panel) and
 * puts on each parcel what was paid for it. Columns are found by their
 * header: a consignment id, tracking code or invoice (= our order no), the
 * collected COD and, if present, the courier's charge.
 */
class PayoutImporter
{
    /** @return array{payout_id: int, matched: int, mismatched: int, unknown: int, unknown_ids: list<string>} */
    public function import(string $path, string $fileName, string $paidOn, ?string $reference, User $by): array
    {
        $fh = fopen($path, 'r');
        $header = fgetcsv($fh) ?: [];
        $col = $this->columns($header);

        $rows = [];
        while (($row = fgetcsv($fh)) !== false) {
            $id = fn (?int $i) => $i === null ? null : trim((string) ($row[$i] ?? ''));
            $key = $id($col['cn']) ?: $id($col['tracking']) ?: $id($col['invoice']);
            if (! $key) {
                continue; // blank or total line
            }
            $rows[] = ['cn' => $id($col['cn']), 'tracking' => $id($col['tracking']), 'invoice' => $id($col['invoice']), 'key' => $key,
                'collected' => $this->money($row[$col['collected']] ?? null), 'charge' => $col['charge'] === null ? null : $this->money($row[$col['charge']] ?? null)];
        }
        fclose($fh);
        if (! $rows) {
            throw ValidationException::withMessages(['file' => __('No parcel rows were found in the file.')]);
        }

        return DB::transaction(function () use ($rows, $fileName, $paidOn, $reference, $by) {
            $payoutId = DB::table('courier_payouts')->insertGetId(['paid_on' => $paidOn, 'reference' => $reference, 'file_name' => mb_substr($fileName, 0, 190),
                'uploaded_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]);
            $out = ['matched' => 0, 'mismatched' => 0, 'unknown' => 0, 'unknown_ids' => []];
            $totals = ['collected' => 0.0, 'charge' => 0.0];
            foreach ($rows as $r) {
                $shipment = $this->find($r);
                if (! $shipment) {
                    $out['unknown']++;
                    $out['unknown_ids'][] = $r['key'];

                    continue;
                }
                DB::table('shipments')->where('id', $shipment->id)->update(['payout_id' => $payoutId, 'payout_collected' => $r['collected'], 'payout_charge' => $r['charge'], 'updated_at' => now()]);
                abs((float) $r['collected'] - (float) $shipment->collected_amount) < 1 ? $out['matched']++ : $out['mismatched']++;
                $totals['collected'] += $r['collected'];
                $totals['charge'] += (float) $r['charge'];
            }
            DB::table('courier_payouts')->where('id', $payoutId)->update(['matched' => $out['matched'], 'mismatched' => $out['mismatched'], 'unknown' => $out['unknown'],
                'total_collected' => $totals['collected'], 'total_charge' => $totals['charge']]);

            return ['payout_id' => $payoutId] + $out;
        });
    }

    /** @return array{cn: ?int, tracking: ?int, invoice: ?int, collected: int, charge: ?int} */
    private function columns(array $header): array
    {
        $find = function (callable $test) use ($header): ?int {
            foreach ($header as $i => $h) {
                if ($test(strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))))) {
                    return $i;
                }
            }

            return null;
        };
        $col = [
            'cn' => $find(fn ($h) => str_contains($h, 'consignment') || $h === 'cid' || $h === 'cn'),
            'tracking' => $find(fn ($h) => str_contains($h, 'tracking')),
            'invoice' => $find(fn ($h) => str_contains($h, 'invoice')),
            'collected' => $find(fn ($h) => str_contains($h, 'collect') || (str_contains($h, 'cod') && ! str_contains($h, 'charge') && ! str_contains($h, 'fee'))),
            'charge' => $find(fn ($h) => str_contains($h, 'charge') || str_contains($h, 'fee')),
        ];
        if (($col['cn'] ?? $col['tracking'] ?? $col['invoice']) === null || $col['collected'] === null) {
            throw ValidationException::withMessages(['file' => __('The file needs a consignment id (or tracking code or invoice) column and a collected amount column. Columns found: :c', ['c' => implode(', ', array_filter($header))])]);
        }

        return $col;
    }

    private function find(array $r): ?object
    {
        $q = DB::table('shipments as s')->orderByDesc('s.id');
        if ($r['cn']) {
            return (clone $q)->where('s.consignment_id', $r['cn'])->first(['s.id', 's.collected_amount']);
        }
        if ($r['tracking']) {
            return (clone $q)->where('s.tracking_code', $r['tracking'])->first(['s.id', 's.collected_amount']);
        }

        return (clone $q)->join('orders as o', 'o.id', '=', 's.order_id')->where('o.order_no', strtoupper($r['invoice']))->first(['s.id', 's.collected_amount']);
    }

    private function money(mixed $v): float
    {
        return round((float) preg_replace('/[^\d.\-]/', '', (string) $v), 2);
    }
}
