<?php
namespace App\Http\Controllers;
use App\Http\Requests\{CancelExpenseRequest,StoreExpenseRequest};
use App\Models\{AuditLog,Expense,ExpenseProof};
use App\Services\ExpenseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
class ExpenseController extends Controller {
 public function index(Request $r){$d=$r->validate(['search'=>'nullable|string|max:100','status'=>['nullable',Rule::in(['POSTED','CANCELLED'])],'category_id'=>'nullable|integer|exists:expense_categories,id','from'=>'nullable|date','to'=>'nullable|date|after_or_equal:from','sort'=>['nullable',Rule::in(['spent_at','amount','transaction_number','created_at'])],'direction'=>['nullable',Rule::in(['asc','desc'])],'per_page'=>'nullable|integer|min:1|max:100']);return Expense::with(['category','creator'])->when($d['search']??null,fn($q,$v)=>$q->where(fn($x)=>$x->where('title','like','%'.$v.'%')->orWhere('description','like','%'.$v.'%')->orWhere('transaction_number','like','%'.$v.'%')))->when($d['status']??null,fn($q,$v)=>$q->where('status',$v))->when($d['category_id']??null,fn($q,$v)=>$q->where('expense_category_id',$v))->when($d['from']??null,fn($q,$v)=>$q->whereDate('spent_at','>=',$v))->when($d['to']??null,fn($q,$v)=>$q->whereDate('spent_at','<=',$v))->orderBy($d['sort']??'spent_at',$d['direction']??'desc')->paginate($d['per_page']??15);}
 public function show(Expense $expense,ExpenseService $service){return $service->detail($expense);}
 public function store(StoreExpenseRequest $r,ExpenseService $service){return response()->json($service->create($r->validated(),$r->user()->id),201);}
 public function cancel(CancelExpenseRequest $r,Expense $expense,ExpenseService $service){return $service->cancel($expense,$r->validated('cancel_reason'),$r->user()->id);}
 public function replacementPrefill(Expense $expense,ExpenseService $service){return $service->prefill($expense);}
 public function replacement(StoreExpenseRequest $r,Expense $expense,ExpenseService $service){return response()->json($service->create($r->validated(),$r->user()->id,$expense),201);}
 public function download(Request $r,ExpenseProof $proof){AuditLog::create(['user_id'=>$r->user()->id,'action'=>'expense.proof.downloaded','auditable_type'=>ExpenseProof::class,'auditable_id'=>$proof->id,'new_values'=>['expense_id'=>$proof->expense_id],'ip'=>$r->ip()]);return Storage::disk('local')->download($proof->storage_path,$proof->original_name);}
}
