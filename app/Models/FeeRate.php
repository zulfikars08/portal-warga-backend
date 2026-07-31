<?php
namespace App\Models;
class FeeRate extends PortalModel {
 protected function casts():array{return ['effective_from'=>'date','effective_until'=>'date','active'=>'boolean'];}
 public function bills(){return $this->hasMany(Bill::class);}
 public function scopeForDate($query,$date){return $query->where('active',true)->whereDate('effective_from','<=',$date)->where(fn($q)=>$q->whereNull('effective_until')->orWhereDate('effective_until','>=',$date));}
}
