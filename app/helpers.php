<?php

use App\Services\SettingsService;

if (! function_exists('settings')) {
    /** A global setting from config/settings.php, typed. */
    function settings(string $key): mixed
    {
        return app(SettingsService::class)->get($key);
    }
}
