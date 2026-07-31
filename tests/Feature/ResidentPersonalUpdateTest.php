<?php

namespace Tests\Feature;

use App\Models\{House, Household, HouseholdMember, Resident, User};
use Database\Seeders\InitialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResidentPersonalUpdateTest extends TestCase
{
    use RefreshDatabase;

    private User $editor;
    private Resident $resident;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(InitialSeeder::class);
        $this->editor = User::factory()->create();
        $this->editor->givePermissionTo('residents.update');
        $this->resident = Resident::create(['full_name'=>'Old Name','nik'=>'3174012345678901','phone'=>'0812','marital_status'=>'MENIKAH','active'=>true]);
    }

    private function url(): string { return "/api/v1/residents/{$this->resident->id}/personal"; }

    public function test_requires_authentication_and_update_permission(): void
    {
        $this->patchJson($this->url(), ['full_name'=>'New'])->assertUnauthorized();
        $this->actingAs(User::factory()->create())->patchJson($this->url(), ['full_name'=>'New'])->assertForbidden();
    }

    public function test_updates_only_personal_allowlist_trims_and_normalizes_empty_values(): void
    {
        $response = $this->actingAs($this->editor)->patchJson($this->url(), [
            'full_name'=>'  New Name  ', 'gender'=>' perempuan ', 'birth_place'=>'  Bandung ',
            'birth_date'=>'2000-02-03', 'marital_status'=>'BELUM MENIKAH', 'phone'=>'   ',
            'email'=>' new@example.test ', 'address'=>' Jalan Satu ', 'active'=>false,
            'household_id'=>999, 'storage_path'=>'secret', 'document_type'=>'KTP',
        ])->assertOk()->assertJsonPath('data.full_name','New Name')->assertJsonPath('data.gender','FEMALE')
            ->assertJsonPath('data.phone',null)->assertJsonPath('data.active',true);
        $this->assertArrayNotHasKey('storage_path', $response->json('data'));
        $this->assertArrayNotHasKey('nik', $response->json('data'));
        $this->assertDatabaseHas('residents', ['id'=>$this->resident->id,'full_name'=>'New Name','phone'=>null,'email'=>'new@example.test','active'=>true]);
    }

    public function test_nik_requires_sensitive_permission_and_response_never_leaks_it_without_permission(): void
    {
        $this->actingAs($this->editor)->patchJson($this->url(), ['full_name'=>'Old Name','nik'=>'9999'])->assertForbidden();
        $this->assertSame('3174012345678901', $this->resident->fresh()->nik);
        $this->editor->givePermissionTo('residents.view_sensitive_documents');
        $this->actingAs($this->editor)->patchJson($this->url(), ['full_name'=>'Old Name','nik'=>'9999'])->assertOk()->assertJsonPath('data.nik','9999');
    }

    public function test_invalid_input_returns_422_and_does_not_update(): void
    {
        $this->actingAs($this->editor)->patchJson($this->url(), ['full_name'=>' ','gender'=>'UNKNOWN','birth_date'=>'nope','email'=>'bad'])->assertUnprocessable()
            ->assertJsonValidationErrors(['full_name','gender','birth_date','email']);
        $this->assertSame('Old Name', $this->resident->fresh()->full_name);
    }

    public function test_household_and_active_cannot_be_mutated(): void
    {
        $house=House::where('house_code','A-01')->first();
        $household=Household::create(['house_id'=>$house->id,'head_resident_id'=>$this->resident->id,'occupancy_type'=>'PERMANENT','started_at'=>'2026-01-01','active'=>true]);
        $membership=HouseholdMember::create(['household_id'=>$household->id,'resident_id'=>$this->resident->id,'member_role'=>'HEAD','joined_at'=>'2026-01-01','active'=>true]);
        $this->actingAs($this->editor)->patchJson($this->url(), ['full_name'=>'New','active'=>false,'household_id'=>999,'member_role'=>'MEMBER'])->assertOk();
        $this->assertTrue($this->resident->fresh()->active);
        $this->assertSame($household->id, $membership->fresh()->household_id);
        $this->assertSame('HEAD', $membership->fresh()->member_role);
    }

    public function test_email_is_unique_but_ignores_current_resident(): void
    {
        $this->resident->update(['email'=>'same@example.test']);
        Resident::create(['full_name'=>'Other','email'=>'other@example.test','marital_status'=>'MENIKAH','active'=>true]);
        $this->actingAs($this->editor)->patchJson($this->url(), ['full_name'=>'Old Name','email'=>'same@example.test'])->assertOk();
        $this->actingAs($this->editor)->patchJson($this->url(), ['full_name'=>'Old Name','email'=>'other@example.test'])->assertUnprocessable()->assertJsonValidationErrors('email');
    }
}
