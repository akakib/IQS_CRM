<?php

namespace Tests\Feature;

use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Orders\OrderService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\OrderConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScorecardTest extends TestCase
{
    use RefreshDatabase;

    private int $phone = 1712345200;

    /** An order of this person that was delivered today. */
    private function delivered(User $u): void
    {
        $o = app(OrderService::class)->create([
            'channel' => 'web', 'phone' => '0'.($this->phone++), 'name' => 'Customer', 'address_line' => 'Road 1',
            'items' => [['variant_id' => ProductVariant::firstWhere('sku', 'D-1')->id, 'qty' => 1]],
        ], null, 'webhook');
        DB::table('orders')->where('id', $o->id)->update(['moderator_id' => $u->id, 'assigned_at' => now(), 'status_id' => OrderStatus::idFor('delivered')]);
        DB::table('order_events')->insert(['order_id' => $o->id, 'to_status_id' => OrderStatus::idFor('delivered'), 'source' => 'webhook', 'created_at' => now()]);
    }

    public function test_the_month_compares_each_person_with_the_team_and_weighs_profit_against_pay(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 12:00:00'));
        (new CatalogSeeder)->run();
        (new CustomerSeeder)->run();
        (new OrderConfigSeeder)->run();
        OrderStatus::forget();
        Product::factory()->withVariant(600, ['sku' => 'D-1'])->create(['name' => 'Dates']);
        $boss = User::factory()->create(['name' => 'Boss']);
        $boss->roles()->attach($this->role(['orders.view' => 'all', 'orders.reassign', 'staff.view', 'staff.edit'], [], 'Manager')->id);
        $mod = $this->role(['orders.view' => 'own', 'orders.edit', 'orders.take'], [], 'Moderator');
        [$mahim, $rima, $jesi] = collect(['Mahim', 'Rima', 'Jesi'])->map(function ($n) use ($mod) {
            $u = User::factory()->create(['name' => $n]);
            $u->roles()->attach($mod->id);

            return $u;
        })->all();
        app(\App\Services\PermissionService::class)->bump();
        foreach (range(1, 4) as $i) {
            $this->delivered($mahim);
        }
        $this->delivered($rima);
        $this->delivered($rima);
        $this->delivered($jesi);

        // Salary on the Staff page; a month-end bonus on the Scorecard.
        $this->actingAs($boss)->put('/users/'.$mahim->id, ['name' => 'Mahim', 'email' => $mahim->email, 'monthly_salary' => 15000])->assertRedirect();
        $this->assertSame(15000.0, (float) DB::table('staff_salaries')->where('user_id', $mahim->id)->value('monthly_salary'));
        $this->post('/scorecard/'.$mahim->id.'/bonus', ['month' => '2026-10', 'amount' => 2000])->assertSessionHas('success');

        // Delivered: Mahim 4, Rima 2 (the team middle), Jesi 1: green, yellow, red.
        $page = $this->get('/scorecard')->assertOk()->assertSee('October 2026')->assertSee('৳15,000')->assertSee('value="2000"', false);
        $rows = app(\App\Services\Reports\MonthlyScorecard::class)->month(today())['rows']->keyBy('name');
        $this->assertSame('green', $rows['Mahim']['lights']['delivered']);
        $this->assertSame('yellow', $rows['Rima']['lights']['delivered']);
        $this->assertSame('red', $rows['Jesi']['lights']['delivered']);
        $this->assertSame(17000.0, $rows['Mahim']['pay']);
        $this->assertNotNull($rows['Mahim']['per_taka']);
        $this->assertNull($rows['Rima']['per_taka']); // no salary set: nothing to divide by

        // A new salary from next month: October keeps 15,000.
        $this->travelTo(Carbon::parse('2026-11-03 12:00:00'));
        $this->put('/users/'.$mahim->id, ['name' => 'Mahim', 'email' => $mahim->email, 'monthly_salary' => 18000]);
        $this->assertSame(15000.0, app(\App\Services\Reports\MonthlyScorecard::class)->month(Carbon::parse('2026-10-01'))['rows']->firstWhere('name', 'Mahim')['salary']);
        $this->assertSame(18000.0, app(\App\Services\Reports\MonthlyScorecard::class)->month(Carbon::parse('2026-11-01'))['rows']->firstWhere('name', 'Mahim')['salary'] ?? 18000.0);

        // A role that hides salaries sees the scorecard without pay, and cannot set a bonus.
        $lead = User::factory()->create();
        $lead->roles()->attach($this->role(['orders.view' => 'all', 'orders.reassign', 'staff.view', 'staff.edit'], ['salary'], 'Lead')->id);
        app(\App\Services\PermissionService::class)->bump();
        $this->actingAs($lead)->get('/scorecard?month=2026-10')->assertOk()->assertSee('Mahim')->assertDontSee('৳15,000')->assertDontSee('Per ৳1');
        $this->post('/scorecard/'.$mahim->id.'/bonus', ['month' => '2026-10', 'amount' => 9])->assertForbidden();
    }
}
