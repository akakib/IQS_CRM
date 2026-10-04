<?php

use Illuminate\Support\Facades\Schedule;

// Access already stops at expires_at; this only clears the expired rows.
Schedule::command('permissions:prune-expired')->hourly();
