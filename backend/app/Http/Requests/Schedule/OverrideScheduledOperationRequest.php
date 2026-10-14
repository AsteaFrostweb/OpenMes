<?php

namespace App\Http\Requests\Schedule;

use App\Models\ScheduledOperation;
use App\Models\Workstation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Move a scheduled operation to another workstation. The station must be
 * active and — when the step names an equipment class — of that class: a
 * planner may override the scheduler's choice, not the routing.
 */
class OverrideScheduledOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasAnyRole(['Admin', 'Supervisor']);
    }

    public function rules(): array
    {
        return [
            'workstation_id' => ['required', 'integer', Rule::exists('workstations', 'id')->where('is_active', true)],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }
                /** @var ScheduledOperation $operation */
                $operation = $this->route('operation');
                $station = Workstation::find($this->integer('workstation_id'));
                if ($operation->workstation_type_id !== null
                    && (int) $station?->workstation_type_id !== (int) $operation->workstation_type_id) {
                    $validator->errors()->add('workstation_id', __('The selected workstation is not of the required type for this step.'));
                }
            },
        ];
    }

    public function station(): Workstation
    {
        return Workstation::findOrFail($this->integer('workstation_id'));
    }
}
