<?php

namespace Tests\Feature;

use App\Models\{Bill, House, PrivateDocument, Resident, User};
use App\Services\{HouseholdService, MonthlyBillService};
use Database\Seeders\InitialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PaymentDetailTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(InitialSeeder::class);
        $this->admin = User::where('email', 'superadmin@portalwarga.test')->firstOrFail();
        $head = Resident::create(['full_name' => 'Detail Head', 'phone' => 'secret-phone', 'marital_status' => 'MENIKAH', 'active' => true]);
        foreach (['KTP', 'KK'] as $type) PrivateDocument::create(['resident_id' => $head->id, 'document_type' => $type, 'storage_path' => "private/$type", 'original_name' => "$type.pdf", 'mime_type' => 'application/pdf', 'size_bytes' => 1]);
        app(HouseholdService::class)->create(['house_id' => House::firstOrFail()->id, 'head_resident_id' => $head->id, 'occupancy_type' => 'PERMANENT', 'started_at' => '2026-01-01']);
        app(MonthlyBillService::class)->generate('2026-04-01');
    }

    private function create(array $extra = []): int
    {
        $bill = Bill::where('status', 'UNPAID')->firstOrFail();
        return $this->actingAs($this->admin)->post('/api/v1/payments', array_replace([
            'bill_ids' => [$bill->id], 'payment_method' => 'CASH', 'paid_at' => '2026-04-10 10:00:00',
        ], $extra), ['Accept' => 'application/json'])->assertCreated()->json('id');
    }

    public function test_detail_requires_authentication_and_view_permission(): void
    {
        $this->getJson('/api/v1/payments/999')->assertUnauthorized();
        $id = $this->create();
        $this->actingAs(User::factory()->create())->getJson("/api/v1/payments/$id")->assertForbidden();
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(Permission::findByName('payments.view', 'web'));
        $this->actingAs($viewer)->getJson("/api/v1/payments/$id")->assertOk();
    }

    public function test_cash_detail_has_explicit_safe_projection_and_live_paid_bill(): void
    {
        $id = $this->create(['note' => 'cash note']);
        $response = $this->actingAs($this->admin)->getJson("/api/v1/payments/$id")->assertOk()
            ->assertJsonPath('data.payment_method', 'CASH')->assertJsonPath('data.status', 'POSTED')
            ->assertJsonPath('data.allocations.0.bill.status', 'PAID')->assertJsonPath('data.allocations.0.bill.paid_amount', 100000)
            ->assertJsonPath('data.payer.full_name', 'Detail Head')->assertJsonCount(0, 'data.proofs');

        $json = $response->json('data');
        $this->assertSame(['id','transaction_number','payment_method','amount','paid_at','status','note','cancelled_at','cancel_reason','house','household','payer','creator','canceller','replacement','replaced_payment','allocations','proofs','created_at','updated_at'], array_keys($json));
        $this->assertSame(['id','full_name'], array_keys($json['payer']));
        $this->assertArrayNotHasKey('phone', $json['payer']);
        $this->assertSame(['id','amount','bill'], array_keys($json['allocations'][0]));
    }

    public function test_transfer_proof_exposes_safe_metadata_and_download_url_not_storage_path(): void
    {
        Storage::fake('local');
        $bill = Bill::where('status', 'UNPAID')->firstOrFail();
        $id = $this->create(['payment_method' => 'TRANSFER', 'proofs' => [[
            'file' => UploadedFile::fake()->image('proof.jpg'), 'transfer_amount' => $bill->amount, 'metadata' => ['bank' => 'ABC'],
        ]]]);
        $response = $this->actingAs($this->admin)->getJson("/api/v1/payments/$id")->assertOk()
            ->assertJsonPath('data.proofs.0.original_name', 'proof.jpg')
            ->assertJsonPath('data.proofs.0.download_url', url('/api/v1/payment-proofs/1/download'));
        $this->assertSame(['id','original_name','mime_type','size_bytes','transfer_amount','download_url'], array_keys($response->json('data.proofs.0')));
        $this->assertStringNotContainsString('storage_path', $response->getContent());
        $this->assertStringNotContainsString('payment-proofs/', $response->getContent());
    }

    public function test_cancelled_detail_and_replacement_links_are_projected_safely(): void
    {
        $original = $this->create();
        $this->actingAs($this->admin)->postJson("/api/v1/payments/$original/cancel", ['cancel_reason' => 'wrong entry'])->assertOk();
        $bill = Bill::where('status', 'UNPAID')->firstOrFail();
        $replacement = $this->actingAs($this->admin)->postJson("/api/v1/payments/$original/replacement", [
            'bill_ids' => [$bill->id], 'payment_method' => 'CASH', 'paid_at' => '2026-04-11 10:00:00',
        ])->assertCreated()->json('id');

        $this->actingAs($this->admin)->getJson("/api/v1/payments/$original")->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED')->assertJsonPath('data.cancel_reason', 'wrong entry')
            ->assertJsonPath('data.canceller.id', $this->admin->id)->assertJsonPath('data.replacement.id', $replacement);
        $this->actingAs($this->admin)->getJson("/api/v1/payments/$replacement")->assertOk()
            ->assertJsonPath('data.replaced_payment.id', $original)->assertJsonPath('data.allocations.0.bill.status', 'PAID');
    }
}
