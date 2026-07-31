<?php

namespace App\Models;

class PaymentProof extends PortalModel
{
    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }
}
