<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Http\Requests\{StoreRoleRequest,UpdateRolePermissionsRequest,UpdateRoleRequest};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\{Permission, Role};

class RolePermissionController extends Controller
{
    public function index() { return Role::with('permissions:id,name')->orderBy('name')->get()->map(fn (Role $role) => $this->resource($role)); }
    public function show(Role $role) { return $this->resource($role->load('permissions:id,name')); }
    public function permissions() { return Permission::orderBy('name')->get(['id','name']); }

    public function store(StoreRoleRequest $request)
    {
        $data = $request->validated();
        return DB::transaction(function () use ($data, $request) {
            $role = Role::create(['name'=>$data['name'],'guard_name'=>'web']);
            $role->syncPermissions($data['permissions'] ?? []);
            $this->audit('role.created',$role,[],['name'=>$role->name,'permissions'=>$role->permissions()->pluck('name')],$request);
            return response()->json($this->resource($role->load('permissions:id,name')),201);
        });
    }

    public function update(UpdateRoleRequest $request, Role $role)
    {
        $this->guardSuperadmin($role, 'Role SUPER_ADMIN tidak dapat diubah.');
        $data=$request->validated();
        return DB::transaction(function () use ($data,$request,$role) {$old=$this->resource($role->load('permissions'));$role->update(['name'=>$data['name']]);if(array_key_exists('permissions',$data))$role->syncPermissions($data['permissions']);$new=$this->resource($role->load('permissions'));$this->audit('role.updated',$role,$old,$new,$request);return $new;});
    }

    public function updatePermissions(UpdateRolePermissionsRequest $request, Role $role)
    {
        $data=$request->validated();
        if($this->isSuperAdmin($role)){$all=Permission::where('guard_name','web')->pluck('name')->all();if(array_diff($all,$data['permissions']))throw ValidationException::withMessages(['permissions'=>'Role SUPER_ADMIN tidak boleh kehilangan permission.']);}
        return DB::transaction(function () use($role,$data,$request){$old=$role->permissions()->pluck('name')->all();$role->syncPermissions($data['permissions']);$this->audit('role.permissions.updated',$role,['permissions'=>$old],['permissions'=>$data['permissions']],$request);return $this->resource($role->load('permissions'));});
    }

    public function destroy(Request $request, Role $role)
    {
        $this->guardSuperadmin($role,'Role SUPER_ADMIN tidak dapat dihapus.');
        if($this->usersCount($role)>0)throw ValidationException::withMessages(['role'=>'Role masih digunakan oleh pengguna dan tidak dapat dihapus.']);
        DB::transaction(function () use($role,$request){$old=$this->resource($role->load('permissions'));$this->audit('role.deleted',$role,$old,[],$request);$role->delete();});
        return response()->noContent();
    }


    private function guardSuperadmin(Role $role,string $message):void{if($this->isSuperAdmin($role))throw ValidationException::withMessages(['role'=>$message]);}
    private function isSuperAdmin(Role $role):bool{return strtolower(str_replace(['_',' '],'',$role->name))==='superadmin';}
    private function resource(Role $role):array{return ['id'=>$role->id,'name'=>$role->name,'permissions'=>$role->permissions->pluck('name')->values(),'users_count'=>$this->usersCount($role),'created_at'=>$role->created_at,'updated_at'=>$role->updated_at];}
    private function usersCount(Role $role):int{return DB::table('model_has_roles')->where('role_id',$role->id)->where('model_type',User::class)->count();}
    private function audit(string $action,Role $role,array $old,array $new,Request $request):void{AuditLog::create(['user_id'=>$request->user()->id,'action'=>$action,'auditable_type'=>Role::class,'auditable_id'=>$role->id,'old_values'=>$old,'new_values'=>$new,'ip'=>$request->ip()]);}
}
