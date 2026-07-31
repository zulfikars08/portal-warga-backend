<?php
namespace Tests\Feature;
use App\Models\{Bill,House,Household,PrivateDocument,Resident};
use App\Services\HouseholdService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
class HouseholdArchitectureTest extends TestCase {
 use RefreshDatabase;
 private HouseholdService $service;
 protected function setUp():void{parent::setUp();$this->service=app(HouseholdService::class);}
 private function house(string $block='A',string $number='1'):House{return House::create(['block_code'=>$block,'house_number'=>$number]);}
 private function resident(string $name):Resident{$resident=Resident::create(['full_name'=>$name,'marital_status'=>'MENIKAH','active'=>true]);$this->document($resident,'KTP');$this->document($resident,'KK');return $resident;}
 private function document(Resident $resident,string $type):PrivateDocument{return PrivateDocument::create(['resident_id'=>$resident->id,'document_type'=>$type,'storage_path'=>strtolower($type).'/'.$resident->id,'original_name'=>strtolower($type).'.pdf','mime_type'=>'application/pdf','size_bytes'=>100]);}
 private function data(House $house,Resident $head,array $extra=[]):array{return $extra+['house_id'=>$house->id,'head_resident_id'=>$head->id,'occupancy_type'=>'PERMANENT','started_at'=>'2026-01-01'];}
 public function test_house_code_generation_and_uniqueness():void{$house=$this->house('a','1');$this->assertSame('A-01',$house->house_code);$this->expectException(QueryException::class);$this->house('A','01');}
 public function test_empty_house_returns_not_occupied():void{$this->assertSame('TIDAK DIHUNI',$this->house()->occupancy_status);}
 public function test_creating_active_household_makes_house_occupied():void{$house=$this->house();$this->service->create($this->data($house,$this->resident('Head')));$this->assertSame('DIHUNI',$house->fresh()->occupancy_status);}
 public function test_closing_last_household_makes_house_not_occupied():void{$house=$this->house();$household=$this->service->create($this->data($house,$this->resident('Head')));$this->service->close($household,'2026-02-01');$this->assertSame('TIDAK DIHUNI',$house->fresh()->occupancy_status);}
 public function test_house_cannot_have_two_active_households():void{$house=$this->house();$this->service->create($this->data($house,$this->resident('One')));$this->expectException(ValidationException::class);$this->service->create($this->data($house,$this->resident('Two')));}
 public function test_one_head_can_lead_multiple_houses():void{$head=$this->resident('Shared Head');$this->service->create($this->data($this->house('A','1'),$head));$this->service->create($this->data($this->house('A','2'),$head));$this->assertSame(2,$head->headedHouseholds()->count());}
 public function test_different_houses_can_have_different_members_under_same_head():void{$head=$this->resident('Head');$one=$this->resident('Member One');$two=$this->resident('Member Two');$h1=$this->service->create($this->data($this->house('A','1'),$head,['member_ids'=>[$one->id]]));$h2=$this->service->create($this->data($this->house('A','2'),$head,['member_ids'=>[$two->id]]));$this->assertTrue($h1->members()->where('resident_id',$one->id)->exists());$this->assertFalse($h1->members()->where('resident_id',$two->id)->exists());$this->assertTrue($h2->members()->where('resident_id',$two->id)->exists());}
 public function test_contract_requires_start_and_end_dates():void{$this->expectException(ValidationException::class);$this->service->create($this->data($this->house(),$this->resident('Head'),['occupancy_type'=>'CONTRACT']));}
 public function test_exactly_one_head_is_enforced():void{$head=$this->resident('Head');$household=$this->service->create($this->data($this->house(),$head));$this->assertSame(1,$household->members()->where('member_role','HEAD')->where('active',true)->count());$this->assertSame($head->id,$household->members()->where('member_role','HEAD')->value('resident_id'));}
 public function test_replacement_closes_old_household_without_overwriting_history():void{$house=$this->house();$old=$this->service->create($this->data($house,$this->resident('Old Head')));$new=$this->service->replace($house->id,'2026-02-01',$this->data($house,$this->resident('New Head'),['started_at'=>'2026-02-01']));$this->assertFalse($old->fresh()->active);$this->assertSame('2026-02-01',$old->fresh()->ended_at->toDateString());$this->assertTrue($new->active);$this->assertSame(2,$house->households()->count());}
 public function test_issued_bill_remains_attached_to_old_household_and_head():void{$house=$this->house();$oldHead=$this->resident('Old Head');$old=$this->service->create($this->data($house,$oldHead));$bill=Bill::create(['house_id'=>$house->id,'household_id'=>$old->id,'fee_code'=>'SECURITY','responsible_head_resident_id'=>$oldHead->id,'house_code_snapshot'=>$house->house_code,'responsible_head_name_snapshot'=>$oldHead->full_name,'fee_name_snapshot'=>'Satpam','amount_snapshot'=>100000,'type'=>'routine','title'=>'Satpam','period'=>'2026-01-01','due_date'=>'2026-01-07','amount'=>100000]);$this->service->replace($house->id,'2026-02-01',$this->data($house,$this->resident('New Head'),['started_at'=>'2026-02-01']));$this->assertSame($old->id,$bill->fresh()->household_id);$this->assertSame($oldHead->id,$bill->fresh()->responsible_head_resident_id);$this->assertSame('Old Head',$bill->fresh()->responsible_head_name_snapshot);}
}
