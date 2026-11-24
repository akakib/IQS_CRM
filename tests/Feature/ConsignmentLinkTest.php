<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class ConsignmentLinkTest extends TestCase
{
    public function test_a_steadfast_cn_opens_its_page_in_a_new_tab_and_others_stay_text(): void
    {
        $html = Blade::render('<x-consignment-link cn="218765432" courier="steadfast" />');
        $this->assertStringContainsString('href="https://steadfast.com.bd/user/consignment/218765432"', $html);
        $this->assertStringContainsString('target="_blank"', $html);

        $this->assertStringNotContainsString('href=', Blade::render('<x-consignment-link cn="99" courier="fake" />'));
        $this->assertStringContainsString('CN -', Blade::render('<x-consignment-link :cn="null" />'));
    }
}
