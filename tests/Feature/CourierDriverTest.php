<?php

namespace Tests\Feature;

use App\Services\Courier\CourierDriver;
use App\Services\Courier\CourierManager;
use App\Services\Courier\Data\BookingRequest;
use App\Services\Courier\FakeCourierDriver;
use App\Services\Courier\SteadfastDriver;
use Tests\TestCase;

class CourierDriverTest extends TestCase
{
    private function request(string $invoice, float $cod = 1500): BookingRequest
    {
        return new BookingRequest($invoice, 'Karim', '01712345678', 'House 1, Dhanmondi, Dhaka', $cod);
    }

    public function test_fake_driver_is_used_in_testing_even_if_env_says_steadfast(): void
    {
        config(['courier.driver' => 'steadfast']);
        $this->app->forgetInstance(CourierManager::class);

        $this->assertInstanceOf(FakeCourierDriver::class, app(CourierDriver::class));
        $this->assertSame('fake', app(CourierManager::class)->resolvedName());
    }

    public function test_staging_is_forced_to_fake_too(): void
    {
        $this->app['env'] = 'staging';
        config(['courier.driver' => 'steadfast']);
        $this->app->forgetInstance(CourierManager::class);

        $this->assertInstanceOf(FakeCourierDriver::class, app(CourierDriver::class));
    }

    public function test_production_resolves_the_configured_driver(): void
    {
        $this->app['env'] = 'production';
        config(['courier.driver' => 'steadfast']);
        $this->app->forgetInstance(CourierManager::class);

        $driver = app(CourierDriver::class);
        $this->assertInstanceOf(SteadfastDriver::class, $driver);

        $this->expectException(\RuntimeException::class);
        $driver->bookBulk([$this->request('IQS-1')]);
    }

    public function test_fake_bulk_booking_is_idempotent_by_invoice(): void
    {
        $fake = new FakeCourierDriver;

        $first = $fake->bookBulk([$this->request('IQS-100'), $this->request('IQS-101')]);
        $again = $fake->bookBulk([$this->request('IQS-100')]);

        $this->assertCount(2, $first);
        $this->assertTrue($first['IQS-100']->ok);
        $this->assertMatchesRegularExpression('/^\d{9}$/', $first['IQS-100']->consignmentId);
        $this->assertSame($first['IQS-100']->consignmentId, $again['IQS-100']->consignmentId);
        $this->assertNotSame($first['IQS-100']->consignmentId, $first['IQS-101']->consignmentId);
    }

    public function test_fake_status_moves_forward_with_time(): void
    {
        $fake = new FakeCourierDriver;
        $fake->bookBulk([$this->request('IQS-200', 990)]);

        $this->assertSame('in_review', $fake->statusByInvoice('IQS-200')->status);
        $this->travel(3)->hours();
        $this->assertSame('delivered', $fake->statusByInvoice('IQS-200')->status);
        $this->assertSame(990.0, $fake->statusByInvoice('IQS-200')->codAmount);
        $this->assertCount(4, $fake->trackingByInvoice('IQS-200'));
        $this->assertNull($fake->statusByInvoice('UNKNOWN'));
    }

    public function test_fake_fraud_check_is_stable_per_phone(): void
    {
        $fake = new FakeCourierDriver;

        $risky = $fake->fraudCheck('01700000000');
        $new = $fake->fraudCheck('01700000009');

        $this->assertSame(37.5, $risky->successRate);
        $this->assertSame(0, $new->totalParcels);
        $this->assertNull($new->successRate);
        $this->assertEquals($fake->fraudCheck('01712345678'), $fake->fraudCheck('01712345678'));
    }

    public function test_webhook_payloads_are_parsed(): void
    {
        $fake = new FakeCourierDriver;

        $status = $fake->parseWebhook(['notification_type' => 'delivery_status', 'consignment_id' => 123, 'invoice' => 'IQS-1', 'status' => 'Delivered', 'cod_amount' => 1500, 'delivery_charge' => 60]);
        $tracking = $fake->parseWebhook(['notification_type' => 'tracking_update', 'consignment_id' => 123, 'tracking_message' => 'Rider picked up']);

        $this->assertSame('delivered', $status->status);
        $this->assertSame('123', $status->consignmentId);
        $this->assertSame('tracking_update', $tracking->type);
        $this->assertSame('Rider picked up', $tracking->message);
        $this->assertNull($fake->parseWebhook(['foo' => 'bar']));
    }
}
