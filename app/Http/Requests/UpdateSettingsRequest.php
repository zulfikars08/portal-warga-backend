<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class UpdateSettingsRequest extends FormRequest {
 public function authorize():bool{return $this->user()?->can('settings.manage')??false;}
 public function rules():array{return ['settings'=>['required','array','min:1'],'settings.*'=>['required','integer','min:1','max:10240']];}
}