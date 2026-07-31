<?php
namespace App\Models;
class ExpenseCategory extends PortalModel {protected function casts():array{return ['active'=>'boolean'];}public function expenses(){return $this->hasMany(Expense::class);}}
