<?php
namespace App\Models;
class PrivateDocument extends PortalModel {protected function casts():array{return ['metadata'=>'array'];}public function resident(){return $this->belongsTo(Resident::class);}}