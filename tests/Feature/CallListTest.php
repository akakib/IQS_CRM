<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\OrderConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** A manager makes a call list from an imported file and gives it to callers; callers share the pool and record each call. */
class CallListTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $rakin;

    private User $pranta;

    private int $importId;

    protected function setUp(): void
    {
        parent::setUp();
        (new CustomerSeeder)->run();
        Storage::fake('local');
        $this->owner = $this->owner();
        $role = $this->role(['calls.work', 'customers.view'], [], 'Caller');
        $this->rakin = User::factory()->create(['name' => 'Rakin', 'is_active' => true]);
        $this->pranta = User::factory()->create(['name' => 'Pranta', 'is_active' => true]);
        $this->rakin->roles()->attach($role->id);
        $this->pranta->roles()->attach($role->id);

        // An imported file: three delivered customers, one returned, one who asked not to be called.
        $csv = "Name,CN,Phone,Address,Products,Amount,Date,Status\n"
            ."Rahima,DM100001,01711111111,Mirpur,Dates,1200,2026-07-01,Delivered\n"
            ."Rahima,DM100002,01711111111,Mirpur,Honey,800,2026-07-20,Delivered\n"
            ."Karim,DM100003,01722222222,Feni,Dates,900,2026-07-02,Delivered\n"
            ."Nusrat,DM100004,01733333333,Sylhet,Dates,700,2026-07-03,Delivered\n"
            ."Kamal,DM100005,01744444444,Gazipur,Dates,500,2026-07-04,Returned\n"
            ."Old,DM100006,01755555555,Dhaka,Dates,400,2026-07-05,Delivered\n";
        $this->actingAs($this->owner)->post('/customers/history', ['file' => UploadedFile::fake()->createWithContent('lifetime.csv', $csv)]);
        $this->importId = (int) DB::table('customer_imports')->max('id'); // imports on upload
        Customer::where('primary_phone', '01755555555')->update(['do_not_call_at' => now()]);
    }

    private function makeList(array $callers, array $extra = []): int
    {
        $this->actingAs($this->owner)->post(route('calls.lists.store'), $extra + [
            'name' => 'Feedback July', 'purpose' => 'feedback', 'daily_target' => 2, 'import_id' => $this->importId, 'status' => 'delivered',
            'callers' => array_map(fn ($u) => $u->id, $callers), 'instructions' => 'Ask how the dates were.',
        ])->assertRedirect();

        return (int) DB::table('call_lists')->max('id');
    }

    private function item(User $user, int $list): object
    {
        return DB::table('call_list_items')->where('call_list_id', $list)->where('state', 'in_hand')->where('user_id', $user->id)->first();
    }

    public function test_a_list_is_made_from_an_import_and_shared_by_its_callers(): void
    {
        $list = $this->makeList([$this->rakin, $this->pranta]);
        // One line per customer, delivered only, never someone who asked not to be called.
        $this->assertEqualsCanonicalizing(['01711111111', '01722222222', '01733333333'], DB::table('call_list_items')->where('call_list_id', $list)->pluck('phone')->all());

        // Each caller sees the list; someone not on it cannot open it.
        $this->actingAs($this->rakin)->get(route('calls.mine'))->assertOk()->assertSee('Feedback July')->assertSeeText('0 / 2');
        $outsider = User::factory()->create(['is_active' => true]);
        $outsider->roles()->attach($this->role(['calls.work'], [], 'Other caller')->id);
        $this->actingAs($outsider)->get(route('calls.work', $list))->assertForbidden();

        // The pool: two callers get two different numbers; opening again keeps the same one.
        $this->actingAs($this->rakin)->get(route('calls.work', $list))->assertOk()->assertSee('Ask how the dates were.');
        $this->actingAs($this->pranta)->get(route('calls.work', $list))->assertOk();
        $r = $this->item($this->rakin, $list);
        $p = $this->item($this->pranta, $list);
        $this->assertNotSame($r->phone, $p->phone);
        $this->actingAs($this->rakin)->get(route('calls.work', $list));
        $this->assertSame($r->id, $this->item($this->rakin, $list)->id);

        // Skip gives it back untouched.
        $this->post(route('calls.skip', [$list, $r->id]))->assertRedirect(route('calls.mine'));
        $this->assertSame('waiting', DB::table('call_list_items')->find($r->id)->state);
        $this->assertSame(0, DB::table('customer_calls')->count());
    }

    public function test_a_call_needs_satisfaction_and_proof_then_counts_shows_and_moves_on(): void
    {
        $list = $this->makeList([$this->rakin]);
        $this->actingAs($this->rakin)->get(route('calls.work', $list));
        $item = $this->item($this->rakin, $list);
        $rec = fn () => UploadedFile::fake()->create('call.m4a', 300, 'audio/mp4');

        // Talked: how satisfied and the recording are a must.
        $this->post(route('calls.record', [$list, $item->id]), ['outcome' => 'answered', 'proof' => $rec()])->assertSessionHasErrors('satisfaction');
        $this->post(route('calls.record', [$list, $item->id]), ['outcome' => 'answered', 'satisfaction' => 75])->assertSessionHasErrors('proof');
        $this->post(route('calls.record', [$list, $item->id]), ['outcome' => 'answered', 'satisfaction' => 25, 'topics' => ['delivery' => 'bad', 'product' => 'good'],
            'note' => 'Box crushed', 'proof' => $rec(), 'seconds' => 95])->assertRedirect(route('calls.work', $list));
        $done = DB::table('call_list_items')->find($item->id);
        $this->assertSame(['done', 'answered', 25], [$done->state, $done->outcome, $done->satisfaction]);
        $call = DB::table('customer_calls')->first();
        $this->assertSame([25, 95, 'answered'], [$call->satisfaction, $call->duration_seconds, $call->outcome]);
        Storage::disk('local')->assertExists($call->proof_path);

        // It counts for today, shows on the journey and as unhappy for the manager; the proof opens for the caller and managers only.
        $this->get(route('calls.mine'))->assertSeeText('1 / 2');
        $this->get(route('journey.show', $item->customer_id))->assertSee('25% satisfied');
        $this->get(route('calls.proof', $call->id))->assertOk();
        $this->actingAs($this->pranta)->get(route('calls.proof', $call->id))->assertNotFound();
        $this->actingAs($this->owner)->get(route('calls.lists.show', $list))->assertOk()->assertSee('Unhappy customers')->assertSee('Box crushed')->assertSee('Rakin');
        $this->get(route('calls.proof', $call->id))->assertOk();

        // Back on the page, the next number is already there.
        $this->actingAs($this->rakin)->get(route('calls.work', $list))->assertOk();
        $this->assertNotSame($item->id, $this->item($this->rakin, $list)->id);
    }

    public function test_no_answer_comes_back_later_three_times_and_do_not_call_is_kept(): void
    {
        $list = $this->makeList([$this->rakin]);
        $shot = fn () => UploadedFile::fake()->image('log.png');
        $this->actingAs($this->rakin)->get(route('calls.work', $list));
        $item = $this->item($this->rakin, $list);

        // No answer: the call-log screenshot is the proof; it comes back in 3 hours, at most three tries.
        $this->post(route('calls.record', [$list, $item->id]), ['outcome' => 'no_answer'])->assertSessionHasErrors('proof');
        $this->post(route('calls.record', [$list, $item->id]), ['outcome' => 'no_answer', 'proof' => $shot()]);
        $row = DB::table('call_list_items')->find($item->id);
        $this->assertSame(['later', 1], [$row->state, $row->attempts]);
        $this->assertEqualsWithDelta(now()->addHours(3)->getTimestamp(), strtotime($row->next_at), 5);
        foreach ([2, 3] as $try) {
            DB::table('call_list_items')->where('id', $item->id)->update(['next_at' => now()->subMinute()]);
            DB::table('call_list_items')->where('id', '!=', $item->id)->where('state', 'in_hand')->update(['state' => 'waiting', 'user_id' => null]);
            $this->get(route('calls.work', $list)); // a due call-back comes before new numbers
            $this->assertSame($item->id, $this->item($this->rakin, $list)->id);
            $this->post(route('calls.record', [$list, $item->id]), ['outcome' => 'busy', 'proof' => $shot()]);
        }
        $this->assertSame(['done', 3], [DB::table('call_list_items')->find($item->id)->state, DB::table('call_list_items')->find($item->id)->attempts]);

        // Call later: a time is a must.
        $this->get(route('calls.work', $list));
        $next = $this->item($this->rakin, $list);
        $this->post(route('calls.record', [$list, $next->id]), ['outcome' => 'later', 'satisfaction' => 75, 'proof' => UploadedFile::fake()->create('c.mp3', 10, 'audio/mpeg')])->assertSessionHasErrors('later_at');
        $this->post(route('calls.record', [$list, $next->id]), ['outcome' => 'later', 'satisfaction' => 75, 'later_at' => now()->addDay()->format('Y-m-d\TH:i'), 'proof' => UploadedFile::fake()->create('c.mp3', 10, 'audio/mpeg')]);
        $this->assertSame('later', DB::table('call_list_items')->find($next->id)->state);

        // Do not call again: kept on the customer, out of every list, never in a new one.
        $this->get(route('calls.work', $list));
        $third = $this->item($this->rakin, $list);
        $other = $this->makeList([$this->pranta]);
        $this->actingAs($this->rakin)->post(route('calls.record', [$list, $third->id]), ['outcome' => 'do_not_call', 'satisfaction' => 0, 'proof' => UploadedFile::fake()->create('c.amr', 10)]);
        $this->assertNotNull(Customer::find($third->customer_id)->do_not_call_at);
        $this->assertSame('done', DB::table('call_list_items')->where('call_list_id', $other)->where('customer_id', $third->customer_id)->value('state'));
        $again = $this->makeList([$this->pranta]);
        $this->assertFalse(DB::table('call_list_items')->where('call_list_id', $again)->where('customer_id', $third->customer_id)->exists());

        // A file that is neither a recording nor a picture is refused.
        $this->actingAs($this->pranta)->get(route('calls.work', $again));
        $p = $this->item($this->pranta, $again);
        $this->post(route('calls.record', [$again, $p->id]), ['outcome' => 'no_answer', 'proof' => UploadedFile::fake()->create('x.exe', 10)])->assertSessionHasErrors('proof');
    }

    public function test_an_order_taken_on_the_call_is_linked_and_the_caller_stays(): void
    {
        (new CatalogSeeder)->run();
        (new OrderConfigSeeder)->run();
        OrderStatus::forget();
        Product::factory()->withVariant(700)->create(['name' => 'Ajwa Dates']);
        $this->rakin->roles()->attach($this->role(['orders.create', 'orders.view', 'orders.edit'], [], 'Orders')->id);
        $list = $this->makeList([$this->rakin]);
        $this->actingAs($this->rakin)->get(route('calls.work', $list))->assertSee('Take an order');
        $item = $this->item($this->rakin, $list);

        $done = $this->post(route('orders.store'), ['channel' => 'phone', 'phone' => $item->phone, 'name' => $item->name, 'address_line' => 'Road 1', 'embed' => 1, 'call_item' => $item->id,
            'items' => [['variant_id' => ProductVariant::first()->id, 'qty' => 1]]]);
        $done->assertOk()->assertSee('stay', false);
        $orderId = (int) DB::table('orders')->max('id');
        $this->assertSame($orderId, DB::table('call_list_items')->find($item->id)->order_id);

        $this->post(route('calls.record', [$list, $item->id]), ['outcome' => 'ordered', 'satisfaction' => 100, 'proof' => UploadedFile::fake()->create('c.m4a', 10)]);
        $this->assertSame($orderId, DB::table('customer_calls')->value('order_id'));
        $this->actingAs($this->owner)->get(route('calls.lists.show', $list))->assertSee('Orders from calls');
        $this->assertSame(1, app(\App\Services\Calls\CallListService::class)->progress(DB::table('call_lists')->find($list))['orders']);
    }

    public function test_managers_change_callers_and_pause(): void
    {
        $list = $this->makeList([$this->rakin]);
        $this->actingAs($this->rakin)->get(route('calls.work', $list));
        $held = $this->item($this->rakin, $list);
        $this->actingAs($this->owner)->put(route('calls.lists.update', $list), ['name' => 'Feedback July', 'purpose' => 'upsell', 'daily_target' => 40,
            'callers' => [$this->pranta->id], 'is_active' => 1])->assertRedirect();
        // Taken off the list: the number goes back, the list is gone from their page.
        $this->assertSame('waiting', DB::table('call_list_items')->find($held->id)->state);
        $this->actingAs($this->rakin)->get(route('calls.work', $list))->assertForbidden();
        $this->actingAs($this->pranta)->get(route('calls.mine'))->assertSeeText('0 / 40');
        $this->actingAs($this->owner)->put(route('calls.lists.update', $list), ['name' => 'Feedback July', 'purpose' => 'upsell', 'daily_target' => 40, 'callers' => [$this->pranta->id], 'is_active' => 0]);
        $this->actingAs($this->pranta)->get(route('calls.mine'))->assertDontSee('Feedback July');
        // Callers cannot manage.
        $this->get(route('calls.lists'))->assertForbidden();
    }

    public function test_review_fixes_pool_order_holds_and_do_not_call(): void
    {
        $list = $this->makeList([$this->rakin, $this->pranta]);
        $rec = fn () => UploadedFile::fake()->create('c.m4a', 10, 'audio/mp4');

        // Skip puts the number behind the others: the caller gets a different one next.
        $this->actingAs($this->rakin)->get(route('calls.work', $list));
        $first = $this->item($this->rakin, $list);
        $this->post(route('calls.skip', [$list, $first->id]));
        $this->get(route('calls.work', $list));
        $this->assertNotSame($first->id, $this->item($this->rakin, $list)->id);

        // A call-back left with someone who went away keeps its place: the next caller gets it before fresh numbers.
        $held = $this->item($this->rakin, $list);
        DB::table('call_list_items')->where('id', $held->id)->update(['attempts' => 1, 'next_at' => now()->subHour(), 'taken_at' => now()->subMinutes(31)]);
        $this->actingAs($this->pranta)->get(route('calls.work', $list));
        $this->assertSame($held->id, $this->item($this->pranta, $list)->id);

        // Being on the page (or tapping Call) keeps the hold: not handed on while it is worked.
        DB::table('call_list_items')->where('id', $held->id)->update(['taken_at' => now()->subMinutes(25)]);
        $this->postJson(route('calls.touch', [$list, $held->id]))->assertOk();
        $this->assertGreaterThan(now()->subMinute()->getTimestamp(), strtotime(DB::table('call_list_items')->find($held->id)->taken_at));

        // Saving a number that went to someone else meanwhile: a clear message, nothing saved.
        $this->actingAs($this->rakin)->get(route('calls.work', $list));
        $mine = $this->item($this->rakin, $list);
        DB::table('call_list_items')->where('id', $mine->id)->update(['user_id' => $this->pranta->id]);
        $this->post(route('calls.record', [$list, $mine->id]), ['outcome' => 'answered', 'satisfaction' => 50, 'proof' => $rec()])->assertSessionHasErrors('outcome');
        $this->assertSame(0, DB::table('customer_calls')->count());
        DB::table('call_list_items')->where('id', $mine->id)->update(['user_id' => $this->rakin->id]);

        // Do not call again also takes the customer's number out of someone else's hands in another list.
        $other = $this->makeList([$this->pranta]);
        $this->actingAs($this->pranta)->get(route('calls.work', $other));
        $theirs = $this->item($this->pranta, $other);
        DB::table('call_list_items')->where('id', $theirs->id)->update(['customer_id' => $mine->customer_id]);
        $this->actingAs($this->rakin)->post(route('calls.record', [$list, $mine->id]), ['outcome' => 'do_not_call', 'satisfaction' => 0, 'proof' => $rec()]);
        $this->assertSame(['done', null], [DB::table('call_list_items')->find($theirs->id)->state, DB::table('call_list_items')->find($theirs->id)->user_id]);

        // Merging keeps the wish: the customer left after a merge is not called either.
        $keep = Customer::where('primary_phone', '01733333333')->first();
        app(\App\Services\Customers\CustomerService::class)->merge($keep, Customer::find($mine->customer_id), $this->owner->id);
        $this->assertNotNull($keep->fresh()->do_not_call_at);
    }

    public function test_a_proof_is_judged_by_what_it_is_and_served_safely(): void
    {
        $list = $this->makeList([$this->rakin]);
        $this->actingAs($this->rakin)->get(route('calls.work', $list));
        $item = $this->item($this->rakin, $list);

        // A web page named like a picture is not a screenshot; a picture is not a recording.
        // A real file (its type read from the content, as on the server), named like a picture.
        $path = tempnam(sys_get_temp_dir(), 'p');
        file_put_contents($path, '<html><body><script>alert(1)</script></body></html>');
        $html = new UploadedFile($path, 'log.jpg', 'image/jpeg', null, true);
        $this->post(route('calls.record', [$list, $item->id]), ['outcome' => 'no_answer', 'proof' => $html])->assertSessionHasErrors('proof');
        $this->post(route('calls.record', [$list, $item->id]), ['outcome' => 'answered', 'satisfaction' => 100, 'proof' => UploadedFile::fake()->image('log.png')])->assertSessionHasErrors('proof');
        $this->post(route('calls.record', [$list, $item->id]), ['outcome' => 'answered', 'satisfaction' => 100, 'proof' => UploadedFile::fake()->create('call.m4a', 10, 'audio/mp4'), 'seconds' => 300]);

        $call = DB::table('customer_calls')->first();
        $this->assertStringEndsWith('.m4a', $call->proof_path);
        $r = $this->get(route('calls.proof', $call->id))->assertOk();
        $this->assertSame('audio/mp4', $r->headers->get('Content-Type'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', (string) $r->headers->get('Content-Security-Policy'));
    }

    public function test_work_time_reports_and_the_order_popup_see_list_calls(): void
    {
        $list = $this->makeList([$this->rakin]);
        $this->actingAs($this->rakin)->get(route('calls.work', $list));
        $item = $this->item($this->rakin, $list);
        // Taken in the morning and saved after hours: never more than the 30-minute hold counts as work.
        DB::table('call_list_items')->where('id', $item->id)->update(['taken_at' => now()->subHours(9)]);
        $this->post(route('calls.record', [$list, $item->id]), ['outcome' => 'busy', 'proof' => UploadedFile::fake()->image('log.png')]);
        $call = DB::table('customer_calls')->first();
        $this->assertLessThanOrEqual(30 * 60, strtotime($call->ended_at) - strtotime($call->started_at));

        // Someone who only makes list calls is in Team Activity.
        $this->assertNotNull(app(\App\Services\Reports\WorkTime::class)->day(today())['people']->firstWhere('id', $this->rakin->id));

        // The calls report counts the proof as a recording.
        $this->actingAs($this->owner)->get(route('calls-report.index'))->assertOk();

        // A caller who may create orders but not browse them still gets the order popup.
        $taker = User::factory()->create(['is_active' => true]);
        $taker->roles()->attach($this->role(['calls.work', 'orders.create'], [], 'Caller who orders')->id);
        $other = $this->makeList([$taker]);
        $this->actingAs($taker)->get(route('calls.work', $other))->assertSee('Take an order')->assertSee('order-new.window', false);
    }
}
