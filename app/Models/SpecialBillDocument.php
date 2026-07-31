<?php
namespace App\Models;
class SpecialBillDocument extends PortalModel {protected $hidden=['storage_path'];public function specialBill(){return $this->belongsTo(SpecialBill::class);}}
