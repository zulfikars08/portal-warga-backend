<?php

namespace App\Http\Controllers;

use App\Models\{AuditLog,Bill,Expense,ExpenseCategory,FeeRate,House,Household,OpeningBalance,Payment,PrivateDocument,Resident};
use App\Models\User;
use App\Services\HouseholdService;
use App\Services\{FeeRateService,MonthlyBillService};
use App\Services\SettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Hash,Storage};
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class ApiController extends Controller
{
    private const MODELS=['houses'=>House::class,'households'=>Household::class,'residents'=>Resident::class,'fee-rates'=>FeeRate::class,'bills'=>Bill::class,'expense-categories'=>ExpenseCategory::class,'opening-balances'=>OpeningBalance::class,'audit-logs'=>AuditLog::class,'users'=>User::class];
    public function login(Request $r){$d=$r->validate(['email'=>'required|email','password'=>'required']);$u=User::where('email',$d['email'])->first();abort_unless($u&&Hash::check($d['password'],$u->password),422,'Kredensial salah.');abort_unless($u->active,403,'Akun tidak aktif.');return ['token'=>$u->createToken('portal')->plainTextToken,'user'=>$u];}
    public function logout(Request $r){$r->user()->currentAccessToken()?->delete();return response()->noContent();}
    public function index(Request $r,string $entity)
    {
        $this->authorizeEntity($r,$entity,'view');
        $m=$this->model($entity);
        $query=$m::latest();
        $relations=match($entity){
            'houses'=>['activeHousehold.head'],
            'bills'=>['house:id,house_code','responsibleHead:id,full_name'],
            'users'=>['roles:id,name'],
            'audit-logs'=>['actor:id,name'],
            default=>[],
        };
        $page=$query->with($relations)->paginate(min((int)$r->input('per_page',15),100));
        $page->setCollection($page->getCollection()->map(function($item)use($entity){
            if($entity==='bills'){
                $item->setAttribute('house_code',$item->house?->house_code ?? $item->house_code_snapshot);
                $item->setAttribute('responsible_head_name',$item->responsibleHead?->full_name ?? $item->responsible_head_name_snapshot);
            }elseif($entity==='users')$item->setAttribute('roles',$item->roles->pluck('name')->values()->all());
            elseif($entity==='audit-logs'){
                $item->setAttribute('actor_name',$item->actor?->name);
                $item->setAttribute('entity_name',$this->auditEntityName($item->auditable_type));
            }
            $item->unsetRelations();
            $data=$item->toArray();
            if($entity==='bills')$data['period']=$item->period?->toDateString();
            return $data;
        }));
        return $page;
    }
    public function show(Request $r,string $entity,int $id){$this->authorizeEntity($r,$entity,'view');$query=$this->model($entity)::query();if($entity==='houses')$query->with(['activeHousehold.head','households.head','households.members.resident','bills']);if($entity==='users')$query->with('roles:id,name');$model=$query->findOrFail($id);if($entity==='users')$model->setAttribute('roles',$model->roles->pluck('name')->values()->all())->unsetRelations();return $model;}
    public function store(Request $r,string $entity,HouseholdService $households,FeeRateService $rates){$this->authorizeEntity($r,$entity,'create');$data=$this->validated($r,$entity);if($entity==='households')return response()->json($households->create($data),201);if($entity==='fee-rates')return response()->json($rates->create($data+['created_by'=>$r->user()->id]),201);if($entity==='expenses')$data['created_by']=$r->user()->id;$roles=$data['roles']??null;unset($data['roles']);$m=$this->model($entity)::create($data);if($entity==='users'&&$roles!==null)$m->syncRoles($roles);return response()->json($m,201);}
    public function update(Request $r,string $entity,int $id,FeeRateService $rates){$this->authorizeEntity($r,$entity,'update');$m=$this->model($entity)::findOrFail($id);$data=$this->validated($r,$entity,true,$id);if($entity==='fee-rates')return $rates->update($m,$data);$roles=$data['roles']??null;unset($data['roles']);$m->update($data);if($entity==='users'&&$roles!==null){abort_if($m->hasRole('superadmin')&&!in_array('superadmin',$roles,true),422,'Super Admin tidak boleh kehilangan role superadmin.');$m->syncRoles($roles);}return $m->fresh();}
    public function monthlyTariffs(Request $r,MonthlyBillService $bills){$r->user()->can('bills.view')||abort(403);$d=$r->validate(['month'=>'required|date_format:Y-m']);return ['period'=>$d['month'].'-01','tariffs'=>$bills->resolveRates($d['month'].'-01')];}
    public function generateMonthlyBills(Request $r,MonthlyBillService $bills){$r->user()->can('bills.generate')||abort(403);$d=$r->validate(['month'=>'required|date_format:Y-m']);return response()->json($bills->generate($d['month'].'-01'),201);}
    public function destroy(Request $r,string $entity,int $id,FeeRateService $rates){$this->authorizeEntity($r,$entity,'delete');abort_if(in_array($entity,['expenses','bills','audit-logs']),405,'Gunakan pembatalan.');$model=$this->model($entity)::findOrFail($id);if($entity==='fee-rates'){$rates->delete($model);return response()->noContent();}$model->delete();return response()->noContent();}


    public function document(Request $r,Resident $resident,SettingService $settings){$d=$r->validate(['document_type'=>['required',Rule::in(['KTP','KK'])],'file'=>['required','file','mimes:jpg,jpeg,png,pdf','max:'.$settings->integer('resident_document_max_kb')]]);$file=$d['file'];$document=PrivateDocument::create(['resident_id'=>$resident->id,'document_type'=>$d['document_type'],'storage_path'=>$file->store('private-documents'),'original_name'=>$file->getClientOriginalName(),'mime_type'=>$file->getMimeType(),'size_bytes'=>$file->getSize(),'uploaded_by'=>$r->user()->id]);return response()->json(ResidentController::safeDocument($document),201);}
    public function download(Request $request,PrivateDocument $document){AuditLog::create(['user_id'=>$request->user()->id,'action'=>'resident_document.downloaded','auditable_type'=>PrivateDocument::class,'auditable_id'=>$document->id,'ip'=>$request->ip(),'metadata'=>['filename'=>basename($document->original_name),'document_type'=>$document->document_type,'mime_type'=>$document->mime_type]]);return Storage::download($document->storage_path,basename($document->original_name));}
    public function dashboard(){return ['houses'=>House::count(),'residents'=>Resident::where('active',1)->count(),'receivables'=>Bill::whereNotIn('status',['PAID','CANCELLED'])->sum(DB::raw('amount-paid_amount')),'cash'=>$this->balance()];}
    public function report(Request $r){$from = $r->filled('from') ? $r->date('from') : now()->startOfMonth();$to = $r->filled('to') ? $r->date('to') : now();return ['from'=>$from,'to'=>$to,'income'=>Payment::where('status','POSTED')->whereBetween('paid_at',[$from,$to])->sum('amount'),'expense'=>Expense::where('status','POSTED')->whereBetween('spent_at',[$from,$to])->sum('amount'),'balance'=>$this->balance()];}
    public function export(Request $r,string $format){$data=$this->report($r);abort_unless(in_array($format,['csv','pdf']),404);if($format==='pdf')return \Barryvdh\DomPDF\Facade\Pdf::loadHTML('<h1>Laporan Portal Warga</h1><pre>'.e(json_encode($data,JSON_PRETTY_PRINT)).'</pre>')->download('report.pdf');return response()->streamDownload(function()use($data){$f=fopen('php://output','w');fputcsv($f,array_keys($data));fputcsv($f,array_map(fn($v)=>(string)$v,array_values($data)));fclose($f);},'report.csv',['Content-Type'=>'text/csv']);}
    private function balance():int{return (int)OpeningBalance::sum('amount')+(int)Payment::where('status','POSTED')->sum('amount')-(int)Expense::where('status','POSTED')->sum('amount');}
    private function model(string $e):string{abort_unless(isset(self::MODELS[$e]),404);return self::MODELS[$e];}
    private function auditEntityName(?string $type):?string
    {
        if(!$type)return null;
        return [Bill::class=>'Tagihan',Payment::class=>'Pembayaran',House::class=>'Rumah',Resident::class=>'Warga',User::class=>'Pengguna'][$type] ?? class_basename($type);
    }
    private function authorizeEntity(Request $r,string $entity,string $action):void
    {
        $map = match($entity) {
            'residents','households'=>['view'=>'residents.view','create'=>'residents.create','update'=>'residents.update','delete'=>'residents.deactivate'],
            'houses'=>['view'=>'houses.view','create'=>'houses.create','update'=>'houses.update','delete'=>'houses.update'],
            'bills'=>['view'=>'bills.view','create'=>'bills.create_special','update'=>'bills.create_special','delete'=>'bills.cancel'],

            'users'=>['view'=>'users.view','create'=>'users.manage','update'=>'users.manage','delete'=>'users.manage'],
            'audit-logs'=>['view'=>'audit_logs.view'],
            'fee-rates','expense-categories','opening-balances'=>['view'=>'settings.manage','create'=>'settings.manage','update'=>'settings.manage','delete'=>'settings.manage'],
            default=>[],
        };
        abort_unless(isset($map[$action]) && $r->user()->can($map[$action]),403,'Anda tidak memiliki permission yang diperlukan.');
    }
    private function validated(Request $r,string $e,bool $partial=false,?int $id=null):array{$p=$partial?'sometimes':'required';$rules=match($e){'houses'=>['block_code'=>"$p|string|max:10",'house_number'=>"$p|string|max:20"],'households'=>['house_id'=>"$p|exists:houses,id",'head_resident_id'=>"$p|exists:residents,id",'occupancy_type'=>[$p,Rule::in(['PERMANENT','CONTRACT'])],'started_at'=>"$p|date",'contract_started_at'=>'nullable|date','contract_ended_at'=>'nullable|date|after_or_equal:contract_started_at','member_ids'=>'array','member_ids.*'=>'integer|distinct|exists:residents,id'],'residents'=>['full_name'=>"$p|string|max:255",'nik'=>'nullable|string|max:32','phone'=>'nullable|string|max:30','marital_status'=>"$p|string|max:30",'active'=>'boolean'],'fee-rates'=>['fee_code'=>[$p,Rule::in(['SECURITY','CLEANING'])],'name'=>"$p|string",'amount'=>"$p|integer|min:1",'effective_from'=>"$p|date",'effective_until'=>'nullable|date','active'=>'boolean'],'bills'=>['house_id'=>"$p|exists:houses,id",'fee_rate_id'=>'nullable|exists:fee_rates,id','type'=>[$p,Rule::in(['routine','special'])],'title'=>"$p|string",'period'=>"$p|date",'due_date'=>"$p|date",'amount'=>"$p|integer|min:1",'note'=>'nullable|string'],'expense-categories'=>['name'=>"$p|string|max:100",'active'=>'boolean'],'expenses'=>['expense_category_id'=>"$p|exists:expense_categories,id",'description'=>"$p|string",'amount'=>"$p|integer|min:1",'spent_at'=>"$p|date"],'opening-balances'=>['as_of'=>"$p|date|unique:opening_balances,as_of",'amount'=>"$p|integer",'note'=>'nullable|string'],'users'=>['name'=>"$p|string",'email'=>[$p,'email',Rule::unique('users','email')->ignore($id)],'password'=>"$p|string|min:8",'role_ids'=>'sometimes|array','role_ids.*'=>'integer|distinct|exists:roles,id','roles'=>'sometimes|array','roles.*'=>'string|distinct|exists:roles,name'],default=>[]};$data=$r->validate($rules);if($e==='users'){if(isset($data['password']))$data['password']=Hash::make($data['password']);$data['roles']=$data['roles']??(isset($data['role_ids'])?Role::whereIn('id',$data['role_ids'])->pluck('name')->all():null);unset($data['role_ids']);}return $data;}
}
