<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReportService
{
    public const TYPES = ['summary','income','expenses','receivables','payments','bills','houses','monthly'];
    private const PAGED = ['income','payments','expenses','receivables','bills'];

    public function generate(string $type, array $filters, bool $export = false): array
    {
        if (!in_array($type, self::TYPES, true)) throw ValidationException::withMessages(['type'=>'Jenis laporan tidak valid.']);
        if ($type === 'houses' && empty($filters['house_id'])) throw ValidationException::withMessages(['house_id'=>'Rumah wajib dipilih.']);
        $unfilteredHistory=in_array($type,self::PAGED,true)&&!$this->hasExplicitRange($filters);
        [$from,$to]=($type === 'houses' && !$this->hasExplicitRange($filters))||$unfilteredHistory ? [null,null] : $this->range($filters,$type === 'summary');
        $query=$this->rowQuery($type,$filters,$from,$to);
        $pagination=null;
        if ($query) {
            if (!$export && in_array($type,self::PAGED,true)) {
                $page=max(1,(int)($filters['page']??1)); $per=max(1,min(100,(int)($filters['per_page']??25)));
                $total=(clone $query)->count();
                $rows=$this->projectRows($type,$query->forPage($page,$per)->get());
                $pagination=['current_page'=>$page,'per_page'=>$per,'total'=>$total,'last_page'=>(int)ceil($total/$per)];
            } else $rows=$this->projectRows($type,$query->get());
        } elseif ($type==='monthly') $rows=$this->monthly($filters);
 elseif ($type==='summary') $rows=$this->monthlyRange($from,$to);
        elseif ($type==='houses') $rows=$this->houseRows($filters,$from,$to);
        else $rows=[];
        [$summaryFrom,$summaryTo]=$from ? [$from,$to] : ($unfilteredHistory ? [null,null] : $this->range($filters));
        $effectiveFilters=$type==='summary' ? [...$filters,'from'=>$summaryFrom->toDateString(),'to'=>$summaryTo->toDateString(),'year'=>$summaryFrom->year===$summaryTo->year?$summaryFrom->year:null] : $filters;
        $data=['type'=>$type,'filters'=>$effectiveFilters,'summary'=>$type==='summary'?$this->financialSummary($filters,$summaryFrom,$summaryTo):$this->summary($filters,$summaryFrom,$summaryTo,false,$type),'rows'=>$rows];
        if($pagination)$data['pagination']=$pagination;
        if($type==='summary')$data['charts']=$this->charts($filters,$from,$to,$rows);
        if($type==='houses'){$data['house']=$this->houseHeader((int)$filters['house_id']);$data['house_totals']=$this->houseTotals($filters,$from,$to);}
        return $data;
    }

    private function range(array $f,bool $activeYearDefault=false): array
    {
        $tz='Asia/Jakarta';
        if(!empty($f['month'])){$d=CarbonImmutable::createFromFormat('!Y-m',$f['month'],$tz);return[$d->startOfMonth(),$d->endOfMonth()];}
        if(!empty($f['year'])){$d=CarbonImmutable::create((int)$f['year'],1,1,0,0,0,$tz);return[$d,$d->endOfYear()];}
        if($activeYearDefault && empty($f['from']) && empty($f['to'])){$d=now($tz)->toImmutable();return[$d->startOfYear(),$d->endOfYear()];}
        return[CarbonImmutable::parse($f['from']??now($tz)->startOfMonth()->toDateString(),$tz)->startOfDay(),CarbonImmutable::parse($f['to']??now($tz)->toDateString(),$tz)->endOfDay()];
    }

    private function hasExplicitRange(array $f): bool
    {
        return !empty($f['from']) || !empty($f['to']) || !empty($f['month']) || !empty($f['year']);
    }

    private function rowQuery(string $type,array $f,$from,$to): ?Builder
    {
        return match($type){'income'=>$this->paymentQuery($f,$from,$to,true),'payments'=>$this->paymentQuery($f,$from,$to,false),'expenses'=>$this->expenseQuery($f,$from,$to),'receivables'=>!empty($f['as_of'])?$this->receivableSnapshotQuery($f,$f['as_of']):$this->billQuery($f,$from,$to,true),'bills'=>$this->billQuery($f,$from,$to,false),default=>null};
    }

    private function paymentBase(array $f,$from,$to): Builder
    {
        $q=DB::table('payments as p')->leftJoin('houses as h','h.id','=','p.house_id')->leftJoin('residents as r','r.id','=','p.payer_resident_id')->leftJoin('users as u','u.id','=','p.created_by');
        if($from)$q->whereBetween('p.paid_at',[$from->utc(),$to->utc()]);
        foreach(['payment_method'=>'p.payment_method','house_id'=>'p.house_id'] as $k=>$c)if(isset($f[$k]))$q->where($c,$f[$k]);
        if(in_array($f['status']??null,['POSTED','CANCELLED'],true))$q->where('p.status',$f['status']);
        if($s=$f['search']??null)$q->where(fn($x)=>$x->where('p.transaction_number','like',"%$s%")->orWhere('r.full_name','like',"%$s%")->orWhere('h.house_code','like',"%$s%"));
        return$q;
    }
    private function paymentQuery(array $f,$from,$to,bool $postedOnly): Builder
    {
        $q=$this->paymentBase($f,$from,$to);if($postedOnly)$q->where('p.status','POSTED');
        return$q->select('p.id','p.transaction_number as payment_number','p.house_id','p.paid_at','h.house_code','r.full_name as payer_name','p.payment_method as method','p.amount','p.status','u.name as created_by')
            ->selectSub(fn($x)=>$x->from('payment_allocations as pa')->whereColumn('pa.payment_id','p.id')->selectRaw('count(*)'),'bill_count')
            ->selectSub(fn($x)=>$x->from('payment_allocations as pa')->join('bills as ab','ab.id','=','pa.bill_id')->whereColumn('pa.payment_id','p.id')->orderBy('pa.id')->limit(1)->select('ab.title'),'bill_title')
            ->orderByDesc('p.paid_at')->orderByDesc('p.id');
    }
    private function expenseBase(array $f,$from,$to): Builder
    {
        $q=DB::table('expenses as e')->leftJoin('expense_categories as c','c.id','=','e.expense_category_id')->leftJoin('users as u','u.id','=','e.created_by');
        if($from)$q->whereBetween('e.spent_at',[$from->toDateString(),$to->toDateString()]);
        if(isset($f['status']))$q->where('e.status',$f['status']);if(isset($f['category_id']))$q->where('e.expense_category_id',$f['category_id']);
        if($s=$f['search']??null)$q->where(fn($x)=>$x->where('e.transaction_number','like',"%$s%")->orWhere('e.title','like',"%$s%")->orWhere('e.description','like',"%$s%"));return$q;
    }
    private function expenseQuery(array $f,$from,$to): Builder{return$this->expenseBase($f,$from,$to)->select('e.id','e.transaction_number','e.spent_at','c.name as category','e.title','e.description','e.amount','e.status','u.name as created_by')->orderByDesc('e.spent_at')->orderByDesc('e.id');}
    private function billBase(array $f,$from,$to,string $dateColumn='created_at'): Builder
    {
        $q=DB::table('bills as b')->leftJoin('houses as h','h.id','=','b.house_id');
        if($from){$bounds=$dateColumn==='created_at'?[$from->utc(),$to->utc()]:[$from->toDateString(),$to->toDateString()];$q->whereBetween("b.$dateColumn",$bounds);}
        foreach(['house_id'=>'b.house_id','bill_type'=>'b.type']as$k=>$c)if(isset($f[$k]))$q->where($c,$f[$k]);
        if(in_array($f['status']??null,['PAID','UNPAID','PARTIAL','CANCELLED'],true))$q->where('b.status',$f['status']);
        if($s=$f['search']??null)$q->where(fn($x)=>$x->where('b.title','like',"%$s%")->orWhere('b.house_code_snapshot','like',"%$s%")->orWhere('b.responsible_head_name_snapshot','like',"%$s%"));return$q;
    }
    private function receivable(Builder $q): Builder
    {
        $alloc=DB::table('payment_allocations as pa')->join('payments as ap','ap.id','=','pa.payment_id')->where('ap.status','POSTED')->selectRaw('pa.bill_id, SUM(pa.amount) allocated')->groupBy('pa.bill_id');
        return$q->leftJoinSub($alloc,'ra','ra.bill_id','=','b.id')->whereNotIn('b.status',['CANCELLED','CANCELED'])->whereRaw('b.amount>COALESCE(ra.allocated,0)')->where(fn($x)=>$x->whereNull('b.special_bill_id')->orWhereExists(fn($s)=>$s->selectRaw('1')->from('special_bills as sb')->whereColumn('sb.id','b.special_bill_id')->whereNotIn('sb.status',['CANCELLED','CANCELED'])));
    }
    private function receivableSnapshotQuery(array $f,string $asOf): Builder
    {
        $cutoff=CarbonImmutable::parse($asOf,'Asia/Jakarta')->endOfDay();
        $alloc=DB::table('payment_allocations as pa')->join('payments as ap','ap.id','=','pa.payment_id')->where('ap.paid_at','<=',$cutoff->utc())->where(fn($q)=>$q->whereNull('ap.cancelled_at')->orWhere('ap.cancelled_at','>',$cutoff->utc()))->selectRaw('pa.bill_id, SUM(pa.amount) allocated')->groupBy('pa.bill_id');
        $q=$this->billBase($f,null,null)->leftJoinSub($alloc,'ra','ra.bill_id','=','b.id')->where('b.created_at','<=',$cutoff->utc())->whereNotIn('b.status',['CANCELLED','CANCELED'])->where(fn($x)=>$x->whereNull('b.special_bill_id')->orWhereExists(fn($s)=>$s->selectRaw('1')->from('special_bills as sb')->whereColumn('sb.id','b.special_bill_id')->where('sb.approved_at','<=',$cutoff->utc())->where(fn($c)=>$c->whereNull('sb.cancelled_at')->orWhere('sb.cancelled_at','>',$cutoff->utc()))))->whereRaw('b.amount>COALESCE(ra.allocated,0)');
        $q->select('b.id','b.house_id','b.title','b.house_code_snapshot as house_code','b.responsible_head_name_snapshot as head_name','b.type as bill_type','b.period','b.due_date','b.amount','b.paid_amount',DB::raw('(b.amount-COALESCE(ra.allocated,0)) as outstanding_amount'),'b.status');
        $date=$cutoff->toDateString();$d30=$cutoff->subDays(30)->toDateString();$d60=$cutoff->subDays(60)->toDateString();$d90=$cutoff->subDays(90)->toDateString();
        return$q->selectRaw("CASE WHEN b.due_date >= ? THEN 'Belum jatuh tempo' WHEN b.due_date >= ? THEN '1–30' WHEN b.due_date >= ? THEN '31–60' WHEN b.due_date >= ? THEN '61–90' ELSE 'Lebih dari 90' END as age_bucket",[$date,$d30,$d60,$d90])->orderByDesc('b.period')->orderByDesc('b.id');
    }
    private function billQuery(array $f,$from,$to,bool $receivable): Builder
    {
        $q=$this->billBase($f,$from,$to,$receivable?'due_date':'created_at');if($receivable)$this->receivable($q);
        $q->select('b.id','b.house_id','b.title','b.house_code_snapshot as house_code','b.responsible_head_name_snapshot as head_name','b.type as bill_type','b.period','b.due_date','b.amount','b.paid_amount',DB::raw($receivable?'(b.amount-COALESCE(ra.allocated,0)) as outstanding_amount':'(b.amount-b.paid_amount) as outstanding_amount'),'b.status');
        if($receivable){$today=now('Asia/Jakarta')->toDateString();$d30=now('Asia/Jakarta')->subDays(30)->toDateString();$d60=now('Asia/Jakarta')->subDays(60)->toDateString();$d90=now('Asia/Jakarta')->subDays(90)->toDateString();$q->selectRaw("CASE WHEN b.due_date >= ? THEN 'Belum jatuh tempo' WHEN b.due_date >= ? THEN '1–30' WHEN b.due_date >= ? THEN '31–60' WHEN b.due_date >= ? THEN '61–90' ELSE 'Lebih dari 90' END as age_bucket",[$today,$d30,$d60,$d90]);}
        return$q->orderByDesc('b.period')->orderByDesc('b.id');
    }

    private function summary(array $f,$from,$to,bool $monthly=false,?string $reportType=null): array
    {
        $payments=$this->paymentBase($f,$from,$to);$pay=(clone$payments)->selectRaw("COUNT(*) transaction_count, COALESCE(SUM(CASE WHEN p.status='POSTED' THEN p.amount ELSE 0 END),0) total_income, COALESCE(SUM(CASE WHEN p.status='POSTED' AND p.payment_method='CASH' THEN p.amount ELSE 0 END),0) cash, COALESCE(SUM(CASE WHEN p.status='POSTED' AND p.payment_method='TRANSFER' THEN p.amount ELSE 0 END),0) transfer, SUM(CASE WHEN p.status='POSTED' THEN 1 ELSE 0 END) active_count, SUM(CASE WHEN p.status='CANCELLED' THEN 1 ELSE 0 END) cancelled_count")->first();
        $expenses=$this->expenseBase($f,$from,$to);$exp=(clone$expenses)->selectRaw("COUNT(*) transaction_count, COALESCE(SUM(CASE WHEN e.status='POSTED' THEN e.amount ELSE 0 END),0) total_expense, SUM(CASE WHEN e.status='POSTED' THEN 1 ELSE 0 END) active_count, SUM(CASE WHEN e.status='CANCELLED' THEN 1 ELSE 0 END) cancelled_count")->first();
        $bills=$this->billBase($f,$from,$to,$monthly?'period':'created_at');$snapshotDate=$f['as_of']??($monthly&&$to?$to->toDateString():null);$recv=$snapshotDate?$this->receivableSnapshotQuery($f,$snapshotDate):$this->receivable($this->billBase($f,$from,$to,$monthly?'period':'due_date'));$bill=(clone$bills)->selectRaw("COALESCE(SUM(b.amount),0) total_billed, COALESCE(SUM(b.paid_amount),0) total_paid_bills, COUNT(*) bill_count, SUM(CASE WHEN b.status='PAID' THEN 1 ELSE 0 END) paid_bill_count, SUM(CASE WHEN b.status='UNPAID' THEN 1 ELSE 0 END) unpaid_bill_count, SUM(CASE WHEN b.status IN ('CANCELLED','CANCELED') THEN 1 ELSE 0 END) cancelled_bill_count")->first();
        $cutoff=$to??now('Asia/Jakarta')->toImmutable();$opening=DB::table('opening_balances')->where('as_of','<=',$cutoff->toDateString())->sum('amount');$allIncome=DB::table('payments')->where('status','POSTED')->where('paid_at','<=',$cutoff->utc())->sum('amount');$allExpense=DB::table('expenses')->where('status','POSTED')->where('spent_at','<=',$cutoff->toDateString())->sum('amount');
        $needsReceivables=$monthly||$reportType==='receivables';
        $summary=['opening_balance'=>(int)$opening,'total_income'=>(int)$pay->total_income,'income_transaction_count'=>(int)$pay->transaction_count,'cash_income'=>(int)$pay->cash,'transfer_income'=>(int)$pay->transfer,'active_payment_count'=>(int)$pay->active_count,'cancelled_payment_count'=>(int)$pay->cancelled_count,'total_expense'=>(int)$exp->total_expense,'expense_transaction_count'=>(int)$exp->transaction_count,'active_expense_count'=>(int)$exp->active_count,'cancelled_expense_count'=>(int)$exp->cancelled_count,'closing_balance'=>(int)($opening+$allIncome-$allExpense),'total_billed'=>(int)$bill->total_billed,'total_paid_bills'=>(int)$bill->total_paid_bills,'bill_count'=>(int)$bill->bill_count,'total_receivables'=>$needsReceivables?(int)(clone$recv)->sum(DB::raw('b.amount-COALESCE(ra.allocated,0)')):0,'houses_in_arrears'=>$needsReceivables?(int)(clone$recv)->distinct()->count('b.house_id'):0,'receivable_count'=>$needsReceivables?(int)$recv->count():0];
        if($reportType==='bills')$summary += ['paid_bill_count'=>(int)$bill->paid_bill_count,'unpaid_bill_count'=>(int)$bill->unpaid_bill_count,'cancelled_bill_count'=>(int)$bill->cancelled_bill_count];
        return $summary;
    }
    private function financialSummary(array $f,CarbonImmutable $from,CarbonImmutable $to):array
    {
        $baseline=DB::table('opening_balances')->where('as_of','<=',$from->toDateString())->orderByDesc('as_of')->first();
        $baseDate=$baseline?CarbonImmutable::parse($baseline->as_of,'Asia/Jakarta')->startOfDay():null;
        $incomeBefore=DB::table('payments')->where('status','POSTED')->where('paid_at','<',$from->utc());
        $expenseBefore=DB::table('expenses')->where('status','POSTED')->where('spent_at','<',$from->toDateString());
        if($baseDate){$incomeBefore->where('paid_at','>=',$baseDate->utc());$expenseBefore->where('spent_at','>=',$baseDate->toDateString());}
        $opening=(int)($baseline->amount??0)+(int)$incomeBefore->sum('amount')-(int)$expenseBefore->sum('amount');
        $income=(int)DB::table('payments')->where('status','POSTED')->whereBetween('paid_at',[$from->utc(),$to->utc()])->sum('amount');
        $expense=(int)DB::table('expenses')->where('status','POSTED')->whereBetween('spent_at',[$from->toDateString(),$to->toDateString()])->sum('amount');
        $bills=$this->activeBills($from,$to,'period');
        $paid=(int)(clone$bills)->join('payment_allocations as pa','pa.bill_id','=','b.id')->join('payments as ap','ap.id','=','pa.payment_id')->where('ap.status','POSTED')->sum('pa.amount');
        $recv=$this->receivableSnapshotQuery($f,$to->toDateString());
        return['opening_balance'=>$opening,'total_income'=>$income,'total_expense'=>$expense,'closing_balance'=>$opening+$income-$expense,'total_billed'=>(int)(clone$bills)->sum('b.amount'),'total_paid_bills'=>$paid,'total_receivables'=>(int)(clone$recv)->sum(DB::raw('b.amount-COALESCE(ra.allocated,0)')),'houses_in_arrears'=>(int)(clone$recv)->distinct()->count('b.house_id')];
    }
    private function activeBills(CarbonImmutable $from,CarbonImmutable $to,string $column):Builder
    {
        return DB::table('bills as b')->whereBetween("b.$column",[$from->toDateString(),$to->toDateString()])->whereNotIn('b.status',['CANCELLED','CANCELED'])->where(fn($x)=>$x->whereNull('b.special_bill_id')->orWhereExists(fn($s)=>$s->selectRaw('1')->from('special_bills as sb')->whereColumn('sb.id','b.special_bill_id')->whereNotIn('sb.status',['CANCELLED','CANCELED'])));
    }
    private function charts(array $f,$from,$to,array $rows):array{return['monthly_cash_flow'=>$rows,'receivable_trend'=>[],'expenses_by_category'=>DB::table('expenses as e')->join('expense_categories as c','c.id','=','e.expense_category_id')->where('e.status','POSTED')->whereBetween('e.spent_at',[$from->toDateString(),$to->toDateString()])->groupBy('c.id','c.name')->select('c.name as category',DB::raw('sum(e.amount) as amount'))->get()->map(fn($x)=>(array)$x)->all()];}
    private function monthlyRange(CarbonImmutable $from,CarbonImmutable $to):array
    {
        $out=[];$month=$from->startOfMonth();
        while($month<=$to){$a=$month->greaterThan($from)?$month:$from;$end=$month->endOfMonth();$b=$end->lessThan($to)?$end:$to;$s=$this->financialSummary([],$a,$b);$out[]=['period'=>$month->format('Y-m'),'billed'=>$s['total_billed'],'income'=>$s['total_income'],'expense'=>$s['total_expense'],'receivables'=>$s['total_receivables'],'closing_balance'=>$s['closing_balance']];$month=$month->addMonth();}
        return$out;
    }
    private function monthly(array $f):array{$year=(int)($f['year']??now('Asia/Jakarta')->year);$out=[];for($m=1;$m<=12;$m++){[$a,$b]=$this->range(['month'=>sprintf('%04d-%02d',$year,$m)]);$s=$this->summary([],$a,$b,true);$out[]=['period'=>sprintf('%04d-%02d',$year,$m),'billed'=>$s['total_billed'],'income'=>$s['total_income'],'expense'=>$s['total_expense'],'receivables'=>$s['total_receivables'],'closing_balance'=>$s['closing_balance']];}return$out;}
    private function projectRows(string $type, $rows): array
    {
        return $rows->map(function($row) use ($type) {
            $row=(array)$row;
            if(in_array($type,['income','payments'],true)){
                $row['paid_at']=CarbonImmutable::parse($row['paid_at'],'UTC')->utc()->toIso8601String();
                $row['bill_count']=(int)$row['bill_count'];
                $row['description']=$row['bill_count']===1?$row['bill_title']:($row['bill_count']>1?$row['bill_count'].' tagihan':'Pembayaran rumah '.$row['house_code']);
                unset($row['bill_title']);
            }
            return$row;
        })->all();
    }
    private function houseRows(array $f,$from,$to):array{$b=collect($this->projectRows('bills',$this->billQuery($f,$from,$to,false)->get()))->map(fn($r)=>['row_type'=>'bill',...$r]);$p=collect($this->projectRows('payments',$this->paymentQuery($f,$from,$to,false)->get()))->map(fn($r)=>['row_type'=>'payment',...$r]);return$b->concat($p)->all();}
    private function houseTotals(array $f,$from,$to):array
    {
        $bills=$this->billBase($f,$from,$to)->whereNotIn('b.status',['CANCELLED','CANCELED']);
        // Allocation dates follow source payment paid_at; bill created_at only selects billed ledger/cohort.
        $allocationBills=$this->billBase($f,null,null)->whereNotIn('b.status',['CANCELLED','CANCELED']);
        $allocations=(clone$allocationBills)->join('payment_allocations as pa','pa.bill_id','=','b.id')->join('payments as ap','ap.id','=','pa.payment_id')->where('ap.status','POSTED');
        if($from)$allocations->whereBetween('ap.paid_at',[$from->utc(),$to->utc()]);
        if(isset($f['payment_method']))$allocations->where('ap.payment_method',$f['payment_method']);
        if(($f['status']??null)==='CANCELLED')$allocations->whereRaw('1=0');
        $paid=(int)(clone$allocations)->sum('pa.amount');
        $allocatedByBill=(clone$allocations)->selectRaw('b.id, SUM(pa.amount) allocated')->groupBy('b.id');
        $outstanding=(int)(clone$bills)->leftJoinSub($allocatedByBill,'aa','aa.id','=','b.id')->selectRaw('COALESCE(SUM(CASE WHEN b.amount > COALESCE(aa.allocated,0) THEN b.amount-COALESCE(aa.allocated,0) ELSE 0 END),0) total')->value('total');
        $payments=$this->paymentBase($f,$from,$to)->where('p.status','POSTED')->select('p.id','p.amount')->distinct();
        return['billed'=>(int)(clone$bills)->sum('b.amount'),'paid_on_bills'=>$paid,'payments'=>(int)DB::query()->fromSub($payments,'hp')->sum('amount'),'outstanding'=>$outstanding];
    }
    private function houseHeader(int $id):array{$h=DB::table('houses as h')->leftJoin('households as hh',fn($j)=>$j->on('hh.house_id','=','h.id')->where('hh.active',true))->leftJoin('residents as r','r.id','=','hh.head_resident_id')->where('h.id',$id)->first(['h.id','h.house_code','h.block_code','h.house_number','r.full_name as active_head_name',DB::raw("CASE WHEN hh.id IS NULL THEN 'VACANT' ELSE 'OCCUPIED' END as status")]);if(!$h)throw ValidationException::withMessages(['house_id'=>'Rumah tidak ditemukan.']);return(array)$h;}
}
