<?php

namespace Database\Seeders;

use App\Enums\LocationType;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database. Safe to run more than once.
     */
    public function run(): void
    {
        foreach ([
            ['name' => 'Shop', 'type' => LocationType::Shop],
            ['name' => 'Online', 'type' => LocationType::Online],
            ['name' => 'Direct', 'type' => LocationType::Virtual],
        ] as $location) {
            Location::firstOrCreate(['name' => $location['name']], $location);
        }

        // First admin comes from .env so no password lives in the repo.
        $email = config('app.seed_admin.email');
        $password = config('app.seed_admin.password');

        if ($email && $password && ! User::where('email', $email)->exists()) {
            User::create([
                'name' => config('app.seed_admin.name'),
                'email' => $email,
                'password' => $password,
                'email_verified_at' => now(),
                'is_active' => true,
            ]);
        }
    }
}
