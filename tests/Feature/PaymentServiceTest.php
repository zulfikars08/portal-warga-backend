<?php

namespace Tests\Feature;

use App\Models\{AuditLog,Bill,FeeRate,House,Payment,PrivateDocument,Resident};
use App\Services\{FeeRateService,HouseholdService,MonthlyBillService,PaymentService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    private PaymentService $payments;
    private House $house;

    protected function setUp(): void
    {
        parent::setUp();
        $this->payments = app(PaymentService::class);
        $this->house = House::create(['block_code' => 'A', 'house_number' => '1']);
        $head = Resident::create(['full_name' => 'Kepala', 'marital_status' => 'MENIKAH', 'active' => true]);
        foreach (['KTP', 'KK'] as $type) PrivateDocument::create(['resident_id' => $head->id, 'document_type' => $type, 'storage_path' => "$type/1", 'original_name' => "$type.pdf", 'mime_type' => 'application/pdf', 'size_bytes' => 1]);
        app(HouseholdService::class)->create(['house_id' => $this->house->id, 'head_resident_id' => $head->id, 'occupancy_type' => 'PERMANENT', 'started_at' => '2026-01-01']);
        $rates = app(FeeRateService::class);
        foreach (['SECURITY' => 100000, 'CLEANING' => 15000] as $code => $amount) $rates->create(['fee_code' => $code, 'name' => $code, 'amount' => $amount, 'effective_from' => '2026-01-01', 'active' => true]);
        app(MonthlyBillService::class)->generate('2026-04-01');
    }

    private function bill(string $code): Bill { return Bill::where('fee_code', $code)->firstOrFail(); }
    private function data(array $extra = []): array { return array_replace(['bill_ids' => [$this->bill('SECURITY')->id], 'payment_method' => 'CASH', 'paid_at' => '2026-04-10 10:00:00'], $extra); }
    private function invalid(string $message, callable $call): void
    {
        try { $call(); $this->fail('ValidationException tidak dilempar.'); }
        catch (ValidationException $e) { $this->assertStringContainsString($message, collect($e->errors())->flatten()->implode(' ')); }
    }

    public function test_cash_payment_posts_backend_total_allocations_bill_and_audit(): void
    {
        $payment = $this->payments->create($this->data(['bill_ids' => Bill::pluck('id')->all()]), null);
        $this->assertSame(115000, $payment->amount);
        $this->assertSame(2, $payment->allocations->count());
        $this->assertSame(2, Bill::where('status', 'PAID')->whereColumn('paid_amount', 'amount')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.created', 'auditable_id' => $payment->id]);
    }

    public function test_transfer_requires_proof_and_exact_total(): void
    {
        $data = $this->data(['payment_method' => 'TRANSFER']);
        $this->invalid('minimal satu bukti', fn() => $this->payments->create($data, null));
        $this->invalid('sama dengan total pembayaran', fn() => $this->payments->create($data + ['proofs' => [['file' => UploadedFile::fake()->image('proof.jpg'), 'transfer_amount' => 999]]], null));
        $this->assertSame(0, Payment::count());
        $this->assertSame('UNPAID', $this->bill('SECURITY')->fresh()->status);
    }

    public function test_valid_transfer_stores_proof(): void
    {
        Storage::fake('local');
        $payment = $this->payments->create($this->data(['payment_method' => 'TRANSFER', 'proofs' => [['file' => UploadedFile::fake()->image('proof.jpg'), 'transfer_amount' => 100000]]]), null);
        $proof = $payment->proofs->first();
        Storage::disk('local')->assertExists($proof->storage_path);
        $this->assertSame(100000, $proof->transfer_amount);
    }

    public function test_rejects_duplicate_payment_and_client_calculated_amount(): void
    {
        $this->payments->create($this->data(), null);
        $this->invalid('sudah lunas', fn() => $this->payments->create($this->data(), null));
        $this->invalid('dihitung oleh backend', fn() => $this->payments->create($this->data(['amount' => 1]), null));
        $this->assertSame(1, Payment::count());
    }

    public function test_cancel_reopens_bills_and_cannot_repeat(): void
    {
        $payment = $this->payments->create($this->data(), null);
        $cancelled = $this->payments->cancel($payment, 'Salah input', null);
        $this->assertSame('CANCELLED', $cancelled->status);
        $this->assertSame('UNPAID', $this->bill('SECURITY')->fresh()->status);
        $this->assertSame(0, $this->bill('SECURITY')->fresh()->paid_amount);
        $this->assertSame(1, AuditLog::where('action', 'payment.cancelled')->count());
        $this->invalid('sudah dibatalkan', fn() => $this->payments->cancel($payment, 'Lagi', null));
    }

    public function test_cancelled_payment_can_be_replaced_once(): void
    {
        $original = $this->payments->create($this->data(), null);
        $this->payments->cancel($original, 'Koreksi', null);
        $replacement = $this->payments->create($this->data(), null, $original);
        $this->assertSame($original->id, $replacement->replaces_payment_id);
        $this->invalid('sudah memiliki pembayaran pengganti', fn() => $this->payments->create($this->data(), null, $original));
    }

    public function test_future_cleaning_bill_is_created_and_paid_at_current_rate(): void
    {
        $payment = $this->payments->create($this->data(['bill_ids' => [$this->bill('CLEANING')->id], 'future_cleaning_periods' => ['2026-05-01', '2026-06-01']]), null);
        $this->assertSame(45000, $payment->amount);
        $this->assertSame(3, $payment->allocations->count());
        $this->assertSame(3, Bill::where('fee_code', 'CLEANING')->where('status', 'PAID')->count());
    }

    public function test_security_periods_cannot_be_combined(): void
    {
        $bill = $this->bill('SECURITY');
        $second = $bill->replicate(['period', 'due_date']);
        $second->period = '2026-05-01'; $second->due_date = '2026-05-07'; $second->save();
        $this->invalid('satu periode', fn() => $this->payments->create($this->data(['bill_ids' => [$bill->id, $second->id]]), null));
    }
}