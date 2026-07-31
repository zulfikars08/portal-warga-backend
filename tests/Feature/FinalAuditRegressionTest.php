<?php

namespace Tests\Feature;

use App\Models\{AuditLog,PrivateDocument,Resident,User};
use Database\Seeders\InitialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Hash,RateLimiter,Storage};
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FinalAuditRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(InitialSeeder::class);
        return User::where('email','superadmin@portalwarga.test')->firstOrFail();
    }

    public function test_login_success_failure_inactive_throttle_and_logout_revokes_token(): void
    {
        RateLimiter::clear('');
        $user=User::factory()->create(['email'=>'auth@example.test','password'=>Hash::make('Password123!'),'active'=>true]);
        $this->postJson('/api/v1/login',['email'=>$user->email,'password'=>'wrong-pass'])->assertUnprocessable();
        $login=$this->postJson('/api/v1/login',['email'=>$user->email,'password'=>'Password123!'])->assertOk()->assertJsonStructure(['token','user'=>['id','email']]);
        $token=$login->json('token');
        $this->withToken($token)->postJson('/api/v1/logout')->assertNoContent();
        $this->assertNull(PersonalAccessToken::findToken($token));

        $user->update(['active'=>false]);
        $this->postJson('/api/v1/login',['email'=>$user->email,'password'=>'Password123!'])->assertForbidden();
        for($i=0;$i<5;$i++)$this->postJson('/api/v1/login',['email'=>'limited@example.test','password'=>'bad']);
        $this->postJson('/api/v1/login',['email'=>'limited@example.test','password'=>'bad'])->assertTooManyRequests();
    }

    public function test_user_update_accepts_unchanged_email_and_create_update_sync_valid_roles(): void
    {
        $admin=$this->admin();
        $staff=Role::create(['name'=>'staff','guard_name'=>'web']);
        $created=$this->actingAs($admin)->postJson('/api/v1/users',['name'=>'Staff','email'=>'staff@example.test','password'=>'Password123!','role_ids'=>[$staff->id]])->assertCreated();
        $user=User::findOrFail($created->json('id'));
        $this->assertTrue($user->hasRole('staff'));
        $this->actingAs($admin)->putJson("/api/v1/users/$user->id",['name'=>'Updated','email'=>$user->email,'roles'=>['staff']])->assertOk();
        $this->actingAs($admin)->putJson("/api/v1/users/$user->id",['roles'=>['missing-role']])->assertUnprocessable();
        $super=Role::where('name','superadmin')->firstOrFail();
        $this->actingAs($admin)->putJson("/api/v1/users/$admin->id",['roles'=>[$staff->name]])->assertUnprocessable();
        $this->assertTrue($admin->fresh()->hasRole($super));
    }

    public function test_dashboard_returns_canonical_stats_and_excludes_cancelled_receivables(): void
    {
        $admin=$this->admin();
        $resident=Resident::create(['full_name'=>'Head','marital_status'=>'MARRIED','active'=>true]);
        $house=\App\Models\House::firstOrFail();
        $household=\App\Models\Household::create(['house_id'=>$house->id,'head_resident_id'=>$resident->id,'occupancy_type'=>'PERMANENT','started_at'=>'2026-01-01','active'=>true]);
        $base=['house_id'=>$house->id,'household_id'=>$household->id,'responsible_head_resident_id'=>$resident->id,'house_code_snapshot'=>$house->house_code,'responsible_head_name_snapshot'=>'Head','fee_code'=>'SECURITY','fee_name_snapshot'=>'Security','amount_snapshot'=>1000,'type'=>'routine','title'=>'Bill','period'=>'2026-07-01','due_date'=>'2026-07-31','amount'=>1000,'paid_amount'=>0];
        \App\Models\Bill::create($base+['status'=>'UNPAID']);
        \App\Models\Bill::create(array_merge($base,['fee_code'=>'CLEANING','fee_name_snapshot'=>'Cleaning','status'=>'CANCELLED']));
        $this->actingAs($admin)->getJson('/api/v1/dashboard')->assertOk()->assertExactJson(['houses'=>20,'residents'=>1,'receivables'=>1000,'cash'=>5000000]);
    }

    public function test_private_document_download_is_audited_without_path_and_audit_payload_is_recursively_redacted(): void
    {
        Storage::fake('local');
        $admin=$this->admin();
        $resident=Resident::create(['full_name'=>'Resident','marital_status'=>'SINGLE','active'=>true]);
        Storage::put('private-documents/secret.pdf','pdf');
        $document=PrivateDocument::create(['resident_id'=>$resident->id,'document_type'=>'KTP','storage_path'=>'private-documents/secret.pdf','original_name'=>'../ktp.pdf','mime_type'=>'application/pdf','size_bytes'=>3,'uploaded_by'=>$admin->id]);
        $this->actingAs($admin)->withServerVariables(['REMOTE_ADDR'=>'203.0.113.7'])->get("/api/v1/documents/$document->id/download")->assertOk();
        $log=AuditLog::where('action','resident_document.downloaded')->firstOrFail();
        $this->assertSame($admin->id,$log->user_id);
        $this->assertSame($document->id,$log->auditable_id);
        $this->assertSame('ktp.pdf',$log->metadata['filename']);
        $this->assertStringNotContainsString('private-documents',json_encode($log->toArray()));

        $sensitive=AuditLog::create(['user_id'=>$admin->id,'action'=>'test','auditable_type'=>User::class,'auditable_id'=>$admin->id,'old_values'=>['nested'=>['password'=>'plain','safe'=>'keep']],'new_values'=>['access_token'=>'abc'],'metadata'=>['file'=>['storage_path'=>'private/x'],'authorization'=>'Bearer x','filename'=>'useful.pdf']]);
        $payload=$this->actingAs($admin)->getJson("/api/v1/audit-logs/$sensitive->id")->assertOk()->json();
        $this->assertSame('[REDACTED]',$payload['old_values']['nested']['password']);
        $this->assertSame('keep',$payload['old_values']['nested']['safe']);
        $this->assertSame('[REDACTED]',$payload['metadata']['file']['storage_path']);
        $this->assertSame('useful.pdf',$payload['metadata']['filename']);
    }
}
