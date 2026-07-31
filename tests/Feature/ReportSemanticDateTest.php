<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ReportService;
use Database\Seeders\InitialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportSemanticDateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private int $houseId;
    private int $householdId;
    private int $residentId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(InitialSeeder::class);
        $this->admin=User::where('email','superadmin@portalwarga.test')->firstOrFail();
        $this->houseId=(int)DB::table('houses')->value('id');
        $this->residentId=(int)DB::table('residents')->insertGetId([
            'full_name'=>'Semantic Date Resident','marital_status'=>'MARRIED','active'=>true,
            'created_at'=>'2026-01-01 00:00:00','updated_at'=>'2026-01-01 00:00:00',
        ]);
        $this->householdId=(int)DB::table('households')->insertGetId([
            'house_id'=>$this->houseId,'head_resident_id'=>$this->residentId,'occupancy_type'=>'PERMANENT',
            'started_at'=>'2026-01-01','active'=>true,'created_at'=>'2026-01-01 00:00:00','updated_at'=>'2026-01-01 00:00:00',
        ]);
        $this->fixtures();
    }

    public function test_receivables_use_due_date_not_created_at(): void
    {
        $ids=$this->rowIds('receivables','month=2026-08');
        $this->assertContains(123,$ids);
        $this->assertContains(124,$ids);
        $this->assertNotContains(125,$ids);
    }

    public function test_receivables_include_exact_august_due_date_boundaries(): void
    {
        $this->assertSame([124,123],$this->rowIds('receivables','month=2026-08'));
    }

    public function test_receivables_exclude_july_due_date_even_when_created_in_august(): void
    {
        $this->assertSame([125],$this->rowIds('receivables','month=2026-07'));
    }

    public function test_receivable_summary_uses_exact_same_due_date_filter(): void
    {
        $summary=$this->jsonReport('receivables','month=2026-08')['summary'];
        $this->assertSame(3000,$summary['total_receivables']);
        $this->assertSame(2,$summary['receivable_count']);
        $this->assertSame(1,$summary['houses_in_arrears']);
    }

    public function test_income_uses_paid_at_not_created_at(): void
    {
        $this->assertSame([202,201],$this->rowIds('income','month=2026-08'));
        $this->assertSame(3000,$this->jsonReport('income','month=2026-08')['summary']['total_income']);
    }

    public function test_payments_include_jakarta_month_boundaries(): void
    {
        $this->assertSame([202,201],$this->rowIds('payments','month=2026-08'));
        $this->assertNotContains(203,$this->rowIds('payments','month=2026-08'));
    }

    public function test_payment_projection_is_safe_zoned_and_descriptive_without_extra_queries(): void
    {
        DB::table('payment_allocations')->insert([
            ['payment_id'=>201,'bill_id'=>123,'amount'=>1000,'created_at'=>'2026-08-01','updated_at'=>'2026-08-01'],
            ['payment_id'=>202,'bill_id'=>124,'amount'=>1000,'created_at'=>'2026-08-01','updated_at'=>'2026-08-01'],
            ['payment_id'=>202,'bill_id'=>125,'amount'=>1000,'created_at'=>'2026-08-01','updated_at'=>'2026-08-01'],
        ]);
        DB::flushQueryLog(); DB::enableQueryLog();
        $rows=app(ReportService::class)->generate('payments',['month'=>'2026-08'],true)['rows'];
        $queries=DB::getQueryLog(); DB::disableQueryLog();
        $byId=array_column($rows,null,'id');

        $this->assertSame($this->houseId,$byId[201]['house_id']);
        $this->assertSame('2026-07-31T17:00:00+00:00',$byId[201]['paid_at']);
        $this->assertSame('Due start',$byId[201]['description']);
        $this->assertSame('2 tagihan',$byId[202]['description']);
        $this->assertArrayNotHasKey('bill_title',$byId[201]);
        $allocationQueries=array_filter($queries,fn($query)=>str_contains($query['query'],'payment_allocations'));
        $this->assertCount(1,$allocationQueries,'Allocation data must stay in single row query, not per-row bill queries.');

        $zero=$this->jsonReport('payments','month=2026-07')['rows'][0];
        $this->assertSame('Pembayaran rumah A-01',$zero['description']);
        $this->assertMatchesRegularExpression('/(?:Z|[+-]00:00)$/',$zero['paid_at']);
    }

    public function test_bill_projection_includes_house_id(): void
    {
        $this->assertSame($this->houseId,$this->jsonReport('bills','month=2026-08')['rows'][0]['house_id']);
    }

    public function test_expenses_use_spent_at_not_created_at(): void
    {
        $this->assertSame([302,301],$this->rowIds('expenses','month=2026-08'));
        $this->assertSame(3000,$this->jsonReport('expenses','month=2026-08')['summary']['total_expense']);
    }

    public function test_bills_use_created_at_with_jakarta_boundaries(): void
    {
        $ids=$this->rowIds('bills','month=2026-08');
        $this->assertContains(125,$ids);
        $this->assertContains(126,$ids);
        $this->assertNotContains(123,$ids);
    }

    public function test_bills_ignore_period_and_due_date_for_range(): void
    {
        $this->assertSame([126,125],$this->rowIds('bills','month=2026-08'));
        $this->assertSame(7000,$this->jsonReport('bills','month=2026-08')['summary']['total_billed']);
    }

    public function test_show_api_and_export_generation_return_same_semantic_rows(): void
    {
        $api=$this->jsonReport('receivables','month=2026-08')['rows'];
        $export=app(ReportService::class)->generate('receivables',['month'=>'2026-08'],true)['rows'];
        $this->assertSame(array_column($api,'id'),array_column($export,'id'));
        $this->assertSame([124,123],array_column($export,'id'));
    }

    public function test_xlsx_export_contains_same_canonical_receivable_ids(): void
    {
        $binary=$this->actingAs($this->admin)->get('/api/v1/reports/receivables/export/xlsx?month=2026-08')->assertOk()->streamedContent();
        $path=tempnam(sys_get_temp_dir(),'semantic-report-'); file_put_contents($path,$binary);
        $zip=new \ZipArchive(); $this->assertTrue($zip->open($path)===true);
        $strings=$zip->getFromName('xl/sharedStrings.xml'); $zip->close(); unlink($path);
        $this->assertStringContainsString('Due start',$strings);
        $this->assertStringContainsString('Due end',$strings);
        $this->assertStringNotContainsString('July due, August created',$strings);
    }

    public function test_semantically_in_range_data_is_not_reported_empty(): void
    {
        foreach(['income','payments','expenses','receivables','bills'] as $type) {
            $this->assertNotEmpty($this->jsonReport($type,'month=2026-08')['rows'],"$type must not be empty");
        }
        $this->assertEmpty($this->jsonReport('receivables','month=2026-06')['rows']);
    }

    public function test_monthly_billed_remains_period_based_but_receivables_are_month_end_snapshot(): void
    {
        $rows=$this->jsonReport('monthly','year=2026')['rows'];
        $august=$rows[7];
        $this->assertSame('2026-08',$august['period']);
        $this->assertSame(3000,$august['billed']);
        $this->assertSame(8000,$august['receivables']);
    }

    public function test_house_group_uses_bill_created_at_and_payment_paid_at(): void
    {
        $query='month=2026-08&house_id='.$this->houseId;
        $rows=$this->jsonReport('houses',$query)['rows'];
        $billIds=array_column(array_values(array_filter($rows,fn($r)=>$r['row_type']==='bill')),'id');
        $paymentIds=array_column(array_values(array_filter($rows,fn($r)=>$r['row_type']==='payment')),'id');
        $this->assertSame([126,125],$billIds);
        $this->assertSame([202,201],$paymentIds);
    }

    private function jsonReport(string $type,string $query): array
    {
        return $this->actingAs($this->admin)->getJson("/api/v1/reports/$type?$query")->assertOk()->json('data');
    }

    public function test_receivable_as_of_snapshot_honours_creation_and_payment_cutoff(): void
    {
        DB::table('payment_allocations')->insert(['payment_id'=>202,'bill_id'=>123,'amount'=>1000,'created_at'=>'2026-08-31','updated_at'=>'2026-08-31']);
        $july=$this->jsonReport('receivables','as_of=2026-07-31');
        $august=$this->jsonReport('receivables','as_of=2026-08-31');
        $this->assertSame(1000,$july['summary']['total_receivables']);
        $this->assertSame([123],array_column($july['rows'],'id'));
        $this->assertSame(7000,$august['summary']['total_receivables']);
        $this->assertSame(7000,array_sum(array_column($august['rows'],'outstanding_amount')));
        $this->assertNotContains(124,array_column($august['rows'],'id'),'Bill created after cutoff must stay excluded.');
    }

    public function test_receivable_as_of_api_and_export_generation_share_rows(): void
    {
        $show=app(ReportService::class)->generate('receivables',['as_of'=>'2026-08-31']);
        $export=app(ReportService::class)->generate('receivables',['as_of'=>'2026-08-31'],true);
        $this->assertSame(array_column($show['rows'],'id'),array_column($export['rows'],'id'));
        $this->assertSame($show['summary']['total_receivables'],$export['summary']['total_receivables']);
    }

    private function rowIds(string $type,string $query): array
    {
        return array_column($this->jsonReport($type,$query)['rows'],'id');
    }

    private function fixtures(): void
    {
        foreach([
            [123,'Due start','2026-08-01','2026-08-01','2026-07-15 00:00:00',1000,'SECURITY'],
            [124,'Due end','2026-08-01','2026-08-31','2026-09-15 00:00:00',2000,'CLEANING'],
            [125,'July due, August created','2026-07-01','2026-07-31','2026-07-31 17:00:00',3000,'SECURITY'],
            [126,'September dates, August end created','2026-09-01','2026-09-30','2026-08-31 16:59:59',4000,'SECURITY'],
        ] as [$id,$title,$period,$due,$created,$amount,$feeCode]) DB::table('bills')->insert([
            'id'=>$id,'house_id'=>$this->houseId,'household_id'=>$this->householdId,'fee_code'=>$feeCode,
            'responsible_head_resident_id'=>$this->residentId,'house_code_snapshot'=>'A-01',
            'responsible_head_name_snapshot'=>'Semantic Date Resident','fee_name_snapshot'=>'Security',
            'amount_snapshot'=>$amount,'type'=>'ROUTINE','title'=>$title,'period'=>$period,'due_date'=>$due,
            'amount'=>$amount,'paid_amount'=>0,'status'=>'UNPAID','created_at'=>$created,'updated_at'=>$created,
        ]);
        foreach([
            [201,'PAY-SEM-201','2026-07-31 17:00:00','2026-07-01 00:00:00',1000],
            [202,'PAY-SEM-202','2026-08-31 16:59:59','2026-09-01 00:00:00',2000],
            [203,'PAY-SEM-203','2026-07-31 16:59:59','2026-08-15 00:00:00',4000],
        ] as [$id,$number,$paid,$created,$amount]) DB::table('payments')->insert([
            'id'=>$id,'transaction_number'=>$number,'house_id'=>$this->houseId,'household_id'=>$this->householdId,
            'payer_resident_id'=>$this->residentId,'payment_method'=>'CASH','amount'=>$amount,'paid_at'=>$paid,
            'status'=>'POSTED','created_by'=>$this->admin->id,'created_at'=>$created,'updated_at'=>$created,
        ]);
        $category=(int)DB::table('expense_categories')->value('id');
        foreach([
            [301,'EXP-SEM-301','2026-08-01','2026-07-01 00:00:00',1000],
            [302,'EXP-SEM-302','2026-08-31','2026-09-01 00:00:00',2000],
            [303,'EXP-SEM-303','2026-07-31','2026-08-15 00:00:00',4000],
        ] as [$id,$number,$spent,$created,$amount]) DB::table('expenses')->insert([
            'id'=>$id,'transaction_number'=>$number,'expense_category_id'=>$category,'title'=>$number,
            'amount'=>$amount,'spent_at'=>$spent,'status'=>'POSTED','created_by'=>$this->admin->id,
            'created_at'=>$created,'updated_at'=>$created,
        ]);
    }
}
