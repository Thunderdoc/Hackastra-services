<?php

namespace Tests\Unit;

use App\Support\NumberToWords;
use PHPUnit\Framework\TestCase;

class NumberToWordsTest extends TestCase
{
    public function test_small_numbers()
    {
        $this->assertSame('Zero', NumberToWords::convert(0));
        $this->assertSame('Seven', NumberToWords::convert(7));
        $this->assertSame('Nineteen', NumberToWords::convert(19));
        $this->assertSame('Forty Two', NumberToWords::convert(42));
        $this->assertSame('Ninety Nine', NumberToWords::convert(99));
    }

    public function test_hundreds_and_thousands()
    {
        $this->assertSame('One Hundred', NumberToWords::convert(100));
        $this->assertSame('One Hundred Five', NumberToWords::convert(105));
        $this->assertSame('Nine Hundred Ninety Nine', NumberToWords::convert(999));
        $this->assertSame('One Thousand', NumberToWords::convert(1000));
        $this->assertSame('One Thousand Five Hundred', NumberToWords::convert(1500));
        $this->assertSame('Twenty Five Thousand Six Hundred', NumberToWords::convert(25600));
    }

    public function test_indian_system_lakh_and_crore()
    {
        $this->assertSame('One Lakh', NumberToWords::convert(100000));
        $this->assertSame('One Lakh Twenty Three Thousand Four Hundred Fifty Six', NumberToWords::convert(123456));
        $this->assertSame('Ten Lakh', NumberToWords::convert(1000000));
        $this->assertSame('One Crore', NumberToWords::convert(10000000));
        $this->assertSame('One Hundred Crore', NumberToWords::convert(1000000000));
        $this->assertSame('Two Crore Thirty Four Lakh Fifty Six Thousand Seven Hundred Eighty Nine', NumberToWords::convert(23456789));
    }

    public function test_in_rupees()
    {
        $this->assertSame('Rupees Zero Only', NumberToWords::inRupees(0));
        $this->assertSame('Rupees One Lakh Only', NumberToWords::inRupees(100000));
        $this->assertSame('Rupees One Thousand Five Hundred Only', NumberToWords::inRupees(1500));
        $this->assertSame('Rupees One Thousand Five Hundred and Paise Fifty Only', NumberToWords::inRupees(1500.50));
        $this->assertSame('Paise Seventy Five Only', NumberToWords::inRupees(0.75));
        $this->assertSame('Minus Rupees Five Hundred Only', NumberToWords::inRupees(-500));
    }
}
