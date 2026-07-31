<?php
namespace App\Models;
class ExpenseProof extends PortalModel {protected function casts():array{return ['metadata'=>'array'];}public function expense(){return $this->belongsTo(Expense::class);}}