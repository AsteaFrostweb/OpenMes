<?php

namespace App\Http\Requests\Schedule;

use App\Enums\ScheduledOperationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Filters for reading the committed operation plan over the API. */
class ListScheduledOperationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasAnyRole(['Admin', 'Supervisor']);
    }

    public function rules(): array
    {
        return [
            'work_order_id' => ['nullable', 'integer'],
            'workstation_id' => ['nullable', 'integer'],
            'line_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::enum(ScheduledOperationStatus::class)],
        ];
    }
}
