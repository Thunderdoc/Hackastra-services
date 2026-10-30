<?php

namespace Tests\Feature;

use App\User;
use Tests\TestCase;

/**
 * Owner screenshot 2026-10-30: the leave queue's DAYS header
 * stacked letter-by-letter (D-A-Y-S) inside a 4% fixed-layout
 * column. The column is wider (5.5%) and the short headers are
 * nowrap, so a header word can never stack again.
 */
class OwnerLeaveDaysHeaderTest extends TestCase
{
    public function test_days_header_is_nowrap_and_the_column_is_wider()
    {
        $admin = User::where('email', 'admin@hackstra.app')->firstOrFail();
        $page = $this->actingAs($admin)->get(route('leave.index'));
        $page->assertOk();
        $page->assertSee('lv-nowrap">Days<', false);
        $page->assertSee('width:5.5%', false);
        $page->assertSee('white-space: nowrap', false);
    }
}
