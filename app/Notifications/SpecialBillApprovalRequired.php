<?php

namespace App\Notifications;

use App\Models\SpecialBill;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SpecialBillApprovalRequired extends Notification
{
    use Queueable;

    public function __construct(private readonly SpecialBill $specialBill) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'SPECIAL_BILL_APPROVAL_REQUIRED',
            'title' => 'Tagihan khusus menunggu persetujuan',
            'message' => "{$this->specialBill->title} membutuhkan persetujuan sebelum diterbitkan.",
            'special_bill_id' => $this->specialBill->id,
            'special_bill_number' => $this->specialBill->special_bill_number,
            'amount' => (int) $this->specialBill->amount,
            'target_type' => $this->specialBill->target_type,
            'target_count' => $this->specialBill->targets()->count(),
            'created_by' => $this->specialBill->created_by,
            'created_at' => $this->specialBill->created_at?->toISOString(),
            'destination' => "/tagihan-khusus/{$this->specialBill->id}",
        ];
    }
}
