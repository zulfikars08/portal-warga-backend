<?php
namespace App\Http\Controllers;
use App\Http\Requests\{CancelSpecialBillRequest,StoreSpecialBillRequest};
use App\Models\{AuditLog,SpecialBill,SpecialBillDocument};
use App\Services\SpecialBillService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
class SpecialBillController extends Controller {
 public function index(Request $r){$d=$r->validate(['search'=>'nullable|string|max:100','status'=>['nullable',Rule::in(['PENDING_APPROVAL','APPROVED','CANCELLED'])],'target_type'=>['nullable',Rule::in(['ALL_OCCUPIED','SELECTED_HOUSES'])],'house_id'=>'nullable|integer|exists:houses,id','from_due_date'=>'nullable|date','to_due_date'=>'nullable|date|after_or_equal:from_due_date','sort'=>['nullable',Rule::in(['created_at','due_date','amount','title','special_bill_number'])],'direction'=>['nullable',Rule::in(['asc','desc'])],'per_page'=>'nullable|integer|min:1|max:100']);return SpecialBill::withCount(['targets','bills'])->when($d['search']??null,fn($q,$v)=>$q->where(fn($x)=>$x->where('special_bill_number','like',"%$v%")->orWhere('title','like',"%$v%")->orWhere('description','like',"%$v%")))->when($d['status']??null,fn($q,$v)=>$q->where('status',$v))->when($d['target_type']??null,fn($q,$v)=>$q->where('target_type',$v))->when($d['house_id']??null,fn($q,$v)=>$q->whereHas('targets',fn($x)=>$x->where('house_id',$v)))->when($d['from_due_date']??null,fn($q,$v)=>$q->whereDate('due_date','>=',$v))->when($d['to_due_date']??null,fn($q,$v)=>$q->whereDate('due_date','<=',$v))->orderBy($d['sort']??'created_at',$d['direction']??'desc')->paginate($d['per_page']??15);}
 public function store(StoreSpecialBillRequest $r,SpecialBillService $s){return response()->json($s->create($r->validated(),$r->user()->id),201);}
 public function show(SpecialBill $specialBill,SpecialBillService $s){return $s->detail($specialBill);}
 public function approve(Request $r,SpecialBill $specialBill,SpecialBillService $s){$r->user()->can('bills.approve_special')||abort(403);return $s->approve($specialBill,$r->user()->id);}
 public function cancel(CancelSpecialBillRequest $r,SpecialBill $specialBill,SpecialBillService $s){return $s->cancel($specialBill,$r->validated('cancel_reason'),$r->user()->id);}
 public function download(Request $r,SpecialBillDocument $document){AuditLog::create(['user_id'=>$r->user()->id,'action'=>'special_bill.document.downloaded','auditable_type'=>SpecialBillDocument::class,'auditable_id'=>$document->id,'new_values'=>['special_bill_id'=>$document->special_bill_id],'ip'=>$r->ip()]);return Storage::disk('local')->download($document->getRawOriginal('storage_path'),$document->original_name);}
}
