<?php

namespace Tests\Unit;

use App\Support\GradeCalculator;
use PHPUnit\Framework\TestCase;

class GradeCalculatorTest extends TestCase
{
    private $bands = [
        ['grade' => 'A', 'mark_from' => 70, 'mark_to' => 100, 'remark' => 'Excellent'],
        ['grade' => 'B', 'mark_from' => 60, 'mark_to' => 69, 'remark' => 'Very Good'],
        ['grade' => 'C', 'mark_from' => 50, 'mark_to' => 59, 'remark' => 'Good'],
        ['grade' => 'F', 'mark_from' => 0, 'mark_to' => 39, 'remark' => 'Fail'],
    ];

    public function test_missing_marks_are_not_zero()
    {
        $this->assertNull(GradeCalculator::total([null, null]));
        $this->assertNull(GradeCalculator::total(['', '']));
        $this->assertSame(0.0, GradeCalculator::total([0, 0]));
        $this->assertSame(30.0, GradeCalculator::total([null, 30]));
    }

    public function test_average_ignores_missing()
    {
        $this->assertSame(50.0, GradeCalculator::average([40, 60, null]));
        $this->assertNull(GradeCalculator::average([null]));
    }

    public function test_grade_bands_and_boundaries()
    {
        $this->assertSame('A', GradeCalculator::gradeFor(70, $this->bands)['grade']);
        $this->assertSame('A', GradeCalculator::gradeFor(100, $this->bands)['grade']);
        $this->assertSame('B', GradeCalculator::gradeFor(69, $this->bands)['grade']);
        $this->assertSame('F', GradeCalculator::gradeFor(0, $this->bands)['grade']);
        $this->assertNull(GradeCalculator::gradeFor(45, $this->bands)); // gap in bands stays honest
        $this->assertNull(GradeCalculator::gradeFor(null, $this->bands));
    }

    public function test_positions_share_ties_and_skip()
    {
        $positions = GradeCalculator::positions([1 => 90, 2 => 80, 3 => 80, 4 => 70, 5 => null]);
        $this->assertSame(1, $positions[1]);
        $this->assertSame(2, $positions[2]);
        $this->assertSame(2, $positions[3]);
        $this->assertSame(4, $positions[4]);
        $this->assertNull($positions[5]);
    }

    public function test_pass_mark()
    {
        $this->assertTrue(GradeCalculator::isPass(40));
        $this->assertFalse(GradeCalculator::isPass(39));
        $this->assertFalse(GradeCalculator::isPass(null));
    }
}
