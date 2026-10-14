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
        'phone' => ['billing phone', 'recipient phone', 'customer phone', 'phone', 'mobile', 'contact'],
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
        'delivered' => ['completed', 'complete', 'delivered', 'paid'],
        'cancelled' => ['cancelled', 'canceled', 'cancel', 'failed', 'trash'],
        'returned' => ['returned', 'return', 'paid return', 'partial return'],
        'refunded' => ['refunded', 'refund'],
        'pending' => ['processing', 'pending', 'on-hold', 'on hold', 'in transit', 'hold', 'partial'],
    ];

    /** @return array{0: list<string>, 1: list<list<string>>} header row and data rows */
    public function read(string $path, string $ext): array
    {
        $rows = strtolower($ext) === 'xlsx' ? $this->xlsx($path) : $this->csv($path);
        $rows = array_values(array_filter($rows, fn ($r) => implode('', array_map('trim', $r)) !== ''));
        if (count($rows) < 2) {
            throw new RuntimeException(__('The file has no rows under the header.'));
        }
        $header = array_map(fn ($h) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)), array_shift($rows));
        foreach ($header as $i => $h) {
            $header[$i] = $h === '' ? __('Column :n', ['n' => $i + 1]) : $h;
        }
        $width = count($header);

        return [$header, array_map(fn ($r) => array_slice(array_pad(array_map(fn ($v) => self::cell((string) $v), $r), $width, ''), 0, $width), $rows)];
    }

    /** Excel keeps numbers as numbers: 1.326885301E9 is a phone (1326885301), 1.0 is 1. */
    private static function cell(string $v): string
    {
        $v = trim($v);
        if (preg_match('/^\d+(\.\d+)?E\+?\d+$/i', $v)) {
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
                        && ! ($field === 'name' && preg_match('/merchant|store|shop|product|item|hub|zone|file|user|sender/', $h))) {
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
     * @return array{orders: list<array>, counts: array<string, int>}
     */
    public function plan(array $header, array $rows, array $mapping, array $values, ?string $defaultMedium, string $source): array
    {
        $cols = fn (string $field) => array_keys(array_filter(array_values(array_map(fn ($h) => $mapping[$h] ?? '', $header)), fn ($f) => $f === $field));
        $get = fn (array $row, string $field, string $glue = ' ') => trim(implode($glue, array_filter(array_map(fn ($i) => self::cell((string) ($row[$i] ?? '')), $cols($field)), fn ($v) => $v !== '')));
        $counts = ['rows' => count($rows), 'orders' => 0, 'known' => 0, 'new' => 0, 'in_iqs' => 0, 'already' => 0, 'bad_phone' => 0, 'no_name' => 0, 'bad_date' => 0, 'skipped' => 0];

        $groups = [];
        foreach ($rows as $n => $row) {
            $no = $get($row, 'order_no');
            $cn = self::cn($get($row, 'cn'));
            $key = $no !== '' ? 'no:'.$no : ($cn ? 'cn:'.$cn : 'row:'.$n);
            if (! isset($groups[$key])) {
                $groups[$key] = ['no' => $no, 'cn' => $cn, 'row' => $row, 'items' => []];
            }
            if (($item = $get($row, 'items')) !== '') {
                $qty = $get($row, 'qty');
                $groups[$key]['items'][] = $item.($qty !== '' && $qty !== '1' ? ' × '.$qty : '');
            }
        }

        // 7/24/2026 or 24/7/2026: a number above 12 in the file says which comes first (day first when unsure).
        $dayFirst = true;
        foreach ($rows as $row) {
            if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-]\d{4}#', $get($row, 'date'), $m)) {
                if ((int) $m[1] > 12) {
                    break;
                }
                if ((int) $m[2] > 12) {
                    $dayFirst = false;
                    break;
                }
            }
        }

        $orders = [];
        foreach ($groups as $g) {
            $row = $g['row'];
            $phone = Phone::normalize($get($row, 'phone'));
            if (! $phone) {
                $counts['bad_phone']++;

                continue;
            }
            $name = $get($row, 'name');
            if ($name === '') {
                $counts['no_name']++;

                continue;
            }
            $date = $this->date($get($row, 'date'), $dayFirst);
            if (! $date) {
                $counts['bad_date']++;

                continue;
            }
            $statusWord = $get($row, 'status');
            $status = $cols('status') ? ($values['status'][$statusWord] ?? $this->guessValue('status', $statusWord)) : 'delivered';
            if ($status === 'skip') {
                $counts['skipped']++;

                continue;
            }
            $mediumWord = $get($row, 'medium');
            $orders[] = [
                'phone' => $phone,
                'name' => mb_substr($name, 0, 150),
                'external_no' => $g['no'] !== '' ? mb_substr($g['no'], 0, 60) : null,
                'cn' => $g['cn'],
                'ordered_at' => $date,
                'amount' => round((float) preg_replace('/[^\d.]/', '', str_replace(',', '', Phone::englishDigits($get($row, 'amount')))), 2),
                'status' => $status,
                'medium' => $mediumWord !== '' ? ($values['medium'][$mediumWord] ?? $this->guessValue('medium', $mediumWord)) : $defaultMedium,
                'items' => mb_substr(implode(', ', array_unique($g['items'])), 0, 1000) ?: null,
                'address' => mb_substr($get($row, 'address', ', '), 0, 500) ?: null,
            ];
        }

        // Already in IQS (a website order carries the website's number) or brought in before.
        $nos = array_values(array_filter(array_column($orders, 'external_no')));
        $inIqs = [];
        $before = [];
        foreach (array_chunk($nos, 500) as $chunk) {
            $inIqs += array_flip(Order::whereIn('external_ref', $chunk)->pluck('external_ref')->merge(Order::whereIn('order_no', $chunk)->pluck('order_no'))->map(fn ($v) => (string) $v)->all());
            $before += array_flip(DB::table('customer_past_orders')->where('source', $source)->whereIn('external_no', $chunk)->pluck('external_no')->all());
        }
        // The same parcel by its CN: booked in IQS, or imported before from any file.
        foreach (array_chunk(array_values(array_filter(array_column($orders, 'cn'))), 500) as $chunk) {
            $cnInIqs = DB::table('shipments')->whereIn('consignment_id', $chunk)->pluck('consignment_id')
                ->merge(DB::table('shipments')->whereIn('tracking_code', $chunk)->pluck('tracking_code'))->map(fn ($v) => 'cn:'.strtoupper((string) $v));
            $inIqs += array_flip($cnInIqs->all());
            $before += array_flip(DB::table('customer_past_orders')->whereIn('cn', $chunk)->pluck('cn')->map(fn ($v) => 'cn:'.$v)->all());
        }
        $known = [];
        foreach (array_chunk(array_values(array_unique(array_column($orders, 'phone'))), 500) as $chunk) {
            $known += DB::table('customer_phones')->whereIn('phone', $chunk)->pluck('customer_id', 'phone')->all();
        }
        $keep = [];
        $newPhones = [];
        foreach ($orders as $o) {
            if (($o['external_no'] !== null && isset($inIqs[$o['external_no']])) || ($o['cn'] && isset($inIqs['cn:'.$o['cn']]))) {
                $counts['in_iqs']++;

                continue;
            }
            if (($o['external_no'] !== null && isset($before[$o['external_no']])) || ($o['cn'] && isset($before['cn:'.$o['cn']]))) {
                $counts['already']++;

                continue;
            }
            $o['customer_id'] = $known[$o['phone']] ?? null;
            $o['customer_id'] ? $counts['known']++ : $newPhones[$o['phone']] = true;
            $keep[] = $o;
        }
        $counts['orders'] = count($keep);
        $counts['new'] = count($newPhones);

        return ['orders' => $keep, 'counts' => $counts];
    }

    /** Writes the planned orders, creating customers for numbers IQS has not seen. */
    public function import(array $plan, int $importId, string $source, ?int $userId): void
    {
        DB::transaction(function () use ($plan, $importId, $source, $userId) {
            $now = now();
            $new = collect($plan['orders'])->whereNull('customer_id')->unique('phone');
            foreach ($new->chunk(300) as $chunk) {
                DB::table('customers')->insert($chunk->map(fn ($o) => ['name' => $o['name'], 'primary_phone' => $o['phone'], 'created_by' => $userId, 'customer_import_id' => $importId, 'created_at' => $now, 'updated_at' => $now])->values()->all());
                $ids = DB::table('customers')->whereIn('primary_phone', $chunk->pluck('phone'))->pluck('id', 'primary_phone');
                DB::table('customer_phones')->insert($chunk->map(fn ($o) => ['customer_id' => $ids[$o['phone']], 'phone' => $o['phone'], 'is_primary' => true, 'created_at' => $now, 'updated_at' => $now])->values()->all());
            }
            $ids = DB::table('customer_phones')->whereIn('phone', $new->pluck('phone'))->pluck('customer_id', 'phone');
            foreach (array_chunk($plan['orders'], 300) as $chunk) {
                DB::table('customer_past_orders')->insertOrIgnore(array_map(fn ($o) => [
                    'customer_id' => $o['customer_id'] ?? $ids[$o['phone']], 'customer_import_id' => $importId, 'source' => $source,
                    'external_no' => $o['external_no'], 'cn' => $o['cn'], 'ordered_at' => $o['ordered_at'], 'amount' => $o['amount'], 'status' => $o['status'],
                    'medium' => $o['medium'], 'items' => $o['items'], 'address' => $o['address'], 'created_at' => $now, 'updated_at' => $now,
                ], $chunk));
            }
        });
    }

    /** A courier CN in one shape, so search finds it: DM240726F65T8N, 123456789. Anything else is no CN. */
    public static function cn(?string $v): ?string
    {
        $v = strtoupper(preg_replace('/\s+/', '', Phone::englishDigits((string) $v)));

        return preg_match('/^[A-Z0-9-]{5,40}$/', $v) ? $v : null;
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
        $fh = fopen($path, 'r') ?: throw new RuntimeException(__('The file could not be read.'));
        $first = (string) fgets($fh);
        rewind($fh);
        $sep = collect([',', ';', "\t"])->sortByDesc(fn ($s) => substr_count($first, $s))->first();
        $rows = [];
        while (($r = fgetcsv($fh, 0, $sep, '"', '')) !== false) {
            $rows[] = $r;
            if (count($rows) > 50001) {
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
        $shared = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            foreach (simplexml_load_string($xml)->si as $si) {
                $shared[] = isset($si->t) ? (string) $si->t : implode('', array_map(fn ($r) => (string) $r->t, iterator_to_array($si->r, false)));
            }
        }
        $sheet = 'xl/worksheets/sheet1.xml';
        if (($wb = $zip->getFromName('xl/workbook.xml')) && ($rels = $zip->getFromName('xl/_rels/workbook.xml.rels'))) {
            $wbx = simplexml_load_string($wb);
            $rid = (string) $wbx->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            foreach (simplexml_load_string($rels)->Relationship as $rel) {
                if ((string) $rel['Id'] === $rid) {
                    $sheet = 'xl/'.ltrim(str_replace('/xl/', '', (string) $rel['Target']), '/');
                }
            }
        }
        $xml = $zip->getFromName($sheet);
        $zip->close();
        if ($xml === false) {
            throw new RuntimeException(__('The Excel file has no sheet.'));
        }
        $rows = [];
        foreach (simplexml_load_string($xml)->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $col = 0;
                foreach (str_split(preg_replace('/\d/', '', (string) $c['r'])) as $ch) {
                    $col = $col * 26 + (ord($ch) - 64);
                }
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
            if (count($rows) > 50001) {
                break;
            }
        }

        return $rows;
    }
}
