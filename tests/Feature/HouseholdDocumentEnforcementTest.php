<?php
namespace Tests\Feature;
use App\Models\{House,PrivateDocument,Resident};
use App\Services\HouseholdService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
class HouseholdDocumentEnforcementTest extends TestCase {
 use RefreshDatabase;
 private HouseholdService $service;
 protected function setUp():void{parent::setUp();$this->service=app(HouseholdService::class);}
 private function house(string $number='1'):House{return House::create(['block_code'=>'A','house_number'=>$number]);}
 private function resident(string $name,bool $active=true):Resident{return Resident::create(['full_name'=>$name,'marital_status'=>'MENIKAH','active'=>$active]);}
 private function doc(Resident $resident,string $type):void{PrivateDocument::create(['resident_id'=>$resident->id,'document_type'=>$type,'storage_path'=>"{$type}/{$resident->id}",'original_name'=>"{$type}.pdf",'mime_type'=>'application/pdf','size_bytes'=>100]);}
 private function documentedHead(string $name='Head'):Resident{$resident=$this->resident($name);$this->doc($resident,'KTP');$this->doc($resident,'KK');return $resident;}
 private function data(House $house,Resident $head,array $members=[]):array{return ['house_id'=>$house->id,'head_resident_id'=>$head->id,'occupancy_type'=>'PERMANENT','started_at'=>'2026-01-01','member_ids'=>array_map(fn($m)=>$m->id,$members)];}
 private function assertValidation(string $message,callable $action):void{try{$action();$this->fail('ValidationException tidak dilempar.');}catch(ValidationException $e){$this->assertStringContainsString($message,collect($e->errors())->flatten()->implode(' '));}}
 public function test_head_without_ktp_is_rejected():void{$head=$this->resident('Tanpa KTP');$this->doc($head,'KK');$this->assertValidation('Kepala keluarga wajib memiliki dokumen KTP.',fn()=>$this->service->create($this->data($this->house(),$head)));}
 public function test_head_without_kk_is_rejected():void{$head=$this->resident('Tanpa KK');$this->doc($head,'KTP');$this->assertValidation('Kepala keluarga wajib memiliki dokumen KK.',fn()=>$this->service->create($this->data($this->house(),$head)));}
 public function test_inactive_head_is_rejected():void{$head=$this->resident('Head Nonaktif',false);$this->doc($head,'KTP');$this->doc($head,'KK');$this->assertValidation('Penghuni yang tidak aktif tidak dapat dimasukkan ke dalam rumah.',fn()=>$this->service->create($this->data($this->house(),$head)));}
 public function test_member_without_ktp_is_rejected():void{$member=$this->resident('Anggota Tanpa KTP');$this->assertValidation('Anggota keluarga wajib memiliki dokumen KTP.',fn()=>$this->service->create($this->data($this->house(),$this->documentedHead(),[$member])));}
 public function test_inactive_member_is_rejected():void{$member=$this->resident('Anggota Nonaktif',false);$this->doc($member,'KTP');$this->assertValidation('Penghuni yang tidak aktif tidak dapat dimasukkan ke dalam rumah.',fn()=>$this->service->create($this->data($this->house(),$this->documentedHead(),[$member])));}
 public function test_documented_head_and_members_succeed():void{$member=$this->resident('Anggota');$this->doc($member,'KTP');$household=$this->service->create($this->data($this->house(),$this->documentedHead(),[$member]));$this->assertTrue($household->active);$this->assertCount(2,$household->members);}
 public function test_failed_replacement_leaves_existing_household_unchanged():void{$house=$this->house();$old=$this->service->create($this->data($house,$this->documentedHead('Head Lama')));$invalid=$this->resident('Head Baru Tanpa Dokumen');$this->assertValidation('Kepala keluarga wajib memiliki dokumen KTP.',fn()=>$this->service->replace($house->id,'2026-02-01',$this->data($house,$invalid)));$old->refresh();$this->assertTrue($old->active);$this->assertNull($old->ended_at);$this->assertSame(1,$house->households()->count());$this->assertSame('DIHUNI',$house->fresh()->occupancy_status);}
 public function test_same_documented_head_can_lead_multiple_households():void{$head=$this->documentedHead();$this->service->create($this->data($this->house('1'),$head));$this->service->create($this->data($this->house('2'),$head));$this->assertSame(2,$head->headedHouseholds()->count());$this->assertSame(2,$head->documents()->count());}
}
