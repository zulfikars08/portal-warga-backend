<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

final class ReportPresentation
{
    public const TITLES = [
        'summary'=>'Ringkasan Keuangan', 'income'=>'Laporan Pemasukan', 'expenses'=>'Laporan Pengeluaran',
        'receivables'=>'Laporan Piutang', 'payments'=>'Laporan Pembayaran', 'bills'=>'Laporan Tagihan',
        'houses'=>'Laporan Per Rumah', 'monthly'=>'Rekap Bulanan',
    ];
    public const MONTHS = [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    public const ENUMS = [
        'POSTED'=>'Aktif','CANCELLED'=>'Dibatalkan','UNPAID'=>'Belum lunas','PARTIAL'=>'Dibayar sebagian','PAID'=>'Lunas',
        'CASH'=>'Tunai','TRANSFER'=>'Transfer','SPECIAL'=>'Tagihan khusus','ROUTINE'=>'Tagihan rutin','special'=>'Tagihan khusus','routine'=>'Tagihan rutin','OCCUPIED'=>'Dihuni','VACANT'=>'Kosong',
        'bill'=>'Tagihan','payment'=>'Pembayaran',
    ];
    public const SUMMARY = [
        'opening_balance'=>['Saldo Awal','money'],'total_income'=>['Total Pemasukan','money'],'income_transaction_count'=>['Jumlah Transaksi Pemasukan','integer'],
        'cash_income'=>['Pemasukan Tunai','money'],'transfer_income'=>['Pemasukan Transfer','money'],'active_payment_count'=>['Pembayaran Tercatat','integer'],
        'cancelled_payment_count'=>['Pembayaran Dibatalkan','integer'],'total_expense'=>['Total Pengeluaran','money'],'expense_transaction_count'=>['Jumlah Transaksi Pengeluaran','integer'],
        'active_expense_count'=>['Pengeluaran Tercatat','integer'],'cancelled_expense_count'=>['Pengeluaran Dibatalkan','integer'],'closing_balance'=>['Saldo Akhir','money'],
        'total_billed'=>['Total Tagihan','money'],'total_paid_bills'=>['Tagihan Terbayar','money'],'bill_count'=>['Jumlah Tagihan','integer'],
        'paid_bill_count'=>['Tagihan Lunas','integer'],'unpaid_bill_count'=>['Tagihan Belum Lunas','integer'],'cancelled_bill_count'=>['Tagihan Dibatalkan','integer'],
        'total_receivables'=>['Total Piutang','money'],'houses_in_arrears'=>['Rumah Menunggak','integer'],'receivable_count'=>['Jumlah Piutang','integer'],
        'billed'=>['Total Tagihan','money'],'paid_on_bills'=>['Terbayar pada Tagihan','money'],'payments'=>['Total Pembayaran','money'],'outstanding'=>['Sisa Tagihan','money'],
    ];
    private const COMMON_PAYMENT = ['payment_number'=>['Nomor Pembayaran','text'],'paid_at'=>['Tanggal Pembayaran','datetime'],'house_code'=>['Kode Rumah','text'],'payer_name'=>['Nama Pembayar','text'],'method'=>['Metode Pembayaran','enum'],'amount'=>['Jumlah','money'],'status'=>['Status','enum'],'created_by'=>['Dibuat Oleh','text'],'bill_count'=>['Jumlah Tagihan','integer']];
    private const COMMON_BILL = ['title'=>['Judul','text'],'house_code'=>['Kode Rumah','text'],'head_name'=>['Kepala Keluarga','text'],'bill_type'=>['Jenis Tagihan','enum'],'period'=>['Periode','period'],'due_date'=>['Jatuh Tempo','date'],'amount'=>['Jumlah Tagihan','money'],'paid_amount'=>['Jumlah Terbayar','money'],'outstanding_amount'=>['Sisa Tagihan','money'],'status'=>['Status','enum']];

    public static function title(string $type): string { return self::TITLES[$type] ?? 'Laporan'; }
    public static function columns(string $type): array
    {
        return match ($type) {
            'income','payments' => self::COMMON_PAYMENT,
            'expenses' => ['transaction_number'=>['Nomor Transaksi','text'],'spent_at'=>['Tanggal Pengeluaran','date'],'category'=>['Kategori','text'],'title'=>['Judul','text'],'description'=>['Keterangan','text'],'amount'=>['Jumlah','money'],'status'=>['Status','enum'],'created_by'=>['Dibuat Oleh','text']],
            'receivables' => [...self::COMMON_BILL,'age_bucket'=>['Umur Piutang','text']],
            'bills' => self::COMMON_BILL,
            'summary','monthly' => ['period'=>['Periode','period'],'billed'=>['Tagihan','money'],'income'=>['Pemasukan','money'],'expense'=>['Pengeluaran','money'],'receivables'=>['Piutang','money'],'closing_balance'=>['Saldo Akhir','money']],
            'houses' => ['row_type'=>['Jenis Data','enum'],'title'=>['Judul Tagihan','text'],'payment_number'=>['Nomor Pembayaran','text'],'period'=>['Periode','period'],'due_date'=>['Jatuh Tempo','date'],'paid_at'=>['Tanggal Pembayaran','datetime'],'payer_name'=>['Nama Pembayar','text'],'bill_type'=>['Jenis Tagihan','enum'],'method'=>['Metode Pembayaran','enum'],'amount'=>['Jumlah','money'],'paid_amount'=>['Jumlah Terbayar','money'],'outstanding_amount'=>['Sisa Tagihan','money'],'status'=>['Status','enum']],
            default => [],
        };
    }
    public static function rows(array $report): array { return is_array($report['rows'] ?? null) ? $report['rows'] : []; }
    public static function summaryEntries(array $report): array
    {
        $keys = match ($report['type'] ?? '') {
            'summary' => ['opening_balance','total_income','total_expense','closing_balance','total_billed','total_paid_bills','total_receivables','houses_in_arrears'],
            'income' => ['total_income','income_transaction_count','cash_income','transfer_income','active_payment_count','cancelled_payment_count'],
            'payments' => ['total_income','income_transaction_count','active_payment_count','cancelled_payment_count'],
            'expenses' => ['total_expense','expense_transaction_count','active_expense_count','cancelled_expense_count'],
            'receivables' => ['total_receivables','receivable_count','houses_in_arrears'],
            'bills' => ['total_billed','total_paid_bills','bill_count','paid_bill_count','unpaid_bill_count','cancelled_bill_count'],
            'houses' => ['billed','paid_on_bills','payments','outstanding'],
            default => [],
        };
        $source = ($report['type'] ?? '') === 'houses' ? ($report['house_totals'] ?? []) : ($report['summary'] ?? []);
        $entries=[];
        foreach ($keys as $key) {
            if (!array_key_exists($key,$source) || !isset(self::SUMMARY[$key])) continue;
            [$label,$type]=self::SUMMARY[$key];
            if (($report['type'] ?? '') === 'payments' && $key === 'total_income') $label='Total Pembayaran Aktif';
            if (($report['type'] ?? '') === 'payments' && $key === 'income_transaction_count') $label='Jumlah Transaksi';
            if (($report['type'] ?? '') === 'payments' && $key === 'active_payment_count') $label='Pembayaran Aktif';
            $entries[]=['key'=>$key,'label'=>$label,'type'=>$type,'value'=>$source[$key]];
        }
        return $entries;
    }
    public static function display(mixed $value, string $type='text'): string
    {
        if ($value === null || $value === '') return '-';
        if ($type === 'money') return 'Rp '.number_format((float)$value,0,',','.');
        if ($type === 'integer') return number_format((int)$value,0,',','.');
        if ($type === 'enum') return self::ENUMS[(string)$value] ?? Str::headline(strtolower((string)$value));
        if ($type === 'period' && preg_match('/^(\d{4})-(\d{2})/',(string)$value,$m)) return self::MONTHS[(int)$m[2]].' '.$m[1];
        if ($type === 'date') return CarbonImmutable::parse($value,'Asia/Jakarta')->locale('id')->translatedFormat('j F Y');
        if ($type === 'datetime') return CarbonImmutable::parse($value)->setTimezone('Asia/Jakarta')->locale('id')->translatedFormat('j F Y, H.i').' WIB';
        return is_bool($value) ? ($value?'Ya':'Tidak') : (string)$value;
    }
    public static function excel(mixed $value, string $type): mixed
    {
        if ($value === null || $value === '') return null;
        if ($type === 'money' || $type === 'integer') return (int) $value;
        if ($type === 'date' || $type === 'datetime') {
            $date = CarbonImmutable::parse($value)->setTimezone('Asia/Jakarta');
            return ExcelDate::PHPToExcel($date);
        }
        return self::display($value, $type);
    }

    public static function numberFormat(string $type): string
    {
        $formats = ['money' => 'Rp #,##0', 'integer' => '0', 'date' => 'dd mmmm yyyy', 'datetime' => 'dd mmmm yyyy hh:mm'];
        return $formats[$type] ?? 'General';
    }
    public static function filters(array $filters): array
    {
        $labels=['from'=>'Mulai','to'=>'Sampai','month'=>'Bulan','year'=>'Tahun','status'=>'Status','payment_method'=>'Metode Pembayaran','bill_type'=>'Jenis Tagihan','house_id'=>'Rumah','category_id'=>'Kategori','search'=>'Pencarian'];
        $out=[]; foreach($labels as $key=>$label) if(isset($filters[$key]) && $filters[$key] !== '') {$type=$key==='month'?'period':(in_array($key,['from','to'])?'date':($key==='status'||$key==='payment_method'||$key==='bill_type'?'enum':'text'));$out[$label]=self::display($filters[$key],$type);} return $out;
    }
    public static function filename(string $type,array $filters,string $extension,?string $houseCode=null): string
    {
        $title=self::title($type); if($type==='houses'&&$houseCode)$title.=' '.$houseCode; $suffix='';
        if(!empty($filters['from'])&&!empty($filters['to']))$suffix=self::display($filters['from'],'date').' - '.self::display($filters['to'],'date');
        elseif(!empty($filters['from']))$suffix='Mulai '.self::display($filters['from'],'date'); elseif(!empty($filters['to']))$suffix='Sampai '.self::display($filters['to'],'date');
        elseif(!empty($filters['month']))$suffix=self::display($filters['month'],'period'); elseif(!empty($filters['year']))$suffix=(string)$filters['year'];
        $name=preg_replace('~[<>:"/|?*\\\\\x00-\x1F\x7F]+~u','-',trim($title.' '.$suffix));
        $name=rtrim(trim(preg_replace('/\s+/u',' ',$name)),'. ');
        return $name.'.'.ltrim(strtolower($extension),'.');
    }
    public static function disposition(string $filename,bool $inline): string
    {
        $ascii=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$filename)?:'report'; $ascii=preg_replace('/[^A-Za-z0-9 ._()-]/','-',$ascii);
        return ($inline?'inline':'attachment').'; filename="'.addcslashes($ascii,'"\\').'"; filename*=UTF-8\'\''.rawurlencode($filename);
    }
}
