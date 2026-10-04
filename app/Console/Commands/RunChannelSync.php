<?php

namespace App\Console\Commands;

use App\Services\Catalog\ChannelSync;
use Illuminate\Console\Command;

class RunChannelSync extends Command
{
    protected $signature = 'channel-sync:run {--limit=50}';

    protected $description = 'Push queued product price / availability / content changes to the website';

    public function handle(ChannelSync $sync): int
    {
        $result = $sync->run((int) $this->option('limit'));
        $this->info("Sent {$result['sent']}, failed {$result['failed']}.");

        return self::SUCCESS;
    }
}
