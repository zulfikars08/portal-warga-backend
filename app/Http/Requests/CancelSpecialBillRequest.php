<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class CancelSpecialBillRequest extends FormRequest {public function authorize():bool{return $this->user()?->can('bills.cancel')??false;}public function rules():array{return ['cancel_reason'=>'required|string|max:1000'];}}
