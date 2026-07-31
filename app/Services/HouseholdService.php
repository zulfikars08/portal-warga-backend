<?php

namespace App\Services;

use App\Models\{AuditLog,House,Household,HouseholdMember,Resident};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HouseholdService
{
    public function create(array $data): Household
    {
        return DB::transaction(function () use ($data) {
            $house = House::lockForUpdate()->findOrFail($data['house_id']);
            [$head, $members] = $this->validateResidents($data);
            $this->validateDates($data);

            if (Household::where('house_id', $house->id)->where('active', true)->whereNull('ended_at')->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['house_id' => 'Rumah sudah memiliki household aktif.']);
            }

            $household = Household::create(collect($data)->except('member_ids')->all() + ['active' => true]);
            HouseholdMember::create(['household_id' => $household->id, 'resident_id' => $head->id, 'member_role' => 'HEAD', 'joined_at' => $data['started_at'], 'active' => true]);
            foreach ($members as $member) {
                HouseholdMember::create(['household_id' => $household->id, 'resident_id' => $member->id, 'member_role' => 'MEMBER', 'joined_at' => $data['started_at'], 'active' => true]);
            }

            return $household->load(['house', 'head', 'members.resident']);
        });
    }

    public function close(Household $household, string $endedAt): Household
    {
        return DB::transaction(function () use ($household, $endedAt) {
            $household = Household::lockForUpdate()->findOrFail($household->id);
            if (!$household->active) {
                throw ValidationException::withMessages(['household' => 'Household sudah ditutup.']);
            }
            $household->update(['active' => false, 'ended_at' => $endedAt]);
            $household->members()->where('active', true)->update(['active' => false, 'left_at' => $endedAt]);
            return $household->fresh(['members']);
        });
    }

    public function replace(int $houseId, string $endedAt, array $new): Household
    {
        return DB::transaction(function () use ($houseId, $endedAt, $new) {
            $new['house_id'] = $houseId;
            $house = House::lockForUpdate()->findOrFail($houseId);
            $old = Household::where('house_id', $house->id)->where('active', true)->whereNull('ended_at')->lockForUpdate()->first();
            if (!$old) {
                throw ValidationException::withMessages(['house' => 'Rumah tidak memiliki household aktif.']);
            }
            if ($endedAt < $old->started_at->toDateString()) {
                throw ValidationException::withMessages(['previous_ended_at' => 'Tanggal berakhir household lama tidak boleh sebelum tanggal mulainya.']);
            }
            Resident::whereIn('id', array_merge([$new['head_resident_id']], $new['member_ids'] ?? []))->lockForUpdate()->get();
            $this->validateResidents($new, $old->id);
            $this->validateDates($new);
            if ($endedAt > $new['started_at']) {
                throw ValidationException::withMessages(['started_at' => 'Tanggal mulai household baru tidak boleh sebelum tanggal berakhir household lama.']);
            }
            $this->close($old, $endedAt);
            $created = $this->create($new);
            AuditLog::create(['user_id' => auth()->id(), 'action' => 'household.replaced', 'auditable_type' => Household::class, 'auditable_id' => $created->id, 'old_values' => ['household_id' => $old->id, 'ended_at' => $endedAt], 'new_values' => ['household_id' => $created->id, 'head_resident_id' => $created->head_resident_id], 'ip' => request()->ip()]);
            return $created;
        });
    }

    private function validateResidents(array $data, ?int $replacedHouseholdId = null): array
    {
        $head = Resident::with('documents')->findOrFail($data['head_resident_id']);
        if (!$head->active) {
            throw ValidationException::withMessages(['head_resident_id' => "Penghuni yang tidak aktif tidak dapat dimasukkan ke dalam rumah. Kepala keluarga: {$head->full_name} (ID {$head->id})."]);
        }
        if (!$head->documents->contains('document_type', 'KTP')) {
            throw ValidationException::withMessages(['head_resident_id' => 'Kepala keluarga wajib memiliki dokumen KTP.']);
        }
        if (!$head->documents->contains('document_type', 'KK')) {
            throw ValidationException::withMessages(['head_resident_id' => 'Kepala keluarga wajib memiliki dokumen KK.']);
        }

        $memberIds = array_values(array_unique($data['member_ids'] ?? []));
        if (in_array($head->id, $memberIds, true)) {
            throw ValidationException::withMessages(['member_ids' => 'Kepala keluarga tidak boleh diduplikasi sebagai anggota.']);
        }

        $members = Resident::with('documents')->whereIn('id', $memberIds)->get()->keyBy('id');
        foreach ($memberIds as $id) {
            $member = $members->get($id);
            if (!$member) {
                throw ValidationException::withMessages(['member_ids' => "Penghuni ID {$id} tidak ditemukan."]);
            }
            if (!$member->active) {
                throw ValidationException::withMessages(['member_ids' => "Penghuni yang tidak aktif tidak dapat dimasukkan ke dalam rumah. Anggota: {$member->full_name} (ID {$member->id})."]);
            }
            if (!$member->documents->contains('document_type', 'KTP')) {
                throw ValidationException::withMessages(['member_ids' => "Anggota keluarga wajib memiliki dokumen KTP. Anggota: {$member->full_name} (ID {$member->id})."]);
            }
            $memberElsewhere = HouseholdMember::where('resident_id', $member->id)->where('member_role', 'MEMBER')->where('active', true)
                ->when($replacedHouseholdId, fn ($query) => $query->where('household_id', '!=', $replacedHouseholdId))->lockForUpdate()->exists();
            if ($memberElsewhere) {
                throw ValidationException::withMessages(['member_ids' => "Anggota sudah aktif pada household lain. Anggota: {$member->full_name} (ID {$member->id})."]);
            }
        }

        return [$head, $members->values()];
    }

    private function validateDates(array $data): void
    {
        if ($data['occupancy_type'] === 'CONTRACT' && (empty($data['contract_started_at']) || empty($data['contract_ended_at']))) {
            throw ValidationException::withMessages(['contract_ended_at' => 'Hunian kontrak wajib memiliki tanggal mulai dan selesai kontrak.']);
        }
        if ($data['occupancy_type'] === 'PERMANENT' && (!empty($data['contract_started_at']) || !empty($data['contract_ended_at']))) {
            throw ValidationException::withMessages(['occupancy_type' => 'Hunian tetap tidak memakai tanggal kontrak.']);
        }
    }
}
