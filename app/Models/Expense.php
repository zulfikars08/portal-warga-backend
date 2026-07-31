<?php
namespace App\Models;
class Expense extends PortalModel {
 protected function casts():array{return ['spent_at'=>'date','cancelled_at'=>'datetime','amount'=>'integer'];}
 public function category(){return $this->belongsTo(ExpenseCategory::class,'expense_category_id');}
 public function proofs(){return $this->hasMany(ExpenseProof::class);}
 public function creator(){return $this->belongsTo(User::class,'created_by');}
 public function canceller(){return $this->belongsTo(User::class,'cancelled_by');}
 public function replacement(){return $this->hasOne(self::class,'replaces_expense_id');}
 public function replacedExpense(){return $this->belongsTo(self::class,'replaces_expense_id');}
}