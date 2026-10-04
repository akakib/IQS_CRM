<?php

namespace App\Console\Commands;

use App\Services\Courier\CourierManager;
use App\Services\Courier\CourierUpdateProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Webhook fallback: ask the courier for the status of every active, unfinished parcel. */
class ResyncCourierStatus extends Command
{
    protected $signature = 'courier:resync {--limit=300}';

    protected $description = 'Pull current courier status for active parcels and apply changes';

    public function handle(CourierManager $courier, CourierUpdateProcessor $processor): int
    {
        $shipments = DB::table('shipments as s')->join('orders as o', 'o.id', '=', 's.order_id')
            ->where('s.is_active', true)->whereNull('s.final_at')->whereNotNull('s.consignment_id')
            ->orderBy('s.updated_at')->limit((int) $this->option('limit'))
            ->get(['s.id', 's.consignment_id', 'o.order_no']);

        $changed = 0;
        foreach ($shipments as $s) {
            try {
                $update = $courier->driver()->statusByInvoice($s->order_no);
                if (! $update) {
                    continue;
                }
                $eventId = DB::table('courier_events')->insertGetId([
                    'shipment_id' => $s->id, 'courier' => $courier->resolvedName(), 'consignment_id' => $s->consignment_id,
                    'notification_type' => 'resync', 'payload' => json_encode($update->raw ?: ['status' => $update->status]), 'created_at' => now(),
                ]);
                $result = $processor->apply($update, $eventId);
                $changed += in_array($result, ['no_change', 'tracking_note'], true) ? 0 : 1;
            } catch (\Throwable $e) {
                $this->warn("{$s->order_no}: {$e->getMessage()}");
            }
        }

        $this->info("Checked {$shipments->count()} parcels, {$changed} changed.");

        return self::SUCCESS;
    }
}
