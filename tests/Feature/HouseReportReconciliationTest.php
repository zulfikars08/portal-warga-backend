<?php

namespace Tests\Feature;

use App\Exports\ReportExport;
use App\Models\User;
use App\Services\ReportService;
use Database\Seeders\InitialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;
use ZipArchive;

class HouseReportReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private int $houseId;
    private int $oldHouseholdId;
    private int $activeHouseholdId;
    private array $billIds=[];
    private array $paymentIds=[];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(InitialSeeder::class);
        $this->admin=User::where('email','superadmin@portalwarga.test')->firstOrFail();
        $this->makeFixtures();
    }

    public function test_posted_and_cancelled_payments_both_appear_in_house_ledger_history(): void
    {
        $rows=$this->report()['rows'];
        $payments=$this->rowsOfType($rows,'payment');
        $this->assertContains($this->paymentIds['split'],$this->ids($payments));
        $this->assertContains($this->paymentIds['cancelled'],$this->ids($payments));
        $this->assertSame('POSTED',$this->row($payments,$this->paymentIds['split'])['status']);
        $this->assertSame('CANCELLED',$this->row($payments,$this->paymentIds['cancelled'])['status']);
    }

    public function test_cancelled_payment_is_excluded_from_payment_and_allocation_totals(): void
    {
        $totals=$this->report()['house_totals'];
        $this->assertSame(1600,$totals['payments']);
        $this->assertSame(1300,$totals['paid_on_bills']);
    }

    public function test_historic_payment_survives_old_household_close_and_new_active_household(): void
    {
        $report=$this->report();
        $this->assertSame('New Head',$report['house']['active_head_name']);
        $this->assertContains($this->paymentIds['historic'],$this->ids($this->rowsOfType($report['rows'],'payment')));
    }

    public function test_house_report_scopes_by_house_not_current_household(): void
    {
        $rows=$this->report()['rows'];
        $this->assertContains($this->billIds['old'],$this->ids($this->rowsOfType($rows,'bill')));
        $this->assertContains($this->paymentIds['historic'],$this->ids($this->rowsOfType($rows,'payment')));
        $this->assertNotContains($this->paymentIds['other_house'],$this->ids($this->rowsOfType($rows,'payment')));
    }

    public function test_one_payment_split_across_two_bills_counts_once_but_both_allocations_count(): void
    {
        $report=$this->report();
        $split=$this->row($this->rowsOfType($report['rows'],'payment'),$this->paymentIds['split']);
        $this->assertSame(2,$split['bill_count']);
        $this->assertSame(700,$split['amount']);
        $this->assertSame(1300,$report['house_totals']['paid_on_bills']);
    }

    public function test_equal_amount_different_payments_both_count(): void
    {
        $totals=$this->report()['house_totals'];
        $this->assertSame(1600,$totals['payments']);
        $equal=array_filter($this->rowsOfType($this->report()['rows'],'payment'),fn($row)=>in_array($row['id'],[$this->paymentIds['equal'],$this->paymentIds['unallocated']],true) && $row['amount']===300);
        $this->assertCount(2,$equal);
    }

    public function test_payments_total_is_unique_posted_direct_house_payments_including_unallocated(): void
    {
        $report=$this->report();
        $this->assertSame(1600,$report['house_totals']['payments']);
        $unallocated=$this->row($this->rowsOfType($report['rows'],'payment'),$this->paymentIds['unallocated']);
        $this->assertSame(0,$unallocated['bill_count']);
    }

    public function test_paid_on_bills_is_posted_active_bill_allocation_sum(): void
    {
        $this->assertSame(1300,$this->report()['house_totals']['paid_on_bills']);
    }

    public function test_outstanding_is_active_bill_amount_minus_posted_allocations(): void
    {
        // Active bills total 2500; posted allocation total 1300. Cancelled payment allocation ignored.
        $this->assertSame(1200,$this->report()['house_totals']['outstanding']);
    }

    public function test_cancelled_bill_is_excluded_from_billed_and_outstanding_but_kept_in_history(): void
    {
        $report=$this->report();
        $this->assertSame(2500,$report['house_totals']['billed']);
        $this->assertSame(1200,$report['house_totals']['outstanding']);
        $this->assertContains($this->billIds['cancelled'],$this->ids($this->rowsOfType($report['rows'],'bill')));
    }

    public function test_no_date_filter_returns_complete_house_history(): void
    {
        $report=$this->report();
        $this->assertCount(4,$this->rowsOfType($report['rows'],'bill'));
        $this->assertCount(5,$this->rowsOfType($report['rows'],'payment'));
    }

    public function test_explicit_date_filter_uses_bill_created_at_and_payment_paid_at_with_matching_totals(): void
    {
        $report=$this->report(['month'=>'2026-02']);
        $this->assertSame([$this->billIds['new']],$this->ids($this->rowsOfType($report['rows'],'bill')));
        $this->assertSame([$this->paymentIds['equal']],$this->ids($this->rowsOfType($report['rows'],'payment')));
        $this->assertSame(['billed'=>1000,'paid_on_bills'=>300,'payments'=>300,'outstanding'=>700],$report['house_totals']);
    }

    public function test_summary_and_ledger_apply_same_house_and_date_filters(): void
    {
        $report=$this->report(['month'=>'2026-02']);
        $this->assertSame(300,$report['summary']['total_income']);
        $this->assertSame(1000,$report['summary']['total_billed']);
        $this->assertSame($report['summary']['total_income'],$report['house_totals']['payments']);
        $this->assertSame($report['summary']['total_billed'],$report['house_totals']['billed']);
    }

    public function test_status_filters_keep_ledger_and_totals_consistent(): void
    {
        $cancelled=$this->report(['status'=>'CANCELLED']);
        $this->assertSame([$this->billIds['cancelled']],$this->ids($this->rowsOfType($cancelled['rows'],'bill')));
        $this->assertSame([$this->paymentIds['cancelled']],$this->ids($this->rowsOfType($cancelled['rows'],'payment')));
        $this->assertSame(['billed'=>0,'paid_on_bills'=>0,'payments'=>0,'outstanding'=>0],$cancelled['house_totals']);
    }

    public function test_pdf_and_xlsx_present_same_four_totals_and_canonical_ledger_rows(): void
    {
        $report=$this->report(['month'=>'2026-02']);
        $html=Blade::render(file_get_contents(resource_path('views/reports/pdf.blade.php')),['report'=>$report,'actor'=>$this->admin->name,'generatedAt'=>'2026-02-28T12:00:00+07:00']);
        foreach(['Feb Active','PAY-HOUSE-EQUAL','Rp 1.000','Rp 300','Rp 700'] as $value)$this->assertStringContainsString($value,$html);
        $this->assertStringNotContainsString('Jan Old',$html);

        $binary=Excel::raw(new ReportExport($report,$this->admin->name,'2026-02-28T12:00:00+07:00'),\Maatwebsite\Excel\Excel::XLSX);
        $path=tempnam(sys_get_temp_dir(),'house-reconcile-'); file_put_contents($path,$binary);
        $zip=new ZipArchive(); $this->assertTrue($zip->open($path)===true);
        $strings=$zip->getFromName('xl/sharedStrings.xml');
        $summary=$zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close(); unlink($path);
        foreach(['Feb Active','PAY-HOUSE-EQUAL'] as $value)$this->assertStringContainsString($value,$strings);
        $this->assertStringNotContainsString('Jan Old',$strings);
        foreach([1000,300,700] as $value)$this->assertStringContainsString("><v>$value</v>",$summary);
    }

    private function report(array $filters=[]): array
    {
        return app(ReportService::class)->generate('houses',['house_id'=>$this->houseId,...$filters],true);
    }

    private function rowsOfType(array $rows,string $type): array
    {
        return array_values(array_filter($rows,fn($row)=>$row['row_type']===$type));
    }

    private function ids(array $rows): array { return array_column($rows,'id'); }
    private function row(array $rows,int $id): array { return array_values(array_filter($rows,fn($row)=>$row['id']===$id))[0]; }

    private function makeFixtures(): void
    {
        DB::table('payment_allocations')->delete();
        DB::table('payments')->delete();
        DB::table('bills')->delete();
        DB::table('households')->delete();
        DB::table('residents')->delete();

        $houses=DB::table('houses')->orderBy('id')->pluck('id')->all();
        $this->houseId=(int)$houses[0];
        $oldResident=$this->resident('Old Head');
        $newResident=$this->resident('New Head');
        $otherResident=$this->resident('Other Head');
        $this->oldHouseholdId=$this->household($this->houseId,$oldResident,false,'2025-01-01','2026-01-31');
        $this->activeHouseholdId=$this->household($this->houseId,$newResident,true,'2026-02-01',null);
        $otherHousehold=$this->household((int)$houses[1],$otherResident,true,'2026-01-01',null);

        $this->billIds['old']=$this->bill($this->oldHouseholdId,$oldResident,'Jan Old',1000,'UNPAID','2026-01-10 00:00:00');
        $this->billIds['new']=$this->bill($this->activeHouseholdId,$newResident,'Feb Active',1000,'UNPAID','2026-02-10 00:00:00');
        $this->billIds['extra']=$this->bill($this->activeHouseholdId,$newResident,'Mar Active',500,'UNPAID','2026-03-10 00:00:00');
        $this->billIds['cancelled']=$this->bill($this->activeHouseholdId,$newResident,'Cancelled Bill',9000,'CANCELLED','2026-04-11 00:00:00');

        $this->paymentIds['historic']=$this->payment($this->houseId,$this->oldHouseholdId,$oldResident,'PAY-HOUSE-HISTORIC',300,'POSTED','2026-01-15 00:00:00');
        $this->paymentIds['split']=$this->payment($this->houseId,$this->activeHouseholdId,$newResident,'PAY-HOUSE-SPLIT',700,'POSTED','2026-03-15 00:00:00');
        $this->paymentIds['equal']=$this->payment($this->houseId,$this->activeHouseholdId,$newResident,'PAY-HOUSE-EQUAL',300,'POSTED','2026-02-15 00:00:00');
        $this->paymentIds['unallocated']=$this->payment($this->houseId,$this->activeHouseholdId,$newResident,'PAY-HOUSE-UNALLOCATED',300,'POSTED','2026-04-15 00:00:00');
        $this->paymentIds['cancelled']=$this->payment($this->houseId,$this->activeHouseholdId,$newResident,'PAY-HOUSE-CANCELLED',8000,'CANCELLED','2026-03-16 00:00:00');
        $this->paymentIds['other_house']=$this->payment((int)$houses[1],$otherHousehold,$otherResident,'PAY-OTHER-HOUSE',777,'POSTED','2026-03-15 00:00:00');

        $this->allocation($this->paymentIds['historic'],$this->billIds['old'],300);
        $this->allocation($this->paymentIds['split'],$this->billIds['new'],400);
        $this->allocation($this->paymentIds['split'],$this->billIds['extra'],300);
        $this->allocation($this->paymentIds['equal'],$this->billIds['new'],300);
        $this->allocation($this->paymentIds['cancelled'],$this->billIds['new'],8000);
    }

    private function resident(string $name): int
    {
        return (int)DB::table('residents')->insertGetId(['full_name'=>$name,'marital_status'=>'MARRIED','active'=>true,'created_at'=>'2026-01-01','updated_at'=>'2026-01-01']);
    }

    private function household(int $houseId,int $residentId,bool $active,string $start,?string $end): int
    {
        return (int)DB::table('households')->insertGetId(['house_id'=>$houseId,'head_resident_id'=>$residentId,'occupancy_type'=>'PERMANENT','started_at'=>$start,'ended_at'=>$end,'active'=>$active,'created_at'=>'2026-01-01','updated_at'=>'2026-01-01']);
    }

    private function bill(int $householdId,int $residentId,string $title,int $amount,string $status,string $created): int
    {
        return (int)DB::table('bills')->insertGetId(['house_id'=>$this->houseId,'household_id'=>$householdId,'fee_code'=>'SECURITY','responsible_head_resident_id'=>$residentId,'house_code_snapshot'=>'A-01','responsible_head_name_snapshot'=>'Fixture Head','fee_name_snapshot'=>'Security','amount_snapshot'=>$amount,'type'=>'ROUTINE','title'=>$title,'period'=>substr($created,0,7).'-01','due_date'=>substr($created,0,7).'-28','amount'=>$amount,'paid_amount'=>0,'status'=>$status,'created_at'=>$created,'updated_at'=>$created]);
    }

    private function payment(int $houseId,int $householdId,int $residentId,string $number,int $amount,string $status,string $paidAt): int
    {
        return (int)DB::table('payments')->insertGetId(['transaction_number'=>$number,'house_id'=>$houseId,'household_id'=>$householdId,'payer_resident_id'=>$residentId,'payment_method'=>'CASH','amount'=>$amount,'paid_at'=>$paidAt,'status'=>$status,'created_by'=>$this->admin->id,'created_at'=>$paidAt,'updated_at'=>$paidAt]);
    }

    private function allocation(int $paymentId,int $billId,int $amount): void
    {
        DB::table('payment_allocations')->insert(['payment_id'=>$paymentId,'bill_id'=>$billId,'amount'=>$amount,'created_at'=>'2026-03-15','updated_at'=>'2026-03-15']);
    }
}
