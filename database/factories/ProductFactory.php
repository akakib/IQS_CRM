<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->words(3, true));

        return ['name' => $name, 'slug' => Str::slug($name).'-'.Str::random(4), 'status' => 'active', 'base_unit' => 'pcs'];
    }

    /** One variant with an online price. */
    public function withVariant(float $price = 500, array $variant = []): static
    {
        return $this->afterCreating(function (Product $product) use ($price, $variant) {
            $sku = $variant['sku'] ?? 'SKU-'.strtoupper(Str::random(6));
            $v = ProductVariant::create($variant + [
                'product_id' => $product->id, 'sku' => $sku, 'name' => 'Default', 'unit' => 'pcs', 'pack_qty' => 1,
                'is_default' => true, 'search_text' => ProductVariant::searchTextFor($product->name, 'Default', $sku),
            ]);
            $online = DB::table('price_lists')->where('system_key', 'online')->value('id')
                ?? DB::table('price_lists')->insertGetId(['system_key' => 'online', 'name' => 'Online', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('variant_prices')->insert(['variant_id' => $v->id, 'price_list_id' => $online, 'regular_price' => $price]);
        });
    }
}
