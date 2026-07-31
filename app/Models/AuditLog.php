<?php
namespace App\Models;
class AuditLog extends PortalModel
{
    protected function casts():array{return ['old_values'=>'array','new_values'=>'array','metadata'=>'array'];}
    public function actor(){return $this->belongsTo(User::class,'user_id');}
    public function toArray():array
    {
        $data=parent::toArray();
        foreach(['old_values','new_values','metadata'] as $key)$data[$key]=$this->redact($data[$key]??null);
        return $data;
    }
    private function redact(mixed $value):mixed
    {
        if(!is_array($value))return $value;
        foreach($value as $key=>&$item){$normalized=strtolower((string)$key);if(in_array($normalized,['password','password_confirmation','token','access_token','secret','authorization','storage_path','file_path','path'],true))$item='[REDACTED]';else $item=$this->redact($item);}
        return $value;
    }
}
