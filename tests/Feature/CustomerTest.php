<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Services\Customers\CustomerService;
use App\Services\Customers\FraudCheckService;
use App\Support\Phone;
use Database\Seeders\CustomerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new CustomerSeeder)->run();
        $this->actingAs($this->owner());
    }

    private function create(string $name, string $phone, array $extra = []): Customer
    {
        return app(CustomerService::class)->save(new Customer, ['name' => $name, 'primary_phone' => $phone], $extra, [], null);
    }

    public function test_phone_numbers_are_normalised(): void
    {
        $this->assertSame('01712345678', Phone::normalize('+880 1712-345678'));
        $this->assertSame('01712345678', Phone::normalize('8801712345678'));
        $this->assertSame('01712345678', Phone::normalize('1712345678'));
        $this->assertNull(Phone::normalize('01212345678'));
        $this->assertNull(Phone::normalize('12345'));
    }

    public function test_customer_with_numbers_and_addresses_is_created(): void
    {
        $zone = DB::table('delivery_zones')->where('system_key', 'inside_dhaka')->value('id');

        $this->post('/customers', [
            'name' => 'Karim', 'primary_phone' => '+8801712345678', 'extra_phones' => ['01812345678', ''],
            'risk_level' => 'normal', 'marketing_consent' => '1',
            'addresses' => [['address_line' => 'House 5, Road 2', 'district' => 'Dhaka', 'thana' => 'Dhanmondi', 'zone_id' => $zone, 'is_default' => 1]],
        ])->assertRedirect();

        $c = Customer::firstWhere('name', 'Karim');
        $this->assertSame('01712345678', $c->primary_phone);
        $this->assertSame(['01712345678', '01812345678'], $c->phones->pluck('phone')->all());
        $this->assertTrue($c->marketing_consent);
        $this->assertNotNull($c->consent_at);
        $this->assertSame('House 5, Road 2, Dhanmondi, Dhaka', $c->addresses->first()->oneLine());
    }

    public function test_a_number_cannot_belong_to_two_customers(): void
    {
        $this->create('Karim', '01712345678', ['01812345678']);

        $this->post('/customers', ['name' => 'Other', 'primary_phone' => '01812345678', 'risk_level' => 'normal'])
            ->assertSessionHasErrors('primary_phone');
        $this->assertSame(1, Customer::count());
    }

    public function test_lookup_finds_by_any_number(): void
    {
        $c = $this->create('Karim', '01712345678', ['01812345678']);

        $this->getJson('/customers/lookup?phone=8801812345678')->assertJson(['found' => true, 'id' => $c->id, 'name' => 'Karim']);
        $this->getJson('/customers/lookup?phone=01999999999')->assertJson(['found' => false, 'valid' => true]);
    }

    public function test_list_search_by_number_and_name_and_mask(): void
    {
        $this->create('Karim', '01712345678');
        $this->create('Rahim', '01812345678');

        $this->get('/customers?q=0181')->assertSee('Rahim')->assertDontSee('Karim');
        $this->get('/customers?q=Kar')->assertSee('Karim')->assertDontSee('Rahim');

        $masked = User::factory()->create();
        $masked->roles()->attach($this->role(['customers.view', 'customers.edit'], ['customer_contact'])->id);
        app(\App\Services\PermissionService::class)->bump();
        $this->actingAs($masked)->get('/customers')->assertSee('017******78')->assertDontSee('01712345678');
        $this->get('/customers/'.Customer::first()->id.'/edit')->assertForbidden();
    }

    public function test_merge_moves_numbers_addresses_and_counts(): void
    {
        $keep = $this->create('Karim', '01712345678');
        $dup = $this->create('Karim Bhai', '01812345678');
        $dup->update(['delivered_count' => 3, 'orders_count' => 4, 'risk_level' => 'watch']);
        $dup->addresses()->create(['address_line' => 'Old address']);

        $this->post("/customers/{$keep->id}/merge", ['duplicate_phone' => '01812345678'])->assertSessionHas('success');

        $keep->refresh();
        $this->assertSame(['01712345678', '01812345678'], $keep->phones->pluck('phone')->all());
        $this->assertSame(3, $keep->delivered_count);
        $this->assertSame('watch', $keep->risk_level);
        $this->assertSame(1, $keep->addresses()->count());
        $this->assertSoftDeleted($dup);
        $this->assertSame($keep->id, Customer::withTrashed()->find($dup->id)->merged_into_id);
        $this->assertDatabaseHas('activity_log', ['action' => 'customer.merged']);
    }

    public function test_fraud_check_uses_cache_and_keeps_history(): void
    {
        $c = $this->create('Risky', '01700000000'); // fake driver: ends in 0 = risky history

        $first = app(FraudCheckService::class)->check($c);
        $this->assertEquals(37.5, (float) $first['steadfast']->success_rate);
        $this->assertSame(8, $first['steadfast']->total_parcels);
        $this->assertSame(0, $first['internal']->total_parcels);

        app(FraudCheckService::class)->check($c); // steadfast cached for 24 h
        $this->assertSame(1, DB::table('customer_fraud_checks')->where('customer_id', $c->id)->where('provider_id', DB::table('fraud_check_providers')->where('system_key', 'steadfast')->value('id'))->count());

        $this->post("/customers/{$c->id}/fraud-check")->assertSessionHas('success'); // force
        $this->assertSame(2, DB::table('customer_fraud_checks')->where('customer_id', $c->id)->where('provider_id', DB::table('fraud_check_providers')->where('system_key', 'steadfast')->value('id'))->count());
        $this->get("/customers/{$c->id}")->assertOk()->assertSee('37.5%');
    }
}
