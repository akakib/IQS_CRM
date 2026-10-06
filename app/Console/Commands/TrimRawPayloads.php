<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Clears the raw webhook text (the biggest thing we store: \15 KB per order)
 * once it is older than data.raw_payload_days. The rows stay, with when they
 * came, for which order and what happened to them; only the raw body goes.
 * Nothing the app shows is read from these bodies after import.
 */
class TrimRawPayloads extends Command
{
    protected $signature = 'data:trim-raw-payloads {--dry-run : Only count}';

    protected $description = 'Clear raw webhook bodies older than the configured number of days';

    public function handle(): int
    {
        $before = now()->subDays((int) settings('data.raw_payload_days'));
        $total = 0;
        foreach ([
            ['integration_inbox', 'received_at', 'payload', '{"trimmed":true}'],
            ['courier_events', 'created_at', 'payload', '{"trimmed":true}'],
            ['shipments', 'booked_at', 'raw_booking', null],
        ] as [$table, $col, $field, $empty]) {
            $q = DB::table($table)->where($col, '<', $before)->whereNotNull($field)
                ->where($field, '!=', $empty ?? '')->when($empty, fn ($q) => $q->where($field, '!=', $empty));
            if ($this->option('dry-run')) {
                $n = (clone $q)->count();
            } else {
                $n = 0;
                while ($ids = (clone $q)->orderBy('id')->limit(2000)->pluck('id')->all()) {
                    $n += DB::table($table)->whereIn('id', $ids)->update([$field => $empty]);
                    if (count($ids) < 2000) {
                        break;
                    }
                }
            }
            $this->line("$table: $n");
            $total += $n;
        }
        $this->info(($this->option('dry-run') ? 'Would clear ' : 'Cleared ').$total.' raw bodies older than '.$before->toDateString());

        return self::SUCCESS;
    }
}
