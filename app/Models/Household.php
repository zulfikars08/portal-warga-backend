<?php
namespace App\Models;
class Household extends PortalModel {
 protected function casts():array{return ['started_at'=>'date','ended_at'=>'date','contract_started_at'=>'date','contract_ended_at'=>'date','active'=>'boolean'];}
 public function house(){return $this->belongsTo(House::class);}
 public function head(){return $this->belongsTo(Resident::class,'head_resident_id');}
 public function members(){return $this->hasMany(HouseholdMember::class);}
 public function bills(){return $this->hasMany(Bill::class);}
}