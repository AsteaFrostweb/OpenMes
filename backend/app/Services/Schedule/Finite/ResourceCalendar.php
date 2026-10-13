<?php

namespace App\Services\Schedule\Finite;

use App\Models\MaintenanceEvent;
use App\Models\Shift;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Working time of a line or workstation across a scheduling horizon, as
 * concrete intervals rather than the per-bucket minute totals the capacity grid
 * uses.
 *
 * Same calendar semantics as {@see \App\Services\Schedule\CapacityService}:
 * active shifts (global plus the line's own) on the weekdays they run, a shift
 * whose end is at or before its start crossing midnight, a zero-length shift
 * contributing nothing, and pending / in-progress maintenance removed — line
 * maintenance from every station on the line, station maintenance from that
 * station only. A maintenance event with no end is taken as one hour, as the
 * capacity grid does.
 *
 * Times are whole minutes since the Unix epoch, which keeps the scheduler's
 * interval arithmetic integer-only.
 */
class ResourceCalendar
{
    /** @var array<string, list<array{0: int, 1: int}>> */
    private array $cache = [];

    /**
     * @param  Collection<int, Shift>  $shifts
     * @param  Collection<int, MaintenanceEvent>  $maintenance
     */
    public function __construct(
        private readonly int $from,
        private readonly int $to,
        private readonly Collection $shifts,
        private readonly Collection $maintenance,
    ) {}

    public static function forHorizon(CarbonInterface $start, CarbonInterface $end): self
    {
        $maintenance = MaintenanceEvent::whereIn('status', [MaintenanceEvent::STATUS_PENDING, 'in_progress'])
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<', $end)
            ->where(fn ($q) => $q->whereNull('scheduled_end_at')->orWhere('scheduled_end_at', '>', $start->copy()->subDay()))
            ->get();

        return new self(
            self::toMinutes($start),
            self::toMinutes($end),
            Shift::where('is_active', true)->get(),
            $maintenance,
        );
    }

    public static function toMinutes(CarbonInterface $moment): int
    {
        return intdiv($moment->getTimestamp(), 60);
    }

    public static function toCarbon(int $minutes): Carbon
    {
        return Carbon::createFromTimestamp($minutes * 60, config('app.timezone'));
    }

    /**
     * Working intervals for a resource, sorted, merged and clipped to the
     * horizon.
     *
     * @return list<array{0: int, 1: int}>
     */
    public function windows(int $lineId, ?int $workstationId = null): array
    {
        $key = $lineId.':'.($workstationId ?? '-');

        return $this->cache[$key] ??= $this->subtract(
            $this->shiftIntervals($lineId),
            $this->maintenanceIntervals($lineId, $workstationId),
        );
    }

    /** Total working minutes the resource offers inside the horizon. */
    public function availableMinutes(int $lineId, ?int $workstationId = null): int
    {
        return array_sum(array_map(fn ($w) => $w[1] - $w[0], $this->windows($lineId, $workstationId)));
    }

    /**
     * The first working moment at or after $t, or null when none is left.
     *
     * @param  list<array{0: int, 1: int}>  $windows
     */
    public static function align(array $windows, int $t): ?int
    {
        foreach ($windows as [$start, $end]) {
            if ($t < $end) {
                return max($t, $start);
            }
        }

        return null;
    }

    /**
     * Where an operation started at $t ends after consuming $minutes of
     * working time — it pauses across non-working gaps. Null when the horizon
     * runs out first.
     *
     * @param  list<array{0: int, 1: int}>  $windows
     */
    public static function consume(array $windows, int $t, int $minutes): ?int
    {
        if ($minutes <= 0) {
            return $t;
        }
        foreach ($windows as [$start, $end]) {
            if ($end <= $t) {
                continue;
            }
            $from = max($t, $start);
            $available = $end - $from;
            if ($available >= $minutes) {
                return $from + $minutes;
            }
            $minutes -= $available;
        }

        return null;
    }

    /**
     * Working minutes between $start and $end on this resource.
     *
     * @param  list<array{0: int, 1: int}>  $windows
     */
    public static function workingMinutesBetween(array $windows, int $start, int $end): int
    {
        $total = 0;
        foreach ($windows as [$a, $b]) {
            $total += max(0, min($b, $end) - max($a, $start));
        }

        return $total;
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private function shiftIntervals(int $lineId): array
    {
        $shifts = $this->shifts->filter(fn (Shift $s) => $s->line_id === null || (int) $s->line_id === $lineId);
        $intervals = [];

        // Start a day early so a night shift that began yesterday still counts.
        $day = self::toCarbon($this->from)->startOfDay()->subDay();
        $last = self::toCarbon($this->to)->startOfDay();
        while ($day->lte($last)) {
            $dow = (int) $day->format('N');
            foreach ($shifts as $shift) {
                $days = $shift->days_of_week;
                if (is_array($days) && ! in_array($dow, $days, true)) {
                    continue;
                }
                $start = $day->copy()->setTimeFromTimeString($shift->start_time);
                $end = $day->copy()->setTimeFromTimeString($shift->end_time);
                if ($end->equalTo($start)) {
                    continue;
                }
                if ($end->lessThan($start)) {
                    $end->addDay();
                }
                $a = max($this->from, self::toMinutes($start));
                $b = min($this->to, self::toMinutes($end));
                if ($b > $a) {
                    $intervals[] = [$a, $b];
                }
            }
            $day->addDay();
        }

        return $this->merge($intervals);
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private function maintenanceIntervals(int $lineId, ?int $workstationId): array
    {
        $intervals = [];
        foreach ($this->maintenance as $event) {
            $appliesToLine = $event->line_id !== null && (int) $event->line_id === $lineId
                && ($event->workstation_id === null || $workstationId === null || (int) $event->workstation_id === $workstationId);
            $appliesToStation = $workstationId !== null && $event->workstation_id !== null && (int) $event->workstation_id === $workstationId;
            if (! $appliesToLine && ! $appliesToStation) {
                continue;
            }
            $end = $event->scheduled_end_at ?? $event->scheduled_at->copy()->addMinutes(60);
            $intervals[] = [self::toMinutes($event->scheduled_at), self::toMinutes($end)];
        }

        return $this->merge($intervals);
    }

    /**
     * @param  list<array{0: int, 1: int}>  $intervals
     * @return list<array{0: int, 1: int}>
     */
    private function merge(array $intervals): array
    {
        usort($intervals, fn ($x, $y) => $x[0] <=> $y[0]);
        $out = [];
        foreach ($intervals as [$a, $b]) {
            if ($out !== [] && $a <= $out[count($out) - 1][1]) {
                $out[count($out) - 1][1] = max($out[count($out) - 1][1], $b);
            } else {
                $out[] = [$a, $b];
            }
        }

        return $out;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $windows
     * @param  list<array{0: int, 1: int}>  $holes
     * @return list<array{0: int, 1: int}>
     */
    private function subtract(array $windows, array $holes): array
    {
        foreach ($holes as [$ha, $hb]) {
            $next = [];
            foreach ($windows as [$a, $b]) {
                if ($hb <= $a || $ha >= $b) {
                    $next[] = [$a, $b];

                    continue;
                }
                if ($ha > $a) {
                    $next[] = [$a, $ha];
                }
                if ($hb < $b) {
                    $next[] = [$hb, $b];
                }
            }
            $windows = $next;
        }

        return $windows;
    }
}
