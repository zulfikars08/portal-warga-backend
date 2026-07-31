<?php
namespace Tests\Feature;
use App\Models\{AuditLog,Setting,User};
use Database\Seeders\InitialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\{Permission,Role};
use Tests\TestCase;
class RolePermissionSettingsTest extends TestCase {
 use RefreshDatabase;
 private function admin():User{$this->seed(InitialSeeder::class);return User::where('email','superadmin@portalwarga.test')->firstOrFail();}
 public function test_unauthenticated_roles_is_401():void{$this->getJson('/api/v1/roles')->assertUnauthorized();}
 public function test_user_without_roles_manage_is_403():void{$this->seed(InitialSeeder::class);$this->actingAs(User::factory()->create())->getJson('/api/v1/roles')->assertForbidden();}
 public function test_superadmin_can_list_roles():void{$this->actingAs($this->admin())->getJson('/api/v1/roles')->assertOk()->assertJsonPath('0.name','superadmin')->assertJsonPath('0.users_count',1);}
 public function test_superadmin_can_create_role():void{$this->actingAs($this->admin())->postJson('/api/v1/roles',['name'=>'bendahara','permissions'=>['dashboard.view']])->assertCreated()->assertJsonPath('permissions.0','dashboard.view');}
 public function test_duplicate_role_is_rejected():void{$u=$this->admin();Role::create(['name'=>'bendahara','guard_name'=>'web']);$this->actingAs($u)->postJson('/api/v1/roles',['name'=>'bendahara'])->assertUnprocessable();}
 public function test_unregistered_permission_is_rejected():void{$this->actingAs($this->admin())->postJson('/api/v1/roles',['name'=>'x','permissions'=>['unknown.permission']])->assertUnprocessable();}
 public function test_role_permissions_can_be_updated():void{$u=$this->admin();$r=Role::create(['name'=>'bendahara','guard_name'=>'web']);$this->actingAs($u)->putJson("/api/v1/roles/$r->id/permissions",['permissions'=>['dashboard.view']])->assertOk()->assertJsonPath('permissions.0','dashboard.view');}
 public function test_superadmin_cannot_be_deleted():void{$u=$this->admin();$r=Role::where('name','superadmin')->firstOrFail();$this->actingAs($u)->deleteJson("/api/v1/roles/$r->id")->assertUnprocessable();}
 public function test_superadmin_cannot_lose_permissions():void{$u=$this->admin();$r=Role::where('name','superadmin')->firstOrFail();$this->actingAs($u)->putJson("/api/v1/roles/$r->id/permissions",['permissions'=>['dashboard.view']])->assertUnprocessable();}
 public function test_assigned_role_cannot_be_deleted():void{$u=$this->admin();$r=Role::create(['name'=>'used','guard_name'=>'web']);User::factory()->create()->assignRole($r);$this->actingAs($u)->deleteJson("/api/v1/roles/$r->id")->assertUnprocessable();}
 public function test_role_change_writes_audit_log():void{$u=$this->admin();$this->actingAs($u)->postJson('/api/v1/roles',['name'=>'audited'])->assertCreated();$this->assertDatabaseHas('audit_logs',['action'=>'role.created','user_id'=>$u->id]);}
 public function test_unauthenticated_settings_is_401():void{$this->getJson('/api/v1/settings')->assertUnauthorized();}
 public function test_user_without_settings_manage_is_403():void{$this->seed(InitialSeeder::class);$this->actingAs(User::factory()->create())->getJson('/api/v1/settings')->assertForbidden();}
 public function test_superadmin_can_read_settings():void{$this->actingAs($this->admin())->getJson('/api/v1/settings')->assertOk()->assertJsonCount(4)->assertJsonPath('0.group','uploads');}
 public function test_superadmin_can_update_allowed_setting():void{$this->actingAs($this->admin())->putJson('/api/v1/settings',['settings'=>['expense_proof_max_kb'=>4096]])->assertOk();$this->assertDatabaseHas('settings',['key'=>'expense_proof_max_kb','value'=>'4096']);}
 public function test_unknown_setting_key_is_rejected():void{$this->actingAs($this->admin())->putJson('/api/v1/settings',['settings'=>['database_password'=>123]])->assertUnprocessable();}
 public function test_secret_settings_are_not_exposed():void{$data=$this->actingAs($this->admin())->getJson('/api/v1/settings')->assertOk()->json();$this->assertStringNotContainsString('password',json_encode($data));$this->assertStringNotContainsString('token',json_encode($data));}
 public function test_setting_change_writes_audit_log():void{$u=$this->admin();$this->actingAs($u)->putJson('/api/v1/settings',['settings'=>['payment_proof_max_kb'=>3072]])->assertOk();$this->assertDatabaseHas('audit_logs',['action'=>'setting.updated','user_id'=>$u->id]);}
}
