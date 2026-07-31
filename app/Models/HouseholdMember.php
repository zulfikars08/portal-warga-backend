<?php
namespace App\Models;
class HouseholdMember extends PortalModel {
 protected function casts():array{return ['joined_at'=>'date','left_at'=>'date','active'=>'boolean'];}
 public function household(){return $this->belongsTo(Household::class);}
 public function resident(){return $this->belongsTo(Resident::class);}
}