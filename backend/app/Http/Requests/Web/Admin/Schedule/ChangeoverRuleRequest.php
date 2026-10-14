<?php

namespace App\Http\Requests\Web\Admin\Schedule;

use App\Models\ChangeoverRule;
use App\Models\Workstation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create / update a sequence-dependent changeover rule. One request for both:
 * the rule set is identical and nothing about a rule is fixed once created.
 */
class ChangeoverRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The /admin/schedule tab gate already decides who reaches this; the
        // rule set changes how every future schedule is calculated, so it is
        // further limited to the roles that may apply a schedule.
        return (bool) $this->user()?->hasAnyRole(['Admin', 'Supervisor']);
    }

    protected function prepareForValidation(): void
    {
        // Blank means "any value"; anything that is not a string is left for
        // the `string` rule to reject rather than cast here.
        $normalize = fn (mixed $value) => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value;
        $input = [
            'from_value' => $normalize($this->input('from_value')),
            'to_value' => $normalize($this->input('to_value')),
        ];

        // A new rule is active unless said otherwise; an update that leaves
        // the flag out keeps the rule's current state.
        if ($this->has('is_active')) {
            $input['is_active'] = $this->boolean('is_active');
        } elseif ($this->isMethod('post')) {
            $input['is_active'] = true;
        }

        $this->merge($input);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'attribute' => ['required', Rule::in(ChangeoverRule::ATTRIBUTES)],
            'from_value' => ['nullable', 'string', 'max:255'],
            'to_value' => ['nullable', 'string', 'max:255'],
            'workstation_type_id' => ['nullable', 'integer', Rule::exists('workstation_types', 'id')->whereNull('deleted_at')],
            'workstation_id' => ['nullable', 'integer', Rule::exists('workstations', 'id')->whereNull('deleted_at')],
            'setup_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'is_active' => ['boolean'],
        ];
    }

    /** A rule scoped to both a station and a class must not contradict itself. */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $stationId = $this->input('workstation_id');
                $typeId = $this->input('workstation_type_id');
                if (! $stationId || ! $typeId || $validator->errors()->isNotEmpty()) {
                    return;
                }
                $station = Workstation::find($stationId);
                if ($station && (int) $station->workstation_type_id !== (int) $typeId) {
                    $validator->errors()->add('workstation_id', __('The workstation is not of the selected equipment class.'));
                }
            },
        ];
    }
}
