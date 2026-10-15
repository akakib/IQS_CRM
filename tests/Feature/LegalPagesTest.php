<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The privacy policy and terms open without signing in (Google checks them). */
class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_privacy_and_terms_are_public(): void
    {
        $this->get('/privacy')->assertOk()->assertSee('Privacy Policy')->assertSee('Google Drive');
        $this->get('/terms')->assertOk()->assertSee('Terms of Service');
        $this->actingAs($this->owner())->get('/privacy')->assertOk();
    }
}
