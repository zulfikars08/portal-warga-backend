<?php

namespace Tests\Feature;

use App\Exports\ReportExport;
use App\Models\User;
use App\Services\ReportService;
use App\Support\ReportPresentation as P;
use Carbon\CarbonImmutable;
use Database\Seeders\InitialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;
use ZipArchive;

class FinancialSummaryReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private int $house1;
    private int $house2;
    private int $category;
    private array $bill=[];
    private array $payment=[];

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-30 12:00:00','Asia/Jakarta'));
        $this->seed(InitialSeeder::class);
        $this->admin=User::where('email','superadmin@portalwarga.test')->firstOrFail();
        $this->fixtures();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_default_summary_uses_current_asia_jakarta_year_and_exposes_effective_filters(): void
    {
        $r=$this->summary();
        $this->assertSame(['from'=>'2026-01-01','to'=>'2026-12-31','year'=>2026],array_intersect_key($r['filters'],array_flip(['from','to','year'])));
    }

    public function test_default_summary_has_exactly_twelve_month_rows(): void
    {
        $this->assertCount(12,$this->summary()['rows']);
    }

    public function test_opening_balance_includes_baseline_dated_exactly_at_period_start(): void
    {
        $s=$this->summary()['summary'];
        $this->assertSame(5000,$s['opening_balance']);
        $this->assertSame($s['opening_balance']+$s['total_income']-$s['total_expense'],$s['closing_balance']);
    }

    public function test_only_posted_income_is_counted(): void
    {
        $this->assertSame(500,$this->summary()['summary']['total_income']);
    }

    public function test_cancelled_payment_is_excluded_from_income(): void
    {
        $this->assertNotSame(9500,$this->summary()['summary']['total_income']);
    }

    public function test_only_posted_expense_is_counted(): void
    {
        $this->assertSame(100,$this->summary()['summary']['total_expense']);
    }

    public function test_cancelled_expense_is_excluded(): void
    {
        $this->assertNotSame(8100,$this->summary()['summary']['total_expense']);
    }

    public function test_closing_balance_obeys_opening_plus_income_minus_expense(): void
    {
        $s=$this->summary()['summary'];
        $this->assertSame(5400,$s['closing_balance']);
        $this->assertSame($s['opening_balance']+$s['total_income']-$s['total_expense'],$s['closing_balance']);
    }

    public function test_active_routine_and_active_special_bills_are_included_by_period(): void
    {
        $this->assertSame(1600,$this->summary()['summary']['total_billed']);
    }

    public function test_only_cancelled_or_canceled_bills_and_special_bill_parents_are_excluded(): void
    {
        $this->assertSame(1600,$this->summary()['summary']['total_billed']);
        $this->assertNotSame(3100,$this->summary()['summary']['total_billed']);
    }

    public function test_total_paid_bills_uses_posted_allocations_and_excludes_cancelled_payment_allocation(): void
    {
        $this->assertSame(400,$this->summary()['summary']['total_paid_bills']);
    }

    public function test_receivables_match_canonical_as_of_report_at_period_end(): void
    {
        $summary=$this->summary(['from'=>'2026-01-01','to'=>'2026-12-31']);
        $receivables=$this->report('receivables',['as_of'=>'2026-12-31']);
        $this->assertSame(200,$summary['summary']['total_receivables']);
        $this->assertSame($receivables['summary']['total_receivables'],$summary['summary']['total_receivables']);
        $this->assertSame(array_sum(array_column($receivables['rows'],'outstanding_amount')),$summary['summary']['total_receivables']);
    }

    public function test_houses_in_arrears_is_distinct_house_count(): void
    {
        $this->assertSame(1,$this->summary()['summary']['houses_in_arrears']);
    }

    public function test_pagination_filters_do_not_change_financial_summary(): void
    {
        $plain=$this->summary(['year'=>2026])['summary'];
        $paged=$this->summary(['year'=>2026,'page'=>99,'per_page'=>1])['summary'];
        $this->assertSame($plain,$paged);
    }

    public function test_monthly_rows_and_cash_flow_chart_have_exact_parity(): void
    {
        $r=$this->summary();
        $this->assertSame($r['rows'],$r['charts']['monthly_cash_flow']);
        $this->assertSame(['period'=>'2026-01','billed'=>1000,'income'=>500,'expense'=>100,'receivables'=>200,'closing_balance'=>5400],$r['rows'][0]);
        $this->assertSame(['period'=>'2026-12','billed'=>0,'income'=>0,'expense'=>0,'receivables'=>200,'closing_balance'=>5400],$r['rows'][11]);
    }

    public function test_date_range_trims_months_and_uses_domain_business_dates(): void
    {
        $r=$this->summary(['from'=>'2026-01-15','to'=>'2026-02-10']);
        $this->assertSame(['2026-01','2026-02'],array_column($r['rows'],'period'));
        $this->assertSame(600,$r['summary']['total_billed']);
        $this->assertSame(500,$r['summary']['total_income']);
        $this->assertSame(100,$r['summary']['total_expense']);
        $this->assertSame(200,$r['summary']['total_receivables']);
    }

    public function test_expense_category_chart_obeys_exact_range(): void
    {
        $chart=$this->summary(['from'=>'2026-01-01','to'=>'2026-01-31'])['charts']['expenses_by_category'];
        $this->assertSame([['category'=>'Operations fixture','amount'=>100]],$chart);
    }

    public function test_pdf_and_xlsx_present_same_eight_metrics_and_monthly_rows(): void
    {
        $r=$this->summary(['year'=>2026]);
        $entries=P::summaryEntries($r);
        $this->assertCount(8,$entries);
        $html=Blade::render(file_get_contents(resource_path('views/reports/pdf.blade.php')),['report'=>$r,'actor'=>$this->admin->name,'generatedAt'=>'2026-12-31T12:00:00+07:00']);
        foreach($entries as $entry)$this->assertStringContainsString($entry['label'],$html);
        foreach(['Januari 2026','Desember 2026'] as $period)$this->assertStringContainsString($period,$html);

        $binary=Excel::raw(new ReportExport($r,$this->admin->name,'2026-12-31T12:00:00+07:00'),\Maatwebsite\Excel\Excel::XLSX);
        $path=tempnam(sys_get_temp_dir(),'financial-summary-');file_put_contents($path,$binary);
        $zip=new ZipArchive();$this->assertTrue($zip->open($path)===true);
        $strings=$zip->getFromName('xl/sharedStrings.xml');$summarySheet=$zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();unlink($path);
        foreach($entries as $entry)$this->assertStringContainsString($entry['label'],$strings);
        foreach(['Januari 2026','Desember 2026'] as $period)$this->assertStringContainsString($period,$strings);
        foreach(array_column($entries,'value') as $value)$this->assertMatchesRegularExpression('/<v>'.preg_quote((string)$value,'/').'(<\/v>|<\/v>)/',$summarySheet);
    }

    public function test_unfiltered_pdf_filename_remains_ringkasan_keuangan_pdf(): void
    {
        $response=$this->actingAs($this->admin)->get('/api/v1/reports/summary/export/pdf')->assertOk();
        $this->assertStringContainsString(rawurlencode('Ringkasan Keuangan.pdf'),$response->headers->get('Content-Disposition'));
    }

    private function summary(array $filters=[]): array { return $this->report('summary',$filters); }
    private function report(string $type,array $filters=[]): array { return app(ReportService::class)->generate($type,$filters,true); }

    private function fixtures(): void
    {
        DB::table('payment_allocations')->delete();DB::table('payments')->delete();DB::table('bills')->delete();
        DB::table('special_bill_targets')->delete();DB::table('special_bills')->delete();DB::table('expenses')->delete();
        DB::table('opening_balances')->delete();DB::table('households')->delete();DB::table('residents')->delete();
        $houses=DB::table('houses')->orderBy('id')->limit(2)->pluck('id')->map(fn($x)=>(int)$x)->all();[$this->house1,$this->house2]=$houses;
        $resident1=$this->resident('Summary Head One');$resident2=$this->resident('Summary Head Two');
        $household1=$this->household($this->house1,$resident1);$household2=$this->household($this->house2,$resident2);
        $this->category=(int)DB::table('expense_categories')->insertGetId(['name'=>'Operations fixture','active'=>true,'created_at'=>'2025-01-01','updated_at'=>'2025-01-01']);
        DB::table('opening_balances')->insert(['as_of'=>'2025-12-01','amount'=>1000,'created_at'=>'2025-12-01','updated_at'=>'2025-12-01']);
        DB::table('opening_balances')->insert(['as_of'=>'2026-01-01','amount'=>5000,'created_at'=>'2026-01-01','updated_at'=>'2026-01-01']);
        $before=$this->payment($this->house1,$household1,$resident1,'PAY-BEFORE',200,'POSTED','2025-12-20 03:00:00');
        $this->expense('EXP-BEFORE',50,'POSTED','2025-12-21');
        $this->payment['posted']=$this->payment($this->house1,$household1,$resident1,'PAY-POSTED',500,'POSTED','2026-01-20 03:00:00');
        $this->payment['cancelled']=$this->payment($this->house1,$household1,$resident1,'PAY-CANCELLED',9000,'CANCELLED','2026-01-21 03:00:00');
        $this->expense('EXP-POSTED',100,'POSTED','2026-01-25');$this->expense('EXP-CANCELLED',8000,'CANCELLED','2026-01-26');
        $this->bill['routine']=$this->bill($this->house1,$household1,$resident1,'Routine active',1000,'UNPAID','ROUTINE','2026-01-01','2026-01-31');
        $activeSpecial=$this->special('SPECIAL-ACTIVE','APPROVED',600,'2026-02-28');
        $this->bill['special']=$this->bill($this->house2,$household2,$resident2,'Special active',600,'PARTIAL','SPECIAL','2026-02-01','2026-02-28',$activeSpecial);
        $this->bill['cancelled']=$this->bill($this->house1,$household1,$resident1,'Bill cancelled',700,'CANCELLED','ROUTINE','2026-03-01','2026-03-31');
        $cancelledSpecial=$this->special('SPECIAL-CANCELLED','CANCELLED',800,'2026-04-30');
        $this->bill['special_cancelled']=$this->bill($this->house2,$household2,$resident2,'Special parent cancelled',800,'UNPAID','SPECIAL','2026-04-01','2026-04-30',$cancelledSpecial);
        $this->allocation($this->payment['posted'],$this->bill['routine'],300);$this->allocation($this->payment['posted'],$this->bill['special'],100);$this->allocation($this->payment['cancelled'],$this->bill['routine'],500);
    }

    private function resident(string $name): int { return (int)DB::table('residents')->insertGetId(['full_name'=>$name,'marital_status'=>'MARRIED','active'=>true,'created_at'=>'2025-01-01','updated_at'=>'2025-01-01']); }
    private function household(int $house,int $resident): int { return (int)DB::table('households')->insertGetId(['house_id'=>$house,'head_resident_id'=>$resident,'occupancy_type'=>'PERMANENT','started_at'=>'2025-01-01','active'=>true,'created_at'=>'2025-01-01','updated_at'=>'2025-01-01']); }
    private function payment(int $house,int $household,int $resident,string $number,int $amount,string $status,string $at): int { return (int)DB::table('payments')->insertGetId(['transaction_number'=>$number,'house_id'=>$house,'household_id'=>$household,'payer_resident_id'=>$resident,'payment_method'=>'CASH','amount'=>$amount,'paid_at'=>$at,'status'=>$status,'created_by'=>$this->admin->id,'created_at'=>$at,'updated_at'=>$at]); }
    private function expense(string $number,int $amount,string $status,string $at): int { return (int)DB::table('expenses')->insertGetId(['transaction_number'=>$number,'expense_category_id'=>$this->category,'title'=>$number,'amount'=>$amount,'spent_at'=>$at,'status'=>$status,'created_by'=>$this->admin->id,'created_at'=>$at,'updated_at'=>$at]); }
    private function special(string $number,string $status,int $amount,string $due): int { return (int)DB::table('special_bills')->insertGetId(['special_bill_number'=>$number,'title'=>$number,'amount'=>$amount,'due_date'=>$due,'target_type'=>'SELECTED_HOUSES','status'=>$status,'created_by'=>$this->admin->id,'created_at'=>'2026-01-01','updated_at'=>'2026-01-01']); }
    private function bill(int $house,int $household,int $resident,string $title,int $amount,string $status,string $type,string $period,string $due,?int $special=null): int { return (int)DB::table('bills')->insertGetId(['special_bill_id'=>$special,'house_id'=>$house,'household_id'=>$household,'fee_code'=>$special?null:'SECURITY','responsible_head_resident_id'=>$resident,'house_code_snapshot'=>'FIX-'.$house,'responsible_head_name_snapshot'=>'Fixture Head','fee_name_snapshot'=>$special?null:'Security','amount_snapshot'=>$special?null:$amount,'type'=>$type,'title'=>$title,'period'=>$period,'due_date'=>$due,'amount'=>$amount,'paid_amount'=>0,'status'=>$status,'created_at'=>$period,'updated_at'=>$period]); }
    private function allocation(int $payment,int $bill,int $amount): void { DB::table('payment_allocations')->insert(['payment_id'=>$payment,'bill_id'=>$bill,'amount'=>$amount,'created_at'=>'2026-01-20','updated_at'=>'2026-01-20']); }
}
