<?php
namespace App\Http\Controllers;
use App\Http\Requests\{CancelPaymentRequest,StorePaymentRequest};
use App\Models\{AuditLog,Payment,PaymentProof};
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
class PaymentController extends Controller {
 public function index(Request $r){$page=Payment::with(['house:id,house_code','payer:id,full_name'])->latest('paid_at')->paginate(min($r->integer('per_page',15),100));$page->getCollection()->each(function($payment){$payment->setAttribute('house_code',$payment->house?->house_code);$payment->setAttribute('payer_name',$payment->payer?->full_name);$payment->unsetRelations();});return $page;}
 public function show(Payment $payment){
  $payment->load(['house','household','payer','creator','canceller','replacement','replacedPayment','allocations.bill.house','allocations.bill.specialBill','proofs']);
  return response()->json(['data'=>$this->detail($payment)]);
 }
 public function store(StorePaymentRequest $r,PaymentService $service){return response()->json($service->create($r->validated(),$r->user()->id),201);}
 public function cancel(CancelPaymentRequest $r,Payment $payment,PaymentService $service){return $service->cancel($payment,$r->validated('cancel_reason'),$r->user()->id);}
 public function replacementPrefill(Payment $payment,PaymentService $service){return $service->prefill($payment);}
 public function replacement(StorePaymentRequest $r,Payment $payment,PaymentService $service){return response()->json($service->create($r->validated(),$r->user()->id,$payment),201);}
 public function download(Request $r,PaymentProof $proof){AuditLog::create(['user_id'=>$r->user()->id,'action'=>'payment.proof.downloaded','auditable_type'=>PaymentProof::class,'auditable_id'=>$proof->id,'new_values'=>['payment_id'=>$proof->payment_id],'ip'=>$r->ip()]);return Storage::disk('local')->download($proof->storage_path,$proof->original_name);}

 private function detail(Payment $payment):array
 {
  $user=fn($user)=>$user?['id'=>$user->id,'name'=>$user->name]:null;
  $related=fn($related)=>$related?['id'=>$related->id,'transaction_number'=>$related->transaction_number,'status'=>$related->status]:null;
  return [
   'id'=>$payment->id,'transaction_number'=>$payment->transaction_number,'payment_method'=>$payment->payment_method,
   'amount'=>$payment->amount,'paid_at'=>$payment->paid_at,'status'=>$payment->status,'note'=>$payment->note,
   'cancelled_at'=>$payment->cancelled_at,'cancel_reason'=>$payment->cancel_reason,
   'house'=>$payment->house?['id'=>$payment->house->id,'house_code'=>$payment->house->house_code,'block_code'=>$payment->house->block_code,'house_number'=>$payment->house->house_number]:null,
   'household'=>$payment->household?['id'=>$payment->household->id,'occupancy_type'=>$payment->household->occupancy_type,'active'=>$payment->household->active]:null,
   'payer'=>$payment->payer?['id'=>$payment->payer->id,'full_name'=>$payment->payer->full_name]:null,
   'creator'=>$user($payment->creator),'canceller'=>$user($payment->canceller),
   'replacement'=>$related($payment->replacement),'replaced_payment'=>$related($payment->replacedPayment),
   'allocations'=>$payment->allocations->map(fn($allocation)=>[
    'id'=>$allocation->id,'amount'=>$allocation->amount,
    'bill'=>['id'=>$allocation->bill->id,'type'=>$allocation->bill->type,'title'=>$allocation->bill->title,
     'fee_code'=>$allocation->bill->fee_code,'period'=>$allocation->bill->period,'due_date'=>$allocation->bill->due_date,
     'amount'=>$allocation->bill->amount,'paid_amount'=>$allocation->bill->paid_amount,'status'=>$allocation->bill->status,
     'house'=>$allocation->bill->house?['id'=>$allocation->bill->house->id,'house_code'=>$allocation->bill->house->house_code]:null,
     'special_bill'=>$allocation->bill->specialBill?['id'=>$allocation->bill->specialBill->id,'special_bill_number'=>$allocation->bill->specialBill->special_bill_number,'title'=>$allocation->bill->specialBill->title,'status'=>$allocation->bill->specialBill->status]:null,
    ],
   ])->all(),
   'proofs'=>$payment->proofs->map(fn($proof)=>[
    'id'=>$proof->id,'original_name'=>$proof->original_name,'mime_type'=>$proof->mime_type,'size_bytes'=>$proof->size_bytes,
    'transfer_amount'=>$proof->transfer_amount,'download_url'=>url("/api/v1/payment-proofs/{$proof->id}/download"),
   ])->all(),
   'created_at'=>$payment->created_at,'updated_at'=>$payment->updated_at,
  ];
 }
}
