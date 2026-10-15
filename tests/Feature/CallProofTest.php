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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** One upload box for every call: real type, never the same file twice, never another number's file, an earlier-day file only when confirmed. */
class CallProofTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function rec(string $name = 'call.m4a'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, 'audio '.++$this->n)->mimeType('audio/mp4');
    }

    private function headers(): array
    {
        return ['Accept' => 'application/json'];
    }

    public function test_incoming_call_checks_the_file(): void
    {
        Carbon::setTestNow('2026-10-15 12:00:00');
        Storage::fake('local');
        $owner = $this->owner();
        $in = fn (array $extra) => $this->actingAs($owner)->post(route('customer-calls.incoming'), $extra + ['phone' => '01711111111', 'reason' => array_key_first(\App\Http\Controllers\CustomerCallController::REASONS_IN)], $this->headers());

        $in(['proof' => UploadedFile::fake()->createWithContent('notes.txt', 'hello')])->assertStatus(422)->assertJsonPath('errors.proof.0', 'This is not a recording or a picture. Add the call recording or a screenshot of the call log.');
        $in(['proof' => $this->rec('Call recording 01722222222_20261015_1140.m4a')])->assertStatus(422)->assertJsonPath('errors.proof.0', 'This file is of 01722222222, not of 01711111111. Pick the file of this call.');
        $in(['proof' => $this->rec('Call recording 20261012_1140.m4a')])->assertStatus(422)->assertJsonPath('errors.proof.0', 'This file is from 12 Oct. Tick "It is the right file" if it is, or pick the file of this call.');
        // No date in the name: the time on the phone counts.
        $in(['proof' => $this->rec('voice.m4a'), 'proof_modified' => (string) now()->subDays(2)->getTimestampMs()])->assertStatus(422);

        $in(['proof' => $this->rec('Call recording 20261012_1140.m4a'), 'proof_old_ok' => '1'])->assertOk();
        $old = DB::table('customer_calls')->latest('id')->first();
        $this->assertSame('Call recording 20261012_1140.m4a', $old->proof_name);
        $this->assertTrue((bool) $old->proof_old);

        $file = $this->rec('+880 1711-111111 today.m4a'); // its own number, written any way
        $in(['proof' => $file, 'proof_modified' => (string) now()->subMinutes(5)->getTimestampMs()])->assertOk();
        $call = DB::table('customer_calls')->latest('id')->first();
        $this->assertFalse((bool) $call->proof_old);
        Storage::disk('local')->assertExists($call->proof_path);

        // The same file for another call: refused.
        $in(['proof' => UploadedFile::fake()->createWithContent('copy.m4a', 'audio '.$this->n)->mimeType('audio/mp4')])->assertStatus(422)
            ->assertJsonPath('errors.proof.0', 'This file was already used for another call. Add the file of this call.');

        // Opens for who made the call and managers only.
        $this->get(route('calls.proof', $call->id))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs(User::factory()->create())->get(route('calls.proof', $call->id))->assertNotFound();
        $this->actingAs($owner)->get(route('calls-report.index'))->assertSee('Earlier file')->assertSee('Call recording 20261012_1140.m4a');
    }

    public function test_order_and_rider_calls_take_the_file_too(): void
    {
        Carbon::setTestNow('2026-10-15 12:00:00');
        Storage::fake('local');
        (new CatalogSeeder)->run();
        (new CustomerSeeder)->run();
        (new OrderConfigSeeder)->run();
        OrderStatus::forget();
        Product::factory()->withVariant(700)->create(['name' => 'Ajwa Dates']);
        $owner = $this->owner();
        $order = app(OrderService::class)->create(['channel' => 'messenger', 'phone' => '01712345678', 'name' => 'Karim', 'address_line' => 'Road 1',
            'items' => [['variant_id' => ProductVariant::first()->id, 'qty' => 1]]], $owner);

        // From the order page: PATCH rides on POST (multipart).
        $id = $this->actingAs($owner)->postJson(route('customer-calls.start'), ['order_id' => $order->id])->json('id');
        $this->post(route('customer-calls.finish', $id), ['_method' => 'PATCH', 'outcome' => array_key_first(\App\Http\Controllers\CustomerCallController::OUTCOMES), 'proof' => $this->rec('01712345678.m4a')], $this->headers())->assertOk();
        $this->assertNotNull(DB::table('customer_calls')->find($id)->proof_path);
        $this->get(route('orders.show', $order))->assertSee(route('calls.proof', $id), false);

        // Rider call: the rider's or the customer's number may be in the name.
        $rider = ['order_id' => $order->id, 'rider_name' => 'Jamal', 'rider_phone' => '01899999999', 'claim' => 'no_answer', 'verdict' => array_key_first(\App\Services\Work\RiderCallService::VERDICTS), 'action' => 'solved'];
        $this->post(route('rider-calls.store'), $rider + ['proof' => $this->rec('01755555555.m4a')], $this->headers())->assertStatus(422);
        $this->post(route('rider-calls.store'), $rider + ['proof' => $this->rec('01899999999 call.m4a')], $this->headers())->assertOk();
        $call = DB::table('rider_calls')->latest('id')->first();
        $this->assertSame('01899999999 call.m4a', $call->proof_name);
        $this->get(route('rider-calls.proof', $call->id))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('rider-calls.proof', $call->id))->assertNotFound();
    }
}
