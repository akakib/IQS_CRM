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

    public function test_a_new_week_target_or_salary_counts_from_when_it_was_set_and_the_past_keeps_its_own(): void
    {
        $boss = $this->owner(['name' => 'Boss']);
        $cal = fn () => new \App\Services\Work\WorkCalendar;

        // Until today Mahim worked the office hours (9 to 5). From today: 2 PM to 10 PM.
        $days = collect(range(0, 6))->mapWithKeys(fn ($d) => [$d => ['on' => 1, 'start' => '14:00', 'end' => '22:00']])->all();
        $this->actingAs($boss)->post('/users/'.$this->mahim->id.'/schedule', ['days' => $days])->assertSessionHas('success');
        $this->assertSame('14:00', $cal()->shift($this->mahim->id, Carbon::parse('2026-10-07'))[0]->format('H:i'));
        $this->assertSame('09:00', $cal()->shift($this->mahim->id, Carbon::parse('2026-10-05'))[0]->format('H:i')); // last Monday: as it was

        // A target set this month does not rewrite September.
        DB::table('kpi_targets')->insert(['user_id' => null, 'metric' => 'delivered_count', 'target' => 40, 'from_month' => '2026-09-01', 'created_at' => now(), 'updated_at' => now()]);
        $this->post('/kpi/targets', ['delivered_count' => 60])->assertSessionHas('success');
        $this->assertSame(40.0, \App\Services\Reports\KpiScorecard::targetsFor('2026-09-01')[0]['delivered_count']);
        $this->assertSame(60.0, \App\Services\Reports\KpiScorecard::targetsFor('2026-10-01')[0]['delivered_count']);

        // A raise from next month: this month keeps the old salary.
        DB::table('staff_salaries')->insert(['user_id' => $this->mahim->id, 'from_month' => '2026-09-01', 'monthly_salary' => 15000, 'created_at' => now(), 'updated_at' => now()]);
        $this->put('/users/'.$this->mahim->id, ['name' => 'Mahim', 'email' => $this->mahim->email, 'monthly_salary' => 18000, 'salary_from' => '2026-11'])->assertRedirect();
        $this->assertDatabaseHas('staff_salaries', ['user_id' => $this->mahim->id, 'from_month' => '2026-11-01']);
        $this->assertEquals(15000, DB::table('staff_salaries')->where('user_id', $this->mahim->id)->where('from_month', '<=', '2026-10-01')->orderByDesc('from_month')->value('monthly_salary')); // October: still 15,000
    }

    public function test_a_night_shift_is_one_attendance_day(): void
    {
        foreach (range(0, 6) as $d) {
            DB::table('work_schedules')->insert(['user_id' => $this->mahim->id, 'weekday' => $d, 'start_time' => '20:00', 'end_time' => '09:00']);
        }
        $this->travelTo(Carbon::parse('2026-10-07 21:00:00'));
        $this->actingAs($this->mahim)->get('/dashboard');
        $this->travelTo(Carbon::parse('2026-10-08 02:00:00'));
        $this->get('/dashboard');
        $this->assertSame(['2026-10-07'], DB::table('work_days')->where('user_id', $this->mahim->id)->pluck('work_date')->map(fn ($d) => substr($d, 0, 10))->all());
        $this->assertSame('2026-10-08 02:00:00', (string) DB::table('work_days')->where('user_id', $this->mahim->id)->value('last_seen_at'));
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

    public function test_new_office_hours_count_from_today_and_the_past_keeps_its_hours(): void
    {
        $settings = app(\App\Services\SettingsService::class);
        [$start, $end] = [settings('work.start'), settings('work.end')]; // the hours before the change
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-14 12:00')); // a Wednesday
        $settings->set(['work.start' => '10:00', 'work.days' => '0,1,2,3,4']);

        $cal = new \App\Services\Work\WorkCalendar;
        $this->assertSame(['10:00', $end], $cal->week(null, \Illuminate\Support\Carbon::parse('2026-10-14'))[3]);
        $this->assertSame([$start, $end], $cal->week(null, \Illuminate\Support\Carbon::parse('2026-10-07'))[3]); // last week stays
        $this->assertFalse($cal->isOffDay(null, \Illuminate\Support\Carbon::parse('2026-10-10'))); // a Saturday that was a work day
        $this->assertTrue($cal->isOffDay(null, \Illuminate\Support\Carbon::parse('2026-10-17')));  // Saturday is off from now on
        $this->assertSame('10:00', \Illuminate\Support\Facades\DB::table('office_schedules')->where('effective_from', '2026-10-14')->value('start_time'));
    }

    public function test_orders_confirmed_or_made_outside_the_shift_are_counted(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-14 12:00')); // a Wednesday
        $user = \App\Models\User::factory()->create(['name' => 'Rakin', 'created_at' => '2026-01-01']);
        (new \Database\Seeders\RoleSeeder)->run();
        $user->roles()->attach(\Illuminate\Support\Facades\DB::table('roles')->where('system_key', 'moderator')->value('id'));
        app(\App\Services\PermissionService::class)->bump();
        // Night shift 20:00 to 09:00 every day from before.
        foreach (range(0, 6) as $wd) {
            \Illuminate\Support\Facades\DB::table('work_schedules')->insert(['user_id' => $user->id, 'weekday' => $wd, 'start_time' => '20:00', 'end_time' => '09:00', 'effective_from' => '2026-01-01']);
        }
        $customer = \Illuminate\Support\Facades\DB::table('customers')->insertGetId(['name' => 'K', 'primary_phone' => '01712345678']);
        $order = fn (string $no, ?string $confirmedAt, string $createdAt, ?int $by = null) => \Illuminate\Support\Facades\DB::table('orders')->insertGetId([
            'order_no' => $no, 'customer_id' => $customer, 'channel' => 'messenger', 'status_id' => \Illuminate\Support\Facades\DB::table('order_statuses')->value('id'), 'ship_name' => 'K', 'ship_phone' => '01712345678',
            'ship_address' => 'R', 'confirmed_by' => $confirmedAt ? $user->id : null, 'confirmed_at' => $confirmedAt, 'created_by' => $by, 'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
        $order('IQ1', '2026-10-13 23:30', '2026-10-13 23:00');            // in the shift
        $order('IQ2', '2026-10-14 02:00', '2026-10-14 01:00');            // after midnight: still the night shift
        $order('IQ3', '2026-10-14 11:00', '2026-10-14 10:30');            // outside
        $order('IQ4', null, '2026-10-14 15:00', $user->id);               // made by hand, outside

        $rows = app(\App\Services\Reports\OutsideShift::class)->orders([$user->id], \Illuminate\Support\Carbon::parse('2026-10-13'), \Illuminate\Support\Carbon::parse('2026-10-14'))->get($user->id);
        $this->assertSame(['IQ3 confirmed', 'IQ4 made'], $rows->map(fn ($r) => $r['order_no'].' '.$r['kind'])->all());

        $this->actingAs($this->owner());
        $this->get(route('work-time.index', ['from' => '2026-10-13', 'to' => '2026-10-14', 'person' => $user->id]))->assertOk()
            ->assertSee('Outside shift')->assertSee('1 confirmed · 1 made')->assertSee('Outside the shift: 1 confirmed, 1 made');
    }

    public function test_communication_left_on_with_nothing_done_stops_counting(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-07 16:00:00'));
        DB::table('users')->where('id', $this->mahim->id)->update(['last_seen_at' => now()]); // the app is open
        $channel = DB::table('chat_channels')->insertGetId(['name' => 'Page', 'type' => 'messenger', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $idle = DB::table('chat_sessions')->insertGetId(['user_id' => $this->mahim->id, 'started_at' => '2026-10-07 15:00:00']);
        DB::table('chat_events')->insert(['user_id' => $this->mahim->id, 'chat_channel_id' => $channel, 'kind' => 'message', 'created_at' => '2026-10-07 15:20:00']);

        app(ChatService::class)->closeStale();
        // Last real work 15:20, plus 10 minutes: not the hour after.
        $this->assertSame('2026-10-07 15:30:00', (string) DB::table('chat_sessions')->where('id', $idle)->value('ended_at'));

        // Someone still tapping keeps it on.
        $busy = DB::table('chat_sessions')->insertGetId(['user_id' => $this->mahim->id, 'started_at' => '2026-10-07 15:00:00']);
        DB::table('chat_events')->insert(['user_id' => $this->mahim->id, 'chat_channel_id' => $channel, 'kind' => 'message', 'created_at' => '2026-10-07 15:50:00']);
        app(ChatService::class)->closeStale();
        $this->assertNull(DB::table('chat_sessions')->where('id', $busy)->value('ended_at'));
    }
}
