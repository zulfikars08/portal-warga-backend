<?php

namespace App\Http\Requests;

use App\Services\SettingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSpecialBillRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('bills.create_special') ?? false; }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'amount' => 'required|integer|min:1',
            'due_date' => 'required|date',
            'target_type' => ['required', Rule::in(['ALL_OCCUPIED', 'SELECTED_HOUSES'])],
            'house_ids' => ['exclude_unless:target_type,SELECTED_HOUSES', 'required_if:target_type,SELECTED_HOUSES', 'array', 'min:1'],
            'house_ids.*' => 'integer|distinct|exists:houses,id',
            'approval_document' => 'required|file|mimes:jpg,jpeg,png,pdf|max:'.app(SettingService::class)->integer('special_bill_document_max_kb'),
            'documents' => 'prohibited',
            'status' => 'prohibited',
            'special_bill_number' => 'prohibited',
            'approved_by' => 'prohibited',
            'cancelled_by' => 'prohibited',
        ];
    }
}
