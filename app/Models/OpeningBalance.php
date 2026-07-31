<?php
namespace App\Models;
class OpeningBalance extends PortalModel { protected function casts():array{return ['as_of'=>'date'];} }
