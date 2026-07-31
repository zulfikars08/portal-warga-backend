<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReplaceHouseOccupantsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('houses.manage_occupants') ?? false;
    }

    public function rules(): array
    {
        if (!$this->route('house')?->activeHousehold()->exists()) return [];
        return [
            'previous_ended_at' => ['required', 'date'],
            'head_resident_id' => ['required', 'integer', 'exists:residents,id'],
            'member_ids' => ['present', 'array'],
            'member_ids.*' => ['integer', 'distinct', 'exists:residents,id', 'different:head_resident_id'],
            'occupancy_type' => ['required', Rule::in(['PERMANENT', 'CONTRACT'])],
            'started_at' => ['required', 'date', 'after_or_equal:previous_ended_at'],
            'contract_started_at' => ['nullable', 'date', 'after_or_equal:started_at', Rule::requiredIf($this->input('occupancy_type') === 'CONTRACT'), 'prohibited_if:occupancy_type,PERMANENT'],
            'contract_ended_at' => ['nullable', 'date', Rule::requiredIf($this->input('occupancy_type') === 'CONTRACT'), 'after_or_equal:contract_started_at', 'prohibited_if:occupancy_type,PERMANENT'],
        ];
    }
}
