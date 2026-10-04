<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->owner();
        $this->actingAs($this->owner);
    }

    public function test_role_change_writes_activity_log_with_before_and_after(): void
    {
        $role = $this->role(['staff.view'], [], 'Helper');

        $this->put("/roles/{$role->id}", ['name' => 'Helper', 'grants' => ['locations.view'], 'masks' => ['salary']]);

        $entry = ActivityLog::where('action', 'role.access_changed')->latest('id')->firstOrFail();
        $this->assertSame($this->owner->id, $entry->actor_id);
        $this->assertSame(['staff.view' => 'all'], $entry->before['grants']);
        $this->assertSame(['locations.view' => 'all'], $entry->after['grants']);
        $this->assertSame(['salary'], $entry->after['masks']);
    }

    public function test_user_changes_are_logged_without_secrets(): void
    {
        $staff = User::factory()->create(['name' => 'Old']);

        $this->put("/users/{$staff->id}", ['name' => 'New', 'email' => $staff->email, 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass']);

        $entry = ActivityLog::where('action', 'user.updated')->where('subject_id', $staff->id)->latest('id')->firstOrFail();
        $this->assertSame('Old', $entry->before['name']);
        $this->assertSame('New', $entry->after['name']);
        $this->assertSame('[hidden]', $entry->after['password']);
        $this->assertStringNotContainsString('brand-new-pass', json_encode($entry->toArray()));
    }

    public function test_access_grants_and_location_changes_are_logged(): void
    {
        $staff = User::factory()->create();
        $role = $this->role([], [], 'Temp');

        $this->post("/users/{$staff->id}/access/roles", ['role_id' => $role->id]);
        $this->post("/users/{$staff->id}/access/overrides", ['permission' => 'staff.view', 'effect' => 'deny', 'reason' => 'Review']);
        $location = Location::factory()->create();
        $this->delete("/locations/{$location->id}");

        foreach (['user.role_given', 'user.access_deny', 'location.created', 'location.deleted'] as $action) {
            $this->assertDatabaseHas('activity_log', ['action' => $action]);
        }
    }

    public function test_log_is_append_only(): void
    {
        $entry = app(\App\Services\ActivityLogger::class)->log('test.entry');

        $this->expectException(LogicException::class);
        $entry->update(['action' => 'changed']);
    }

    public function test_log_page_lists_and_filters(): void
    {
        app(\App\Services\ActivityLogger::class)->log('role.access_changed', ['role', 5]);
        app(\App\Services\ActivityLogger::class)->log('location.created', ['location', 7]);

        $this->get('/activity')->assertOk()->assertSee('role.access_changed')->assertSee('location.created');
        $this->get('/activity?type=location')->assertSee('location.created')->assertDontSee('role.access_changed');
    }

    public function test_log_page_needs_permission(): void
    {
        $this->actingAs(User::factory()->create())->get('/activity')->assertForbidden();
    }
}
