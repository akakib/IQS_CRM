<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        // The 3 price lists. Names are editable later; system_key is what code uses.
        foreach ([
            ['online', 'Online (website & chat)', 1],
            ['shop', 'Shop counter', 2],
            ['b2b', 'B2B / wholesale', 3],
        ] as [$key, $name, $order]) {
            if (! DB::table('price_lists')->where('system_key', $key)->exists()) {
                DB::table('price_lists')->insert([
                    'system_key' => $key, 'name' => $name, 'sort_order' => $order, 'is_active' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }
}
