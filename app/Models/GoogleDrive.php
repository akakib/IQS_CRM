<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The business's Google Drive connection: one row. The secret and token are encrypted and never sent to the browser. */
class GoogleDrive extends Model
{
    protected $table = 'google_drive';

    protected $fillable = ['client_id', 'client_secret', 'refresh_token', 'account_email', 'folders', 'connected_at', 'connected_by', 'tested_at', 'test_ok', 'test_message'];

    protected $hidden = ['client_secret', 'refresh_token'];

    protected function casts(): array
    {
        return ['client_secret' => 'encrypted', 'refresh_token' => 'encrypted', 'folders' => 'array', 'connected_at' => 'datetime', 'tested_at' => 'datetime', 'test_ok' => 'boolean'];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }

    public function hasKeys(): bool
    {
        return $this->client_id && $this->client_secret;
    }

    public function isConnected(): bool
    {
        return (bool) $this->refresh_token;
    }
}
