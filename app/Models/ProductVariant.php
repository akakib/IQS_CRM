<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Price and availability changes are recorded by ProductService
 * (price_history, availability_events) rather than the generic activity log.
 */
class ProductVariant extends Model
{
    use HasFactory, SoftDeletes;

    public const AVAILABILITY = ['in_stock' => 'In stock', 'backorder' => 'Pre-order', 'out_of_stock' => 'Out of stock'];

    protected $fillable = [
        'product_id', 'sku', 'barcode', 'shelf_code', 'name', 'unit', 'pack_qty', 'weight_g', 'cost_price', 'is_default',
        'sort_order', 'is_active', 'search_text', 'availability_status', 'backorder_limit_qty',
        'backorder_taken_qty', 'availability_source', 'oos_marked_by', 'oos_marked_at',
        'expected_restock_date', 'oos_review_at',
    ];

    protected function casts(): array
    {
        return [
            'pack_qty' => 'decimal:3',
            'cost_price' => 'decimal:2',
            'backorder_limit_qty' => 'decimal:3',
            'backorder_taken_qty' => 'decimal:3',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'oos_marked_at' => 'datetime',
            'oos_review_at' => 'datetime',
            'expected_restock_date' => 'date',
            'stock_qty' => 'integer',
            'stock_counted_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'oos_marked_by');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(VariantPrice::class, 'variant_id');
    }

    public function isSellable(): bool
    {
        return $this->is_active && $this->availability_status !== 'out_of_stock';
    }

    public function availabilityLabel(): string
    {
        return __(self::AVAILABILITY[$this->availability_status] ?? $this->availability_status);
    }

    public static function searchTextFor(string $productName, string $variantName, string $sku, ?string $barcode = null): string
    {
        return mb_substr(mb_strtolower(trim("{$productName} {$variantName} {$sku} {$barcode}")), 0, 600);
    }
}
