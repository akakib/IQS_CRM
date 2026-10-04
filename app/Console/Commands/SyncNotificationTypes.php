<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncNotificationTypes extends Command
{
    protected $signature = 'notifications:sync';

    protected $description = 'Copy config/notifications.php event types into notification_types (keeps admin edits)';

    public function handle(): int
    {
        $now = now();
        $added = 0;

        foreach (config('notifications.types') as $key => [$name, $priority]) {
            // Existing rows keep the priority/active state an admin chose.
            if (! DB::table('notification_types')->where('system_key', $key)->exists()) {
                DB::table('notification_types')->insert([
                    'system_key' => $key, 'name' => $name, 'default_priority' => $priority,
                    'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $added++;
            } else {
                DB::table('notification_types')->where('system_key', $key)->update(['name' => $name]);
            }
        }

        $this->info("{$added} notification types added.");

        return self::SUCCESS;
    }
}
