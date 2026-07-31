<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Services\SettingService;
class StorePaymentRequest extends FormRequest {
 public function authorize():bool{return $this->user()?->can('payments.create')??false;}
 public function rules():array{return ['bill_ids'=>['required','array','min:1'],'bill_ids.*'=>['required','integer','distinct','exists:bills,id'],'future_cleaning_periods'=>['sometimes','array','max:12'],'future_cleaning_periods.*'=>['required','date'],'payment_method'=>['required',Rule::in(['CASH','TRANSFER'])],'paid_at'=>['required','date'],'note'=>['nullable','string','max:1000'],'proofs'=>['sometimes','array'],'proofs.*.file'=>['required','file','mimes:jpg,jpeg,png,pdf','max:'.app(SettingService::class)->integer('payment_proof_max_kb')],'proofs.*.transfer_amount'=>['nullable','integer','min:1'],'proofs.*.metadata'=>['nullable','array'],'house_id'=>['prohibited'],'household_id'=>['prohibited'],'payer_resident_id'=>['prohibited'],'amount'=>['prohibited'],'allocations'=>['prohibited'],'paid_amount'=>['prohibited'],'advance_amount'=>['prohibited']];}
}
