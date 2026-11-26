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

    /**
     * Reasons the system sets (pre-order, advance, website cancel), packer-only ones, and two folded into others
     * (Deliver on a date = Customer asked to wait; Stock arriving = Waiting for stock). Still valid on old orders
     * and for the system; not offered when a person holds or cancels an order.
     */
    public const NOT_PICKED_BY_STAFF = ['awaiting_stock', 'scheduled', 'advance_wait', 'item_not_found', 'item_damaged', 'no_advance', 'website_cancelled'];

    /** @return array<int, string> id => label, only the reasons a person picks by hand */
    public static function staffOptions(string $type): array
    {
        return self::where('reason_type', $type)->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('system_key')->orWhereNotIn('system_key', self::NOT_PICKED_BY_STAFF))
            ->orderBy('sort_order')->pluck('label_en', 'id')->all();
    }

    /** @return array<int, string> id => label for a reason type */
    public static function options(string $type): array
    {
        return self::where('reason_type', $type)->where('is_active', true)->orderBy('sort_order')->pluck('label_en', 'id')->all();
    }
}
