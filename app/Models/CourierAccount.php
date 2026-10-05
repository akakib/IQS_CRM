<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A courier API account (keys encrypted at rest; never sent back to the browser in full). */
class CourierAccount extends Model
{
    protected $fillable = ['courier', 'name', 'api_key', 'secret_key', 'webhook_token', 'is_default', 'is_active', 'last_checked_at', 'last_check_result', 'updated_by'];

    protected $hidden = ['api_key', 'secret_key', 'webhook_token'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'secret_key' => 'encrypted',
            'webhook_token' => 'encrypted',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'last_checked_at' => 'datetime',
        ];
    }

    /** The account used for booking with this courier. */
    public static function defaultFor(string $courier): ?self
    {
        return static::where('courier', $courier)->where('is_active', true)->orderByDesc('is_default')->orderBy('id')->first();
    }

    /** Tokens Steadfast may call the webhook with: the server .env one and any typed in Settings. */
    public static function webhookTokens(string $courier): array
    {
        $tokens = [(string) config("courier.$courier.webhook_token")];
        try {
            foreach (static::where('courier', $courier)->where('is_active', true)->get() as $account) {
                $tokens[] = (string) $account->webhook_token;
            }
        } catch (\Illuminate\Database\QueryException) {
            // table not there yet: the .env token still works
        }

        return array_values(array_filter($tokens, fn ($t) => $t !== ''));
    }

    /** "2n2r…irrto": enough to recognise it, not enough to use it. */
    public static function mask(?string $value): string
    {
        $value = (string) $value;

        return strlen($value) <= 8 ? str_repeat('•', strlen($value)) : substr($value, 0, 4).'…'.substr($value, -4);
    }
}
