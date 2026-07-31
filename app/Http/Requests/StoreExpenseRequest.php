<?php
namespace App\Http\Requests;
use App\Services\SettingService;
use Illuminate\Foundation\Http\FormRequest;
class StoreExpenseRequest extends FormRequest {
 public function authorize():bool{return $this->user()?->can('expenses.create')??false;}
 public function rules():array{return ['expense_category_id'=>['required','integer','exists:expense_categories,id'],'title'=>['required','string','max:255'],'description'=>['nullable','string','max:2000'],'amount'=>['required','integer','min:1'],'spent_at'=>['required','date'],'proofs'=>['required','array','min:1'],'proofs.*.file'=>['required','file','mimes:jpg,jpeg,png,pdf','max:'.app(SettingService::class)->integer('expense_proof_max_kb')],'proofs.*.metadata'=>['nullable','array'],'status'=>['prohibited'],'transaction_number'=>['prohibited'],'created_by'=>['prohibited'],'cancelled_by'=>['prohibited'],'cancelled_at'=>['prohibited'],'cancel_reason'=>['prohibited'],'replaces_expense_id'=>['prohibited'],'proof_path'=>['prohibited']];}
 public function messages():array{return ['amount.min'=>'Nominal pengeluaran harus lebih besar dari nol.','proofs.required'=>'Bukti pengeluaran wajib diunggah.','proofs.min'=>'Bukti pengeluaran wajib diunggah.'];}
}