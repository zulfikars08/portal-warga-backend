<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class CancelExpenseRequest extends FormRequest {public function authorize():bool{return $this->user()?->can('expenses.cancel')??false;}public function rules():array{return ['cancel_reason'=>['required','string','max:1000']];}}