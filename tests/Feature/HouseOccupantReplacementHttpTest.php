<?php

namespace Tests\Feature;

use App\Models\{AuditLog, Bill, House, HouseholdMember, PrivateDocument, Resident, User};
use App\Services\HouseholdService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class HouseOccupantReplacementHttpTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private HouseholdService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(HouseholdService::class);
        Permission::create(['name' => 'houses.manage_occupants', 'guard_name' => 'web']);
        $this->user = User::factory()->create();
        $this->user->givePermissionTo('houses.manage_occupants');
    }

    private function house(string $number = '1'): House { return House::create(['block_code' => 'A', 'house_number' => $number]); }
    private function resident(string $name, array $docs = ['KTP', 'KK'], bool $active = true): Resident
    {
        $resident = Resident::create(['full_name' => $name, 'marital_status' => 'MENIKAH', 'active' => $active]);
        foreach ($docs as $type) PrivateDocument::create(['resident_id' => $resident->id, 'document_type' => $type, 'storage_path' => "$type/{$resident->id}", 'original_name' => "$type.pdf", 'mime_type' => 'application/pdf', 'size_bytes' => 1]);
        return $resident;
    }
    private function occupied(?House $house = null, ?Resident $head = null, array $members = []): array
    {
        $house ??= $this->house(); $head ??= $this->resident('Old Head');
        $household = $this->service->create(['house_id' => $house->id, 'head_resident_id' => $head->id, 'member_ids' => array_map(fn ($r) => $r->id, $members), 'occupancy_type' => 'PERMANENT', 'started_at' => '2026-01-01']);
        return [$house, $household, $head];
    }
    private function payload(Resident $head, array $extra = []): array
    {
        return $extra + ['previous_ended_at' => '2026-02-01', 'head_resident_id' => $head->id, 'member_ids' => [], 'occupancy_type' => 'PERMANENT', 'started_at' => '2026-02-01', 'contract_started_at' => null, 'contract_ended_at' => null];
    }
    private function replacementContext(House $house) { return $this->actingAs($this->user)->getJson("/api/v1/houses/{$house->id}/occupant-replacement"); }
    private function replaceOccupants(House $house, array $payload) { return $this->actingAs($this->user)->postJson("/api/v1/houses/{$house->id}/replace-occupants", $payload); }

    public function test_routes_require_authentication(): void { [$house] = $this->occupied(); $this->getJson("/api/v1/houses/{$house->id}/occupant-replacement")->assertUnauthorized(); $this->postJson("/api/v1/houses/{$house->id}/replace-occupants", [])->assertUnauthorized(); }
    public function test_routes_require_manage_permission(): void { [$house] = $this->occupied(); $other = User::factory()->create(); $this->actingAs($other)->getJson("/api/v1/houses/{$house->id}/occupant-replacement")->assertForbidden(); $this->actingAs($other)->postJson("/api/v1/houses/{$house->id}/replace-occupants", [])->assertForbidden(); }
    public function test_context_returns_409_without_active_household(): void { $this->replacementContext($this->house())->assertStatus(409)->assertExactJson(['message' => 'Rumah tidak memiliki household aktif.']); }
    public function test_post_returns_409_without_active_household(): void { $this->replaceOccupants($this->house(), $this->payload($this->resident('New')))->assertStatus(409)->assertExactJson(['message' => 'Rumah tidak memiliki household aktif.']); }
    public function test_context_has_safe_exact_resident_projection(): void { [$house] = $this->occupied(); $response = $this->replacementContext($house)->assertOk(); $this->assertSame(['id', 'full_name'], array_keys($response->json('data.head_candidates.0'))); $this->assertArrayNotHasKey('nik', $response->json('data.head_candidates.0')); }
    public function test_context_includes_exact_house_and_candidate_contract(): void { [$house, $old, $head] = $this->occupied(); $response = $this->replacementContext($house)->assertJsonPath('data.current_household.id', $old->id)->assertJsonPath('data.current_household.head.id', $head->id); $this->assertSame(['id', 'block_code', 'house_number', 'house_code', 'occupancy_status'], array_keys($response->json('data.house'))); $this->assertSame(['house', 'current_household', 'head_candidates', 'member_candidates'], array_keys($response->json('data'))); }
    public function test_only_active_documented_heads_are_eligible(): void { [$house] = $this->occupied(); $yes = $this->resident('Eligible'); $this->resident('No KTP', ['KK']); $this->resident('No KK', ['KTP']); $this->resident('Inactive', ['KTP', 'KK'], false); $ids = collect($this->replacementContext($house)->json('data.head_candidates'))->pluck('id'); $this->assertTrue($ids->contains($yes->id)); $this->assertCount(2, $ids); }
    public function test_head_of_another_house_remains_eligible_head(): void { [$house] = $this->occupied(); $shared = $this->resident('Shared'); $this->occupied($this->house('2'), $shared); $this->assertContains($shared->id, collect($this->replacementContext($house)->json('data.head_candidates'))->pluck('id')->all()); }
    public function test_only_active_ktp_members_are_eligible(): void { [$house] = $this->occupied(); $yes = $this->resident('Eligible Member', ['KTP']); $this->resident('No KTP', ['KK']); $this->resident('Inactive', ['KTP'], false); $ids = collect($this->replacementContext($house)->json('data.member_candidates'))->pluck('id'); $this->assertContains($yes->id, $ids->all()); }
    public function test_member_active_elsewhere_is_not_eligible(): void { $busy = $this->resident('Busy', ['KTP']); $this->occupied($this->house('2'), null, [$busy]); [$house] = $this->occupied(); $this->assertNotContains($busy->id, collect($this->replacementContext($house)->json('data.member_candidates'))->pluck('id')->all()); }
    public function test_current_members_remain_eligible(): void { $member = $this->resident('Current', ['KTP']); [$house] = $this->occupied(null, null, [$member]); $this->assertContains($member->id, collect($this->replacementContext($house)->json('data.member_candidates'))->pluck('id')->all()); }
    public function test_success_returns_exact_message_and_loaded_relations(): void { [$house] = $this->occupied(); $head = $this->resident('New'); $member = $this->resident('Member', ['KTP']); $this->replaceOccupants($house, $this->payload($head, ['member_ids' => [$member->id]]))->assertCreated()->assertJsonPath('message', 'Penghuni rumah berhasil diganti.')->assertJsonPath('data.house.id', $house->id)->assertJsonPath('data.head.id', $head->id)->assertJsonPath('data.members.1.resident.id', $member->id); }
    public function test_success_closes_old_household_and_members(): void { $oldMember = $this->resident('Old Member', ['KTP']); [$house, $old] = $this->occupied(null, null, [$oldMember]); $this->replaceOccupants($house, $this->payload($this->resident('New')))->assertCreated(); $this->assertFalse($old->fresh()->active); $this->assertSame('2026-02-01', $old->fresh()->ended_at->toDateString()); $this->assertFalse($old->members()->first()->fresh()->active); }
    public function test_success_preserves_old_bill_and_history(): void { [$house, $old, $head] = $this->occupied(); $bill = Bill::create(['house_id' => $house->id, 'household_id' => $old->id, 'fee_code' => 'X', 'responsible_head_resident_id' => $head->id, 'house_code_snapshot' => $house->house_code, 'responsible_head_name_snapshot' => $head->full_name, 'fee_name_snapshot' => 'X', 'amount_snapshot' => 1, 'type' => 'routine', 'title' => 'X', 'period' => '2026-01-01', 'due_date' => '2026-01-01', 'amount' => 1]); $this->replaceOccupants($house, $this->payload($this->resident('New')))->assertCreated(); $this->assertSame($old->id, $bill->fresh()->household_id); $this->assertSame(2, $house->households()->count()); }
    public function test_success_writes_audit(): void { [$house] = $this->occupied(); $this->replaceOccupants($house, $this->payload($this->resident('New')))->assertCreated(); $this->assertDatabaseHas('audit_logs', ['action' => 'household.replaced', 'user_id' => $this->user->id]); }
    public function test_head_may_lead_multiple_active_houses(): void { [$house] = $this->occupied(); $head = $this->resident('Shared'); $this->occupied($this->house('2'), $head); $this->replaceOccupants($house, $this->payload($head))->assertCreated(); $this->assertSame(2, $head->headedHouseholds()->where('active', true)->count()); }
    public function test_current_member_can_remain_member(): void { $member = $this->resident('Stay', ['KTP']); [$house] = $this->occupied(null, null, [$member]); $this->replaceOccupants($house, $this->payload($this->resident('New'), ['member_ids' => [$member->id]]))->assertCreated(); $this->assertSame(1, HouseholdMember::where('resident_id', $member->id)->where('active', true)->count()); }
    public function test_member_active_elsewhere_is_rejected_atomically(): void { $member = $this->resident('Busy', ['KTP']); $this->occupied($this->house('2'), null, [$member]); [$house, $old] = $this->occupied(); $this->replaceOccupants($house, $this->payload($this->resident('New'), ['member_ids' => [$member->id]]))->assertUnprocessable(); $this->assertTrue($old->fresh()->active); $this->assertSame(1, $house->households()->count()); }

    public static function invalidPayloads(): array
    {
        return [
            'missing previous end' => ['previous_ended_at', null], 'bad previous end' => ['previous_ended_at', 'bad'],
            'missing head' => ['head_resident_id', null], 'missing members' => ['member_ids', null], 'members not array' => ['member_ids', 1],
            'bad occupancy' => ['occupancy_type', 'HOTEL'], 'missing start' => ['started_at', null], 'bad start' => ['started_at', 'bad'],
            'chronology' => ['started_at', '2026-01-31'],
        ];
    }
    public function test_invalid_payload_returns_422(): void { foreach (self::invalidPayloads() as [$field, $value]) { [$house] = $this->occupied($this->house((string)(House::count() + 1))); $payload = $this->payload($this->resident('New '.Resident::count())); if ($value === null) unset($payload[$field]); else $payload[$field] = $value; $this->replaceOccupants($house, $payload)->assertUnprocessable()->assertJsonValidationErrors($field); } }
    public function test_head_cannot_also_be_member(): void { [$house] = $this->occupied(); $head = $this->resident('New'); $this->replaceOccupants($house, $this->payload($head, ['member_ids' => [$head->id]]))->assertUnprocessable()->assertJsonValidationErrors('member_ids.0'); }
    public function test_duplicate_members_are_rejected(): void { [$house] = $this->occupied(); $head = $this->resident('New'); $member = $this->resident('Member', ['KTP']); $this->replaceOccupants($house, $this->payload($head, ['member_ids' => [$member->id, $member->id]]))->assertUnprocessable()->assertJsonValidationErrors('member_ids.1'); }
    public function test_contract_dates_are_required_for_contract(): void { [$house] = $this->occupied(); $this->replaceOccupants($house, $this->payload($this->resident('New'), ['occupancy_type' => 'CONTRACT']))->assertUnprocessable()->assertJsonValidationErrors(['contract_started_at', 'contract_ended_at']); }
    public function test_contract_end_must_not_precede_contract_start(): void { [$house] = $this->occupied(); $this->replaceOccupants($house, $this->payload($this->resident('New'), ['occupancy_type' => 'CONTRACT', 'contract_started_at' => '2026-02-02', 'contract_ended_at' => '2026-02-01']))->assertUnprocessable()->assertJsonValidationErrors('contract_ended_at'); }
    public function test_contract_start_must_not_precede_new_household_start(): void { [$house] = $this->occupied(); $this->replaceOccupants($house, $this->payload($this->resident('New'), ['occupancy_type' => 'CONTRACT', 'contract_started_at' => '2026-01-31', 'contract_ended_at' => '2026-03-01']))->assertUnprocessable()->assertJsonValidationErrors('contract_started_at'); }
    public function test_previous_end_must_not_precede_old_household_start_atomically(): void { [$house, $old] = $this->occupied(); $this->replaceOccupants($house, $this->payload($this->resident('New'), ['previous_ended_at' => '2025-12-31', 'started_at' => '2026-02-01']))->assertUnprocessable()->assertJsonValidationErrors('previous_ended_at'); $this->assertTrue($old->fresh()->active); $this->assertSame(1, $house->households()->count()); }
    public function test_permanent_prohibits_contract_dates(): void { [$house] = $this->occupied(); $this->replaceOccupants($house, $this->payload($this->resident('New'), ['contract_started_at' => '2026-02-01']))->assertUnprocessable()->assertJsonValidationErrors('contract_started_at'); }
    public function test_inactive_or_undocumented_candidates_are_rejected(): void { [$house, $old] = $this->occupied(); $this->replaceOccupants($house, $this->payload($this->resident('Bad', [], false)))->assertUnprocessable(); $this->assertTrue($old->fresh()->active); }
}
