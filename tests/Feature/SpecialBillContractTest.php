<?php

namespace Tests\Feature;

use App\Models\{AuditLog, Bill, House, Household, Payment, Resident, SpecialBill, SpecialBillDocument, User};
use App\Services\{MonthlyBillService, PaymentService, SpecialBillService};
use Database\Seeders\{DemoSeeder, InitialSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SpecialBillContractTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(InitialSeeder::class);
        $this->admin = User::where('email', 'superadmin@portalwarga.test')->firstOrFail();
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['title' => 'Iuran khusus', 'description' => 'Keperluan warga', 'amount' => 125000, 'due_date' => '2026-08-31', 'target_type' => 'ALL_OCCUPIED', 'approval_document' => UploadedFile::fake()->create('approval.pdf', 10, 'application/pdf')], $overrides);
    }

    private function create(array $overrides = []): SpecialBill
    {
        return app(SpecialBillService::class)->create($this->payload($overrides), $this->admin->id);
    }

    private function occupied(string $number = '99', string $name = 'Kepala Tes'): House
    {
        $house = House::create(['block_code' => 'T', 'house_number' => $number, 'house_code' => 'T-'.$number]);
        $head = Resident::create(['full_name' => $name, 'marital_status' => 'MENIKAH', 'active' => true]);
        Household::create(['house_id' => $house->id, 'head_resident_id' => $head->id, 'occupancy_type' => 'PERMANENT', 'started_at' => '2026-01-01', 'active' => true]);
        return $house;
    }

    private function selected(House $house): SpecialBill
    {
        return $this->create(['target_type' => 'SELECTED_HOUSES', 'house_ids' => [$house->id]]);
    }

    private function apiCreate(array $payload = [])
    {
        return $this->actingAs($this->admin)->postJson('/api/v1/special-bills', $payload ?: $this->payload());
    }

    public function test_01_create_requires_authentication(): void
    {
        $this->postJson('/api/v1/special-bills', $this->payload())->assertUnauthorized();
    }

    public function test_02_create_requires_permission(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/v1/special-bills', $this->payload())->assertForbidden();
    }

    public function test_03_create_validates_required_fields(): void
    {
        $this->apiCreate([])->assertCreated();
        $this->actingAs($this->admin)->postJson('/api/v1/special-bills', [])->assertUnprocessable()->assertJsonValidationErrors(['title', 'amount', 'due_date', 'target_type', 'approval_document']);
    }

    public function test_04_create_validates_target_type(): void
    {
        $this->apiCreate($this->payload(['target_type' => 'UNKNOWN']))->assertJsonValidationErrors('target_type');
    }

    public function test_05_selected_houses_require_house_ids(): void
    {
        $this->apiCreate($this->payload(['target_type' => 'SELECTED_HOUSES']))->assertJsonValidationErrors('house_ids');
    }

    public function test_06_selected_houses_reject_duplicate_house_ids(): void
    {
        $house = House::first();
        $this->apiCreate($this->payload(['target_type' => 'SELECTED_HOUSES', 'house_ids' => [$house->id, $house->id]]))->assertJsonValidationErrors('house_ids.1');
    }

    public function test_07_create_persists_pending_special_bill_and_number(): void
    {
        $special = $this->create();
        $this->assertMatchesRegularExpression('/^SPB-\d{8}-[0-9A-Z]{8}$/', $special->special_bill_number);
        $this->assertSame('PENDING_APPROVAL', $special->status);
    }

    public function test_08_create_does_not_generate_bills(): void
    {
        $special = $this->create();
        $this->assertFalse(Bill::where('special_bill_id', $special->id)->exists());
    }

    public function test_09_document_response_does_not_expose_storage_path(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/v1/special-bills/'.$this->create()->id)->assertOk();
        $this->assertArrayNotHasKey('storage_path', $response->json('documents.0'));
    }

    public function test_10_create_stores_approval_document_privately(): void
    {
        $special = $this->create();
        Storage::disk('local')->assertExists($special->documents->first()->getRawOriginal('storage_path'));
        Storage::disk('public')->assertMissing($special->documents->first()->getRawOriginal('storage_path'));
    }

    public function test_11_download_requires_authentication(): void
    {
        $document = $this->create()->documents->first();
        $this->getJson('/api/v1/special-bill-documents/'.$document->id.'/download')->assertUnauthorized();
    }

    public function test_12_download_requires_view_permission(): void
    {
        $document = $this->create()->documents->first();
        $this->actingAs(User::factory()->create())->get('/api/v1/special-bill-documents/'.$document->id.'/download')->assertForbidden();
    }

    public function test_13_download_returns_file_and_writes_audit(): void
    {
        $fixture = database_path('seeders/fixtures/demo-document.pdf');
        $special = $this->create([
            'approval_document' => new UploadedFile($fixture, 'demo-document.pdf', 'application/pdf', null, true),
        ]);
        $document = $special->documents->first();
        $response = $this->actingAs($this->admin)->get('/api/v1/special-bill-documents/'.$document->id.'/download');
        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('content-disposition'));
        $this->assertStringContainsString('demo-document.pdf', (string) $response->headers->get('content-disposition'));
        $downloaded = $response->streamedContent();
        $this->assertNotSame('', $downloaded);
        $this->assertStringStartsWith('%PDF-', $downloaded);
        $this->assertGreaterThan(500, strlen($downloaded));
        $this->assertDatabaseHas('audit_logs', ['action' => 'special_bill.document.downloaded', 'auditable_id' => $document->id, 'user_id' => $this->admin->id]);
    }

    public function test_demo_document_fixture_is_a_nontrivial_pdf(): void
    {
        $fixture = file_get_contents(database_path('seeders/fixtures/demo-document.pdf'));
        $this->assertNotFalse($fixture);
        $this->assertStringStartsWith('%PDF-', $fixture);
        $this->assertStringEndsWith("%%EOF\n", $fixture);
        $this->assertGreaterThan(500, strlen($fixture));
    }

    public function test_14_approval_requires_authentication(): void
    {
        $this->postJson('/api/v1/special-bills/'.$this->create()->id.'/approve')->assertUnauthorized();
    }

    public function test_15_approval_requires_permission(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/api/v1/special-bills/'.$this->create()->id.'/approve')->assertForbidden();
    }

    public function test_16_selected_approval_generates_only_targeted_bills(): void
    {
        $target = $this->occupied('91'); $this->occupied('92');
        $special = app(SpecialBillService::class)->approve($this->selected($target), $this->admin->id);
        $this->assertSame([$target->id], $special->bills->pluck('house_id')->all());
    }

    public function test_17_all_occupied_approval_snapshots_current_occupied_houses(): void
    {
        $first = $this->occupied('81'); $second = $this->occupied('82');
        $special = app(SpecialBillService::class)->approve($this->create(), $this->admin->id);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $special->targets->pluck('house_id')->all());
    }

    public function test_18_all_occupied_approval_rejects_empty_population(): void
    {
        $this->expectException(ValidationException::class);
        app(SpecialBillService::class)->approve($this->create(), $this->admin->id);
    }

    public function test_19_approval_rejects_unoccupied_selected_house(): void
    {
        $this->expectException(ValidationException::class);
        app(SpecialBillService::class)->approve($this->selected(House::first()), $this->admin->id);
    }

    public function test_20_generated_bill_contains_immutable_snapshots(): void
    {
        $house = $this->occupied('71', 'Nama Awal');
        $special = app(SpecialBillService::class)->approve($this->selected($house), $this->admin->id);
        $bill = $special->bills->first();
        $this->assertSame('Nama Awal', $bill->responsible_head_name_snapshot);
        $this->assertSame('Iuran khusus', $bill->fee_name_snapshot);
        $this->assertSame(125000, (int) $bill->amount_snapshot);
    }

    public function test_21_approval_writes_approved_and_generated_audits(): void
    {
        $special = app(SpecialBillService::class)->approve($this->selected($this->occupied('61')), $this->admin->id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'special_bill.approved', 'auditable_id' => $special->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'special_bill.bills.generated', 'auditable_id' => $special->id]);
    }

    public function test_22_double_approval_is_rejected_without_extra_bills(): void
    {
        $special = app(SpecialBillService::class)->approve($this->selected($this->occupied('51')), $this->admin->id);
        try { app(SpecialBillService::class)->approve($special, $this->admin->id); $this->fail('Double approval accepted'); } catch (ValidationException) {}
        $this->assertSame(1, Bill::where('special_bill_id', $special->id)->count());
    }

    public function test_23_failed_approval_rolls_back_targets_bills_status_and_audits(): void
    {
        $special = $this->selected(House::first());
        try { app(SpecialBillService::class)->approve($special, $this->admin->id); } catch (ValidationException) {}
        $this->assertSame('PENDING_APPROVAL', $special->fresh()->status);
        $this->assertSame(0, $special->bills()->count());
        $this->assertFalse(AuditLog::where('auditable_id', $special->id)->where('action', 'special_bill.approved')->exists());
    }

    public function test_24_special_bill_is_compatible_with_payment_service(): void
    {
        $special = app(SpecialBillService::class)->approve($this->selected($this->occupied('41')), $this->admin->id);
        $payment = app(PaymentService::class)->create(['bill_ids' => [$special->bills->first()->id], 'payment_method' => 'CASH', 'paid_at' => '2026-08-01 10:00:00'], $this->admin->id);
        $this->assertSame('PAID', $special->bills->first()->fresh()->status);
        $this->assertSame(125000, (int) $payment->amount);
    }

    public function test_25_database_prevents_duplicate_special_bill_per_house(): void
    {
        $special = app(SpecialBillService::class)->approve($this->selected($this->occupied('31')), $this->admin->id);
        $this->expectException(\Illuminate\Database\QueryException::class);
        Bill::create($special->bills->first()->getAttributes() + ['id' => null]);
    }

    public function test_26_pending_special_bill_can_be_cancelled(): void
    {
        $special = app(SpecialBillService::class)->cancel($this->create(), 'Tidak jadi', $this->admin->id);
        $this->assertSame('CANCELLED', $special->status);
    }

    public function test_27_approved_special_bill_cancellation_cancels_generated_bills(): void
    {
        $special = app(SpecialBillService::class)->approve($this->selected($this->occupied('21')), $this->admin->id);
        app(SpecialBillService::class)->cancel($special, 'Dibatalkan rapat', $this->admin->id);
        $this->assertSame('CANCELLED', $special->bills->first()->fresh()->status);
    }

    public function test_28_cancellation_preserves_bills_as_history(): void
    {
        $special = app(SpecialBillService::class)->approve($this->selected($this->occupied('22')), $this->admin->id);
        $billId = $special->bills->first()->id;
        app(SpecialBillService::class)->cancel($special, 'Arsip', $this->admin->id);
        $this->assertDatabaseHas('bills', ['id' => $billId, 'status' => 'CANCELLED']);
    }

    public function test_29_paid_special_bill_cannot_be_cancelled(): void
    {
        $special = app(SpecialBillService::class)->approve($this->selected($this->occupied('23')), $this->admin->id);
        $special->bills->first()->update(['status' => 'PAID', 'paid_amount' => 125000]);
        $this->expectException(ValidationException::class);
        app(SpecialBillService::class)->cancel($special, 'Tidak boleh', $this->admin->id);
    }

    public function test_30_double_cancellation_is_rejected(): void
    {
        $special = app(SpecialBillService::class)->cancel($this->create(), 'Pertama', $this->admin->id);
        $this->expectException(ValidationException::class);
        app(SpecialBillService::class)->cancel($special, 'Kedua', $this->admin->id);
    }

    public function test_31_cancellation_writes_reason_actor_and_audit(): void
    {
        $special = app(SpecialBillService::class)->cancel($this->create(), 'Keputusan rapat', $this->admin->id);
        $this->assertSame('Keputusan rapat', $special->cancel_reason);
        $this->assertSame($this->admin->id, $special->cancelled_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'special_bill.cancelled', 'auditable_id' => $special->id]);
    }

    public function test_32_cancel_endpoint_validates_reason(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/special-bills/'.$this->create()->id.'/cancel', [])->assertJsonValidationErrors('cancel_reason');
    }

    public function test_33_put_special_bill_route_is_absent(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutesByMethod()['PUT'] ?? []);
        $this->assertFalse($routes->contains(fn ($route) => $route->matches(\Illuminate\Http\Request::create('/api/v1/special-bills/1', 'PUT'))));
    }

    public function test_34_delete_special_bill_route_is_absent(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutesByMethod()['DELETE'] ?? []);
        $this->assertFalse($routes->contains(fn ($route) => $route->matches(\Illuminate\Http\Request::create('/api/v1/special-bills/1', 'DELETE'))));
    }

    public function test_35_database_rejects_document_without_storage_path(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        SpecialBillDocument::create(['special_bill_id' => $this->create()->id, 'original_name' => 'x.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1]);
    }

    public function test_36_document_size_limit_comes_from_setting(): void
    {
        DB::table('settings')->where('key', 'special_bill_document_max_kb')->update(['value' => '1']);
        $this->apiCreate($this->payload(['approval_document' => UploadedFile::fake()->create('large.pdf', 2, 'application/pdf')]))->assertJsonValidationErrors('approval_document');
    }

    public function test_37_demo_seeder_is_idempotent_for_special_bills(): void
    {
        $this->seed(DemoSeeder::class); $count = SpecialBill::count();
        $this->seed(DemoSeeder::class);
        $this->assertSame($count, SpecialBill::count());
    }

    public function test_38_lifecycle_records_all_four_audit_actions(): void
    {
        $special = app(SpecialBillService::class)->approve($this->selected($this->occupied('11')), $this->admin->id);
        app(SpecialBillService::class)->cancel($special, 'Selesai', $this->admin->id);
        $actions = AuditLog::where('auditable_id', $special->id)->pluck('action')->all();
        foreach (['special_bill.created', 'special_bill.approved', 'special_bill.bills.generated', 'special_bill.cancelled'] as $action) $this->assertContains($action, $actions);
    }

    public function test_39_special_bill_does_not_collide_with_routine_bill_uniqueness(): void
    {
        $house = $this->occupied('12');
        app(SpecialBillService::class)->approve($this->selected($house), $this->admin->id);
        $result = app(MonthlyBillService::class)->generate('2026-08-01');
        $this->assertSame(2, $result['created']);
        $this->assertSame(3, Bill::where('house_id', $house->id)->count());
    }

    public function test_40_routine_monthly_generation_remains_idempotent_after_special_bill(): void
    {
        $house = $this->occupied('13');
        app(SpecialBillService::class)->approve($this->selected($house), $this->admin->id);
        app(MonthlyBillService::class)->generate('2026-09-01');
        $second = app(MonthlyBillService::class)->generate('2026-09-01');
        $this->assertSame(0, $second['created']);
        $this->assertSame(3, Bill::where('house_id', $house->id)->count());
    }
}
