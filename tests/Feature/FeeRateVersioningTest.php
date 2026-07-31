<?php
namespace Tests\Feature;
use App\Models\{Bill,FeeRate,House,PrivateDocument,Resident};
use App\Services\{FeeRateService,HouseholdService,MonthlyBillService};
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
class FeeRateVersioningTest extends TestCase {
 use RefreshDatabase;
 private FeeRateService $rates;private MonthlyBillService $bills;
 protected function setUp():void{parent::setUp();$this->rates=app(FeeRateService::class);$this->bills=app(MonthlyBillService::class);}
 private function rate(string $code,string $from,string|null $until=null,int $amount=100000):FeeRate{return $this->rates->create(['fee_code'=>$code,'name'=>$code==='SECURITY'?'Satpam':'Kebersihan','amount'=>$amount,'effective_from'=>$from,'effective_until'=>$until,'active'=>true]);}
 private function expectValidation(string $text,callable $fn):void{try{$fn();$this->fail('ValidationException tidak dilempar.');}catch(ValidationException $e){$this->assertStringContainsString($text,collect($e->errors())->flatten()->implode(' '));}}
 private function occupiedHouse(string $number='1'):House{$house=House::create(['block_code'=>'A','house_number'=>$number]);$head=Resident::create(['full_name'=>'Head '.$number,'marital_status'=>'MENIKAH','active'=>true]);foreach(['KTP','KK'] as $type)PrivateDocument::create(['resident_id'=>$head->id,'document_type'=>$type,'storage_path'=>$type.'/'.$head->id,'original_name'=>$type.'.pdf','mime_type'=>'application/pdf','size_bytes'=>100]);app(HouseholdService::class)->create(['house_id'=>$house->id,'head_resident_id'=>$head->id,'occupancy_type'=>'PERMANENT','started_at'=>'2026-01-01']);return $house;}
 private function defaults():void{$this->rate('SECURITY','2026-01-01',null,100000);$this->rate('CLEANING','2026-01-01',null,15000);}
 public function test_different_codes_may_overlap():void{$this->rate('SECURITY','2026-01-01');$this->rate('CLEANING','2026-01-01');$this->assertSame(2,FeeRate::count());}
 public function test_same_code_overlap_is_rejected():void{$this->rate('SECURITY','2026-01-01','2026-08-31');$this->expectValidation('bertumpang tindih',fn()=>$this->rate('SECURITY','2026-08-01'));}
 public function test_open_ended_rate_blocks_later_rate():void{$this->rate('SECURITY','2026-01-01');$this->expectValidation('bertumpang tindih',fn()=>$this->rate('SECURITY','2026-09-01'));}
 public function test_adjacent_periods_are_accepted():void{$this->rate('SECURITY','2026-01-01','2026-08-31');$this->rate('SECURITY','2026-09-01');$this->assertSame(2,FeeRate::where('fee_code','SECURITY')->count());}
 public function test_update_ignores_itself():void{$rate=$this->rate('SECURITY','2026-01-01','2026-08-31');$updated=$this->rates->update($rate,['amount'=>110000]);$this->assertSame(110000,$updated->amount);}
 public function test_update_into_other_period_is_rejected():void{$one=$this->rate('SECURITY','2026-01-01','2026-08-31');$two=$this->rate('SECURITY','2026-09-01');$this->expectValidation('bertumpang tindih',fn()=>$this->rates->update($two,['effective_from'=>'2026-08-01']));}
 public function test_april_resolves_one_rate_per_required_code():void{$this->defaults();$resolved=$this->bills->resolveRates('2026-04-01');$this->assertSame(['SECURITY','CLEANING'],array_keys($resolved));$this->assertSame(2,count($resolved));}
 public function test_future_security_rate_does_not_change_old_snapshot():void{$this->rate('SECURITY','2026-01-01','2026-08-31',100000);$this->rate('CLEANING','2026-01-01',null,15000);$house=$this->occupiedHouse();$this->bills->generate('2026-04-01');$bill=Bill::where('house_id',$house->id)->where('fee_code','SECURITY')->firstOrFail();$this->rate('SECURITY','2026-09-01',null,120000);$this->assertSame(100000,$bill->fresh()->amount_snapshot);$this->assertSame(100000,$bill->fresh()->amount);}
 public function test_september_uses_new_security_rate():void{$this->rate('SECURITY','2026-01-01','2026-08-31',100000);$this->rate('SECURITY','2026-09-01',null,120000);$this->rate('CLEANING','2026-01-01',null,15000);$house=$this->occupiedHouse();$this->bills->generate('2026-09-01');$this->assertSame(120000,Bill::where('house_id',$house->id)->where('fee_code','SECURITY')->value('amount_snapshot'));}
 public function test_generation_is_idempotent():void{$this->defaults();$this->occupiedHouse();$this->assertSame(2,$this->bills->generate('2026-04-01')['created']);$this->assertSame(0,$this->bills->generate('2026-04-01')['created']);$this->assertSame(2,Bill::count());}
 public function test_house_gets_one_bill_per_code_per_month():void{$this->defaults();$house=$this->occupiedHouse();$this->bills->generate('2026-04-01');$this->assertSame(['CLEANING'=>1,'SECURITY'=>1],Bill::where('house_id',$house->id)->selectRaw('fee_code,count(*) total')->groupBy('fee_code')->pluck('total','fee_code')->map(fn($v)=>(int)$v)->all());}
 public function test_missing_required_code_aborts_generation():void{$this->rate('SECURITY','2026-01-01');$this->occupiedHouse();$this->expectValidation('CLEANING tidak ditemukan',fn()=>$this->bills->generate('2026-04-01'));$this->assertSame(0,Bill::count());}
 public function test_ambiguous_rates_abort_generation():void{FeeRate::create(['fee_code'=>'SECURITY','name'=>'Satpam','amount'=>100000,'effective_from'=>'2026-01-01','active'=>true]);FeeRate::create(['fee_code'=>'SECURITY','name'=>'Satpam Baru','amount'=>120000,'effective_from'=>'2026-03-01','active'=>true]);$this->rate('CLEANING','2026-01-01');$this->occupiedHouse();$this->expectValidation('SECURITY ambigu',fn()=>$this->bills->generate('2026-04-01'));$this->assertSame(0,Bill::count());}
 public function test_referenced_rate_cannot_be_hard_deleted():void{$this->defaults();$this->occupiedHouse();$this->bills->generate('2026-04-01');$rate=FeeRate::where('fee_code','SECURITY')->firstOrFail();$this->expectValidation('tidak dapat dihapus',fn()=>$this->rates->delete($rate));$this->assertDatabaseHas('fee_rates',['id'=>$rate->id]);}
}
