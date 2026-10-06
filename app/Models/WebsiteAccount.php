<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The website's (WooCommerce) REST API keys, encrypted at rest, never sent back to the browser in full. */
class WebsiteAccount extends Model
{
    protected $fillable = ['name', 'url', 'consumer_key', 'consumer_secret', 'is_active', 'last_checked_at', 'last_check_result', 'updated_by'];

    protected $hidden = ['consumer_key', 'consumer_secret'];

    protected function casts(): array
    {
        return [
            'consumer_key' => 'encrypted',
            'consumer_secret' => 'encrypted',
            'is_active' => 'boolean',
            'last_checked_at' => 'datetime',
        ];
    }

    /** The account used for the website API (one website for now). */
    public static function current(): ?self
    {
        try {
            return static::where('is_active', true)->orderBy('id')->first();
        } catch (\Illuminate\Database\QueryException) {
            return null; // table not there yet (mid-deploy, or a test without a database)
        }
    }
}
