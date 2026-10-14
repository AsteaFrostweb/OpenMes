<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A what-if finite-capacity schedule: calculated, reviewable, and inert until a
 * Supervisor or Admin applies it. See ScheduleSimulationService.
 */
class ScheduleSimulation extends Model
{
    use HasTenant;

    const STATUS_DRAFT = 'draft';

    const STATUS_APPLIED = 'applied';

    const STATUS_DISCARDED = 'discarded';

    protected $fillable = [
        'tenant_id',
        'status',
        'horizon_start',
        'horizon_end',
        'parameters',
        'summary',
        'created_by_id',
        'applied_by_id',
        'applied_at',
        'discarded_at',
    ];

    protected function casts(): array
    {
        return [
            'horizon_start' => 'datetime',
            'horizon_end' => 'datetime',
            'parameters' => 'array',
            'summary' => 'array',
            'applied_at' => 'datetime',
            'discarded_at' => 'datetime',
        ];
    }

    public function operations(): HasMany
    {
        return $this->hasMany(ScheduledOperation::class)->orderBy('sequence');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }
}
