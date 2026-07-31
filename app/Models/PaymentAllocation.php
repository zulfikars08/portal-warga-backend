<?php

namespace App\Models;

class PaymentAllocation extends PortalModel
{
    public function payment() { return $this->belongsTo(Payment::class); }
    public function bill() { return $this->belongsTo(Bill::class); }
}
