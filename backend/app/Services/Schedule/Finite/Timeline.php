<?php

namespace App\Services\Schedule\Finite;

/**
 * What is already booked while a schedule is being built: busy intervals per
 * resource, and operator demand per line.
 *
 * Resource keys are `ws:<id>` for a workstation and `line:<id>` for a line used
 * as a whole. A line booked as a whole conflicts with every station on it and
 * vice versa — the caller passes both keys when checking — which is the same
 * rule the planner's line-level conflict detection already applies.
 *
 * Intervals are half-open [start, end) in epoch minutes.
 */
class Timeline
{
    /** @var array<string, list<array{start: int, end: int, attributes: array<string, mixed>}>> */
    private array $busy = [];

    /** @var array<int, list<array{start: int, end: int, operators: int, skills: list<int>}>> */
    private array $labor = [];

    /**
     * @param  array<string, mixed>  $attributes  changeover dimensions of what runs there
     */
    public function book(string $key, int $start, int $end, array $attributes = []): void
    {
        if ($end <= $start) {
            return;
        }
        $this->busy[$key][] = ['start' => $start, 'end' => $end, 'attributes' => $attributes];
        usort($this->busy[$key], fn ($a, $b) => $a['start'] <=> $b['start']);
    }

    /**
     * @param  list<int>  $skills
     */
    public function bookLabor(int $lineKey, int $start, int $end, int $operators, array $skills = []): void
    {
        if ($end <= $start) {
            return;
        }
        $this->labor[$lineKey][] = ['start' => $start, 'end' => $end, 'operators' => $operators, 'skills' => $skills];
    }

    /**
     * The latest end among bookings overlapping [start, end) on any of the
     * keys — where the next attempt should start — or null when it is free.
     *
     * @param  list<string>  $keys
     */
    public function conflictEnd(array $keys, int $start, int $end): ?int
    {
        $latest = null;
        foreach ($keys as $key) {
            foreach ($this->busy[$key] ?? [] as $slot) {
                if ($slot['start'] < $end && $slot['end'] > $start) {
                    $latest = max($latest ?? $slot['end'], $slot['end']);
                }
            }
        }

        return $latest;
    }

    /**
     * What ran last on the resource before $t — for sequence-dependent setup.
     *
     * @return array<string, mixed>|null
     */
    public function previousAttributes(string $key, int $t): ?array
    {
        $previous = null;
        foreach ($this->busy[$key] ?? [] as $slot) {
            if ($slot['end'] <= $t && ($previous === null || $slot['end'] >= $previous['end'])) {
                $previous = $slot;
            }
        }

        return $previous['attributes'] ?? null;
    }

    /**
     * Operators already committed on a line during [start, end). Summed over
     * every overlapping booking, which errs on the side of not overbooking
     * people. With skills given, only bookings that need one of them count —
     * those are the people competing for the same qualification.
     *
     * @param  list<int>|null  $skills
     */
    public function laborInUse(int $lineKey, int $start, int $end, ?array $skills = null): int
    {
        $total = 0;
        foreach ($this->labor[$lineKey] ?? [] as $slot) {
            if ($slot['start'] >= $end || $slot['end'] <= $start) {
                continue;
            }
            if ($skills !== null && array_intersect($skills, $slot['skills']) === []) {
                continue;
            }
            $total += $slot['operators'];
        }

        return $total;
    }

    /** The first booking end on the line after $t, to retry once people free up. */
    public function nextLaborRelease(int $lineKey, int $t): ?int
    {
        $next = null;
        foreach ($this->labor[$lineKey] ?? [] as $slot) {
            if ($slot['end'] > $t) {
                $next = min($next ?? $slot['end'], $slot['end']);
            }
        }

        return $next;
    }

    /**
     * Booked working-time footprint per resource key, for the load summary.
     *
     * @return array<string, list<array{start: int, end: int}>>
     */
    public function bookings(): array
    {
        return array_map(
            fn ($slots) => array_map(fn ($s) => ['start' => $s['start'], 'end' => $s['end']], $slots),
            $this->busy,
        );
    }
}
