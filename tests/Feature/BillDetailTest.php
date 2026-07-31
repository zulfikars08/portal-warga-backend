<?php

namespace Tests\Feature;

use App\Models\{Bill, FeeRate, House, Household, Payment, PaymentAllocation, PrivateDocument, Resident, SpecialBill, User};
use Database\Seeders\InitialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillDetailTest extends TestCase
{
    use RefreshDatabase;

    private User $viewer;
    private Bill $bill;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(InitialSeeder::class);
        $this->viewer = User::where('email', 'superadmin@portalwarga.test')->firstOrFail();
        $house = House::firstOrFail();
        $head = Resident::create(['full_name' => 'Safe Head', 'phone' => 'secret-phone', 'marital_status' => 'MENIKAH', 'active' => true]);
        PrivateDocument::create(['resident_id' => $head->id, 'document_type' => 'KTP', 'storage_path' => 'private/secret.pdf', 'original_name' => 'secret.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1]);
        $household = Household::create(['house_id' => $house->id, 'head_resident_id' => $head->id, 'occupancy_type' => 'PERMANENT', 'started_at' => '2026-01-01', 'active' => true]);
        $rate = FeeRate::where('fee_code', 'SECURITY')->firstOrFail();
        $this->bill = Bill::create(['house_id' => $house->id, 'household_id' => $household->id, 'fee_rate_id' => $rate->id, 'fee_code' => 'SECURITY', 'responsible_head_resident_id' => $head->id, 'house_code_snapshot' => $house->house_code, 'responsible_head_name_snapshot' => $head->full_name, 'fee_name_snapshot' => 'Security snapshot', 'amount_snapshot' => 100000, 'type' => 'routine', 'title' => 'Security', 'period' => '2026-07-01', 'due_date' => '2026-07-10', 'amount' => 100000, 'paid_amount' => 0, 'status' => 'UNPAID', 'fee_snapshot' => ['name' => 'Security snapshot', 'amount' => 100000]]);
    }

    private function getBill(Bill $bill = null)
    {
        return $this->actingAs($this->viewer)->getJson('/api/v1/bills/'.($bill ?: $this->bill)->id);
    }

    public function test_detail_requires_authentication_and_permission(): void
    {
        $this->getJson('/api/v1/bills/'.$this->bill->id)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/v1/bills/'.$this->bill->id)->assertForbidden();
    }

    public function test_routine_detail_has_source_house_head_status_and_no_sensitive_data(): void
    {
        $response = $this->getBill()->assertOk()->assertJsonPath('data.source.kind', 'routine')->assertJsonPath('data.source.fee_code', 'SECURITY')->assertJsonPath('data.source.name', 'Security snapshot')->assertJsonPath('data.house.occupied', true)->assertJsonPath('data.household.active', true)->assertJsonPath('data.responsible_head.full_name', 'Safe Head')->assertJsonPath('data.status', 'UNPAID')->assertJsonPath('data.outstanding_amount', 100000);
        $json = json_encode($response->json(), JSON_THROW_ON_ERROR);
        foreach (['storage_path', 'private/secret.pdf', 'secret.pdf', 'secret-phone', 'password', 'remember_token', 'personal_access_tokens'] as $secret) $this->assertStringNotContainsString($secret, $json);
    }

    public function test_paid_detail_has_payment_allocation_information(): void
    {
        $payment = Payment::create(['transaction_number' => 'PAY-DETAIL-1', 'house_id' => $this->bill->house_id, 'household_id' => $this->bill->household_id, 'payer_resident_id' => $this->bill->responsible_head_resident_id, 'payment_method' => 'TRANSFER', 'amount' => 100000, 'paid_at' => '2026-07-05 12:00:00', 'status' => 'POSTED']);
        PaymentAllocation::create(['payment_id' => $payment->id, 'bill_id' => $this->bill->id, 'amount' => 100000]);
        $this->bill->update(['paid_amount' => 100000, 'status' => 'PAID']);
        $this->getBill()->assertOk()->assertJsonPath('data.status', 'PAID')->assertJsonPath('data.outstanding_amount', 0)->assertJsonPath('data.payments.0.amount', 100000)->assertJsonPath('data.payments.0.payment.transaction_number', 'PAY-DETAIL-1')->assertJsonPath('data.payments.0.payment.method', 'TRANSFER')->assertJsonPath('data.payments.0.payment.status', 'POSTED');
    }

    public function test_cancelled_special_bill_has_safe_source_and_cancellation_actor(): void
    {
        $special = SpecialBill::create(['special_bill_number' => 'SPB-DETAIL-1', 'title' => 'Special source', 'amount' => 55000, 'due_date' => '2026-07-31', 'target_type' => 'ALL_OCCUPIED', 'status' => 'CANCELLED', 'approved_by' => $this->viewer->id, 'approved_at' => '2026-07-01 10:00:00', 'cancelled_by' => $this->viewer->id, 'cancelled_at' => '2026-07-02 10:00:00', 'cancel_reason' => 'Wrong source']);
        $bill = $this->bill->replicate(['fee_rate_id', 'fee_code', 'period']);
        $bill->special_bill_id = $special->id; $bill->fee_rate_id = null; $bill->fee_code = null; $bill->type = 'special'; $bill->period = '2026-08-01'; $bill->title = $special->title; $bill->amount = $bill->amount_snapshot = 55000; $bill->status = 'CANCELLED'; $bill->save();
        $this->getBill($bill)->assertOk()->assertJsonPath('data.source.kind', 'special')->assertJsonPath('data.source.special_bill_number', 'SPB-DETAIL-1')->assertJsonPath('data.source.title', 'Special source')->assertJsonPath('data.source.amount', 55000)->assertJsonPath('data.source.approver.name', $this->viewer->name)->assertJsonPath('data.cancellation.reason', 'Wrong source')->assertJsonPath('data.cancellation.cancelled_by.name', $this->viewer->name);
    }
}
