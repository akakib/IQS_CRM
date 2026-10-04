<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $override = []): array
    {
        return $override + [
            'store_name' => 'Iqbal Store',
            'store_hotline' => '01711111111',
            'orders_pickup_cutoffs' => ['15:00'],
            'orders_packaging_cost' => '25.50',
            'orders_duplicate_window_hours' => '24',
            'orders_freeze_minutes_before_pickup' => '30',
            'orders_max_working_orders' => '1',
            'orders_max_no_answer' => '3',
            'orders_discount_limit' => '200',
            'tracking_web_event_id' => 'wc_purchase_{external_ref}',
            'verification_rerun_on_edit' => '1',
        ];
    }

    public function test_defaults_apply_until_saved(): void
    {
        $this->assertSame('Iqbal Store', settings('store.name'));
        $this->assertSame(['15:00'], settings('orders.pickup_cutoffs'));
        $this->assertTrue(settings('verification.rerun_on_edit'));
    }

    public function test_owner_saves_typed_settings_and_change_is_logged(): void
    {
        $this->actingAs($this->owner());

        $this->put('/settings', $this->payload(['orders_pickup_cutoffs' => ['18:00', '12:30', '18:00']]))
            ->assertSessionHas('success');

        $this->assertSame(25.5, settings('orders.packaging_cost'));
        $this->assertSame('01711111111', settings('store.hotline'));
        $this->assertSame(['12:30', '18:00'], settings('orders.pickup_cutoffs'));
        $this->assertDatabaseHas('activity_log', ['action' => 'settings.updated']);
    }

    public function test_settings_cost_one_query_then_none(): void
    {
        settings('store.name');
        DB::enableQueryLog();
        foreach (range(1, 20) as $i) {
            settings('orders.packaging_cost');
        }
        $this->assertCount(0, DB::getQueryLog());
    }

    public function test_validation_and_logo_upload(): void
    {
        Storage::fake('public');
        $this->actingAs($this->owner());

        $this->put('/settings', $this->payload(['store_hotline' => '123', 'orders_pickup_cutoffs' => ['25:99']]))
            ->assertSessionHasErrors(['store_hotline', 'orders_pickup_cutoffs.0']);

        $this->put('/settings', $this->payload(['store_logo' => UploadedFile::fake()->image('logo.png', 200, 200)]))
            ->assertSessionHasNoErrors();
        Storage::disk('public')->assertExists(settings('store.logo'));
    }

    public function test_view_only_and_no_access(): void
    {
        $viewer = User::factory()->create();
        $viewer->roles()->attach($this->role(['settings.view'])->id);
        app(\App\Services\PermissionService::class)->bump();

        $this->actingAs($viewer)->get('/settings')->assertOk()->assertDontSee('Save settings');
        $this->put('/settings', $this->payload())->assertForbidden();

        $this->actingAs(User::factory()->create())->get('/settings')->assertForbidden();
    }
}
