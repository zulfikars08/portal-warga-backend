<?php

namespace App\Services;

use App\Models\{AuditLog,Bill,FeeRate,Household,Payment,PaymentAllocation,PaymentProof};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{DB,Storage,Validator};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function create(array $data, ?int $userId, ?Payment $replaces = null): Payment
    {
        $this->rejectClientCalculatedFields($data);
        Validator::make($data,['proofs.*.file'=>['file','mimes:jpg,jpeg,png,pdf','max:'.app(SettingService::class)->integer('payment_proof_max_kb')]])->validate();
        $stored = [];
        try {
            return DB::transaction(function () use ($data,$userId,$replaces,&$stored) {
                if($replaces){$replaces=Payment::lockForUpdate()->findOrFail($replaces->id);if($replaces->status!=='CANCELLED')$this->fail('payment','Pembayaran asal harus sudah dibatalkan.');if($replaces->replacement()->exists())$this->fail('payment','Pembayaran ini sudah memiliki pembayaran pengganti.');}
                $billIds = array_values(array_unique($data['bill_ids'] ?? []));
                if (!$billIds) $this->fail('bill_ids','Minimal satu tagihan harus dipilih.');
                $bills = Bill::whereIn('id',$billIds)->lockForUpdate()->get();
                if ($bills->count() !== count($billIds)) $this->fail('bill_ids','Satu atau lebih tagihan tidak ditemukan.');
                $this->assertUnpaid($bills);
                $anchor = $bills->first();
                $future = collect($data['future_cleaning_periods'] ?? [])->map(fn($p)=>CarbonImmutable::parse($p)->startOfMonth())->unique(fn($p)=>$p->toDateString())->values();
                if ($future->count() !== count($data['future_cleaning_periods'] ?? [])) $this->fail('future_cleaning_periods','Periode pembayaran kebersihan tidak boleh duplikat.');
                $this->createFutureCleaningBills($future,$anchor,$data['paid_at'],$bills);
                $bills = Bill::whereIn('id',$bills->pluck('id'))->lockForUpdate()->get();
                $this->assertUnpaid($bills);
                $this->assertSameResponsibility($bills);
                if ($bills->where('fee_code','SECURITY')->count()>1) $this->fail('bill_ids','Iuran satpam hanya dapat dibayar untuk satu periode dalam satu transaksi.');
                if ($bills->where('fee_code','CLEANING')->count()>12) $this->fail('bill_ids','Iuran kebersihan hanya dapat dibayar maksimal untuk 12 periode.');
                $amount=(int)$bills->sum(fn($bill)=>(int)$bill->amount-(int)$bill->paid_amount);
                $proofs=$data['proofs']??[];
                $this->validateProofs($data['payment_method'],$proofs,$amount);
                $payment=Payment::create(['transaction_number'=>$this->number(),'house_id'=>$anchor->house_id,'household_id'=>$anchor->household_id,'payer_resident_id'=>$anchor->responsible_head_resident_id,'payment_method'=>$data['payment_method'],'amount'=>$amount,'paid_at'=>$data['paid_at'],'status'=>'POSTED','note'=>$data['note']??null,'created_by'=>$userId,'replaces_payment_id'=>$replaces?->id]);
                foreach($bills as $bill){$outstanding=(int)$bill->amount-(int)$bill->paid_amount;PaymentAllocation::create(['payment_id'=>$payment->id,'bill_id'=>$bill->id,'amount'=>$outstanding]);$bill->update(['paid_amount'=>$bill->amount,'status'=>'PAID']);}
                foreach($proofs as $row){$file=$row['file'];$path=$file->store('payment-proofs','local');$stored[]=$path;PaymentProof::create(['payment_id'=>$payment->id,'storage_path'=>$path,'original_name'=>$file->getClientOriginalName(),'mime_type'=>$file->getMimeType(),'size_bytes'=>$file->getSize(),'transfer_amount'=>$row['transfer_amount']??null,'uploaded_by'=>$userId,'metadata'=>$row['metadata']??null]);}
                $this->audit('payment.created',$payment,[],['bill_ids'=>$bills->pluck('id')->all(),'amount'=>$amount],$userId);
                return $this->detail($payment);
            });
        } catch(\Throwable $e){foreach($stored as $path)Storage::disk('local')->delete($path);throw $e;}
    }

    public function cancel(Payment $payment,string $reason,?int $userId):Payment
    {
        return DB::transaction(function()use($payment,$reason,$userId){$payment=Payment::with('allocations')->lockForUpdate()->findOrFail($payment->id);if($payment->status==='CANCELLED')$this->fail('payment','Pembayaran sudah dibatalkan sebelumnya.');$old=$payment->toArray();$bills=Bill::whereIn('id',$payment->allocations->pluck('bill_id'))->lockForUpdate()->get();foreach($bills as $bill)$bill->update(['status'=>'UNPAID','paid_amount'=>0]);$payment->update(['status'=>'CANCELLED','cancel_reason'=>$reason,'cancelled_by'=>$userId,'cancelled_at'=>now()]);$this->audit('payment.cancelled',$payment,$old,$payment->fresh()->toArray(),$userId);return $this->detail($payment->fresh());});
    }

    public function prefill(Payment $payment):array
    {
        $payment->loadMissing(['allocations.bill','replacement']);
        if($payment->status!=='CANCELLED')$this->fail('payment','Pembayaran asal harus sudah dibatalkan.');
        if($payment->replacement)$this->fail('payment','Pembayaran ini sudah memiliki pembayaran pengganti.');
        $bills=$payment->allocations->pluck('bill')->filter()->values();
        if($bills->count()!==$payment->allocations->count()||$bills->isEmpty())$this->fail('bill_ids','Tagihan pembayaran asal tidak tersedia lengkap.');
        $this->assertUnpaid($bills);
        return ['replaces_payment_id'=>$payment->id,'transaction_number'=>$payment->transaction_number,'bill_ids'=>$bills->pluck('id')->all(),'payment_method'=>$payment->payment_method,'paid_at'=>now()->toDateTimeString(),'note'=>$payment->note];
    }

    public function detail(Payment $payment):Payment{$payment->load(['house','household','payer','allocations.bill','proofs','creator','canceller','replacement','replacedPayment']);$payment->proofs->each->makeHidden('storage_path');return $payment;}

    private function createFutureCleaningBills($periods,Bill $anchor,string $paidAt,$bills):void
    {
        if($periods->isEmpty())return;
        $household=Household::with(['house','head'])->lockForUpdate()->find($anchor->household_id);
        if(!$household||!$household->active||$household->ended_at)$this->fail('future_cleaning_periods','Pembayaran di muka hanya dapat dilakukan untuk rumah yang sedang dihuni.');
        $rateDate=CarbonImmutable::parse($paidAt)->toDateString();
        $rates=FeeRate::where('fee_code','CLEANING')->where('active',true)->whereDate('effective_from','<=',$rateDate)->where(fn($q)=>$q->whereNull('effective_until')->orWhereDate('effective_until','>=',$rateDate))->lockForUpdate()->get();
        if($rates->count()!==1)$this->fail('future_cleaning_periods','Tarif kebersihan aktif tidak tersedia secara unik pada tanggal pembayaran.');$rate=$rates->first();
        foreach($periods as $period){if($household->occupancy_type==='CONTRACT'&&$household->contract_ended_at&&$period->gt($household->contract_ended_at->startOfMonth()))$this->fail('future_cleaning_periods','Periode pembayaran kebersihan melewati tanggal akhir kontrak penghuni.');$bill=Bill::firstOrCreate(['house_id'=>$anchor->house_id,'fee_code'=>'CLEANING','period'=>$period],['household_id'=>$household->id,'fee_rate_id'=>$rate->id,'responsible_head_resident_id'=>$household->head_resident_id,'house_code_snapshot'=>$household->house->house_code,'responsible_head_name_snapshot'=>$household->head->full_name,'fee_name_snapshot'=>$rate->name,'amount_snapshot'=>$rate->amount,'type'=>'routine','title'=>$rate->name,'due_date'=>$period->day(7),'amount'=>$rate->amount,'paid_amount'=>0,'status'=>'UNPAID','fee_snapshot'=>['id'=>$rate->id,'fee_code'=>'CLEANING','name'=>$rate->name,'amount'=>$rate->amount,'locked_at_payment'=>true]]);$bills->push($bill);}
    }

    private function assertUnpaid($bills):void{foreach($bills as $bill)if($bill->status!=='UNPAID'||(int)$bill->paid_amount!==0)$this->fail('bill_ids','Tagihan yang sudah lunas tidak dapat dibayar kembali.');}
    private function assertSameResponsibility($bills):void{foreach(['house_id'=>'Tagihan dari rumah berbeda tidak dapat digabungkan.','household_id'=>'Tagihan dari household berbeda tidak dapat digabungkan.','responsible_head_resident_id'=>'Tagihan dengan kepala keluarga berbeda tidak dapat digabungkan.'] as $field=>$message)if($bills->pluck($field)->unique()->count()!==1)$this->fail('bill_ids',$message);}
    private function validateProofs(string $method,array $proofs,int $amount):void{if($method==='TRANSFER'&&!$proofs)$this->fail('proofs','Pembayaran transfer wajib memiliki minimal satu bukti.');if($method==='TRANSFER'){foreach($proofs as $proof)if(!isset($proof['transfer_amount']))$this->fail('proofs','Setiap bukti transfer wajib memiliki nominal transfer.');if((int)collect($proofs)->sum('transfer_amount')!==$amount)$this->fail('proofs','Total nominal bukti transfer harus sama dengan total pembayaran.');}}
    private function rejectClientCalculatedFields(array $data):void{foreach(['house_id','household_id','payer_resident_id','amount','allocations','paid_amount','advance_amount'] as $field)if(array_key_exists($field,$data))$this->fail($field,"Field {$field} dihitung oleh backend dan tidak boleh dikirim.");}
    private function number():string{return 'PAY-'.now()->format('Ymd').'-'.strtoupper(substr((string)Str::ulid(),-8));}
    private function fail(string $field,string $message):never{throw ValidationException::withMessages([$field=>$message]);}
    private function audit(string $action,Payment $payment,array $old,array $new,?int $userId):void{AuditLog::create(['user_id'=>$userId,'action'=>$action,'auditable_type'=>Payment::class,'auditable_id'=>$payment->id,'old_values'=>$old,'new_values'=>$new,'ip'=>request()->ip()]);}
}
