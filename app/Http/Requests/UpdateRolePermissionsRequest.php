<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
class UpdateRolePermissionsRequest extends FormRequest {
 public function authorize():bool{return $this->user()?->can('roles.manage')??false;}
 public function rules():array{return ['permissions'=>['required','array'],'permissions.*'=>['string','distinct',Rule::exists('permissions','name')->where('guard_name','web')]];}
}