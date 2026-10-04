<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VariantPrice extends Model
{
    public const CREATED_AT = null;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'regular_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'sale_starts_at' => 'datetime',
            'sale_ends_at' => 'datetime',
        ];
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /** Sale price while the sale window is open, otherwise the regular price. */
    public function effective(): string
    {
        $onSale = $this->sale_price !== null
            && (! $this->sale_starts_at || $this->sale_starts_at->isPast())
            && (! $this->sale_ends_at || $this->sale_ends_at->isFuture());

        return $onSale ? $this->sale_price : $this->regular_price;
    }
}
