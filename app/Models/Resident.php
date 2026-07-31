<?php
namespace App\Models;
class Resident extends PortalModel {
 protected $fillable=['full_name','nik','gender','birth_place','birth_date','phone','email','address','marital_status','active'];
 protected $hidden=['nik'];
 protected function casts():array{return ['birth_date'=>'date:Y-m-d','active'=>'boolean'];}
 public function householdMemberships(){return $this->hasMany(HouseholdMember::class);}
 public function headedHouseholds(){return $this->hasMany(Household::class,'head_resident_id');}
 public function documents(){return $this->hasMany(PrivateDocument::class);}
}