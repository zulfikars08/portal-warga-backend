<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
class StoreRoleRequest extends FormRequest {
 public function authorize():bool{return $this->user()?->can('roles.manage')??false;}
 public function rules():array{return ['name'=>['required','string','max:100',Rule::unique('roles','name')],'permissions'=>['sometimes','array'],'permissions.*'=>['string','distinct',Rule::exists('permissions','name')->where('guard_name','web')]];}
}