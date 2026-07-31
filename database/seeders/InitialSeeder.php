<?php

namespace Database\Seeders;

use App\Models\{ExpenseCategory,FeeRate,House,OpeningBalance,Setting,SpecialBill,User};
use App\Services\{FeeRateService,SpecialBillService};
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\{Permission,Role};
use Spatie\Permission\PermissionRegistrar;

class InitialSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();
        $permissions = [
            'dashboard.view',
            'residents.view','residents.create','residents.update','residents.deactivate','residents.view_sensitive_documents',
            'houses.view','houses.create','houses.update','houses.manage_occupants',
            'bills.view','bills.generate','bills.create_special','bills.approve_special','bills.cancel',
            'payments.view','payments.create','payments.cancel',
            'expenses.view','expenses.create','expenses.cancel',
            'reports.view','reports.export',
            'users.view','users.manage','roles.view','roles.manage','settings.manage','audit_logs.view',
        ];
        foreach ($permissions as $name) Permission::firstOrCreate(['name'=>$name,'guard_name'=>'web']);
        $role = Role::firstOrCreate(['name'=>'superadmin','guard_name'=>'web']);
        $role->syncPermissions($permissions);
        $user = User::updateOrCreate(['email'=>'superadmin@portalwarga.test'],['name'=>'Super Admin','password'=>Hash::make('Password123!')]);
        $user->syncRoles([$role]);
        foreach (range(1,20) as $i) House::firstOrCreate(['block_code'=>$i<=10?'A':'B','house_number'=>sprintf('%02d',$i<=10?$i:$i-10)]);
        $rates=app(FeeRateService::class);
        if (!FeeRate::where('fee_code','SECURITY')->whereDate('effective_from','2026-01-01')->exists()) $rates->create(['fee_code'=>'SECURITY','name'=>'Satpam','amount'=>100000,'effective_from'=>'2026-01-01','active'=>true,'created_by'=>$user->id]);
        if (!FeeRate::where('fee_code','CLEANING')->whereDate('effective_from','2026-01-01')->exists()) $rates->create(['fee_code'=>'CLEANING','name'=>'Kebersihan','amount'=>15000,'effective_from'=>'2026-01-01','active'=>true,'created_by'=>$user->id]);
        foreach (['Keamanan','Kebersihan','Perawatan','Administrasi'] as $name) ExpenseCategory::firstOrCreate(['name'=>$name]);
        if (!OpeningBalance::whereDate('as_of','2026-01-01')->exists()) OpeningBalance::create(['as_of'=>'2026-01-01','amount'=>5000000,'note'=>'Saldo awal 2026']);
        foreach (\App\Http\Controllers\SettingController::ALLOWED as $key => $definition) Setting::firstOrCreate(['key'=>$key],['value'=>(string)config("portal.$key",$definition['default']),'type'=>$definition['type'],'group'=>$definition['group'],'updated_by'=>$user->id]);
        Permission::whereNotIn('name', $permissions)->where('guard_name', 'web')->delete();
        $registrar->forgetCachedPermissions();
        SpecialBill::where('status','PENDING_APPROVAL')->each(fn(SpecialBill $bill)=>app(SpecialBillService::class)->notifyApprovers($bill));
    }
}
