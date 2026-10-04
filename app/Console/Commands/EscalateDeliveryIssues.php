<?php

namespace App\Console\Commands;

use App\Services\Orders\DeliveryIssueService;
use Illuminate\Console\Command;

class EscalateDeliveryIssues extends Command
{
    protected $signature = 'issues:escalate';

    protected $description = 'Send delivery issues that passed their SLA to the managers';

    public function handle(DeliveryIssueService $issues): int
    {
        $this->info($issues->escalate().' escalated.');

        return self::SUCCESS;
    }
}
