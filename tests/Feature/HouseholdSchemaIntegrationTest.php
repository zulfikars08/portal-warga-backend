<?php

namespace Tests\Feature;

use App\Models\{House,HouseholdMember,PrivateDocument,Resident};
use App\Services\HouseholdService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HouseholdSchemaIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_household_service_persists_new_schema_and_members(): void
    {
        $house=House::create(['block_code'=>'a','house_number'=>'1']);
        $head=Resident::create(['full_name'=>'Head','marital_status'=>'MARRIED']);
        $member=Resident::create(['full_name'=>'Member','marital_status'=>'SINGLE']);
        foreach ([[$head,'KTP'],[$head,'KK'],[$member,'KTP']] as [$resident,$type]) PrivateDocument::create(['resident_id'=>$resident->id,'document_type'=>$type,'storage_path'=>strtolower($type).'/'.$resident->id,'original_name'=>strtolower($type).'.pdf','mime_type'=>'application/pdf','size_bytes'=>100]);

        $household=app(HouseholdService::class)->create(['house_id'=>$house->id,'head_resident_id'=>$head->id,'occupancy_type'=>'PERMANENT','started_at'=>'2026-01-01','member_ids'=>[$member->id]]);

        $this->assertSame('A-01',$house->fresh()->house_code);
        $this->assertCount(2,$household->members);
        $this->assertDatabaseHas('household_members',['resident_id'=>$head->id,'member_role'=>'HEAD']);
        $this->assertDatabaseHas('household_members',['resident_id'=>$member->id,'member_role'=>'MEMBER']);
    }

    public function test_house_cannot_have_two_active_households(): void
    {
        $house=House::create(['block_code'=>'A','house_number'=>'1']);
        $heads=collect(['One','Two'])->map(function($name){$resident=Resident::create(['full_name'=>$name,'marital_status'=>'MARRIED']);foreach(['KTP','KK'] as $type)PrivateDocument::create(['resident_id'=>$resident->id,'document_type'=>$type,'storage_path'=>strtolower($type).'/'.$resident->id,'original_name'=>strtolower($type).'.pdf','mime_type'=>'application/pdf','size_bytes'=>100]);return $resident;});
        $service=app(HouseholdService::class);
        $service->create(['house_id'=>$house->id,'head_resident_id'=>$heads[0]->id,'occupancy_type'=>'PERMANENT','started_at'=>'2026-01-01']);

        $this->expectException(ValidationException::class);
        $service->create(['house_id'=>$house->id,'head_resident_id'=>$heads[1]->id,'occupancy_type'=>'PERMANENT','started_at'=>'2026-02-01']);
    }
}