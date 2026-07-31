<?php

namespace Tests\Feature;

use App\Models\{AuditLog, House, Household, HouseholdMember, PrivateDocument, Resident, User};
use Database\Seeders\InitialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ResidentDetailTest extends TestCase
{
    use RefreshDatabase;

    private User $viewer;
    private Resident $resident;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(InitialSeeder::class);
        $this->viewer = User::factory()->create();
        $this->viewer->givePermissionTo(Permission::findByName('residents.view', 'web'));
        $this->resident = Resident::create(['full_name'=>'Budi Detail','nik'=>'3174012345678901','phone'=>'08123456789','marital_status'=>'MENIKAH','active'=>true]);
    }

    private function getDetail(?User $user = null)
    {
        return ($user ? $this->actingAs($user) : $this)->getJson("/api/v1/residents/{$this->resident->id}");
    }

    private function doc(string $type='KTP'): PrivateDocument
    {
        return PrivateDocument::create(['resident_id'=>$this->resident->id,'document_type'=>$type,'storage_path'=>"private-documents/secret-$type",'original_name'=>"$type.pdf",'mime_type'=>'application/pdf','size_bytes'=>123]);
    }

    private function household(string $role='HEAD', bool $active=true, ?string $ended=null): Household
    {
        $house=House::where('house_code','A-01')->first() ?? House::create(['block_code'=>'a','house_number'=>'1']);
        $head=$role==='HEAD' ? $this->resident : Resident::create(['full_name'=>'Kepala','marital_status'=>'MENIKAH','active'=>true]);
        $household=Household::create(['house_id'=>$house->id,'head_resident_id'=>$head->id,'occupancy_type'=>'PERMANENT','started_at'=>'2026-01-01','ended_at'=>$ended,'active'=>$active]);
        HouseholdMember::create(['household_id'=>$household->id,'resident_id'=>$this->resident->id,'member_role'=>$role,'joined_at'=>'2026-01-01','left_at'=>$ended,'active'=>$active]);
        if($role==='MEMBER') HouseholdMember::create(['household_id'=>$household->id,'resident_id'=>$head->id,'member_role'=>'HEAD','joined_at'=>'2026-01-01','active'=>$active]);
        return $household;
    }

    public function test_detail_requires_authentication(): void { $this->getDetail()->assertUnauthorized(); }
    public function test_detail_requires_residents_view_permission(): void { $this->getDetail(User::factory()->create())->assertForbidden(); }
    public function test_envelope_personal_fields_and_full_timestamps_match_frontend(): void
    {
        $r=$this->getDetail($this->viewer)->assertOk()->assertJsonPath('data.full_name','Budi Detail')->assertJsonPath('data.phone','08123456789');
        $this->assertNotNull($r->json('data.created_at')); $this->assertNotNull($r->json('data.updated_at')); $this->assertArrayNotHasKey('full_name',$r->json());
    }
    public function test_nik_is_masked_server_side_and_raw_nik_never_serialized_without_sensitive_permission(): void
    {
        $r=$this->getDetail($this->viewer)->assertJsonPath('data.nik_masked','3174********8901');
        $this->assertArrayNotHasKey('nik',$r->json('data')); $this->assertStringNotContainsString('3174012345678901',$r->getContent());
    }
    public function test_sensitive_permission_returns_full_nik_only(): void
    {
        $this->viewer->givePermissionTo('residents.view_sensitive_documents');
        $r=$this->getDetail($this->viewer)->assertJsonPath('data.nik','3174012345678901'); $this->assertArrayNotHasKey('nik_masked',$r->json('data'));
    }
    public function test_current_head_household_has_a_01_and_member_list(): void
    {
        $this->household(); $other=Resident::create(['full_name'=>'Anak','marital_status'=>'BELUM MENIKAH','active'=>true]);
        HouseholdMember::create(['household_id'=>Household::first()->id,'resident_id'=>$other->id,'member_role'=>'MEMBER','joined_at'=>'2026-01-02','active'=>true]);
        $this->getDetail($this->viewer)->assertJsonPath('data.current_household.house.house_code','A-01')->assertJsonPath('data.current_household.role','HEAD')->assertJsonPath('data.current_household.members.1.full_name','Anak');
    }
    public function test_member_current_household_links_head(): void
    {
        $this->household('MEMBER'); $this->getDetail($this->viewer)->assertJsonPath('data.current_household.role','MEMBER')->assertJsonPath('data.current_household.head.full_name','Kepala');
    }
    public function test_closed_household_remains_in_history_but_not_current(): void
    {
        $this->household('HEAD',false,'2026-02-01'); $this->getDetail($this->viewer)->assertJsonPath('data.current_household',null)->assertJsonPath('data.household_history.0.active',false)->assertJsonPath('data.household_history.0.ended_at','2026-02-01');
    }
    public function test_head_requires_ktp_and_kk_and_reports_missing_types(): void
    {
        $this->household(); $this->doc('KTP'); $this->getDetail($this->viewer)->assertJsonPath('data.documents.missing_required_document_types',['KK']);
    }
    public function test_member_requires_only_ktp(): void
    {
        $this->household('MEMBER'); $this->getDetail($this->viewer)->assertJsonPath('data.documents.missing_required_document_types',['KTP']);
    }
    public function test_document_metadata_needs_sensitive_permission_and_never_leaks_storage_path(): void
    {
        $this->doc(); $r=$this->getDetail($this->viewer)->assertJsonPath('data.documents.can_view',false)->assertJsonCount(0,'data.documents.items'); $this->assertStringNotContainsString('storage_path',$r->getContent());
        $this->viewer->givePermissionTo('residents.view_sensitive_documents'); $r=$this->getDetail($this->viewer)->assertJsonPath('data.documents.items.0.original_name','KTP.pdf'); $this->assertStringNotContainsString('storage_path',$r->getContent()); $this->assertStringNotContainsString('secret-KTP',$r->getContent());
    }
    public function test_download_requires_authentication_and_sensitive_permission(): void
    {
        $doc=$this->doc(); $this->getJson("/api/v1/documents/$doc->id/download")->assertUnauthorized(); $this->actingAs($this->viewer)->getJson("/api/v1/documents/$doc->id/download")->assertForbidden();
    }
    public function test_download_returns_file_and_writes_audit(): void
    {
        Storage::fake('local'); $doc=$this->doc(); Storage::disk('local')->put($doc->storage_path,'pdf'); $this->viewer->givePermissionTo('residents.view_sensitive_documents');
        $this->actingAs($this->viewer)->get("/api/v1/documents/$doc->id/download")->assertOk(); $this->assertDatabaseHas('audit_logs',['user_id'=>$this->viewer->id,'action'=>'resident_document.downloaded','auditable_id'=>$doc->id]);
    }
    public function test_upload_response_contains_safe_metadata_not_storage_path(): void
    {
        Storage::fake('local'); $this->viewer->givePermissionTo('residents.view_sensitive_documents'); $r=$this->actingAs($this->viewer)->post("/api/v1/residents/{$this->resident->id}/documents",['document_type'=>'KTP','file'=>UploadedFile::fake()->create('id.pdf',10,'application/pdf')],['Accept'=>'application/json'])->assertCreated(); $this->assertStringNotContainsString('storage_path',$r->getContent());
    }
    public function test_deactivation_is_blocked_with_active_membership_and_db_unchanged(): void
    {
        $this->household(); $this->viewer->givePermissionTo('residents.deactivate'); $this->actingAs($this->viewer)->postJson("/api/v1/residents/{$this->resident->id}/deactivate")->assertUnprocessable()->assertJsonPath('message','Warga dengan keanggotaan atau peran kepala household aktif tidak dapat dinonaktifkan. Tutup atau pindahkan household terlebih dahulu.'); $this->assertTrue($this->resident->fresh()->active);
    }
    public function test_deactivate_without_membership_then_reactivate_without_deleting(): void
    {
        $this->viewer->givePermissionTo('residents.deactivate'); $this->actingAs($this->viewer)->postJson("/api/v1/residents/{$this->resident->id}/deactivate")->assertOk()->assertJsonPath('active',false); $this->assertDatabaseHas('residents',['id'=>$this->resident->id,'active'=>false]); $this->actingAs($this->viewer)->postJson("/api/v1/residents/{$this->resident->id}/reactivate")->assertOk()->assertJsonPath('active',true);
    }
    public function test_generic_delete_cannot_hard_delete_resident(): void
    {
        $this->viewer->givePermissionTo('residents.deactivate'); $this->actingAs($this->viewer)->deleteJson("/api/v1/residents/{$this->resident->id}")->assertMethodNotAllowed(); $this->assertDatabaseHas('residents',['id'=>$this->resident->id]);
    }
    public function test_allowed_actions_follow_permissions_and_state(): void
    {
        $this->getDetail($this->viewer)->assertJsonPath('data.allowed_actions.edit',false)->assertJsonPath('data.allowed_actions.deactivate',false)->assertJsonPath('data.allowed_actions.upload_document',false);
        $this->viewer->givePermissionTo(['residents.update','residents.deactivate','residents.view_sensitive_documents']); $this->getDetail($this->viewer)->assertJsonPath('data.allowed_actions.edit',true)->assertJsonPath('data.allowed_actions.deactivate',true)->assertJsonPath('data.allowed_actions.upload_document',true);
    }
    public function test_detail_does_not_normalize_or_mutate_existing_marital_status(): void
    {
        $this->getDetail($this->viewer)->assertJsonPath('data.marital_status','MENIKAH'); $this->assertSame('MENIKAH',$this->resident->fresh()->marital_status);
    }
}
