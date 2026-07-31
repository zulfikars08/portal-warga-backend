<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use Database\Seeders\InitialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ReportModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(InitialSeeder::class);
        $this->admin=User::where('email','superadmin@portalwarga.test')->firstOrFail();
    }

    public static function reportTypes(): array
    {
        return [['summary'],['income'],['expenses'],['receivables'],['payments'],['bills'],['monthly']];
    }

    #[DataProvider('reportTypes')]
    public function test_report_endpoints_require_authentication(string $type): void
    {
        $this->getJson("/api/v1/reports/$type")->assertUnauthorized();
    }

    #[DataProvider('reportTypes')]
    public function test_view_permission_is_required(string $type): void
    {
        $this->actingAs(User::factory()->create())->getJson("/api/v1/reports/$type")->assertForbidden();
    }

    #[DataProvider('reportTypes')]
    public function test_report_contract_is_stable_for_empty_data(string $type): void
    {
        $r=$this->actingAs($this->admin)->getJson("/api/v1/reports/$type?year=2099")->assertOk()
            ->assertJsonPath('data.type',$type)->assertJsonStructure(['data'=>['filters','summary','rows'],'meta'=>['generated_at','currency','timezone']]);
        if($type==='monthly')$r->assertJsonCount(12,'data.rows');
    }

    public static function invalidFilters(): array
    {
        return [
            ['from=bad','from'],['from=2026-02-02&to=2026-02-01','to'],['month=2026-13','month'],
            ['year=1999','year'],['status=DELETED','status'],['payment_method=CARD','payment_method'],
            ['page=0','page'],['per_page=101','per_page'],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_are_rejected(string $query,string $field): void
    {
        $this->actingAs($this->admin)->getJson("/api/v1/reports/payments?$query")->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_export_permission_is_independent_from_view_permission(): void
    {
        $user=User::factory()->create();
        $user->givePermissionTo(Permission::findByName('reports.view'));
        $this->actingAs($user)->getJson('/api/v1/reports/income')->assertOk();
        $this->actingAs($user)->get('/api/v1/reports/income/export/pdf')->assertForbidden();
    }

    public function test_empty_pdf_is_valid(): void
    {
        $r=$this->actingAs($this->admin)->get('/api/v1/reports/income/export/pdf?year=2099')->assertOk();
        $this->assertStringContainsString('application/pdf',$r->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF',$r->getContent());
    }

    public function test_empty_xlsx_is_valid_zip(): void
    {
        $r=$this->actingAs($this->admin)->get('/api/v1/reports/income/export/xlsx?year=2099')->assertOk();
        $this->assertStringContainsString('spreadsheetml',$r->headers->get('content-type'));
        $this->assertStringStartsWith('PK',$r->streamedContent());
    }

    public function test_payment_summary_counts_cancelled_but_excludes_its_amount(): void
    {
        $residentId=DB::table('residents')->insertGetId(['full_name'=>'Ringkasan Tester','marital_status'=>'MARRIED','active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $householdId=DB::table('households')->insertGetId(['house_id'=>DB::table('houses')->value('id'),'head_resident_id'=>$residentId,'occupancy_type'=>'PERMANENT','started_at'=>'2026-01-01','active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $household=DB::table('households')->find($householdId);
        DB::table('payment_allocations')->delete();
        Payment::query()->delete();
        $base=['house_id'=>$household->house_id,'household_id'=>$household->id,'payer_resident_id'=>$household->head_resident_id,'payment_method'=>'CASH','created_by'=>$this->admin->id];
        Payment::create([...$base,'transaction_number'=>'PAY-SUMMARY-POSTED','amount'=>125000,'paid_at'=>'2026-09-10 03:00:00','status'=>'POSTED']);
        Payment::create([...$base,'transaction_number'=>'PAY-SUMMARY-CANCELLED','amount'=>987654,'paid_at'=>'2026-09-11 03:00:00','status'=>'CANCELLED']);

        $this->actingAs($this->admin)->getJson('/api/v1/reports/payments?month=2026-09')->assertOk()
            ->assertJsonPath('data.summary.total_income',125000)
            ->assertJsonPath('data.summary.income_transaction_count',2)
            ->assertJsonPath('data.summary.active_payment_count',1)
            ->assertJsonPath('data.summary.cancelled_payment_count',1);
    }

    public function test_bill_summary_uses_full_filtered_dataset_before_pagination(): void
    {
        DB::table('bills')->delete();
        foreach (DB::table('houses')->limit(3)->get() as $i => $house) {
            $residentId=DB::table('residents')->insertGetId(['full_name'=>"Kepala Tagihan $i",'marital_status'=>'MARRIED','active'=>true,'created_at'=>now(),'updated_at'=>now()]);
            DB::table('households')->insert(['house_id'=>$house->id,'head_resident_id'=>$residentId,'occupancy_type'=>'PERMANENT','started_at'=>'2026-01-01','active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        }
        $targets=DB::table('households as hh')->join('houses as h','h.id','=','hh.house_id')->join('residents as r','r.id','=','hh.head_resident_id')->select('hh.id as household_id','hh.house_id','hh.head_resident_id','h.house_code','r.full_name')->limit(3)->get();
        $this->assertCount(3,$targets);
        foreach ([['PAID',100000,100000,'Needle Paid'],['UNPAID',200000,0,'Needle Unpaid'],['CANCELLED',300000,150000,'Needle Cancelled']] as $i=>$values) {
            [$status,$amount,$paid,$title]=$values; $h=$targets[$i];
            DB::table('bills')->insert(['house_id'=>$h->house_id,'household_id'=>$h->household_id,'fee_code'=>'SECURITY','responsible_head_resident_id'=>$h->head_resident_id,'house_code_snapshot'=>$h->house_code,'responsible_head_name_snapshot'=>$h->full_name,'fee_name_snapshot'=>'Keamanan','amount_snapshot'=>$amount,'type'=>'routine','title'=>$title,'period'=>'2026-09-01','due_date'=>'2026-09-30','amount'=>$amount,'paid_amount'=>$paid,'status'=>$status,'created_at'=>'2026-09-10 03:00:00','updated_at'=>'2026-09-10 03:00:00']);
        }
        $summary=$this->actingAs($this->admin)->getJson('/api/v1/reports/bills?month=2026-09&per_page=1')->assertOk()->assertJsonPath('data.pagination.total',3)->json('data.summary');
        $this->assertSame([600000,250000,3,1,1,1],array_map(fn($key)=>$summary[$key],['total_billed','total_paid_bills','bill_count','paid_bill_count','unpaid_bill_count','cancelled_bill_count']));
        $this->actingAs($this->admin)->getJson('/api/v1/reports/bills?month=2026-09&status=CANCELLED')->assertOk()->assertJsonPath('data.summary.bill_count',1)->assertJsonPath('data.summary.paid_bill_count',0)->assertJsonPath('data.summary.unpaid_bill_count',0)->assertJsonPath('data.summary.cancelled_bill_count',1);
        $houseId=$targets[0]->house_id;
        $this->actingAs($this->admin)->getJson("/api/v1/reports/bills?month=2026-09&house_id=$houseId&bill_type=routine&search=Paid")->assertOk()->assertJsonPath('data.summary.total_billed',100000)->assertJsonPath('data.summary.bill_count',1)->assertJsonPath('data.summary.paid_bill_count',1);
    }

    public function test_unfiltered_expense_report_uses_all_history_and_separates_cancelled_amount(): void
    {
        $category=DB::table('expense_categories')->where('active',true)->first();
        DB::table('expenses')->insert([
            ['transaction_number'=>'EXP-OLD-ACTIVE','expense_category_id'=>$category->id,'title'=>'Aktif lama','amount'=>50000,'spent_at'=>'2025-01-10','status'=>'POSTED','created_by'=>$this->admin->id,'cancelled_by'=>null,'cancelled_at'=>null,'cancel_reason'=>null,'created_at'=>now(),'updated_at'=>now()],
            ['transaction_number'=>'EXP-OLD-CANCELLED','expense_category_id'=>$category->id,'title'=>'Batal lama','amount'=>90000,'spent_at'=>'2025-02-10','status'=>'CANCELLED','created_by'=>$this->admin->id,'cancelled_by'=>$this->admin->id,'cancelled_at'=>now(),'cancel_reason'=>'Koreksi','created_at'=>now(),'updated_at'=>now()],
        ]);
        $response=$this->actingAs($this->admin)->getJson('/api/v1/reports/expenses')->assertOk()
            ->assertJsonPath('data.summary.total_expense',50000)->assertJsonPath('data.summary.expense_transaction_count',2)
            ->assertJsonPath('data.summary.active_expense_count',1)->assertJsonPath('data.summary.cancelled_expense_count',1)
            ->assertJsonPath('data.pagination.total',2);
        $this->assertCount(2,$response->json('data.rows'));
    }

    public function test_house_report_requires_house_and_returns_flat_rows_safe_header_and_totals(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/reports/houses')->assertUnprocessable()->assertJsonValidationErrors('house_id');
        $id=\App\Models\House::firstOrFail()->id;
        $r=$this->actingAs($this->admin)->getJson("/api/v1/reports/houses?house_id=$id&year=2099")->assertOk()
            ->assertJsonStructure(['data'=>['house'=>['id','house_code','block_code','house_number','active_head_name','status'],'house_totals'=>['billed','paid_on_bills','payments','outstanding'],'rows']]);
        $this->assertStringNotContainsString('phone',$r->getContent());
        $this->assertStringNotContainsString('storage_path',$r->getContent());
    }
}
