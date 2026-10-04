<?php

namespace Database\Seeders;

use App\Enums\LocationType;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database. Safe to run more than once.
     */
    public function run(): void
    {
        foreach ([
            // All online orders are packed at the shop. Warehouses are added from the UI.
            ['name' => 'Riajuddin Bazar', 'type' => LocationType::Shop],
            ['name' => 'Direct', 'type' => LocationType::Virtual],
        ] as $location) {
            Location::firstOrCreate(['name' => $location['name']], $location);
        }

        $this->call(CatalogSeeder::class);
        $this->call(CustomerSeeder::class);
        $this->call(OrderConfigSeeder::class);
        $this->call(ConfirmationSeeder::class);
        $this->call(RoleSeeder::class);
        $this->call(NotificationSeeder::class);
        $this->call(PointsSeeder::class);

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

        // The seed admin is the Owner.
        $admin = $email ? User::where('email', $email)->first() : null;
        $owner = Role::firstWhere('system_key', Role::OWNER);
        if ($admin && $owner && ! $admin->roles()->whereKey($owner->id)->exists()) {
            $admin->roles()->attach($owner->id, ['reason' => 'Initial owner']);
            app(PermissionService::class)->bump();
        }
    }
}
