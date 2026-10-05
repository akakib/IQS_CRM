<?php

namespace App\Console\Commands;

use App\Services\Marketing\AdCostService;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/** Remind about dollar vendors whose unpaid balance is due by tomorrow. */
class VendorDueReminders extends Command
{
    protected $signature = 'vendors:due-reminders';

    protected $description = 'Notify about dollar vendor payments that are due';

    public function handle(AdCostService $costs, NotificationService $notifications): int
    {
        $due = $costs->vendorBalances()->filter(fn ($v) => $v->due > 0 && $v->next_due && $v->next_due <= now()->addDay()->toDateString());
        foreach ($due as $v) {
            $notifications->send('vendor_payment_due', __('Pay :v: ৳:a due :d', ['v' => $v->name, 'a' => number_format($v->due), 'd' => date('d M', strtotime($v->next_due))]), null, [
                'link' => route('usd-lots.index'), 'group_key' => 'vendor_due_'.$v->id,
            ]);
        }
        $this->info($due->count().' reminder(s).');

        return self::SUCCESS;
    }
}
