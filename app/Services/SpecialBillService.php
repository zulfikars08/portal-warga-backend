<?php

namespace App\Services;

use App\Models\{AuditLog, Bill, House, SpecialBill, SpecialBillDocument, SpecialBillTarget, User};
use App\Notifications\SpecialBillApprovalRequired;
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SpecialBillService
{
    public function create(array $data, ?int $userId): SpecialBill
    {
        $stored = [];
        try {
            return DB::transaction(function () use ($data, $userId, &$stored) {
                $special = SpecialBill::create([
                    'special_bill_number' => $this->number(), 'title' => $data['title'],
                    'description' => $data['description'] ?? null, 'amount' => $data['amount'],
                    'due_date' => $data['due_date'], 'target_type' => $data['target_type'],
                    'status' => 'PENDING_APPROVAL', 'created_by' => $userId,
                ]);
                if ($data['target_type'] === 'SELECTED_HOUSES') {
                    foreach ($data['house_ids'] as $id) SpecialBillTarget::create(['special_bill_id' => $special->id, 'house_id' => $id]);
                }
                $file = $data['approval_document'];
                $path = $file->store('special-bill-documents', 'local');
                $stored[] = $path;
                SpecialBillDocument::create(['special_bill_id' => $special->id, 'storage_path' => $path, 'original_name' => $file->getClientOriginalName(), 'mime_type' => $file->getMimeType(), 'size_bytes' => $file->getSize(), 'uploaded_by' => $userId]);
                $this->audit('special_bill.created', $special, [], ['target_type' => $special->target_type, 'house_ids' => $data['house_ids'] ?? []], $userId);
                $this->notifyApprovers($special);
                return $this->detail($special);
            });
        } catch (\Throwable $e) {
            foreach ($stored as $path) Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    public function approve(SpecialBill $special, ?int $userId): SpecialBill
    {
        return DB::transaction(function () use ($special, $userId) {
            $special = SpecialBill::lockForUpdate()->findOrFail($special->id);
            if ($special->status !== 'PENDING_APPROVAL') $this->fail('special_bill', 'Hanya tagihan khusus berstatus PENDING_APPROVAL yang dapat disetujui.');
            if (!$special->documents()->exists()) $this->fail('approval_document', 'Dokumen persetujuan wajib tersedia.');

            if ($special->target_type === 'ALL_OCCUPIED') {
                $houseIds = House::whereHas('activeHousehold.head')->lockForUpdate()->pluck('id');
                if ($houseIds->isEmpty()) $this->fail('targets', 'Tidak ada rumah dihuni yang dapat ditagih.');
                foreach ($houseIds as $id) SpecialBillTarget::firstOrCreate(['special_bill_id' => $special->id, 'house_id' => $id]);
            }
            $targets = SpecialBillTarget::where('special_bill_id', $special->id)->lockForUpdate()->get();
            $houses = House::with('activeHousehold.head')->whereIn('id', $targets->pluck('house_id'))->lockForUpdate()->get()->keyBy('id');
            $invalid = $targets->map(fn ($target) => $houses->get($target->house_id))->filter(fn ($house) => !$house || !$house->activeHousehold || !$house->activeHousehold->head)->map(fn ($house) => $house?->house_code ?? 'ID tidak ditemukan')->values();
            if ($invalid->isNotEmpty()) $this->fail('house_ids', 'Rumah tidak dihuni/tidak memiliki kepala keluarga aktif: '.$invalid->implode(', ').'.');

            foreach ($targets as $target) {
                $house = $houses[$target->house_id]; $household = $house->activeHousehold;
                Bill::create([
                    'special_bill_id' => $special->id, 'house_id' => $house->id, 'household_id' => $household->id,
                    'fee_rate_id' => null, 'fee_code' => null, 'responsible_head_resident_id' => $household->head_resident_id,
                    'house_code_snapshot' => $house->house_code, 'responsible_head_name_snapshot' => $household->head->full_name,
                    'fee_name_snapshot' => $special->title, 'amount_snapshot' => $special->amount, 'type' => 'special',
                    'title' => $special->title, 'period' => now()->startOfMonth(), 'due_date' => $special->due_date,
                    'amount' => $special->amount, 'paid_amount' => 0, 'status' => 'UNPAID',
                    'fee_snapshot' => ['special_bill_id' => $special->id, 'special_bill_number' => $special->special_bill_number, 'title' => $special->title, 'amount' => (int) $special->amount],
                    'note' => $special->description,
                ]);
            }
            $special->update(['status' => 'APPROVED', 'approved_by' => $userId, 'approved_at' => now()]);
            $this->readApprovalNotifications($special);
            $this->audit('special_bill.approved', $special, ['status' => 'PENDING_APPROVAL'], ['status' => 'APPROVED'], $userId);
            $this->audit('special_bill.bills.generated', $special, [], ['bill_count' => $targets->count(), 'bill_ids' => $special->bills()->pluck('id')->all()], $userId);
            return $this->detail($special->fresh());
        });
    }

    public function cancel(SpecialBill $special, string $reason, ?int $userId): SpecialBill
    {
        return DB::transaction(function () use ($special, $reason, $userId) {
            $special = SpecialBill::lockForUpdate()->findOrFail($special->id);
            if ($special->status === 'CANCELLED') $this->fail('special_bill', 'Tagihan khusus sudah dibatalkan.');
            $bills = Bill::where('special_bill_id', $special->id)->lockForUpdate()->get();
            if ($bills->contains(fn ($bill) => $bill->status === 'PAID' || (int) $bill->paid_amount > 0)) $this->fail('special_bill', 'Tagihan khusus dengan pembayaran tidak dapat dibatalkan.');
            $billIds = $bills->pluck('id')->all();
            $bills->each->update(['status' => 'CANCELLED']);
            $oldStatus = $special->status;
            $special->update(['status' => 'CANCELLED', 'cancel_reason' => $reason, 'cancelled_by' => $userId, 'cancelled_at' => now()]);
            $this->readApprovalNotifications($special);
            $this->audit('special_bill.cancelled', $special, ['status' => $oldStatus], ['status' => 'CANCELLED', 'cancel_reason' => $reason], $userId);
            if ($billIds) $this->audit('special_bill.bills.cancelled', $special, [], ['bill_count' => count($billIds), 'bill_ids' => $billIds], $userId);
            return $this->detail($special->fresh());
        });
    }

    public function detail(SpecialBill $special): SpecialBill
    {
        $special->load(['targets.house', 'documents', 'bills', 'creator', 'approver', 'canceller']);
        $special->setAttribute('summary', ['target_count' => $special->targets->count(), 'bill_count' => $special->bills->count(), 'total_amount' => $special->bills->sum('amount'), 'paid_bill_count' => $special->bills->where('status', 'PAID')->count()]);
        return $special;
    }

    private function number(): string { return 'SPB-'.now()->format('Ymd').'-'.strtoupper(substr((string) Str::ulid(), -8)); }
    public function notifyApprovers(SpecialBill $special): void
    {
        User::where('active', true)->get()->filter->can('bills.approve_special')->each(function (User $user) use ($special) {
            $exists = $user->notifications()->where('type', SpecialBillApprovalRequired::class)->where('data->special_bill_id', $special->id)->exists();
            if (!$exists) $user->notify(new SpecialBillApprovalRequired($special));
        });
    }
    private function readApprovalNotifications(SpecialBill $special): void
    {
        DB::table('notifications')->whereNull('read_at')->where('type', SpecialBillApprovalRequired::class)->where('data->special_bill_id', $special->id)->update(['read_at' => now(), 'updated_at' => now()]);
    }
    private function fail(string $key, string $message): never { throw ValidationException::withMessages([$key => $message]); }
    private function audit(string $action, SpecialBill $special, array $old, array $new, ?int $userId): void { AuditLog::create(['user_id' => $userId, 'action' => $action, 'auditable_type' => SpecialBill::class, 'auditable_id' => $special->id, 'old_values' => $old, 'new_values' => $new, 'ip' => request()->ip()]); }
}
