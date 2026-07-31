<?php

namespace App\Http\Controllers;

use App\Models\{AuditLog, Setting};
use App\Http\Requests\UpdateSettingsRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SettingController extends Controller
{
    public const ALLOWED = [
        'resident_document_max_kb' => ['label'=>'Maksimum dokumen penghuni','type'=>'integer','group'=>'uploads','default'=>2048],
        'payment_proof_max_kb' => ['label'=>'Maksimum bukti pembayaran','type'=>'integer','group'=>'uploads','default'=>2048],
        'expense_proof_max_kb' => ['label'=>'Maksimum bukti pengeluaran','type'=>'integer','group'=>'uploads','default'=>2048],
        'special_bill_document_max_kb' => ['label'=>'Maksimum dokumen tagihan khusus','type'=>'integer','group'=>'uploads','default'=>2048],
    ];

    public function index()
    {
        return collect(self::ALLOWED)->map(function(array $definition,string $key){$setting=Setting::where('key',$key)->first();return ['key'=>$key,'label'=>$definition['label'],'value'=>$setting?->typedValue()??$definition['default'],'type'=>$definition['type'],'group'=>$definition['group'],'updated_at'=>$setting?->updated_at];})->values();
    }

    public function update(UpdateSettingsRequest $request)
    {
        $data=$request->validated();
        $unknown=array_diff(array_keys($data['settings']),array_keys(self::ALLOWED));
        if($unknown)throw ValidationException::withMessages(['settings'=>'Key pengaturan tidak diizinkan: '.implode(', ',$unknown).'.']);
        DB::transaction(function()use($data,$request){foreach($data['settings'] as $key=>$value){$definition=self::ALLOWED[$key];$old=Setting::where('key',$key)->first();$setting=Setting::updateOrCreate(['key'=>$key],['value'=>(string)$value,'type'=>$definition['type'],'group'=>$definition['group'],'updated_by'=>$request->user()->id]);AuditLog::create(['user_id'=>$request->user()->id,'action'=>'setting.updated','auditable_type'=>Setting::class,'auditable_id'=>$setting->id,'old_values'=>$old?['value'=>$old->typedValue()]:[],'new_values'=>['key'=>$key,'value'=>(int)$value],'ip'=>$request->ip()]);}});
        return $this->index();
    }
}
