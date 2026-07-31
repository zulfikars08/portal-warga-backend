<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReplaceHouseOccupantsRequest;
use App\Models\{House, HouseholdMember, Resident};
use App\Services\HouseholdService;
use Illuminate\Http\JsonResponse;

class HouseOccupantReplacementController extends Controller
{
    public function context(House $house): JsonResponse
    {
        $old = $house->activeHousehold()->with(['head:id,full_name', 'members.resident:id,full_name'])->first();
        if (!$old) {
            return response()->json(['message' => 'Rumah tidak memiliki household aktif.'], 409);
        }

        $residentProjection = fn (Resident $resident) => ['id' => $resident->id, 'full_name' => $resident->full_name];
        $heads = Resident::query()->select('residents.id', 'residents.full_name')->where('active', true)
            ->whereHas('documents', fn ($q) => $q->where('document_type', 'KTP'))
            ->whereHas('documents', fn ($q) => $q->where('document_type', 'KK'))
            ->orderBy('full_name')->get()->map($residentProjection)->values();
        $members = Resident::query()->select('residents.id', 'residents.full_name')->where('active', true)
            ->whereHas('documents', fn ($q) => $q->where('document_type', 'KTP'))
            ->whereDoesntHave('householdMemberships', fn ($q) => $q->where('active', true)->where('member_role', 'MEMBER')->where('household_id', '!=', $old->id))
            ->orderBy('full_name')->get()->map($residentProjection)->values();

        return response()->json(['data' => [
            'house' => [
                'id' => $house->id,
                'block_code' => $house->block_code,
                'house_number' => $house->house_number,
                'house_code' => $house->house_code,
                'occupancy_status' => $house->occupancy_status,
            ],
            'current_household' => [
                'id' => $old->id,
                'head' => $residentProjection($old->head),
                'members' => $old->members->where('member_role', 'MEMBER')->where('active', true)->map(fn (HouseholdMember $membership) => $residentProjection($membership->resident))->values(),
                'occupancy_type' => $old->occupancy_type,
                'started_at' => $old->started_at?->toDateString(),
                'contract_started_at' => $old->contract_started_at?->toDateString(),
                'contract_ended_at' => $old->contract_ended_at?->toDateString(),
            ],
            'head_candidates' => $heads,
            'member_candidates' => $members,
        ]]);
    }

    public function replace(ReplaceHouseOccupantsRequest $request, House $house, HouseholdService $service): JsonResponse
    {
        if (!$house->activeHousehold()->exists()) {
            return response()->json(['message' => 'Rumah tidak memiliki household aktif.'], 409);
        }
        $data = $request->validated();
        $endedAt = $data['previous_ended_at'];
        unset($data['previous_ended_at']);
        $household = $service->replace($house->id, $endedAt, $data);

        return response()->json(['message' => 'Penghuni rumah berhasil diganti.', 'data' => $household], 201);
    }
}
