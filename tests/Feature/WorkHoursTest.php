<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Reports\WorkTime;
use App\Services\SettingsService;
use App\Services\Work\BreakService;
use App\Services\Work\ChatService;
use Database\Seeders\OrderConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Time worked: Communication closes by itself, breaks end it, overtime is what falls outside the shift, night shifts. */
class WorkHoursTest extends TestCase
{
    use RefreshDatabase;

    private User $mahim;

    protected function setUp(): void
    {
        parent::setUp();
        (new OrderConfigSeeder)->run();
        $this->travelTo(Carbon::parse('2026-10-07 18:30:00')); // a Wednesday
        app(SettingsService::class)->set(['work.start' => '09:00', 'work.end' => '17:00', 'work.days' => '0,1,2,3,4,6']);
        $this->mahim = User::factory()->create(['name' => 'Mahim', 'created_at' => '2026-09-01']);
        $this->mahim->roles()->attach($this->role(['orders.view' => 'own', 'orders.edit', 'orders.take'], [], 'Moderator')->id);
        app(\App\Services\PermissionService::class)->bump();
    }

    public function test_communication_left_on_closes_where_the_app_last_saw_them_and_a_break_ends_it(): void
    {
        $other = User::factory()->create(['last_seen_at' => now()]);
        DB::table('users')->where('id', $this->mahim->id)->update(['last_seen_at' => '2026-10-07 16:00:00']);
        $left = DB::table('chat_sessions')->insertGetId(['user_id' => $this->mahim->id, 'started_at' => '2026-10-07 15:00:00']);
        $still = DB::table('chat_sessions')->insertGetId(['user_id' => $other->id, 'started_at' => '2026-10-07 18:00:00']);

        $this->assertSame(1, app(ChatService::class)->closeStale());
        $this->assertSame('2026-10-07 16:00:00', (string) DB::table('chat_sessions')->where('id', $left)->value('ended_at')); // not 18:30
        $this->assertNull(DB::table('chat_sessions')->where('id', $still)->value('ended_at')); // still here

        // Going on a break ends Communication: the two never overlap.
        $lunch = (int) DB::table('status_reasons')->where('reason_type', 'break')->where('system_key', 'lunch')->value('id');
        app(BreakService::class)->start($other, $lunch);
        $this->assertNotNull(DB::table('chat_sessions')->where('id', $still)->value('ended_at'));
    }

    public function test_worked_is_communication_and_orders_and_overtime_is_the_part_outside_the_shift(): void
    {
        // In Communication 16:00 to 18:00; the shift ends at 17:00.
        DB::table('chat_sessions')->insert(['user_id' => $this->mahim->id, 'started_at' => '2026-10-07 16:00:00', 'ended_at' => '2026-10-07 18:00:00']);
        $row = app(WorkTime::class)->day(today())['people']->firstWhere('id', $this->mahim->id);
        $this->assertSame(2 * 3600, $row['worked']);
        $this->assertSame(3600, $row['overtime']);
    }

    public function test_a_break_forgotten_on_a_night_shift_closes_at_that_morning_s_shift_end(): void
    {
        foreach (range(0, 6) as $d) {
            DB::table('work_schedules')->insert(['user_id' => $this->mahim->id, 'weekday' => $d, 'start_time' => '20:00', 'end_time' => '09:00']);
        }
        $lunch = (int) DB::table('status_reasons')->where('reason_type', 'break')->where('system_key', 'lunch')->value('id');
        $this->travelTo(Carbon::parse('2026-10-08 01:00:00')); // the shift began at 20:00 the evening before
        app(BreakService::class)->start($this->mahim->fresh(), $lunch);
        $this->travelTo(Carbon::parse('2026-10-08 09:05:00'));
        $this->assertSame(1, app(BreakService::class)->autoClose());
        $this->assertSame('2026-10-08 09:00:00', (string) DB::table('staff_breaks')->value('ended_at')); // not the next day
    }
}
