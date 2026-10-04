<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_index_lists_locations_as_table_and_cards(): void
    {
        Location::factory()->create(['name' => 'Lovelane', 'type' => 'store']);

        $this->get('/locations')
            ->assertOk()
            ->assertSee('Lovelane')
            ->assertSee('data-view="table"', false)
            ->assertSee('data-view="cards"', false);
    }

    public function test_search_filter_and_pagination_work_from_the_query_string(): void
    {
        Location::factory()->create(['name' => 'Lovelane', 'type' => 'store']);
        Location::factory()->create(['name' => 'Newmarket', 'type' => 'store']);
        Location::factory()->create(['name' => 'Online', 'type' => 'online']);

        $this->get('/locations?q=Love')->assertSee('Lovelane')->assertDontSee('Newmarket');
        $this->get('/locations?type=online')->assertSee('>Online<', false)->assertDontSee('Lovelane');

        Location::factory()->count(30)->create(['type' => 'shop']);
        $response = $this->get('/locations?type=shop&per_page=25&page=2');
        $response->assertOk();
        $this->assertCount(5, $response->viewData('locations')->items());
        $this->assertStringContainsString('type=shop', $response->viewData('locations')->url(1));
    }

    public function test_index_stays_within_query_budget(): void
    {
        Location::factory()->count(40)->create();

        DB::enableQueryLog();
        $this->get('/locations?per_page=25')->assertOk();

        // session + user + count + select
        $this->assertLessThanOrEqual(5, count(DB::getQueryLog()));
    }

    public function test_location_can_be_created(): void
    {
        $this->post('/locations', ['name' => 'Lovelane', 'type' => 'store', 'is_active' => '1'])
            ->assertRedirect('/locations');

        $this->assertDatabaseHas('locations', ['name' => 'Lovelane', 'type' => 'store', 'is_active' => true]);
    }

    public function test_create_validates_name_type_and_uniqueness(): void
    {
        Location::factory()->create(['name' => 'Shop']);

        $this->post('/locations', ['name' => '', 'type' => 'castle'])
            ->assertSessionHasErrors(['name', 'type']);
        $this->post('/locations', ['name' => 'Shop', 'type' => 'shop'])
            ->assertSessionHasErrors('name');
    }

    public function test_location_can_be_updated(): void
    {
        $location = Location::factory()->create(['name' => 'Old', 'type' => 'store', 'is_active' => true]);

        $this->put("/locations/{$location->id}", ['name' => 'New', 'type' => 'shop', 'is_active' => '0'])
            ->assertRedirect('/locations');

        $this->assertDatabaseHas('locations', ['id' => $location->id, 'name' => 'New', 'type' => 'shop', 'is_active' => false]);
    }

    public function test_location_is_soft_deleted_and_name_can_be_reused(): void
    {
        $location = Location::factory()->create(['name' => 'Temp']);

        $this->delete("/locations/{$location->id}")->assertRedirect('/locations');

        $this->assertSoftDeleted($location);
        $this->assertNotContains('Temp', $this->get('/locations')->viewData('locations')->pluck('name'));
        $this->post('/locations', ['name' => 'Temp', 'type' => 'shop'])->assertSessionHasNoErrors();
    }
}
