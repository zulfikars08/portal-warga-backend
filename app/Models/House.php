<?php
namespace App\Models;
use Illuminate\Database\Eloquent\SoftDeletes;
class House extends PortalModel {
 use SoftDeletes;
 protected $appends=['occupancy_status'];
 protected static function booted():void{static::saving(function(House $house){$house->block_code=strtoupper(trim($house->block_code));$house->house_number=str_pad(trim($house->house_number),2,'0',STR_PAD_LEFT);$house->house_code=$house->block_code.'-'.$house->house_number;});}
 public function households(){return $this->hasMany(Household::class);}
 public function activeHousehold(){return $this->hasOne(Household::class)->where('active',true)->whereNull('ended_at');}
 public function bills(){return $this->hasMany(Bill::class);}
 public function getOccupancyStatusAttribute():string{return $this->relationLoaded('activeHousehold')?($this->activeHousehold?'DIHUNI':'TIDAK DIHUNI'):($this->activeHousehold()->exists()?'DIHUNI':'TIDAK DIHUNI');}
}