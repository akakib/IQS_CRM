<?php

namespace App\Services\Customers;

use App\Models\Order;
use App\Support\Phone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use ZipArchive;

/**
 * Past orders from any CSV or Excel export. The person maps each column to a
 * field (guessed from the header), says what the file's status and medium
 * words mean, checks a preview, then imports. Rows sharing an order number
 * become one order (WooCommerce gives one row per product).
 */
class HistoryImport
{
    public const FIELDS = [
        'phone' => 'Phone', 'cn' => 'CN (courier consignment)', 'name' => 'Name', 'order_no' => 'Order number', 'date' => 'Order date', 'amount' => 'Amount',
        'status' => 'Status', 'medium' => 'Medium', 'items' => 'Products', 'qty' => 'Quantity', 'address' => 'Address',
    ];

    public const STATUSES = ['delivered' => 'Delivered', 'cancelled' => 'Cancelled', 'returned' => 'Returned', 'refunded' => 'Refunded', 'pending' => 'Not finished', 'skip' => 'Skip'];

    /** Header words that point to a field, most specific first. */
    private const GUESS = [
        'phone' => ['billing phone', 'recipient phone', 'customer phone', 'phone number', 'contact number', 'phone', 'mobile', 'contact'],
        'name' => ['billing first name', 'billing last name', 'recipient name', 'customer name', 'receiver name', 'full name', 'name'],
        'cn' => ['consignment id', 'consignment', 'cn', 'tracking code', 'tracking id', 'tracking'],
        'order_no' => ['order number', 'order no', 'order id', 'merchant order id', 'invoice'],
        'date' => ['order date', 'created at', 'date created', 'created', 'date'],
        'amount' => ['order total amount', 'order total', 'collectable amount', 'amount to collect', 'cod amount', 'total amount', 'total', 'amount'],
        'status' => ['order status', 'delivery status', 'status'],
        'medium' => ['utm source', 'order source', 'source', 'channel', 'medium'],
        'items' => ['item name', 'product name', 'products', 'product', 'item description', 'description'],
        'qty' => ['quantity', 'qty'],
        'address' => ['billing address 1', 'shipping address 1', 'recipient address', 'address', 'billing city', 'city', 'area'],
    ];

    private const WORDS = [
        'delivered' => ['completed', 'complete', 'delivered', 'paid', 'partial delivery', 'partially delivered', 'partial'],
        'cancelled' => ['cancelled', 'canceled', 'cancel', 'failed', 'trash'],
        'returned' => ['returned', 'return', 'paid return', 'partial return'],
        'refunded' => ['refunded', 'refund'],
        'pending' => ['processing', 'pending', 'on-hold', 'on hold', 'in transit', 'hold'],
    ];

    /** Word stems tried inside a longer phrase ("Pickup Cancelled", "Partial Delivery"), in this order. */
    private const STEMS = [
        'returned' => ['return'], 'refunded' => ['refund'], 'cancelled' => ['cancel', 'fail'],
        'delivered' => ['deliver', 'complete'], 'pending' => ['pending', 'process', 'transit', 'hold'],
    ];

    /** The most rows one file may have; a longer file is refused, never cut silently. */
    private const MAX_ROWS = 50000;

