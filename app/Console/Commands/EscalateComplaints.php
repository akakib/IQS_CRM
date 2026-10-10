<?php

namespace App\Console\Commands;

use App\Services\Complaints\ComplaintService;
use Illuminate\Console\Command;

class EscalateComplaints extends Command
{
    protected $signature = 'complaints:escalate';

    protected $description = 'Send complaints that passed their SLA to the managers';

    public function handle(ComplaintService $complaints): int
    {
        $this->info($complaints->escalate().' escalated.');

        return self::SUCCESS;
    }
}
