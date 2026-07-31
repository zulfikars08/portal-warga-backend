<?php

namespace Tests\Feature;

use App\Models\{House, Household, Resident, SpecialBill, User};
use App\Notifications\SpecialBillApprovalRequired;
use App\Services\SpecialBillService;
use Database\Seeders\InitialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
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

    private function createSpecialBill(): SpecialBill
    {
        return app(SpecialBillService::class)->create([
            'title' => 'Iuran khusus', 'description' => 'Keperluan warga', 'amount' => 125000,
            'due_date' => '2026-08-31', 'target_type' => 'ALL_OCCUPIED',
            'approval_document' => UploadedFile::fake()->create('approval.pdf', 10, 'application/pdf'),
        ], $this->admin->id);
    }

    private function notification(User $user): DatabaseNotification
    {
        return $user->notifications()->firstOrFail();
    }

    private function occupied(): void
    {
        $house = House::create(['block_code' => 'N', 'house_number' => '1', 'house_code' => 'N-1']);
        $resident = Resident::create(['full_name' => 'Kepala', 'marital_status' => 'MENIKAH', 'active' => true]);
        Household::create(['house_id' => $house->id, 'head_resident_id' => $resident->id, 'occupancy_type' => 'PERMANENT', 'started_at' => '2026-01-01', 'active' => true]);
    }

    public function test_01_notification_list_requires_authentication(): void
    {
        $this->getJson('/api/v1/notifications')->assertUnauthorized();
    }

    public function test_02_unread_count_requires_authentication(): void
    {
        $this->getJson('/api/v1/notifications/unread-count')->assertUnauthorized();
    }

    public function test_03_special_bill_creation_notifies_active_effective_approvers(): void
    {
        $direct = User::factory()->create();
        $direct->givePermissionTo('bills.approve_special');
        $this->createSpecialBill();
        $this->assertSame(1, $direct->notifications()->count());
        $this->assertSame(1, $this->admin->notifications()->count());
    }

    public function test_04_special_bill_creation_does_not_notify_users_without_permission(): void
    {
        $user = User::factory()->create();
        $this->createSpecialBill();
        $this->assertSame(0, $user->notifications()->count());
    }

    public function test_05_special_bill_creation_does_not_notify_inactive_approvers(): void
    {
        $user = User::factory()->create(['active' => false]);
        $user->givePermissionTo('bills.approve_special');
        $this->createSpecialBill();
        $this->assertSame(0, $user->notifications()->count());
    }

    public function test_06_notification_data_matches_contract(): void
    {
        $special = $this->createSpecialBill();
        $data = $this->notification($this->admin)->data;
        $this->assertSame('SPECIAL_BILL_APPROVAL_REQUIRED', $data['type']);
        $this->assertSame('Tagihan khusus menunggu persetujuan', $data['title']);
        $this->assertSame('Iuran khusus membutuhkan persetujuan sebelum diterbitkan.', $data['message']);
        $this->assertSame($special->id, $data['special_bill_id']);
        $this->assertSame($special->special_bill_number, $data['special_bill_number']);
        $this->assertSame(125000, $data['amount']);
        $this->assertSame('ALL_OCCUPIED', $data['target_type']);
        $this->assertSame(0, $data['target_count']);
        $this->assertSame($this->admin->id, $data['created_by']);
        $this->assertNotEmpty($data['created_at']);
        $this->assertSame("/tagihan-khusus/{$special->id}", $data['destination']);
    }

    public function test_07_notification_destination_is_relative(): void
    {
        $this->createSpecialBill();
        $this->assertStringStartsWith('/', $this->notification($this->admin)->data['destination']);
        $this->assertStringNotContainsString('://', $this->notification($this->admin)->data['destination']);
    }

    public function test_08_recipient_special_bill_and_type_are_unique(): void
    {
        $special = $this->createSpecialBill();
        $count = $this->admin->notifications()->where('type', SpecialBillApprovalRequired::class)->where('data->special_bill_id', $special->id)->count();
        $this->assertSame(1, $count);
    }

    public function test_09_notification_list_is_paginated_and_safe_json(): void
    {
        $this->createSpecialBill();
        $response = $this->actingAs($this->admin)->getJson('/api/v1/notifications?per_page=1')->assertOk();
        $response->assertJsonStructure(['data', 'current_page', 'per_page', 'total']);
        $this->assertIsArray($response->json('data.0.data'));
    }

    public function test_10_notification_list_rejects_unsafe_page_size(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/notifications?per_page=1000')->assertUnprocessable();
    }

    public function test_11_unread_count_returns_current_users_count(): void
    {
        $this->createSpecialBill();
        $this->actingAs($this->admin)->getJson('/api/v1/notifications/unread-count')->assertOk()->assertExactJson(['count' => 1]);
    }

    public function test_12_mark_one_as_read_marks_owned_notification(): void
    {
        $this->createSpecialBill();
        $notification = $this->notification($this->admin);
        $this->actingAs($this->admin)->postJson("/api/v1/notifications/{$notification->id}/read")->assertOk();
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_13_mark_one_as_read_requires_authentication(): void
    {
        $this->createSpecialBill();
        $this->postJson('/api/v1/notifications/'.$this->notification($this->admin)->id.'/read')->assertUnauthorized();
    }

    public function test_14_mark_one_as_read_enforces_ownership(): void
    {
        $this->createSpecialBill();
        $other = User::factory()->create();
        $this->actingAs($other)->postJson('/api/v1/notifications/'.$this->notification($this->admin)->id.'/read')->assertNotFound();
    }

    public function test_15_mark_all_as_read_only_marks_current_users_notifications(): void
    {
        $other = User::factory()->create();
        $other->givePermissionTo('bills.approve_special');
        $this->createSpecialBill();
        $this->actingAs($this->admin)->postJson('/api/v1/notifications/read-all')->assertOk();
        $this->assertSame(0, $this->admin->unreadNotifications()->count());
        $this->assertSame(1, $other->unreadNotifications()->count());
    }

    public function test_16_approval_marks_matching_notifications_read_for_all_recipients(): void
    {
        $other = User::factory()->create();
        $other->givePermissionTo('bills.approve_special');
        $special = $this->createSpecialBill();
        $this->occupied();
        app(SpecialBillService::class)->approve($special, $this->admin->id);
        $this->assertSame(0, $this->admin->unreadNotifications()->count());
        $this->assertSame(0, $other->unreadNotifications()->count());
    }

    public function test_17_cancellation_marks_matching_notifications_read_for_all_recipients(): void
    {
        $other = User::factory()->create();
        $other->givePermissionTo('bills.approve_special');
        $special = $this->createSpecialBill();
        app(SpecialBillService::class)->cancel($special, 'Batal', $this->admin->id);
        $this->assertSame(0, $this->admin->unreadNotifications()->count());
        $this->assertSame(0, $other->unreadNotifications()->count());
    }

    public function test_18_me_exposes_superadmin_approval_permission(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/me')->assertOk()->assertJsonFragment(['bills.approve_special']);
    }
}
