<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** T5 CRM installs on phones: every page links the manifest, icon and service worker. */
class InstallableAppTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_link_the_app_manifest_and_files_exist(): void
    {
        $this->get('/login')->assertOk()->assertSee('/manifest.webmanifest', false)->assertSee('/icons/apple-touch-icon.png', false)->assertSee("register('/sw.js')", false);

        $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true);
        $this->assertSame('T5 CRM', $manifest['name']);
        $this->assertSame('standalone', $manifest['display']);
        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
        }
        $this->assertFileExists(public_path('sw.js'));
        $this->assertFileExists(public_path('offline.html'));
    }
}
