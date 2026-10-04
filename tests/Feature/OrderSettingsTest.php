<?php

namespace Tests\Feature;

use App\Models\OrderStatus;
use App\Services\Orders\DeliveryCharges;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\OrderConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new CustomerSeeder)->run();
        (new OrderConfigSeeder)->run();
        $this->actingAs($this->owner());
    }

    public function test_free_shipping_rule_added_from_settings_takes_effect(): void
    {
        $this->get('/settings/charges')->assertOk()->assertSee('Inside Dhaka');
        $inside = DB::table('delivery_zones')->where('system_key', 'inside_dhaka')->value('id');
        $this->assertSame(70.0, app(DeliveryCharges::class)->for($inside, 500, 5000));

        $this->post('/settings/charges/rules', ['zone_id' => '', 'min_weight_g' => 0, 'min_order_total' => 3000, 'charge' => 0, 'priority' => 100])
            ->assertSessionHas('success');

        $this->assertSame(0.0, app(DeliveryCharges::class)->for($inside, 500, 5000));
        $this->assertDatabaseHas('activity_log', ['action' => 'delivery_rule.created']);
    }

    public function test_reasons_and_status_names_are_editable(): void
    {
        $this->get('/settings/reasons')->assertOk()->assertSee('Cancel reasons');

        $this->post('/settings/reasons', ['reason_type' => 'cancel', 'label_en' => 'Ordered by mistake', 'blame_stage' => 'customer'])->assertSessionHas('success');
        $this->assertDatabaseHas('status_reasons', ['label_en' => 'Ordered by mistake', 'blame_stage' => 'customer']);

        $id = OrderStatus::idFor('no_answer');
        $this->put("/settings/statuses/{$id}", ['name_en' => 'Not picking up', 'color' => '#ff0000'])->assertSessionHas('success');
        $this->assertSame('Not picking up', OrderStatus::map()[$id]['name']);
        $this->assertSame('no_answer', OrderStatus::map()[$id]['key']);
    }
}
