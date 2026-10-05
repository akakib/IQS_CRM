<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A courier API account (keys encrypted at rest; never sent back to the browser in full). */
class CourierAccount extends Model
{
    protected $fillable = ['courier', 'name', 'api_key', 'secret_key', 'is_default', 'is_active', 'last_checked_at', 'last_check_result', 'updated_by'];

    protected $hidden = ['api_key', 'secret_key'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'secret_key' => 'encrypted',
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

    /** "2n2r…irrto": enough to recognise it, not enough to use it. */
    public static function mask(?string $value): string
    {
        $value = (string) $value;

        return strlen($value) <= 8 ? str_repeat('•', strlen($value)) : substr($value, 0, 4).'…'.substr($value, -4);
    }
}
