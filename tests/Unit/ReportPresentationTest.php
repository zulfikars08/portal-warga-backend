<?php

namespace Tests\Unit;

use App\Support\ReportPresentation as P;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReportPresentationTest extends TestCase
{
    public static function titles(): array
    {
        return [
            ['summary','Ringkasan Keuangan'], ['income','Laporan Pemasukan'],
            ['expenses','Laporan Pengeluaran'], ['receivables','Laporan Piutang'],
            ['payments','Laporan Pembayaran'], ['bills','Laporan Tagihan'],
            ['houses','Laporan Per Rumah'], ['monthly','Rekap Bulanan'],
        ];
    }

    #[DataProvider('titles')]
    public function test_all_report_titles_and_unfiltered_filenames(string $type, string $title): void
    {
        $this->assertSame($title, P::title($type));
        $this->assertSame("$title.pdf", P::filename($type, [], '.PDF'));
    }

    public function test_filename_describes_each_date_filter_shape(): void
    {
        $this->assertSame('Laporan Pemasukan 1 Januari 2026 - 31 Januari 2026.xlsx', P::filename('income',['from'=>'2026-01-01','to'=>'2026-01-31'],'xlsx'));
        $this->assertSame('Laporan Pemasukan Mulai 1 Januari 2026.pdf', P::filename('income',['from'=>'2026-01-01'],'pdf'));
        $this->assertSame('Laporan Pemasukan Sampai 30 September 2026.pdf', P::filename('income',['to'=>'2026-09-30'],'pdf'));
    }

    public function test_month_year_and_house_code_filename_order_are_exact(): void
    {
        $this->assertSame('Rekap Bulanan September 2026.xlsx', P::filename('monthly',['month'=>'2026-09'],'xlsx'));
        $this->assertSame('Laporan Tagihan 2027.pdf', P::filename('bills',['year'=>2027],'pdf'));
        $this->assertSame('Laporan Per Rumah A-01 September 2026.pdf', P::filename('houses',['month'=>'2026-09'],'pdf','A-01'));
    }

    public function test_filename_removes_illegal_characters_and_trailing_dots(): void
    {
        $this->assertSame('Laporan Per Rumah A-B-C-D-E-F-G-H-I.xlsx', P::filename('houses',[],'xlsx','A<B>:C"D/E\\F|G?H*I.'));
    }

    public function test_disposition_supports_rfc5987_for_inline_and_attachment(): void
    {
        $name='Laporan Piutang September 2026.pdf';
        $this->assertSame('inline; filename="Laporan Piutang September 2026.pdf"; filename*=UTF-8\'\'Laporan%20Piutang%20September%202026.pdf',P::disposition($name,true));
        $this->assertStringStartsWith('attachment; filename=',P::disposition('Laporan ü.xlsx',false));
        $this->assertStringContainsString("filename*=UTF-8''Laporan%20%C3%BC.xlsx",P::disposition('Laporan ü.xlsx',false));
    }

    public function test_display_humanizes_enums_period_dates_datetimes_money_and_counts(): void
    {
        $this->assertSame('Dibayar sebagian',P::display('PARTIAL','enum'));
        $this->assertSame('Menunggu Verifikasi',P::display('MENUNGGU_VERIFIKASI','enum'));
        $this->assertSame('September 2026',P::display('2026-09','period'));
        $this->assertSame('30 September 2026',P::display('2026-09-30','date'));
        $this->assertSame('30 September 2026, 07.15 WIB',P::display('2026-09-30T00:15:00Z','datetime'));
        $this->assertSame('Rp 1.234.567',P::display(1234567,'money'));
        $this->assertSame('1.234.567',P::display(1234567,'integer'));
    }

    public function test_excel_keeps_money_and_counts_numeric(): void
    {
        $this->assertSame(12500,P::excel('12500','money'));
        $this->assertSame(12,P::excel('12','integer'));
        $this->assertSame('Tunai',P::excel('CASH','enum'));
        $this->assertSame('September 2026',P::excel('2026-09','period'));
    }

    public function test_bill_summary_has_exact_six_metrics(): void
    {
        $report=['type'=>'bills','summary'=>['total_billed'=>600,'total_paid_bills'=>250,'bill_count'=>3,'paid_bill_count'=>1,'unpaid_bill_count'=>1,'cancelled_bill_count'=>1,'total_receivables'=>999]];
        $this->assertSame([
            ['key'=>'total_billed','label'=>'Total Tagihan','type'=>'money','value'=>600],
            ['key'=>'total_paid_bills','label'=>'Tagihan Terbayar','type'=>'money','value'=>250],
            ['key'=>'bill_count','label'=>'Jumlah Tagihan','type'=>'integer','value'=>3],
            ['key'=>'paid_bill_count','label'=>'Tagihan Lunas','type'=>'integer','value'=>1],
            ['key'=>'unpaid_bill_count','label'=>'Tagihan Belum Lunas','type'=>'integer','value'=>1],
            ['key'=>'cancelled_bill_count','label'=>'Tagihan Dibatalkan','type'=>'integer','value'=>1],
        ],P::summaryEntries($report));
    }

    public function test_filters_have_indonesian_labels_and_stable_order(): void
    {
        $this->assertSame(['Mulai'=>'1 September 2026','Sampai'=>'30 September 2026','Bulan'=>'September 2026','Status'=>'Lunas'],P::filters(['status'=>'PAID','month'=>'2026-09','to'=>'2026-09-30','from'=>'2026-09-01']));
    }
}
