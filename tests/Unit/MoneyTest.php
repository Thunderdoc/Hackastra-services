<?php

namespace Tests\Unit;

use App\Helpers\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_indian_grouping()
    {
        $this->assertSame('0', Money::indianFormat(0));
        $this->assertSame('999', Money::indianFormat(999));
        $this->assertSame('1,000', Money::indianFormat(1000));
        $this->assertSame('10,000', Money::indianFormat(10000));
        $this->assertSame('1,00,000', Money::indianFormat(100000));
        $this->assertSame('12,34,567', Money::indianFormat(1234567));
        $this->assertSame('1,00,00,000', Money::indianFormat(10000000));
    }

    public function test_inr_prefix_and_negatives()
    {
        $this->assertSame('₹ 1,00,000', Money::inr(100000));
        $this->assertSame('-₹ 1,500', Money::inr(-1500));
    }

    public function test_fraction_only_when_present()
    {
        $this->assertSame('1,500.5', Money::indianFormat(1500.50));
        $this->assertSame('1,500', Money::indianFormat(1500.00));
    }
}
