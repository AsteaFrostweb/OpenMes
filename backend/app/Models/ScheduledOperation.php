<?php

namespace App\Models;

use App\Enums\ScheduledOperationStatus;
use App\Models\Concerns\HasTenant;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where and when one routing step of one work order is planned to run — or the
 * machine-readable reason it cannot be. Routing data itself stays in the work
 * order's process snapshot; this row is only the planning outcome.
 *
 * Audited, because a planner overriding the workstation the scheduler picked is
 * a schedule decision someone should be able to trace.
 */
class ScheduledOperation extends Model
{
    use Auditable, HasTenant;

    const SOURCE_FIXED = 'fixed';

    const SOURCE_AUTO = 'auto';

    const SOURCE_MANUAL = 'manual';

    const SOURCE_LINE = 'line';

    protected $fillable = [
        'tenant_id',
        'schedule_simulation_id',
        'work_order_id',
        'batch_step_id',
        'step_number',
        'step_name',
        'sequence',
        'line_id',
        'workstation_id',
        'workstation_type_id',
        'resource_source',
        'planned_start_at',
        'planned_end_at',
        'duration_minutes',
        'setup_minutes',
        'required_operators',
        'status',
        'reasons',
    ];

    protected function casts(): array
    {
        return [
            'planned_start_at' => 'datetime',
            'planned_end_at' => 'datetime',
            'status' => ScheduledOperationStatus::class,
            'reasons' => 'array',
            'step_number' => 'integer',
            'sequence' => 'integer',
            'duration_minutes' => 'integer',
            'setup_minutes' => 'integer',
            'required_operators' => 'integer',
        ];
    }

    /**
     * Rows are generated in bulk for every simulation; logging each insert and
     * each discard would bury the entries that matter. Only edits — a planner
     * overriding a placement — reach the audit log.
     */
    protected function auditCreate(): void {}

    protected function auditDelete(): void {}

    /** The committed plan — operations that belong to no what-if draft. */
    public function scopeCommitted(Builder $query): Builder
    {
        return $query->whereNull('schedule_simulation_id');
    }

    public function simulation(): BelongsTo
    {
        return $this->belongsTo(ScheduleSimulation::class, 'schedule_simulation_id');
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function batchStep(): BelongsTo
    {
        return $this->belongsTo(BatchStep::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(Line::class);
    }

    public function workstation(): BelongsTo
    {
        return $this->belongsTo(Workstation::class);
    }

    public function workstationType(): BelongsTo
    {
        return $this->belongsTo(WorkstationType::class);
    }
}