    /** @return array{0: list<string>, 1: list<list<string>>} header row and data rows */
    public function read(string $path, string $ext): array
    {
        $rows = strtolower($ext) === 'xlsx' ? $this->xlsx($path) : $this->csv($path);
        $rows = array_values(array_filter($rows, fn ($r) => implode('', array_map('trim', $r)) !== ''));
        if (count($rows) < 2) {
            throw new RuntimeException(__('The file has no rows under the header.'));
        }
        if (count($rows) > self::MAX_ROWS + 1) {
            throw new RuntimeException(__('The file has more than :n rows. Split it and import the parts.', ['n' => number_format(self::MAX_ROWS)]));
        }
        $header = array_map(fn ($h) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)), array_shift($rows));
        $seen = [];
        foreach ($header as $i => $h) {
            $h = $h === '' ? __('Column :n', ['n' => $i + 1]) : $h;
            // Two columns with one name would share one mapping: number the repeats.
            $key = mb_strtolower($h);
            $seen[$key] = ($seen[$key] ?? 0) + 1;
            $header[$i] = $seen[$key] > 1 ? $h.' ('.$seen[$key].')' : $h;
        }
        $width = count($header);

        return [$header, array_map(fn ($r) => array_slice(array_pad(array_map(fn ($v) => self::cell((string) $v), $r), $width, ''), 0, $width), $rows)];
    }

    /** "Address: House 12, Mirpur" or "Name: Rahima" from a hand-filled sheet: the label at the front is not part of the value. */
    public static function unlabel(string $v): string
    {
        $who = "(?:customer|cust|client|buyer|recipient|receiver|billing|shipping|ship|delivery|present|permanent|first|last|full|alt|alternate|other|contact|home|office|order"
            .'|গ্রাহক(?:ের)?|কাস্টমার|ক্রেতা(?:র)?|প্রাপক(?:ের)?|ডেলিভারি|পণ্যের|অর্ডার)';
        $what = '(?:name|add(?:ress|r|rs)?|adress|adrs|phone|ph|mobile|mob|tel|number|cell|contact|products?|items?|qty|quantity|amount|total|price|note|city|invoice|status'
            .'|source|channel|medium|date|time|cn|tracking|consignment|order'
            .'|নাম|ঠিকানা|ফোন|মোবাইল|নম্বর|নাম্বার|নং|পণ্য|পরিমাণ|টাকা|দাম|তারিখ|শহর)';
        $tail = '(?:\s*(?:name|no\.?|number|id|code|date|address|নাম|নং))?';
        // "Customer Name:", "Tracking ID:", "Customer's:", "নাম:", then the colon, then an optional dash ("Name:- Rahima").
        $pattern = "/^\s*(?:$who(?:'s)?\s*)?(?:$what$tail|$who(?:'s)?)\s*[:：ঃ]\s*(?:[-–]\s*)?/iu";

        return trim(preg_replace($pattern, '', $v, 1));
    }

    /** Excel keeps numbers as numbers: 1.326885301E9 is a phone (1326885301), 1.0 is 1. */
    private static function cell(string $v): string
    {
        $v = trim($v);
        // Expand only when nothing was cut: 1.326885301E9 is exact, 8.80133E+12 lost digits and stays as it is (a bad phone, not a wrong one).
        if (preg_match('/^\d+(?:\.(\d+))?E\+?(\d+)$/i', $v, $m) && strlen($m[1] ?? '') >= (int) $m[2]) {
            return sprintf('%.0f', (float) $v);
        }

        return preg_match('/^\d+\.0+$/', $v) ? substr($v, 0, strpos($v, '.')) : $v;
    }

    /** Per column: a sample and its distinct values (when few), for the mapping screen. */
    public function columns(array $header, array $rows): array
    {
        return array_map(function ($h, $i) use ($rows) {
            $vals = array_column($rows, $i);
            $distinct = array_values(array_unique(array_filter($vals, fn ($v) => $v !== '')));

            return ['header' => $h, 'sample' => $distinct[0] ?? '', 'values' => count($distinct) <= 40 ? $distinct : null];
        }, $header, array_keys($header));
    }

    /** @return array<string, string> header => field ('' = ignore); each field once, name and address may take two columns */
    public function guess(array $header): array
    {
        $map = array_fill_keys($header, '');
        $lower = array_map(fn ($h) => strtolower(preg_replace('/[_\-]+/', ' ', $h)), $header);
        foreach (self::GUESS as $field => $words) {
            $taken = 0;
            foreach ($words as $w) {
                foreach ($lower as $i => $h) {
                    // One-word guesses that are easy to mistake match the whole header only (invoice_id is a payout invoice, not an order).
                    $exact = in_array($w, ['invoice', 'cn', 'tracking', 'name'], true);
                    if ($map[$header[$i]] === '' && ($h === $w || (! $exact && str_contains($h, $w))) && ! ($field === 'amount' && str_contains($h, 'item'))
                        && ! ($field === 'name' && preg_match('/merchant|store|shop|product|item|hub|zone|file|user|sender/', $h))
                        && ! ($field === 'phone' && str_contains($h, 'name'))) {
                        $map[$header[$i]] = $field;
                        $taken++;
                        if ($taken >= (in_array($field, ['name'], true) ? 2 : 1)) {
                            continue 3;
                        }
                    }
                }
            }
        }

        return $map;
    }

    /** What a status or medium word most likely means. */
    public function guessValue(string $kind, string $word): string
    {
        $w = strtolower(trim(preg_replace('/^wc-/', '', $word)));
        if ($kind === 'status') {
            foreach (self::WORDS as $status => $list) {
                if (in_array($w, $list, true)) {
                    return $status;
                }
            }
            foreach (self::STEMS as $status => $stems) {
                foreach ($stems as $stem) {
                    if (preg_match('/\b'.preg_quote($stem, '/').'/', $w)) {
                        return $status;
                    }
                }
            }

            return 'skip';
        }
        foreach (Order::CHANNELS as $key => $label) {
            if ($w === $key || $w === strtolower($label)) {
                return $key;
            }
        }

        return match (true) {
            str_contains($w, 'facebook') || str_contains($w, 'fb') || str_contains($w, 'messenger') => 'messenger',
            str_contains($w, 'whatsapp') => 'whatsapp',
            str_contains($w, 'insta') => 'instagram',
            str_contains($w, 'tiktok') => 'tiktok',
            str_contains($w, 'web') || str_contains($w, 'google') || str_contains($w, 'direct') || str_contains($w, 'checkout') => 'web',
            default => 'other',
        };
    }

    /**
     * Rows to orders, checked against what is already in IQS. Nothing is written.
     *
     * @return array{orders: list<array>, updates: list<array>, restore: list<int>, counts: array<string, int>}
     */
    public function plan(array $header, array $rows, array $mapping, array $values, ?string $defaultMedium, string $source): array
    {
        $cols = fn (string $field) => array_keys(array_filter(array_values(array_map(fn ($h) => $mapping[$h] ?? '', $header)), fn ($f) => $f === $field));
        $get = fn (array $row, string $field, string $glue = ' ') => trim(implode($glue, array_filter(array_map(fn ($i) => self::unlabel(self::cell((string) ($row[$i] ?? ''))), $cols($field)), fn ($v) => $v !== '')));
        $getRaw = fn (array $row, string $field, string $glue = ' ') => trim(implode($glue, array_filter(array_map(fn ($i) => self::cell((string) ($row[$i] ?? '')), $cols($field)), fn ($v) => $v !== '')));
        $counts = ['rows' => count($rows), 'orders' => 0, 'known' => 0, 'new' => 0, 'in_iqs' => 0, 'already' => 0, 'updated' => 0, 'bad_phone' => 0, 'no_name' => 0, 'bad_date' => 0, 'skipped' => 0];

        // One order per order number, else per CN, else per row (a row is then known by its content).
        $groups = [];
        $cnOwner = [];
        foreach ($rows as $n => $row) {
            $no = $get($row, 'order_no');
            $cn = self::cn($get($row, 'cn'));
            $key = $no !== '' ? 'no:'.$no : ($cn ? 'cn:'.$cn : 'row:'.$n);
            if (! isset($groups[$key])) {
                // One CN is one parcel: a second order claiming it in the same file keeps its order but not the CN.
                if ($cn && isset($cnOwner[$cn]) && $cnOwner[$cn] !== $key) {
                    $cn = null;
                } elseif ($cn) {
                    $cnOwner[$cn] = $key;
                }
                $groups[$key] = ['no' => $no, 'cn' => $cn, 'row' => $row, 'items' => [], 'items_raw' => []];
            }
            if (($item = $get($row, 'items')) !== '') {
                $qty = $get($row, 'qty');
                $groups[$key]['items'][] = $item.($qty !== '' && $qty !== '1' ? ' × '.$qty : '');
            }
            if (($item = $getRaw($row, 'items')) !== '') { // as the key of a row imported before labels were stripped
                $qty = $getRaw($row, 'qty');
                $groups[$key]['items_raw'][] = $item.($qty !== '' && $qty !== '1' ? ' × '.$qty : '');
            }
        }

        // 24/7/2026 or 7/24/2026: a number above 12 decides; otherwise Pathao's "7/24/2026, 16:27" shape is month first, anything else day first.
        $dayFirst = null;
        $commaShape = false;
        foreach ($rows as $row) {
            $d = $get($row, 'date');
            if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-]\d{4}#', $d, $m)) {
                $commaShape = $commaShape || (bool) preg_match('#^\d{1,2}/\d{1,2}/\d{4},\s*\d#', $d);
                if ((int) $m[1] > 12) {
                    $dayFirst = true;
                    break;
                }
                if ((int) $m[2] > 12) {
                    $dayFirst = false;
                    break;
                }
            }
        }
        $dayFirst ??= ! $commaShape;

        $orders = [];
        foreach ($groups as $g) {
            $row = $g['row'];
            $phones = Phone::extract($get($row, 'phone', ' / '));
            $phone = $phones[0] ?? null;
            // The same row from before labels were stripped is still known (its key was made from the raw cells).
            $rawKey = fn () => sha1(implode('|', [$phone, $getRaw($row, 'date'), $getRaw($row, 'amount'),
                (string) (mb_substr(implode(', ', array_unique($g['items_raw'])), 0, 1000) ?: null), mb_strtolower($getRaw($row, 'name'))]));
            if (! $phone) {
                $counts['bad_phone']++;

                continue;
            }
            $name = $get($row, 'name');
            if ($name === '') {
                $counts['no_name']++;

                continue;
            }
            $rawDate = $get($row, 'date');
            $date = $rawDate === '' ? now()->format('Y-m-d H:i:s') : $this->date($rawDate, $dayFirst);
            if (! $date) {
                $counts['bad_date']++;

                continue;
            }
            $statusWord = $get($row, 'status');
            $status = $cols('status') && $statusWord !== '' ? ($values['status'][$statusWord] ?? $this->guessValue('status', $statusWord)) : 'delivered';
            if ($status === 'skip') {
                $counts['skipped']++;

                continue;
            }
            $mediumWord = $get($row, 'medium');
            $amountRaw = $get($row, 'amount');
            $items = mb_substr(implode(', ', array_unique($g['items'])), 0, 1000) ?: null;
            // The first figure in the cell: "Tk. 1,250", "1250+60", "Advance 200, COD 1050" give 1250, 1250, 200.
            $amount = preg_match('/\d[\d,]*(?:\.\d+)?/', Phone::englishDigits($amountRaw), $am) ? round((float) str_replace(',', '', $am[0]), 2) : 0.0;
            $orders[] = [
                'phone' => $phone,
                'other_phones' => array_slice($phones, 1, 2),
                'name' => mb_substr($name, 0, 150),
                'external_no' => $g['no'] !== '' ? mb_substr($g['no'], 0, 60) : null,
                'cn' => $g['cn'],
                // Neither number nor CN: the row is known by what it says (the raw date, so an empty date is the same row tomorrow too).
                'row_key' => $g['no'] === '' && ! $g['cn'] ? sha1(implode('|', [$phone, $rawDate, number_format($amount, 2, '.', ''), (string) $items, mb_strtolower($name)])) : null,
                'row_key_old' => $g['no'] === '' && ! $g['cn'] ? $rawKey() : null,
                'ordered_at' => $date,
                'amount' => $amount,
                'status' => $status,
                'medium' => $mediumWord !== '' ? ($values['medium'][$mediumWord] ?? $this->guessValue('medium', $mediumWord)) : $defaultMedium,
                'items' => $items,
                'address' => mb_substr($get($row, 'address', ', '), 0, 500) ?: null,
            ];
        }

        // Already in IQS (a website order carries the website's number), or brought in before from any file.
        $inIqs = [];
        $before = [];
        foreach (array_chunk(array_values(array_unique(array_filter(array_column($orders, 'external_no')))), 500) as $chunk) {
            $inIqs += array_flip(Order::whereIn('external_ref', $chunk)->pluck('external_ref')->merge(Order::whereIn('order_no', $chunk)->pluck('order_no'))->map(fn ($v) => 'no:'.$v)->all());
            foreach (DB::table('customer_past_orders')->whereIn('external_no', $chunk)->orderBy('id')->get() as $p) {
                $before['no:'.$p->external_no] ??= $p;
            }
        }
        foreach (array_chunk(array_values(array_unique(array_filter(array_column($orders, 'cn')))), 500) as $chunk) {
            $inIqs += array_flip(DB::table('shipments')->whereIn('consignment_id', $chunk)->pluck('consignment_id')
                ->merge(DB::table('shipments')->whereIn('tracking_code', $chunk)->pluck('tracking_code'))->map(fn ($v) => 'cn:'.strtoupper((string) $v))->all());
            foreach (DB::table('customer_past_orders')->whereIn('cn', $chunk)->get() as $p) {
                $before['cn:'.$p->cn] = $p;
            }
        }
        foreach (array_chunk(array_values(array_unique(array_filter(array_merge(array_column($orders, 'row_key'), array_column($orders, 'row_key_old'))))), 500) as $chunk) {
            foreach (DB::table('customer_past_orders')->whereIn('row_key', $chunk)->get() as $p) {
                $before['row:'.$p->row_key] = $p;
            }
        }

        // Who owns any number in the row; rows of one file that share a number are one person.
        [$known, $restore] = $this->owners(array_values(array_unique(array_merge(array_column($orders, 'phone'), ...array_column($orders, 'other_phones')))));
        $groupOf = [];
        foreach ($orders as &$o) {
            $numbers = array_merge([$o['phone']], $o['other_phones']);
            foreach ($numbers as $nb) {
                if (isset($known[$nb])) {
                    $o['customer_id'] = $known[$nb];
                    break;
                }
            }
            $primary = $o['phone'];
            foreach ($numbers as $nb) {
                if (isset($groupOf[$nb])) {
                    $primary = $groupOf[$nb];
                    break;
                }
            }
            foreach ($numbers as $nb) {
                $groupOf[$nb] ??= $primary;
            }
            $o['phone'] = $primary;
            $o['other_phones'] = array_values(array_diff(array_unique($numbers), [$primary]));
        }
        unset($o);
        $keep = [];
        $updates = [];
        $phonesToAdd = [];
        $knownPhones = [];
        $newPhones = [];
        $seenRows = [];
        $cnTaken = array_flip(array_keys(array_filter($before, fn ($k) => str_starts_with($k, 'cn:'), ARRAY_FILTER_USE_KEY)));
        foreach ($orders as $o) {
            if (($o['external_no'] !== null && isset($inIqs['no:'.$o['external_no']])) || ($o['cn'] && isset($inIqs['cn:'.$o['cn']]))) {
                $counts['in_iqs']++;

                continue;
            }
            if ($o['row_key'] && isset($seenRows[$o['row_key']])) {
                $counts['already']++; // the same row twice in one file

                continue;
            }
            if ($o['row_key']) {
                $seenRows[$o['row_key']] = true;
            }
            // Brought in before: the file is newer, so what changed is updated, and a CN or number it lacked is filled in.
            $old = ($o['cn'] ? ($before['cn:'.$o['cn']] ?? null) : null)
                ?? ($o['external_no'] !== null ? ($before['no:'.$o['external_no']] ?? null) : null)
                ?? ($o['row_key'] ? ($before['row:'.$o['row_key']] ?? $before['row:'.$o['row_key_old']] ?? null) : null);
            if ($old) {
                // A second number the newer file carries goes on the customer too.
                foreach ($o['other_phones'] as $nb) {
                    $phonesToAdd[$nb] = (int) $old->customer_id;
                }
                $fields = ['ordered_at', 'amount', 'status', 'medium', 'items', 'address'];
                if ($o['row_key'] && $old->row_key !== $o['row_key']) {
                    $fields[] = 'row_key'; // made from the raw cells before; now from the clean ones
                }
                if ($o['cn'] && ! $old->cn && ! isset($cnTaken['cn:'.$o['cn']])) {
                    $fields[] = 'cn';
                }
                if ($o['external_no'] !== null && $old->external_no === null) {
                    $fields[] = 'external_no';
                }
                $new = array_intersect_key($o, array_flip($fields));
                $changed = array_filter($new, fn ($v, $k) => $v !== null && (string) $v !== (string) $old->{$k}
                    && ! ($k === 'amount' && abs((float) $v - (float) $old->amount) < 0.01)
                    && ! ($k === 'ordered_at' && substr((string) $v, 0, 16) === substr((string) $old->ordered_at, 0, 16)), ARRAY_FILTER_USE_BOTH);
                if ($changed) {
                    $updates[] = ['id' => $old->id, 'old' => array_intersect_key((array) $old, $changed)] + $changed;
                    $counts['updated']++;
                } else {
                    $counts['already']++;
                }

                continue;
            }
            $o['customer_id'] ??= null;
            if ($o['customer_id']) {
                $knownPhones[$o['phone']] = true;
            } else {
                $newPhones[$o['phone']] = true;
            }
            $keep[] = $o;
        }
        $counts['orders'] = count($keep);
        $counts['known'] = count($knownPhones);
        $counts['new'] = count($newPhones);

        return ['orders' => $keep, 'updates' => $updates, 'phones' => $phonesToAdd, 'restore' => array_values(array_intersect_key($restore, $knownPhones)), 'counts' => $counts];
    }

    /**
     * Who owns each phone: a customer's own number first, then a primary phone, following merges to the
     * customer that is left. A customer deleted by hand is brought back when the file has their orders.
     *
     * @return array{0: array<string, int>, 1: array<string, int>} phone => customer id, phone => id to restore
     */
    private function owners(array $phones): array
    {
        $owner = [];
        foreach (array_chunk($phones, 500) as $chunk) {
            foreach (DB::table('customers')->whereIn('primary_phone', $chunk)->get(['id', 'primary_phone as phone']) as $r) {
                $owner[$r->phone] = $r->id;
            }
            foreach (DB::table('customer_phones')->whereIn('phone', $chunk)->get(['customer_id', 'phone']) as $r) {
                $owner[$r->phone] = $r->customer_id; // the customer's own number wins over a primary phone left on an old row
            }
        }
        $rows = DB::table('customers')->whereIn('id', array_unique(array_values($owner)))->get(['id', 'merged_into_id', 'deleted_at'])->keyBy('id');
        $known = [];
        $restore = [];
        foreach ($owner as $phone => $id) {
            for ($hop = 0; $hop < 10 && ($c = $rows[$id] ?? DB::table('customers')->find($id, ['id', 'merged_into_id', 'deleted_at'])) && $c->merged_into_id; $hop++) {
                $id = $c->merged_into_id;
            }
            $c = $rows[$id] ?? DB::table('customers')->find($id, ['id', 'merged_into_id', 'deleted_at']);
            if (! $c) {
                continue;
            }
            $known[$phone] = $c->id;
            if ($c->deleted_at && ! $c->merged_into_id) {
                $restore[$phone] = $c->id;
            }
        }

        return [$known, $restore];
    }

    /**
     * Writes the planned orders, creating customers for numbers IQS has not seen.
     *
     * @return array{inserted: int, changes: array<int, array>} rows really written, and what the updates overwrote (for Undo)
     */
    public function import(array $plan, int $importId, string $source, ?int $userId): array
    {
        return DB::transaction(function () use ($plan, $importId, $source, $userId) {
            $now = now();
            if ($plan['restore'] ?? []) {
                DB::table('customers')->whereIn('id', $plan['restore'])->update(['deleted_at' => null, 'updated_at' => $now]);
            }
            $new = collect($plan['orders'])->whereNull('customer_id')->unique('phone');
            foreach ($new->chunk(300) as $chunk) {
                DB::table('customers')->insert($chunk->map(fn ($o) => ['name' => $o['name'], 'primary_phone' => $o['phone'], 'created_by' => $userId, 'customer_import_id' => $importId, 'created_at' => $now, 'updated_at' => $now])->values()->all());
                $ids = DB::table('customers')->whereIn('primary_phone', $chunk->pluck('phone'))->pluck('id', 'primary_phone');
                DB::table('customer_phones')->insertOrIgnore($chunk->map(fn ($o) => ['customer_id' => $ids[$o['phone']], 'phone' => $o['phone'], 'is_primary' => true, 'created_at' => $now, 'updated_at' => $now])->values()->all());
            }
            $ids = DB::table('customers')->whereIn('primary_phone', $new->pluck('phone'))->where('customer_import_id', $importId)->pluck('id', 'primary_phone');
            $others = collect($plan['orders'])->filter(fn ($o) => $o['other_phones'] ?? [])
                ->flatMap(fn ($o) => array_map(fn ($p) => ['customer_id' => $o['customer_id'] ?? $ids[$o['phone']], 'phone' => $p, 'is_primary' => false, 'created_at' => $now, 'updated_at' => $now], $o['other_phones']))
                ->concat(collect($plan['phones'] ?? [])->map(fn ($cid, $p) => ['customer_id' => $cid, 'phone' => $p, 'is_primary' => false, 'created_at' => $now, 'updated_at' => $now])->values())
                ->unique('phone')->values();
            foreach ($others->chunk(300) as $chunk) {
                DB::table('customer_phones')->insertOrIgnore($chunk->all()); // a number that belongs to someone already stays theirs
            }
            $changes = [];
            foreach ($plan['updates'] ?? [] as $u) {
                $changes[$u['id']] = $u['old'];
                DB::table('customer_past_orders')->where('id', $u['id'])->update(array_diff_key($u, ['id' => 1, 'old' => 1]) + ['updated_at' => $now]);
            }
            $inserted = 0;
            foreach (array_chunk($plan['orders'], 300) as $chunk) {
                $inserted += DB::table('customer_past_orders')->insertOrIgnore(array_map(fn ($o) => [
                    'customer_id' => $o['customer_id'] ?? $ids[$o['phone']], 'customer_import_id' => $importId, 'source' => $source,
                    'external_no' => $o['external_no'], 'cn' => $o['cn'], 'row_key' => $o['row_key'], 'ordered_at' => $o['ordered_at'], 'amount' => $o['amount'],
                    'status' => $o['status'], 'medium' => $o['medium'], 'items' => $o['items'], 'address' => $o['address'], 'created_at' => $now, 'updated_at' => $now,
                ], $chunk));
            }

            return ['inserted' => $inserted, 'changes' => $changes];
        });
    }

    /** A courier CN in one shape, so search finds it: DM240726F65T8N, 123456789. Anything else is no CN. */
    public static function cn(?string $v): ?string
    {
        $v = strtoupper(preg_replace('/\s+/', '', Phone::englishDigits((string) $v)));

        return preg_match('/^[A-Z0-9-]{5,40}$/', $v) && preg_match('/\d/', $v) ? $v : null;
    }

    /** The sample file to fill in: our own column names, one example row (its phone is not real, so it never imports). */
    public const SAMPLE = [
        ['Name', 'CN', 'Phone', 'Address', 'Products', 'Amount', 'Date', 'Status'],
        ['Rahima Akter', 'DM240726F65T8N', '01XXXXXXXXX', 'House 12, Road 5, Mirpur, Dhaka', 'Medjool Dates 1 kg x 2, Honey 500 g', '1250', '2026-07-24', 'Delivered'],
    ];

    /** A small .xlsx (a zip of XML) with the sample rows, written without any package. */
    public static function sampleXlsx(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'iqs');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Orders" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $rows = '';
        foreach (self::SAMPLE as $r => $cells) {
            $rows .= '<row r="'.($r + 1).'">';
            foreach ($cells as $c => $v) {
                $rows .= '<c r="'.chr(65 + $c).($r + 1).'" t="inlineStr"><is><t>'.htmlspecialchars($v, ENT_XML1).'</t></is></c>';
            }
            $rows .= '</row>';
        }
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols><col min="1" max="8" width="24" customWidth="1"/></cols><sheetData>'.$rows.'</sheetData></worksheet>');
        $zip->close();
        $bytes = file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    public function signature(array $header): string
    {
        return hash('sha256', implode('|', array_map('strtolower', $header)));
    }

    private function date(string $v, bool $dayFirst = true): ?string
    {
        $v = trim(str_replace(',', ' ', Phone::englishDigits($v)));
        if ($v === '') {
            return null;
        }
        if (is_numeric($v) && (float) $v > 20000 && (float) $v < 80000) { // an Excel date number
            return Carbon::create(1899, 12, 30)->addSeconds((int) round((float) $v * 86400))->format('Y-m-d H:i:s');
        }
        // 24/07/2026 16:27 (or 07/24/2026 when the file puts the month first)
        if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})\s*(.*)$#', $v, $m)) {
            [$d, $mo] = $dayFirst ? [(int) $m[1], (int) $m[2]] : [(int) $m[2], (int) $m[1]];
            if (! checkdate($mo, $d, (int) $m[3])) {
                return null;
            }
            $v = sprintf('%04d-%02d-%02d %s', $m[3], $mo, $d, $m[4]);
        }
        try {
            $date = Carbon::parse(trim($v));

            return $date->year > 2000 && $date->year < 2100 ? $date->format('Y-m-d H:i:s') : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<list<string>> */
    private function csv(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException(__('The file could not be read.'));
        }
        // Excel saves "CSV" in the Windows code page and "Unicode Text" as UTF-16: make it UTF-8 first.
        if (str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF")) {
            $raw = mb_convert_encoding(substr($raw, 2), 'UTF-8', str_starts_with($raw, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
        } elseif (! mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }
        $raw = preg_replace('/\r\n?/', "\n", $raw);
        $first = strtok($raw, "\n") ?: '';
        $sep = collect([',', ';', "\t"])->sortByDesc(fn ($s) => substr_count($first, $s))->first();
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        $rows = [];
        while (($r = fgetcsv($fh, 0, $sep, '"', '')) !== false) {
            $rows[] = $r;
            if (count($rows) > self::MAX_ROWS + 1) {
                break;
            }
        }
        fclose($fh);

        return $rows;
    }

    /** The first sheet of an .xlsx, without any package: it is a zip of XML files. @return list<list<string>> */
    private function xlsx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException(__('The Excel file could not be opened. Save it again as .xlsx or CSV.'));
        }
        // Some writers prefix every element (<x:row>): drop element prefixes so plain names work.
        $plain = fn (string|false $x) => $x === false ? false : preg_replace('/(<\/?)[A-Za-z][\w.-]*:(?=[A-Za-z])/', '$1', $x);
        $shared = [];
        if (($xml = $plain($zip->getFromName('xl/sharedStrings.xml'))) !== false) {
            foreach (simplexml_load_string($xml)->si as $si) {
                $shared[] = isset($si->t) ? (string) $si->t : implode('', array_map(fn ($r) => (string) $r->t, iterator_to_array($si->r, false)));
            }
        }
        $sheet = 'xl/worksheets/sheet1.xml';
        if (($wb = $zip->getFromName('xl/workbook.xml')) && ($rels = $plain($zip->getFromName('xl/_rels/workbook.xml.rels')))
            && preg_match('/<(?:\w+:)?sheet\b[^>]*\b(?:\w+:)?id="([^"]+)"/', $wb, $sm)) {
            $rid = $sm[1];
            foreach (simplexml_load_string($rels)->Relationship as $rel) {
                if ((string) $rel['Id'] === $rid) {
                    $sheet = 'xl/'.ltrim(str_replace('/xl/', '', (string) $rel['Target']), '/');
                }
            }
        }
        $xml = $plain($zip->getFromName($sheet));
        $zip->close();
        if ($xml === false) {
            throw new RuntimeException(__('The Excel file has no sheet.'));
        }
        $rows = [];
        foreach (simplexml_load_string($xml)->sheetData->row as $row) {
            $cells = [];
            $next = 1;
            foreach ($row->c as $c) {
                $col = 0;
                foreach (str_split(preg_replace('/[^A-Z]/', '', strtoupper((string) $c['r']))) as $ch) {
                    $col = $col * 26 + (ord($ch) - 64);
                }
                $col = $col ?: $next; // a cell without a reference follows the one before it
                $next = $col + 1;
                $type = (string) $c['t'];
                $cells[$col - 1] = match ($type) {
                    's' => $shared[(int) $c->v] ?? '',
                    'inlineStr' => (string) $c->is->t,
                    default => (string) $c->v,
                };
            }
            $line = [];
            for ($i = 0, $max = $cells ? max(array_keys($cells)) : -1; $i <= $max; $i++) {
                $line[] = $cells[$i] ?? '';
            }
            $rows[] = $line;
            if (count($rows) > self::MAX_ROWS + 1) {
                break;
            }
        }

        return $rows;
    }
}
