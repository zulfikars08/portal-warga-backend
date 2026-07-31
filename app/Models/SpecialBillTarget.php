<?php
namespace App\Models;
class SpecialBillTarget extends PortalModel {public function specialBill(){return $this->belongsTo(SpecialBill::class);}public function house(){return $this->belongsTo(House::class);}}
