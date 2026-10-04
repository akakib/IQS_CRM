<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StatusReason extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return array<int, string> id => label for a reason type */
    public static function options(string $type): array
    {
        return self::where('reason_type', $type)->where('is_active', true)->orderBy('sort_order')->pluck('label_en', 'id')->all();
    }
}
