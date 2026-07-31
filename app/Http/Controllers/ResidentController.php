<?php

namespace App\Http\Controllers;

use App\Models\{Resident, PrivateDocument};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ResidentController extends Controller
{
    public function updatePersonal(Request $request, Resident $resident)
    {
        $input = $request->only(['full_name', 'nik', 'gender', 'birth_place', 'birth_date', 'marital_status', 'phone', 'email', 'address']);
        foreach ($input as $key => $value) if (is_string($value)) $input[$key] = trim($value) ?: null;
        if (array_key_exists('gender', $input) && $input['gender'] !== null) {
            $input['gender'] = match (strtoupper($input['gender'])) {
                'MALE', 'LAKI-LAKI', 'LAKI LAKI', 'L' => 'MALE',
                'FEMALE', 'PEREMPUAN', 'P' => 'FEMALE',
                default => $input['gender'],
            };
        }
        $request->merge($input);
        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'nik' => ['nullable', 'string', 'max:32'],
            'gender' => ['nullable', Rule::in(['MALE', 'FEMALE'])],
            'birth_place' => ['nullable', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date'],
            'marital_status' => ['nullable', Rule::in(['BELUM MENIKAH', 'MENIKAH', 'CERAI', 'CERAI HIDUP', 'CERAI MATI', 'DUDA JANDA', 'SINGLE', 'MARRIED', 'DIVORCED', 'WIDOWED', 'DUDA', 'JANDA'])],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('residents', 'email')->ignore($resident->id)],
            'address' => ['nullable', 'string'],
        ]);
        if (array_key_exists('nik', $validated) && !$request->user()->can('residents.view_sensitive_documents')) abort(403, 'NIK requires residents.view_sensitive_documents permission.');
        $resident->update($validated);

        return response()->json(['data' => $this->personal($resident->fresh(), $request->user()->can('residents.view_sensitive_documents'))]);
    }

    public function show(Request $request, Resident $resident)
    {
        $resident->load([
            'householdMemberships' => fn ($query) => $query->with(['household.house', 'household.head', 'household.members.resident'])->orderByDesc('joined_at'),
            'documents' => fn ($query) => $query->latest(),
        ]);

        $sensitive = $request->user()->can('residents.view_sensitive_documents');
        $activeMembership = $resident->householdMemberships->first(fn ($membership) => $membership->active && !$membership->left_at && $membership->household?->active && !$membership->household?->ended_at);
        $required = match ($activeMembership?->member_role) {
            'HEAD' => ['KTP', 'KK'],
            'MEMBER' => ['KTP'],
            default => [],
        };
        $missing = array_values(array_diff($required, $resident->documents->pluck('document_type')->unique()->all()));

        $data = [
            'id' => $resident->id,
            'full_name' => $resident->full_name,
            'gender' => $resident->gender,
            'birth_place' => $resident->birth_place,
            'birth_date' => $resident->birth_date?->toDateString(),
            'phone' => $resident->phone,
            'email' => $resident->email,
            'address' => $resident->address,
            'marital_status' => $resident->marital_status,
            'active' => $resident->active,
            'created_at' => $resident->created_at?->toIso8601String(),
            'updated_at' => $resident->updated_at?->toIso8601String(),
            'current_household' => $activeMembership ? $this->membership($activeMembership, true) : null,
            'household_history' => $resident->householdMemberships->map(fn ($membership) => $this->membership($membership))->values(),
            'documents' => [
                'can_view' => $sensitive,
                'items' => $sensitive ? $resident->documents->map(fn ($document) => $this->document($document))->values() : [],
                'missing_required_document_types' => $missing,
            ],
            'allowed_actions' => [
                'edit' => $request->user()->can('residents.update'),
                'deactivate' => $request->user()->can('residents.deactivate') && $resident->active && !$activeMembership,
                'reactivate' => $request->user()->can('residents.deactivate') && !$resident->active,
                'upload_document' => $sensitive,
            ],
        ];
        if ($sensitive) {
            $data['nik'] = $resident->nik;
        } else {
            $data['nik_masked'] = $this->maskNik($resident->nik);
        }

        return response()->json(['data' => $data]);
    }

    public function deactivate(Resident $resident)
    {
        return DB::transaction(function () use ($resident) {
            $resident = Resident::lockForUpdate()->findOrFail($resident->id);
            $occupied = $resident->householdMemberships()->where('active', true)->whereNull('left_at')->whereHas('household', fn ($query) => $query->where('active', true)->whereNull('ended_at'))->exists();
            abort_if($occupied, 422, 'Warga dengan keanggotaan atau peran kepala household aktif tidak dapat dinonaktifkan. Tutup atau pindahkan household terlebih dahulu.');
            $resident->update(['active' => false]);
            return response()->json(['id' => $resident->id, 'active' => false]);
        });
    }

    public function reactivate(Resident $resident)
    {
        return DB::transaction(function () use ($resident) {
            $resident = Resident::lockForUpdate()->findOrFail($resident->id);
            $resident->update(['active' => true]);
            return response()->json(['id' => $resident->id, 'active' => true]);
        });
    }

    public static function safeDocument(PrivateDocument $document): array
    {
        return [
            'id' => $document->id,
            'type' => $document->document_type,
            'original_name' => basename($document->original_name),
            'mime' => $document->mime_type,
            'size' => $document->size_bytes,
            'created_at' => $document->created_at?->toIso8601String(),
            'download_endpoint' => "/api/v1/documents/{$document->id}/download",
        ];
    }

    private function document(PrivateDocument $document): array
    {
        return self::safeDocument($document);
    }

    private function membership($membership, bool $includeMembers = false): array
    {
        $household = $membership->household;
        $data = [
            'id' => $membership->id,
            'house' => $household?->house ? ['id' => $household->house->id, 'house_code' => $household->house->house_code, 'status' => $household->house->occupancy_status] : null,
            'head' => $household?->head ? ['id' => $household->head->id, 'full_name' => $household->head->full_name] : null,
            'role' => $membership->member_role,
            'occupancy_type' => $household?->occupancy_type,
            'joined_at' => $membership->joined_at?->toDateString(),
            'started_at' => $household?->started_at?->toDateString(),
            'ended_at' => $household?->ended_at?->toDateString(),
            'contract_started_at' => $household?->contract_started_at?->toDateString(),
            'contract_ended_at' => $household?->contract_ended_at?->toDateString(),
            'active' => (bool) ($membership->active && !$membership->left_at && $household?->active && !$household?->ended_at),
        ];
        if ($includeMembers) {
            $data['members'] = $household->members->map(fn ($member) => ['id' => $member->resident_id, 'full_name' => $member->resident?->full_name, 'role' => $member->member_role])->values();
        }
        return $data;
    }

    private function maskNik(?string $nik): ?string
    {
        if ($nik === null || $nik === '') return null;
        $length = strlen($nik);
        if ($length <= 8) return str_repeat('*', $length);
        return substr($nik, 0, 4).str_repeat('*', $length - 8).substr($nik, -4);
    }

    private function personal(Resident $resident, bool $sensitive): array
    {
        $data = [
            'id' => $resident->id,
            'full_name' => $resident->full_name,
            'gender' => $resident->gender,
            'birth_place' => $resident->birth_place,
            'birth_date' => $resident->birth_date?->toDateString(),
            'marital_status' => $resident->marital_status,
            'phone' => $resident->phone,
            'email' => $resident->email,
            'address' => $resident->address,
            'active' => $resident->active,
        ];
        $data[$sensitive ? 'nik' : 'nik_masked'] = $sensitive ? $resident->nik : $this->maskNik($resident->nik);
        return $data;
    }
}
