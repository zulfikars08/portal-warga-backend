<?php

namespace Tests\Feature;

use App\Models\House;
use App\Models\User;
use App\Support\ReportPresentation as P;
use Database\Seeders\InitialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

class ReportExportPresentationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(InitialSeeder::class);
        $this->admin=User::where('email','superadmin@portalwarga.test')->firstOrFail();
    }

    public static function reports(): array
    {
        return [
            ['summary','Ringkasan Keuangan'], ['income','Laporan Pemasukan'],
            ['expenses','Laporan Pengeluaran'], ['receivables','Laporan Piutang'],
            ['payments','Laporan Pembayaran'], ['bills','Laporan Tagihan'],
            ['houses','Laporan Per Rumah'], ['monthly','Rekap Bulanan'],
        ];
    }

    #[DataProvider('reports')]
    public function test_each_pdf_is_inline_valid_and_named_for_its_indonesian_title(string $type,string $title): void
    {
        $response=$this->actingAs($this->admin)->get($this->exportUrl($type,'pdf'));
        $response->assertOk()->assertHeader('Content-Type','application/pdf');
        $disposition=$response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('inline;', $disposition);
        $this->assertStringContainsString(rawurlencode($title), $disposition);
        $content=$response->getContent();
        $this->assertStringStartsWith('%PDF-', $content);
        $this->assertGreaterThan(500,strlen($content));
        $this->assertStringNotContainsString('Laravel', $content);
        $this->assertStringNotContainsString('Laporan Receivables', $content);
    }

    #[DataProvider('reports')]
    public function test_each_xlsx_is_attachment_with_same_filtered_filename(string $type,string $title): void
    {
        $response=$this->actingAs($this->admin)->get($this->exportUrl($type,'xlsx'));
        $response->assertOk();
        $this->assertStringContainsString('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',$response->headers->get('Content-Type'));
        $disposition=$response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment;', $disposition);
        $expected=$title.($type==='houses'?' '.House::firstOrFail()->house_code:'').' September 2026.xlsx';
        $this->assertStringContainsString(rawurlencode($expected),$disposition);
        $this->assertStringStartsWith('PK',$response->streamedContent());
    }

    public function test_xlsx_contains_ringkasan_data_human_headers_and_numeric_money(): void
    {
        $response=$this->actingAs($this->admin)->get('/api/v1/reports/monthly/export/xlsx?month=2026-09');
        $binary=$response->streamedContent();
        $path=tempnam(sys_get_temp_dir(),'report-xlsx-');
        file_put_contents($path,$binary);
        $zip=new ZipArchive();
        $this->assertTrue($zip->open($path)===true);
        $workbook=$zip->getFromName('xl/workbook.xml');
        $strings=$zip->getFromName('xl/sharedStrings.xml');
        $sheet=$zip->getFromName('xl/worksheets/sheet2.xml');
        $zip->close(); unlink($path);
        $this->assertStringContainsString('name="Ringkasan"',$workbook);
        $this->assertStringContainsString('name="Data"',$workbook);
        $this->assertStringContainsString('Rekap Bulanan',$strings);
        $this->assertStringContainsString('Saldo Akhir',$strings);
        $this->assertStringContainsString('Pemasukan',$strings);
        $this->assertMatchesRegularExpression('/<c r="[A-Z]+\d+"(?: s="\d+")?><v>\d+<\/v><\/c>/',$sheet);
    }

    public function test_pdf_blade_presents_indonesian_labels_types_and_never_midnight_noise(): void
    {
        $report=[
            'type'=>'bills','filters'=>['month'=>'2026-09','status'=>'PARTIAL'],
            'summary'=>['total_billed'=>125000,'total_paid_bills'=>25000,'bill_count'=>2,'paid_bill_count'=>0,'unpaid_bill_count'=>0,'cancelled_bill_count'=>0,'total_receivables'=>100000],
            'rows'=>[['title'=>'Iuran September','house_code'=>'A-01','head_name'=>'Budi','bill_type'=>'ROUTINE','period'=>'2026-09','due_date'=>'2026-09-30','amount'=>100000,'paid_amount'=>25000,'outstanding_amount'=>75000,'status'=>'PARTIAL']],
        ];
        $html=Blade::render(file_get_contents(resource_path('views/reports/pdf.blade.php')),['report'=>$report,'actor'=>'Admin','generatedAt'=>'2026-09-30T00:15:00Z']);
        foreach(['Laporan Tagihan','Bulan:','September 2026','Status:','Dibayar sebagian','Total Tagihan','Rp 125.000','Jumlah Tagihan','>2<','Kode Rumah','Tagihan rutin','30 September 2026'] as $text)$this->assertStringContainsString($text,$html);
        $this->assertStringNotContainsString('00:00',$html);
        $this->assertStringNotContainsString('Laporan Receivables',$html);
        $this->assertStringNotContainsString('Total Piutang',$html);
    }

    private function exportUrl(string $type,string $format): string
    {
        $query='month=2026-09';
        if($type==='houses')$query.='&house_id='.House::firstOrFail()->id;
        return "/api/v1/reports/$type/export/$format?$query";
    }
}
