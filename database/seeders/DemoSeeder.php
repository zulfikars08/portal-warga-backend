<?php

namespace Database\Seeders;

use App\Models\{Bill,Expense,ExpenseCategory,House,Payment,PrivateDocument,Resident,SpecialBill};
use App\Services\{ExpenseService,HouseholdService,MonthlyBillService,PaymentService,SpecialBillService};
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Storage};

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(InitialSeeder::class);
        $households=app(HouseholdService::class);
        foreach(House::orderBy('id')->take(15)->get() as $i=>$house){$head=Resident::firstOrCreate(['full_name'=>'Kepala Keluarga Demo '.($i+1)],['phone'=>'08120000'.sprintf('%04d',$i+1),'marital_status'=>'MENIKAH','active'=>true]);$member=Resident::firstOrCreate(['full_name'=>'Anggota Keluarga Demo '.($i+1)],['marital_status'=>'BELUM_MENIKAH','active'=>true]);foreach([[$head,'KTP'],[$head,'KK'],[$member,'KTP']] as [$resident,$type])PrivateDocument::firstOrCreate(['resident_id'=>$resident->id,'document_type'=>$type],['storage_path'=>'demo/'.strtolower($type).'-'.$resident->id.'.pdf','original_name'=>strtolower($type).'-demo.pdf','mime_type'=>'application/pdf','size_bytes'=>100]);if(!$house->activeHousehold()->exists())$households->create(['house_id'=>$house->id,'head_resident_id'=>$head->id,'occupancy_type'=>$i>=13?'CONTRACT':'PERMANENT','started_at'=>'2025-12-01','contract_started_at'=>$i>=13?'2025-12-01':null,'contract_ended_at'=>$i>=13?'2026-11-30':null,'member_ids'=>[$member->id]]);}
        foreach(['2026-01-01','2026-02-01','2026-03-01'] as $period)app(MonthlyBillService::class)->generate($period);
        if(!Expense::where('title','Honor satpam Januari 2026')->exists()){$expenses=app(ExpenseService::class);$expenseProof=database_path('seeders/fixtures/demo-document.pdf');
        $expenseData=fn(string $category,string $title,int $amount,string $date,array $metadata=[]):array=>['expense_category_id'=>ExpenseCategory::where('name',$category)->value('id'),'title'=>$title,'description'=>$title.' demo','amount'=>$amount,'spent_at'=>$date,'proofs'=>[['file'=>new UploadedFile($expenseProof,str($title)->slug().'.pdf','application/pdf',null,true),'metadata'=>$metadata]]];
        $expenses->create($expenseData('Keamanan','Honor satpam Januari 2026',2500000,'2026-01-31',['vendor'=>'Satpam Demo']),null);
        $expenses->create($expenseData('Keamanan','Token listrik pos satpam',375000,'2026-02-12',['invoice'=>'INV-DEMO-001']),null);
        $multi=$expenseData('Perawatan','Perbaikan selokan',850000,'2026-03-10');$multi['proofs'][]=['file'=>new UploadedFile($expenseProof,'perbaikan-selokan-tambahan.pdf','application/pdf',null,true),'metadata'=>['part'=>2]];$expenses->create($multi,null);
        $expenses->create($expenseData('Perawatan','Perbaikan jalan',600000,'2026-04-10'),null);
        $expenses->create($expenseData('Administrasi','Kegiatan warga',300000,'2026-05-10'),null);
        $cancelledExpense=$expenses->create($expenseData('Administrasi','Cetak formulir lama',150000,'2026-03-15'),null);$expenses->cancel($cancelledExpense,'Demo koreksi nominal',null);$expenses->create($expenseData('Administrasi','Cetak formulir revisi',125000,'2026-03-16',['replacement'=>true]),null,$cancelledExpense);}
        $payments=app(PaymentService::class);$proof=database_path('seeders/fixtures/demo-document.pdf');
        $demoPdf=file_get_contents($proof);
        foreach(['payment_proofs','expense_proofs','special_bill_documents'] as $table)DB::table($table)->where('mime_type','application/pdf')->pluck('storage_path')->each(fn($path)=>Storage::disk('local')->put($path,$demoPdf));
        $bill=Bill::where('fee_code','SECURITY')->where('status','UNPAID')->first();$payments->create(['bill_ids'=>[$bill->id],'payment_method'=>'CASH','paid_at'=>'2026-01-05 10:00:00'],null);
        $bill=Bill::where('fee_code','CLEANING')->where('status','UNPAID')->first();$payments->create(['bill_ids'=>[$bill->id],'payment_method'=>'TRANSFER','paid_at'=>'2026-01-06 10:00:00','proofs'=>[['file'=>new UploadedFile($proof,'transfer-1.pdf','application/pdf',null,true),'transfer_amount'=>$bill->amount]]],null);
        $bill=Bill::where('fee_code','SECURITY')->where('status','UNPAID')->first();$payments->create(['bill_ids'=>[$bill->id],'payment_method'=>'TRANSFER','paid_at'=>'2026-02-05 10:00:00','proofs'=>[['file'=>new UploadedFile($proof,'transfer-a.pdf','application/pdf',null,true),'transfer_amount'=>40000],['file'=>new UploadedFile($proof,'transfer-b.pdf','application/pdf',null,true),'transfer_amount'=>$bill->amount-40000]]],null);
        $bill=Bill::where('fee_code','CLEANING')->where('status','UNPAID')->first();$payments->create(['bill_ids'=>[$bill->id],'future_cleaning_periods'=>['2026-04-01','2026-05-01','2026-06-01'],'payment_method'=>'CASH','paid_at'=>'2026-03-05 10:00:00'],null);
        $bill=Bill::where('fee_code','SECURITY')->where('status','UNPAID')->first();$cancelled=$payments->create(['bill_ids'=>[$bill->id],'payment_method'=>'CASH','paid_at'=>'2026-03-06 10:00:00'],null);$payments->cancel($cancelled,'Demo koreksi pembayaran',null);$payments->create(['bill_ids'=>[$bill->id],'payment_method'=>'CASH','paid_at'=>'2026-03-07 10:00:00','note'=>'Pembayaran pengganti demo'],null,$cancelled);
        $specials=app(SpecialBillService::class);$document=fn()=>new UploadedFile($proof,'persetujuan-tagihan-khusus.pdf','application/pdf',null,true);
        SpecialBill::where('title','Renovasi gerbang demo')->update(['title'=>'Kegiatan 17 Agustus 2026']);
        SpecialBill::where('title','Perbaikan jalan lingkungan demo')->update(['title'=>'Perbaikan Lampu Jalan']);
        SpecialBill::where('title','Acara warga dibatalkan demo')->update(['title'=>'Rencana Kegiatan Lama']);
        if(!SpecialBill::where('title','Kegiatan 17 Agustus 2026')->exists())$specials->create(['title'=>'Kegiatan 17 Agustus 2026','description'=>'Menunggu persetujuan','amount'=>125000,'due_date'=>'2026-08-15','target_type'=>'SELECTED_HOUSES','house_ids'=>House::orderBy('id')->take(3)->pluck('id')->all(),'approval_document'=>$document()],null);
        if(!SpecialBill::where('title','Perbaikan Lampu Jalan')->exists()){$special=$specials->create(['title'=>'Perbaikan Lampu Jalan','amount'=>75000,'due_date'=>'2026-08-20','target_type'=>'ALL_OCCUPIED','approval_document'=>$document()],null);$specials->approve($special,null);}
        if(!SpecialBill::where('title','Rencana Kegiatan Lama')->exists()){$special=$specials->create(['title'=>'Rencana Kegiatan Lama','amount'=>50000,'due_date'=>'2026-08-25','target_type'=>'SELECTED_HOUSES','house_ids'=>House::orderBy('id')->take(2)->pluck('id')->all(),'approval_document'=>$document()],null);$specials->approve($special,null);$specials->cancel($special,'Acara demo dibatalkan',null);}
    }
}
