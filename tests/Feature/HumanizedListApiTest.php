<?php

namespace Tests\Feature;

use App\Models\{AuditLog,Bill,House,Household,Payment,Resident,User};
use Database\Seeders\InitialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HumanizedListApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin():User{$this->seed(InitialSeeder::class);return User::where('email','superadmin@portalwarga.test')->firstOrFail();}

    public function test_bill_list_adds_flat_human_fields_without_relation_dump():void
    {
        $admin=$this->admin();$house=House::firstOrFail();$head=Resident::create(['full_name'=>'Kepala Aman','marital_status'=>'MENIKAH','active'=>true]);$household=Household::create(['house_id'=>$house->id,'head_resident_id'=>$head->id,'occupancy_type'=>'PERMANENT','started_at'=>'2026-01-01','active'=>true]);
        Bill::create(['house_id'=>$house->id,'household_id'=>$household->id,'responsible_head_resident_id'=>$head->id,'fee_code'=>'SECURITY','house_code_snapshot'=>$house->house_code,'responsible_head_name_snapshot'=>$head->full_name,'fee_name_snapshot'=>'Tes','amount_snapshot'=>1,'type'=>'special','title'=>'Tes','period'=>'2026-01-01','due_date'=>'2026-01-31','amount'=>1,'paid_amount'=>0,'status'=>'UNPAID']);
        $response=$this->actingAs($admin)->getJson('/api/v1/bills')->assertOk()->assertJsonPath('data.0.house_code',$house->house_code)->assertJsonPath('data.0.responsible_head_name','Kepala Aman')->assertJsonPath('data.0.period','2026-01-01')->assertJsonMissingPath('data.0.period_month')->assertJsonMissingPath('data.0.period_year')->assertJsonMissingPath('data.0.house')->assertJsonMissingPath('data.0.responsible_head');
        $this->assertStringNotContainsString('password',$response->getContent());
    }

    public function test_user_and_audit_lists_expose_safe_flat_names():void
    {
        $admin=$this->admin();
        $this->actingAs($admin)->getJson('/api/v1/users')->assertOk()->assertJsonPath('data.0.roles.0','superadmin')->assertJsonMissingPath('data.0.password');
        AuditLog::create(['user_id'=>$admin->id,'action'=>'bill.test','auditable_type'=>Bill::class,'auditable_id'=>99]);
        $this->actingAs($admin)->getJson('/api/v1/audit-logs')->assertOk()->assertJsonPath('data.0.actor_name',$admin->name)->assertJsonPath('data.0.entity_name','Tagihan')->assertJsonMissingPath('data.0.actor');
    }

    public function test_payment_list_preserves_pagination_and_adds_flat_names():void
    {
        $admin=$this->admin();$house=House::firstOrFail();$payer=Resident::create(['full_name'=>'Pembayar Aman','marital_status'=>'MENIKAH','active'=>true]);$household=Household::create(['house_id'=>$house->id,'head_resident_id'=>$payer->id,'occupancy_type'=>'PERMANENT','started_at'=>'2026-01-01','active'=>true]);
        Payment::create(['transaction_number'=>'PAY-LIST','house_id'=>$house->id,'household_id'=>$household->id,'payer_resident_id'=>$payer->id,'payment_method'=>'CASH','amount'=>1,'paid_at'=>'2026-01-01','status'=>'POSTED','created_by'=>$admin->id]);
        $this->actingAs($admin)->getJson('/api/v1/payments')->assertOk()->assertJsonPath('data.0.house_code',$house->house_code)->assertJsonPath('data.0.payer_name','Pembayar Aman')->assertJsonMissingPath('data.0.house')->assertJsonMissingPath('data.0.payer')->assertJsonStructure(['data','current_page','last_page','per_page','total']);
    }
}