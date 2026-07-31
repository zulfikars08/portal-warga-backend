<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'from' => ['nullable','date_format:Y-m-d'], 'to' => ['nullable','date_format:Y-m-d','after_or_equal:from'], 'as_of' => ['nullable','date_format:Y-m-d'],
            'month' => ['nullable','date_format:Y-m'], 'year' => ['nullable','integer','between:2000,2100'],
            'status' => ['nullable',Rule::in(['POSTED','CANCELLED','UNPAID','PARTIAL','PAID'])],
            'payment_method' => ['nullable',Rule::in(['CASH','TRANSFER'])], 'bill_type' => ['nullable','string','max:50'],
            'house_id' => ['nullable','integer','exists:houses,id'], 'category_id' => ['nullable','integer','exists:expense_categories,id'],
            'search' => ['nullable','string','max:100'], 'page' => ['nullable','integer','min:1'], 'per_page' => ['nullable','integer','between:1,100'],
        ];
    }

    public function messages(): array
    {
        return ['date_format'=>'Format :attribute tidak valid.','after_or_equal'=>'Tanggal akhir harus sama atau setelah tanggal awal.','exists'=>':attribute tidak ditemukan.','in'=>':attribute tidak valid.','integer'=>':attribute harus berupa angka.','between'=>':attribute harus antara :min dan :max.'];
    }
}