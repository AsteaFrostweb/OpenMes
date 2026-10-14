<?php

namespace Tests\Unit\Services\Schedule;

use App\Services\Schedule\Finite\ResourceCalendar;
use App\Services\Schedule\Finite\Timeline;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic the finite scheduler is built on, without a database: working
 * windows (epoch minutes, half-open) and the booking timeline.
 */
class FiniteSchedulingPrimitivesTest extends TestCase
{
    /** Two shifts: 06:00–14:00 and 22:00–06:00 next day, as minutes from 0. */
    private const WINDOWS = [[360, 840], [1320, 1800]];

    public function test_align_moves_to_the_next_working_moment(): void
    {
        $this->assertSame(360, ResourceCalendar::align(self::WINDOWS, 0));
        $this->assertSame(500, ResourceCalendar::align(self::WINDOWS, 500));
        $this->assertSame(1320, ResourceCalendar::align(self::WINDOWS, 840));
        $this->assertNull(ResourceCalendar::align(self::WINDOWS, 1800));
    }

    public function test_consume_pauses_across_non_working_gaps(): void
    {
        // 60 min inside the first window.
        $this->assertSame(420, ResourceCalendar::consume(self::WINDOWS, 360, 60));
        // 2 h from 13:00: one hour today, the rest after the 22:00 start.
        $this->assertSame(1380, ResourceCalendar::consume(self::WINDOWS, 780, 120));
        $this->assertSame(780, ResourceCalendar::consume(self::WINDOWS, 780, 0));
        // More work than the horizon holds.
        $this->assertNull(ResourceCalendar::consume(self::WINDOWS, 360, 2000));
    }

    public function test_working_minutes_between_ignores_gaps(): void
    {
        $this->assertSame(480 + 480, ResourceCalendar::workingMinutesBetween(self::WINDOWS, 0, 2000));
        $this->assertSame(60 + 60, ResourceCalendar::workingMinutesBetween(self::WINDOWS, 780, 1380));
        $this->assertSame(0, ResourceCalendar::workingMinutesBetween(self::WINDOWS, 840, 1320));
    }

    public function test_conflicts_are_half_open_and_report_the_latest_end(): void
    {
        $timeline = new Timeline;
        $timeline->book('ws:1', 100, 200);
        $timeline->book('line:1', 150, 300);

        $this->assertNull($timeline->conflictEnd(['ws:1'], 200, 260));
        $this->assertSame(200, $timeline->conflictEnd(['ws:1'], 190, 260));
        // A station and its line are checked together.
        $this->assertSame(300, $timeline->conflictEnd(['ws:1', 'line:1'], 190, 260));
    }

    public function test_previous_attributes_come_from_the_last_job_before(): void
    {
        $timeline = new Timeline;
        $timeline->book('ws:1', 100, 200, ['product_type' => 'A']);
        $timeline->book('ws:1', 200, 300, ['product_type' => 'B']);

        $this->assertNull($timeline->previousAttributes('ws:1', 150));
        $this->assertSame(['product_type' => 'A'], $timeline->previousAttributes('ws:1', 250));
        $this->assertSame(['product_type' => 'B'], $timeline->previousAttributes('ws:1', 300));
        $this->assertSame(200, $timeline->nextBooking('ws:1', 150)['start']);
    }

    public function test_labor_in_use_is_the_peak_not_the_sum(): void
    {
        $timeline = new Timeline;
        // Back to back: never more than 2 people at once.
        $timeline->bookLabor(1, 0, 60, 2);
        $timeline->bookLabor(1, 60, 120, 2);
        // Overlapping the first: 2 + 1 at once.
        $timeline->bookLabor(1, 30, 90, 1, [7]);

        $this->assertSame(3, $timeline->laborInUse(1, 0, 120));
        $this->assertSame(2, $timeline->laborInUse(1, 100, 120));
        // Only bookings needing the skill compete for it.
        $this->assertSame(1, $timeline->laborInUse(1, 0, 120, [7]));
        $this->assertSame(0, $timeline->laborInUse(2, 0, 120));
        $this->assertSame(60, $timeline->nextLaborRelease(1, 10));
    }
}
