<?php
namespace App\Services;
use App\Models\FeeRate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class FeeRateService {
 public function create(array $data):FeeRate{return DB::transaction(function()use($data){$this->validatePeriod($data);$this->ensureNoOverlap($data);return FeeRate::create($data);});}
 public function update(FeeRate $rate,array $data):FeeRate{return DB::transaction(function()use($rate,$data){$rate=FeeRate::lockForUpdate()->findOrFail($rate->id);$candidate=array_merge($rate->only(['fee_code','name','amount','effective_from','effective_until','active','created_by']),$data);$this->validatePeriod($candidate);$this->ensureNoOverlap($candidate,$rate->id);$rate->update($data);return $rate->fresh();});}
 public function delete(FeeRate $rate):void{DB::transaction(function()use($rate){$rate=FeeRate::lockForUpdate()->findOrFail($rate->id);if($rate->bills()->exists())throw ValidationException::withMessages(['fee_rate'=>'Tarif yang sudah digunakan oleh tagihan tidak dapat dihapus.']);$rate->delete();});}
 private function validatePeriod(array $data):void{if(!empty($data['effective_until'])&&$data['effective_until']<$data['effective_from'])throw ValidationException::withMessages(['effective_until'=>'Tanggal akhir berlaku harus sama dengan atau setelah tanggal mulai berlaku.']);}
 private function ensureNoOverlap(array $data,?int $except=null):void{if(!($data['active']??true))return;$query=FeeRate::where('fee_code',$data['fee_code'])->where('active',true)->when($except,fn($q)=>$q->whereKeyNot($except))->whereDate('effective_from','<=',$data['effective_until']??'9999-12-31')->where(fn($q)=>$q->whereNull('effective_until')->orWhereDate('effective_until','>=',$data['effective_from']))->lockForUpdate();if($query->exists()){ $label=$data['fee_code']==='SECURITY'?'Satpam':'Kebersihan';throw ValidationException::withMessages(['effective_from'=>"Tarif {$label} pada periode tersebut bertumpang tindih dengan tarif lain yang sudah tersedia."]);}}
}