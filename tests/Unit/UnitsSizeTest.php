<?php

namespace Tests\Unit;

use App\Support\Units;
use PHPUnit\Framework\TestCase;

class UnitsSizeTest extends TestCase
{
    public function test_the_size_is_read_from_the_name_in_grams_or_ml(): void
    {
        $this->assertSame([500.0, 'g'], Units::sizeFromName('500g'));
        $this->assertSame([3000.0, 'g'], Units::sizeFromName(null, 'বড় আম্বার খেজুর 3kg'));
        $this->assertSame([350.0, 'g'], Units::sizeFromName('Default'.'', 'Hazelnut Cream (350 gm)'));
        $this->assertSame([600.0, 'ml'], Units::sizeFromName('Heinz Tomato Ketchup (600ml)'));
        $this->assertSame([1500.0, 'ml'], Units::sizeFromName('Oil 1.5 L'));
        $this->assertSame([2250.0, 'g'], Units::sizeFromName('Milk Powder 2,25 kg'));
        $this->assertSame([500.0, 'g'], Units::sizeFromName('খেজুর ৫০০ গ্রাম'));
        $this->assertSame([250.0, 'g'], Units::sizeFromName('250g', 'Almond 1kg')); // the variant's own size first
        $this->assertNull(Units::sizeFromName('20PCS', 'Nutmeg'));
    }

    public function test_amounts_show_in_the_big_unit_once_there_is_enough(): void
    {
        $this->assertSame('42.5 kg', Units::amount(42500));
        $this->assertSame('750 g', Units::amount(750));
        $this->assertSame('3.6 L', Units::amount(0, 3600));
        $this->assertSame('1.2 kg · 250 ml', Units::amount(1200, 250));
        $this->assertSame('-', Units::amount(0, 0));
    }
}
