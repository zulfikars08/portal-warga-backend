<?php

namespace App\Http\Controllers;

use App\Models\Bill;

class BillController extends Controller
{
    public function show(Bill $bill)
    {
        $bill->load([
            'house.activeHousehold', 'household.head', 'responsibleHead', 'feeRate',
            'specialBill.approver', 'specialBill.canceller',
            'paymentAllocations.payment.canceller',
        ]);

        $household = $bill->household;
        $head = $bill->responsibleHead ?: $household?->head;
        $special = $bill->specialBill;
        $snapshot = $bill->fee_snapshot ?: [];

        return response()->json(['data' => [
            'id' => $bill->id,
            'type' => $bill->type,
            'title' => $bill->title,
            'period' => $bill->period?->toDateString(),
            'due_date' => $bill->due_date?->toDateString(),
            'amount' => (int) $bill->amount,
            'paid_amount' => (int) $bill->paid_amount,
            'outstanding_amount' => max(0, (int) $bill->amount - (int) $bill->paid_amount),
            'status' => $bill->status,
            'note' => $bill->note,
            'house' => $bill->house ? [
                'id' => $bill->house->id,
                'code' => $bill->house->house_code,
                'block_code' => $bill->house->block_code,
                'house_number' => $bill->house->house_number,
                'occupied' => $bill->house->activeHousehold !== null,
            ] : null,
            'household' => $household ? [
                'id' => $household->id,
                'occupancy_type' => $household->occupancy_type,
                'active' => (bool) $household->active,
            ] : null,
            'responsible_head' => $head ? [
                'id' => $head->id,
                'full_name' => $head->full_name,
                'active' => (bool) $head->active,
            ] : null,
            'source' => $special ? [
                'kind' => 'special',
                'id' => $special->id,
                'special_bill_number' => $special->special_bill_number,
                'title' => $special->title,
                'amount' => (int) $special->amount,
                'approved_at' => $special->approved_at?->toISOString(),
                'approver' => $this->actor($special->approver),
            ] : [
                'kind' => 'routine',
                'fee_code' => $bill->fee_code,
                'name' => $bill->fee_name_snapshot ?? $snapshot['name'] ?? $bill->feeRate?->name,
                'rate_snapshot' => (int) ($bill->amount_snapshot ?? $snapshot['amount'] ?? $bill->amount),
                'period' => $bill->period?->toDateString(),
                'fee_rate' => $bill->feeRate ? ['id' => $bill->feeRate->id, 'fee_code' => $bill->feeRate->fee_code, 'name' => $bill->feeRate->name, 'amount' => (int) $bill->feeRate->amount] : null,
            ],
            'payments' => $bill->paymentAllocations->map(fn ($allocation) => [
                'allocation_id' => $allocation->id,
                'amount' => (int) $allocation->amount,
                'payment' => $allocation->payment ? [
                    'id' => $allocation->payment->id,
                    'transaction_number' => $allocation->payment->transaction_number,
                    'method' => $allocation->payment->payment_method,
                    'date' => $allocation->payment->paid_at?->toISOString(),
                    'amount' => (int) $allocation->payment->amount,
                    'status' => $allocation->payment->status,
                    'cancel_reason' => $allocation->payment->cancel_reason,
                    'cancelled_at' => $allocation->payment->cancelled_at?->toISOString(),
                    'cancelled_by' => $this->actor($allocation->payment->canceller),
                ] : null,
            ])->values(),
            'cancellation' => $special && $special->status === 'CANCELLED' ? [
                'reason' => $special->cancel_reason,
                'cancelled_at' => $special->cancelled_at?->toISOString(),
                'cancelled_by' => $this->actor($special->canceller),
            ] : null,
            'created_at' => $bill->created_at?->toISOString(),
            'updated_at' => $bill->updated_at?->toISOString(),
        ]]);
    }

    private function actor($user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name] : null;
    }
}