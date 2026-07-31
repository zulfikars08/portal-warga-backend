<?php
namespace App\Models;
class SpecialBill extends PortalModel {
 protected function casts():array{return ['due_date'=>'date','approved_at'=>'datetime','cancelled_at'=>'datetime'];}
 public function targets(){return $this->hasMany(SpecialBillTarget::class);}
 public function documents(){return $this->hasMany(SpecialBillDocument::class);}
 public function bills(){return $this->hasMany(Bill::class);}
 public function creator(){return $this->belongsTo(User::class,'created_by');}
 public function approver(){return $this->belongsTo(User::class,'approved_by');}
 public function canceller(){return $this->belongsTo(User::class,'cancelled_by');}
}
