<?php

namespace App\Http\Requests\Schedule;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Apply or discard a what-if schedule. No input — the request exists so the
 * decision of who may commit a generated plan lives in one place.
 */
class ScheduleSimulationActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasAnyRole(['Admin', 'Supervisor']);
    }

    public function rules(): array
    {
        return [];
    }
}
