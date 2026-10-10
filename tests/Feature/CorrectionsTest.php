<?php

namespace Tests\Feature;

use Database\Seeders\OrderConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** A record typed wrong is fixed where it is, not added again. */
class CorrectionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_zone_rule_and_reason_are_edited_and_a_rule_deleted(): void
    {
        (new OrderConfigSeeder)->run();
        $this->actingAs($this->owner());
        $zone = DB::table('delivery_zones')->insertGetId(['name' => 'Dhak', 'is_active' => true]);
        $rule = DB::table('delivery_charge_rules')->insertGetId(['zone_id' => $zone, 'min_weight_g' => 0, 'max_weight_g' => null, 'min_order_total' => 0, 'charge' => 6, 'priority' => 10, 'is_active' => true]);
        $reason = DB::table('status_reasons')->value('id');

        $this->get('/settings/charges')->assertOk()->assertSee('Edit charge rule');
        $this->put(route('settings.charges.zones.update', $zone), ['name' => 'Dhaka'])->assertSessionHas('success');
        $this->assertSame('Dhaka', DB::table('delivery_zones')->where('id', $zone)->value('name'));

        $this->put(route('settings.charges.rules.update', $rule), ['zone_id' => $zone, 'min_weight_g' => 0, 'min_order_total' => 0, 'charge' => 60, 'priority' => 10])->assertSessionHas('success');
        $this->assertSame(60.0, (float) DB::table('delivery_charge_rules')->where('id', $rule)->value('charge'));
        $this->put(route('settings.charges.rules.update', $rule), ['min_weight_g' => 500, 'max_weight_g' => 100, 'min_order_total' => 0, 'charge' => 60, 'priority' => 10])->assertSessionHasErrors('max_weight_g');
        $this->delete(route('settings.charges.rules.destroy', $rule))->assertSessionHas('success');
        $this->assertFalse(DB::table('delivery_charge_rules')->where('id', $rule)->exists());

        $this->get('/settings/reasons')->assertOk()->assertSee('Rename');
        $this->put(route('settings.reasons.update', $reason), ['label_en' => 'Customer changed mind'])->assertSessionHas('success');
        $this->assertSame('Customer changed mind', DB::table('status_reasons')->where('id', $reason)->value('label_en'));
    }

    public function test_a_kpi_target_is_removed_from_its_row(): void
    {
        (new OrderConfigSeeder)->run();
        \App\Models\OrderStatus::forget();
        $this->actingAs($this->owner());
        $this->post(route('kpi.targets'), ['delivered_count' => 300])->assertSessionHas('success');
        $this->get('/kpi')->assertOk()->assertSee('target-del-', false);
        $this->post(route('kpi.targets'), ['user_id' => ''])->assertSessionHas('success');
        $this->assertNull(DB::table('kpi_targets')->where('metric', 'delivered_count')->value('target'));
        $this->get('/kpi')->assertOk()->assertDontSee('target-del-', false); // an emptied target leaves no row
    }
}
