<?php

namespace Tests\Feature;

use App\Models\{AuditLog, Bill, House, Payment, PrivateDocument, Resident, User};
use App\Services\{HouseholdService, MonthlyBillService};
use Database\Seeders\InitialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PaymentHttpAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(InitialSeeder::class);
        $this->cashier = User::where('email', 'superadmin@portalwarga.test')->firstOrFail();

        $house = House::firstOrFail();
        $head = Resident::create(['full_name' => 'Payment Head', 'marital_status' => 'MENIKAH', 'active' => true]);
        foreach (['KTP', 'KK'] as $type) PrivateDocument::create(['resident_id' => $head->id, 'document_type' => $type, 'storage_path' => "$type/payment", 'original_name' => "$type.pdf", 'mime_type' => 'application/pdf', 'size_bytes' => 1]);
        app(HouseholdService::class)->create(['house_id' => $house->id, 'head_resident_id' => $head->id, 'occupancy_type' => 'PERMANENT', 'started_at' => '2026-01-01']);
        app(MonthlyBillService::class)->generate('2026-04-01');
    }

    private function bill(string $code = 'SECURITY'): Bill
    {
        return Bill::where('fee_code', $code)->firstOrFail();
    }

    private function payload(array $extra = []): array
    {
        return array_replace(['bill_ids' => [$this->bill()->id], 'payment_method' => 'CASH', 'paid_at' => '2026-04-10 10:00:00'], $extra);
    }

    private function pay(array $payload)
    {
        return $this->actingAs($this->cashier)->post('/api/v1/payments', $payload, ['Accept' => 'application/json']);
    }

    public function test_authentication_and_payments_create_permission_are_required(): void
    {
        $this->postJson('/api/v1/payments', $this->payload())->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson('/api/v1/payments', $this->payload())->assertForbidden();
    }

    public function test_bill_ids_must_be_nonempty_distinct_array(): void
    {
        $this->pay($this->payload(['bill_ids' => $this->bill()->id]))->assertUnprocessable()->assertJsonValidationErrors('bill_ids');
        $this->pay($this->payload(['bill_ids' => []]))->assertUnprocessable()->assertJsonValidationErrors('bill_ids');
        $this->pay($this->payload(['bill_ids' => [$this->bill()->id, $this->bill()->id]]))->assertUnprocessable()->assertJsonValidationErrors('bill_ids.1');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_unpaid_bill_is_accepted_and_server_computes_total_allocation_paid_status_and_audit(): void
    {
        $bills = Bill::orderBy('id')->get();
        $response = $this->pay($this->payload(['bill_ids' => $bills->pluck('id')->all()]))->assertCreated()
            ->assertJsonPath('amount', 115000)->assertJsonPath('status', 'POSTED');
        $paymentId = $response->json('id');

        $this->assertDatabaseCount('payment_allocations', 2);
        foreach ($bills as $bill) {
            $this->assertDatabaseHas('payment_allocations', ['payment_id' => $paymentId, 'bill_id' => $bill->id, 'amount' => $bill->amount]);
            $this->assertDatabaseHas('bills', ['id' => $bill->id, 'status' => 'PAID', 'paid_amount' => $bill->amount]);
        }
        $this->assertDatabaseHas('audit_logs', ['user_id' => $this->cashier->id, 'action' => 'payment.created', 'auditable_type' => Payment::class, 'auditable_id' => $paymentId]);
    }

    public function test_paid_and_cancelled_bills_are_rejected(): void
    {
        $paid = $this->bill();
        $paid->update(['status' => 'PAID', 'paid_amount' => $paid->amount]);
        $this->pay($this->payload())->assertUnprocessable()->assertJsonValidationErrors('bill_ids');

        $cancelled = $this->bill('CLEANING');
        $cancelled->update(['status' => 'CANCELLED']);
        $this->pay($this->payload(['bill_ids' => [$cancelled->id]]))->assertUnprocessable()->assertJsonValidationErrors('bill_ids');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_second_payment_for_same_bill_is_rejected(): void
    {
        $this->pay($this->payload())->assertCreated();
        $this->pay($this->payload())->assertUnprocessable()->assertJsonValidationErrors('bill_ids');
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_client_amount_and_other_calculated_fields_are_rejected(): void
    {
        foreach (['amount', 'paid_amount', 'advance_amount', 'allocations', 'house_id', 'household_id', 'payer_resident_id'] as $field) {
            $this->pay($this->payload([$field => $field === 'allocations' ? [] : 1]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_transfer_requires_proof_and_exact_proof_total(): void
    {
        $this->pay($this->payload(['payment_method' => 'TRANSFER']))->assertUnprocessable()->assertJsonValidationErrors('proofs');
        $this->pay($this->payload(['payment_method' => 'TRANSFER', 'proofs' => [['file' => UploadedFile::fake()->image('proof.jpg'), 'transfer_amount' => 1]]]))
            ->assertUnprocessable()->assertJsonValidationErrors('proofs');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_cash_accepts_no_proof(): void
    {
        $this->pay($this->payload())->assertCreated();
        $this->assertDatabaseCount('payment_proofs', 0);
    }

    public function test_multiple_transfer_proofs_are_stored_privately_and_paths_are_not_exposed(): void
    {
        Storage::fake('local');
        $response = $this->pay($this->payload(['payment_method' => 'TRANSFER', 'proofs' => [
            ['file' => UploadedFile::fake()->image('one.jpg'), 'transfer_amount' => 40000],
            ['file' => UploadedFile::fake()->create('two.pdf', 10, 'application/pdf'), 'transfer_amount' => 60000],
        ]]))->assertCreated()->assertJsonCount(2, 'proofs');

        $this->assertDatabaseCount('payment_proofs', 2);
        foreach (Payment::findOrFail($response->json('id'))->proofs as $proof) Storage::disk('local')->assertExists($proof->storage_path);
        $this->assertStringNotContainsString('storage_path', $response->getContent());
    }

    public function test_mixed_valid_and_invalid_bills_roll_back_everything(): void
    {
        Storage::fake('local');
        $invalid = $this->bill('CLEANING');
        $invalid->update(['status' => 'PAID', 'paid_amount' => $invalid->amount]);
        $valid = $this->bill();

        $this->pay($this->payload(['bill_ids' => [$valid->id, $invalid->id], 'payment_method' => 'TRANSFER', 'proofs' => [[
            'file' => UploadedFile::fake()->image('rollback.jpg'), 'transfer_amount' => 115000,
        ]]]))->assertUnprocessable()->assertJsonValidationErrors('bill_ids');

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertDatabaseCount('payment_proofs', 0);
        $this->assertSame('UNPAID', $valid->fresh()->status);
        $this->assertSame(0, AuditLog::where('action', 'payment.created')->count());
        Storage::disk('local')->assertDirectoryEmpty('payment-proofs');
    }

    public function test_replacement_prefill_is_authoritative_and_replacement_uses_existing_route_contract(): void
    {
        $bill = $this->bill();
        $oldId = $this->pay($this->payload())->assertCreated()->json('id');
        $this->actingAs($this->cashier)->postJson("/api/v1/payments/{$oldId}/cancel", ['cancel_reason' => 'Salah pencatatan'])->assertOk();
        $this->actingAs($this->cashier)->getJson("/api/v1/payments/{$oldId}/replacement-prefill")->assertOk()->assertJsonPath('replaces_payment_id', $oldId)->assertJsonPath('transaction_number', Payment::findOrFail($oldId)->transaction_number)->assertJsonPath('bill_ids.0', $bill->id);
        $newId = $this->actingAs($this->cashier)->postJson("/api/v1/payments/{$oldId}/replacement", $this->payload())->assertCreated()->json('id');
        $this->assertDatabaseHas('payments', ['id' => $newId, 'replaces_payment_id' => $oldId]);
        $this->actingAs($this->cashier)->getJson("/api/v1/payments/{$oldId}/replacement-prefill")->assertUnprocessable()->assertJsonValidationErrors('payment');
    }

    public function test_replacement_prefill_rejects_active_payment_and_requires_permission(): void
    {
        $oldId = $this->pay($this->payload())->assertCreated()->json('id');
        $this->actingAs($this->cashier)->getJson("/api/v1/payments/{$oldId}/replacement-prefill")->assertUnprocessable()->assertJsonValidationErrors('payment');
        $this->actingAs(User::factory()->create())->getJson("/api/v1/payments/{$oldId}/replacement-prefill")->assertForbidden();
    }
}
