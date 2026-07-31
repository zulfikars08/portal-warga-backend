<?php

namespace App\Models;

class Payment extends PortalModel
{
    protected function casts(): array
    {
        return ['paid_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function allocations() { return $this->hasMany(PaymentAllocation::class); }
    public function proofs() { return $this->hasMany(PaymentProof::class); }
    public function house() { return $this->belongsTo(House::class); }
    public function household() { return $this->belongsTo(Household::class); }
    public function payer() { return $this->belongsTo(Resident::class, 'payer_resident_id'); }
    public function replacement() { return $this->hasOne(self::class, 'replaces_payment_id'); }
    public function replacedPayment() { return $this->belongsTo(self::class, 'replaces_payment_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function canceller() { return $this->belongsTo(User::class, 'cancelled_by'); }
}
