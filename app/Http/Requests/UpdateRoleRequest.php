<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
class UpdateRoleRequest extends FormRequest {
 public function authorize():bool{return $this->user()?->can('roles.manage')??false;}
 public function rules():array{$role=$this->route('role');return ['name'=>['required','string','max:100',Rule::unique('roles','name')->ignore($role?->id)],'permissions'=>['sometimes','array'],'permissions.*'=>['string','distinct',Rule::exists('permissions','name')->where('guard_name','web')]];}
}