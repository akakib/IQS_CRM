<?php

namespace Tests\Feature;

use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComponentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_component_library_page_renders_every_component(): void
    {
        $this->actingAs($this->owner())->get('/dev/components')->assertOk()
            ->assertSee('Component library')->assertSee('Slide-over')->assertSee('Barcode scan input')
            ->assertSee('data-view="table"', false)->assertSee('data-view="cards"', false);
    }

    public function test_filter_controls_are_rendered_once_so_nothing_is_sent_twice(): void
    {
        $html = $this->actingAs($this->owner())->get('/locations')->getContent();

        $this->assertSame(1, substr_count($html, 'name="type"'));
        $this->assertSame(1, substr_count($html, 'name="per_page"'));
    }

    public function test_bulk_deactivate_and_activate_locations(): void
    {
        $this->actingAs($this->owner());
        [$a, $b, $c] = Location::factory()->count(3)->create(['is_active' => true]);

        $this->post('/locations/bulk', ['action' => 'deactivate', 'ids' => [$a->id, $b->id]])->assertSessionHas('success');

        $this->assertFalse($a->fresh()->is_active);
        $this->assertFalse($b->fresh()->is_active);
        $this->assertTrue($c->fresh()->is_active);
        $this->assertSame(2, \App\Models\ActivityLog::where('action', 'location.updated')->count());
    }

    public function test_bulk_needs_edit_permission(): void
    {
        $user = \App\Models\User::factory()->create();
        $user->roles()->attach($this->role(['locations.view'])->id);
        app(\App\Services\PermissionService::class)->bump();

        $this->actingAs($user)->post('/locations/bulk', ['action' => 'deactivate', 'ids' => [1]])->assertForbidden();
        $this->get('/locations')->assertDontSee('Select all on this page');
    }
}
