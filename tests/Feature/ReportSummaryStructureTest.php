<?php

namespace Tests\Feature;

use App\Exports\ReportExport;
use App\Support\ReportPresentation as P;
use Illuminate\Support\Facades\Blade;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReportSummaryStructureTest extends TestCase
{
    private const EXPECTED = [
        'summary' => [['opening_balance','Saldo Awal','money'],['total_income','Total Pemasukan','money'],['total_expense','Total Pengeluaran','money'],['closing_balance','Saldo Akhir','money'],['total_billed','Total Tagihan','money'],['total_paid_bills','Tagihan Terbayar','money'],['total_receivables','Total Piutang','money'],['houses_in_arrears','Rumah Menunggak','integer']],
        'income' => [['total_income','Total Pemasukan','money'],['income_transaction_count','Jumlah Transaksi Pemasukan','integer'],['cash_income','Pemasukan Tunai','money'],['transfer_income','Pemasukan Transfer','money'],['active_payment_count','Pembayaran Tercatat','integer'],['cancelled_payment_count','Pembayaran Dibatalkan','integer']],
        'expenses' => [['total_expense','Total Pengeluaran','money'],['expense_transaction_count','Jumlah Transaksi Pengeluaran','integer'],['active_expense_count','Pengeluaran Tercatat','integer'],['cancelled_expense_count','Pengeluaran Dibatalkan','integer']],
        'receivables' => [['total_receivables','Total Piutang','money'],['receivable_count','Jumlah Piutang','integer'],['houses_in_arrears','Rumah Menunggak','integer']],
        'payments' => [['total_income','Total Pembayaran Aktif','money'],['income_transaction_count','Jumlah Transaksi','integer'],['active_payment_count','Pembayaran Aktif','integer'],['cancelled_payment_count','Pembayaran Dibatalkan','integer']],
        'bills' => [['total_billed','Total Tagihan','money'],['total_paid_bills','Tagihan Terbayar','money'],['bill_count','Jumlah Tagihan','integer'],['paid_bill_count','Tagihan Lunas','integer'],['unpaid_bill_count','Tagihan Belum Lunas','integer'],['cancelled_bill_count','Tagihan Dibatalkan','integer']],
        'houses' => [['billed','Total Tagihan','money'],['paid_on_bills','Terbayar pada Tagihan','money'],['payments','Total Pembayaran','money'],['outstanding','Sisa Tagihan','money']],
        'monthly' => [],
    ];

    public static function reportTypes(): array
    {
        return array_map(fn ($type) => [$type], array_keys(self::EXPECTED));
    }

    public static function contextualReports(): array
    {
        return [
            ['summary',['Saldo Awal','Total Pemasukan','Total Pengeluaran','Saldo Akhir','Total Tagihan','Tagihan Terbayar','Total Piutang','Rumah Menunggak'],[]],
            ['income',['Total Pemasukan','Jumlah Transaksi Pemasukan','Pemasukan Tunai','Pemasukan Transfer','Pembayaran Tercatat','Pembayaran Dibatalkan'],['Total Pengeluaran','Total Tagihan','Total Piutang','Rumah Menunggak']],
            ['expenses',['Total Pengeluaran','Jumlah Transaksi Pengeluaran','Pengeluaran Tercatat','Pengeluaran Dibatalkan'],['Total Pemasukan','Total Tagihan','Total Piutang','Rumah Menunggak']],
            ['receivables',['Total Piutang','Jumlah Piutang','Rumah Menunggak'],['Total Pemasukan','Total Pengeluaran','Total Tagihan']],
            ['payments',['Total Pembayaran Aktif','Jumlah Transaksi','Pembayaran Aktif','Pembayaran Dibatalkan'],['Total Pemasukan','Total Pengeluaran','Total Tagihan','Total Piutang','Rumah Menunggak']],
            ['bills',['Total Tagihan','Tagihan Terbayar','Jumlah Tagihan','Tagihan Lunas','Tagihan Belum Lunas','Tagihan Dibatalkan'],['Total Pemasukan','Total Pengeluaran','Total Piutang','Rumah Menunggak']],
            ['houses',['Total Tagihan','Terbayar pada Tagihan','Total Pembayaran','Sisa Tagihan'],['Total Pemasukan','Total Pengeluaran','Total Piutang','Rumah Menunggak']],
            ['monthly',[],['Total Pemasukan','Total Pengeluaran','Total Tagihan','Total Piutang','Rumah Menunggak']],
        ];
    }

    #[DataProvider('reportTypes')]
    public function test_all_eight_types_have_exact_centralized_summary_entries(string $type): void
    {
        $entries=P::summaryEntries($this->report($type));
        $this->assertSame(self::EXPECTED[$type],array_map(fn($e)=>[$e['key'],$e['label'],$e['type']],$entries));
        $this->assertSame($entries ? range(1,count($entries)) : [],array_column($entries,'value'));
    }

    public function test_payment_summary_label_overrides_are_exact(): void
    {
        $entries=P::summaryEntries($this->report('payments'));
        $this->assertSame(['Total Pembayaran Aktif','Jumlah Transaksi','Pembayaran Aktif','Pembayaran Dibatalkan'],array_column($entries,'label'));
    }

    public function test_houses_summary_reads_house_totals_not_summary(): void
    {
        $report=$this->report('houses');
        $report['summary']=array_fill_keys(array_column(self::EXPECTED['houses'],0),999);
        $this->assertSame([1,2,3,4],array_column(P::summaryEntries($report),'value'));
    }

    public function test_monthly_has_zero_summary_entries_even_when_summary_has_values(): void
    {
        $this->assertSame([],P::summaryEntries(['type'=>'monthly','summary'=>['total_income'=>999]]));
    }

    #[DataProvider('contextualReports')]
    public function test_pdf_has_only_contextual_summary_metrics(string $type,array $included,array $excluded): void
    {
        $html=$this->pdfHtml($this->report($type));
        foreach($included as $label)$this->assertStringContainsString('>'.$label.'<',$html);
        foreach($excluded as $label)$this->assertStringNotContainsString('>'.$label.'<',$html);
    }

    #[DataProvider('contextualReports')]
    public function test_xlsx_has_only_contextual_summary_metrics(string $type,array $included,array $excluded): void
    {
        $book=$this->workbook($this->report($type));
        $values=$this->columnValues($book->getSheetByName('Ringkasan'),'A');
        foreach($included as $label)$this->assertContains($label,$values);
        foreach($excluded as $label)$this->assertNotContains($label,$values);
        $book->disconnectWorksheets();
    }

    #[DataProvider('reportTypes')]
    public function test_pdf_and_xlsx_metric_order_matches_presentation_entries(string $type): void
    {
        $report=$this->report($type); $labels=array_column(P::summaryEntries($report),'label');
        $html=$this->pdfHtml($report); $position=-1;
        foreach($labels as $label){$next=strpos($html,'>'.$label.'<');$this->assertGreaterThan($position,$next);$position=$next;}
        $book=$this->workbook($report); $sheet=$book->getSheetByName('Ringkasan');
        $header=$this->findRow($sheet,'Ringkasan','Nilai');
        $this->assertSame($labels,array_slice($this->columnValues($sheet,'A'),$header));
        $book->disconnectWorksheets();
    }

    public function test_empty_expenses_xlsx_has_named_sheets_and_exact_identity(): void
    {
        $book=$this->workbook($this->zeroReport('expenses'));
        $this->assertSame(['Ringkasan','Data'],$book->getSheetNames());
        foreach($book->getWorksheetIterator() as $sheet){$this->assertSame('Portal Warga',$sheet->getCell('A1')->getValue());$this->assertSame('Laporan Pengeluaran',$sheet->getCell('A2')->getValue());}
        $book->disconnectWorksheets();
    }

    public function test_empty_expenses_summary_header_and_rows_are_exact(): void
    {
        $book=$this->workbook($this->zeroReport('expenses')); $sheet=$book->getSheetByName('Ringkasan');
        $row=$this->findRow($sheet,'Ringkasan','Nilai');
        $this->assertSame(5,$row);
        $this->assertSame(['Total Pengeluaran','Jumlah Transaksi Pengeluaran','Pengeluaran Tercatat','Pengeluaran Dibatalkan'],array_slice($this->columnValues($sheet,'A'),$row));
        $book->disconnectWorksheets();
    }

    public function test_first_summary_metric_is_not_header_filter_or_frozen_row(): void
    {
        $book=$this->workbook($this->zeroReport('expenses')); $sheet=$book->getSheetByName('Ringkasan'); $header=$this->findRow($sheet,'Ringkasan','Nilai'); $metric=$header+1;
        $this->assertSame("A$header:B$header",$sheet->getAutoFilter()->getRange());
        $this->assertSame("A$metric",$sheet->getFreezePane());
        $this->assertNotSame($sheet->getStyle("A$header")->getFill()->getStartColor()->getARGB(),$sheet->getStyle("A$metric")->getFill()->getStartColor()->getARGB());
        $book->disconnectWorksheets();
    }

    public function test_empty_summary_money_and_counts_are_numeric_zero_with_exact_formats(): void
    {
        $book=$this->workbook($this->zeroReport('expenses')); $sheet=$book->getSheetByName('Ringkasan'); $row=$this->findRow($sheet,'Ringkasan','Nilai');
        foreach(['B'.($row+1)=>'Rp #,##0','B'.($row+2)=>'0','B'.($row+3)=>'0','B'.($row+4)=>'0'] as $coordinate=>$format){$cell=$sheet->getCell($coordinate);$this->assertSame(0,$cell->getValue());$this->assertSame(DataType::TYPE_NUMERIC,$cell->getDataType());$this->assertSame($format,$cell->getStyle()->getNumberFormat()->getFormatCode());}
        $book->disconnectWorksheets();
    }

    public function test_payment_summary_pdf_labels_and_xlsx_values_formats_are_exact(): void
    {
        $report=['type'=>'payments','filters'=>[],'summary'=>['total_income'=>125000,'income_transaction_count'=>2,'active_payment_count'=>1,'cancelled_payment_count'=>1],'rows'=>[]];
        $html=$this->pdfHtml($report);
        foreach(['Total Pembayaran Aktif','Rp 125.000','Jumlah Transaksi','Pembayaran Aktif','Pembayaran Dibatalkan'] as $text)$this->assertStringContainsString($text,$html);
        $book=$this->workbook($report);$sheet=$book->getSheetByName('Ringkasan');$row=$this->findRow($sheet,'Ringkasan','Nilai');
        foreach([['B'.($row+1),125000,'Rp #,##0'],['B'.($row+2),2,'0'],['B'.($row+3),1,'0'],['B'.($row+4),1,'0']] as [$coordinate,$value,$format]){$cell=$sheet->getCell($coordinate);$this->assertSame($value,$cell->getValue());$this->assertSame(DataType::TYPE_NUMERIC,$cell->getDataType());$this->assertSame($format,$cell->getStyle()->getNumberFormat()->getFormatCode());}
        $book->disconnectWorksheets();
    }

    public function test_bill_summary_xlsx_has_exact_six_numeric_metrics_and_formats(): void
    {
        $report=['type'=>'bills','filters'=>[],'summary'=>['total_billed'=>600000,'total_paid_bills'=>250000,'bill_count'=>3,'paid_bill_count'=>1,'unpaid_bill_count'=>1,'cancelled_bill_count'=>1,'total_receivables'=>200000],'rows'=>[]];
        $book=$this->workbook($report);$sheet=$book->getSheetByName('Ringkasan');$row=$this->findRow($sheet,'Ringkasan','Nilai');
        $this->assertSame(['Total Tagihan','Tagihan Terbayar','Jumlah Tagihan','Tagihan Lunas','Tagihan Belum Lunas','Tagihan Dibatalkan'],array_slice($this->columnValues($sheet,'A'),$row,6));
        foreach([[600000,'Rp #,##0'],[250000,'Rp #,##0'],[3,'0'],[1,'0'],[1,'0'],[1,'0']] as $i=>[$value,$format]){$cell=$sheet->getCell('B'.($row+$i+1));$this->assertSame($value,$cell->getValue());$this->assertSame(DataType::TYPE_NUMERIC,$cell->getDataType());$this->assertSame($format,$cell->getStyle()->getNumberFormat()->getFormatCode());}
        $this->assertNotContains('Total Piutang',$this->columnValues($sheet,'A'));
        $book->disconnectWorksheets();
    }

    public function test_summary_used_cells_contain_no_formulas(): void
    {
        $book=$this->workbook($this->zeroReport('expenses')); $sheet=$book->getSheetByName('Ringkasan');
        foreach($sheet->getCellCollection()->getCoordinates() as $coordinate)$this->assertNotSame(DataType::TYPE_FORMULA,$sheet->getCell($coordinate)->getDataType(),$coordinate);
        $book->disconnectWorksheets();
    }

    public function test_empty_data_retains_exact_headers_and_empty_message(): void
    {
        $book=$this->workbook($this->zeroReport('expenses')); $sheet=$book->getSheetByName('Data'); $header=$this->findRow($sheet,'No.','Nomor Transaksi');
        $expected=array_merge(['No.'],array_column(P::columns('expenses'),0));
        $this->assertSame($expected,$sheet->rangeToArray("A$header:I$header",null,true,false)[0]);
        $this->assertSame('Tidak ada data laporan untuk filter yang dipilih.',$sheet->getCell('A'.($header+1))->getValue());
        $book->disconnectWorksheets();
    }

    public function test_empty_data_message_is_merged_across_columns_and_has_no_formulas(): void
    {
        $book=$this->workbook($this->zeroReport('expenses')); $sheet=$book->getSheetByName('Data'); $header=$this->findRow($sheet,'No.','Nomor Transaksi');
        $this->assertArrayHasKey('A'.($header+1).':I'.($header+1),$sheet->getMergeCells());
        foreach($sheet->getCellCollection()->getCoordinates() as $coordinate)$this->assertNotSame(DataType::TYPE_FORMULA,$sheet->getCell($coordinate)->getDataType(),$coordinate);
        $book->disconnectWorksheets();
    }

    public function test_nonempty_expense_total_formula_exists_only_for_amount_column(): void
    {
        $report=$this->zeroReport('expenses');$report['rows'][]=['transaction_number'=>'EXP-1','spent_at'=>'2026-09-01','category'=>'Operasional','title'=>'Air','description'=>'','amount'=>12500,'status'=>'POSTED','created_by'=>'Admin'];
        $book=$this->workbook($report);$sheet=$book->getSheetByName('Data');$header=$this->findRow($sheet,'No.','Nomor Transaksi');$total=$header+2;
        $this->assertSame('Total',$sheet->getCell("A$total")->getValue());
        $this->assertSame('=SUM(G'.($header+1).":G".($header+1).')',$sheet->getCell("G$total")->getValue());
        foreach(['B','C','D','E','F','H','I'] as $column)$this->assertNull($sheet->getCell("$column$total")->getValue());
        $book->disconnectWorksheets();
    }

    public function test_nonempty_monthly_totals_cover_only_relevant_money_columns(): void
    {
        $report=$this->zeroReport('monthly');$report['rows'][]=['period'=>'2026-09','billed'=>1,'income'=>2,'expense'=>3,'receivables'=>4,'closing_balance'=>5];
        $book=$this->workbook($report);$sheet=$book->getSheetByName('Data');$header=$this->findRow($sheet,'No.','Periode');$total=$header+2;
        $this->assertNull($sheet->getCell("B$total")->getValue());
        foreach(['C','D','E','F','G'] as $column)$this->assertSame("=SUM($column".($header+1).":$column".($header+1).')',$sheet->getCell("$column$total")->getValue());
        $book->disconnectWorksheets();
    }

    private function report(string $type): array
    {
        $values=[];foreach(self::EXPECTED[$type] as $i=>$entry)$values[$entry[0]]=$i+1;
        return ['type'=>$type,'filters'=>[],'summary'=>$type==='houses'?['billed'=>999]:$values,'house_totals'=>$type==='houses'?$values:[],'rows'=>[]];
    }

    private function zeroReport(string $type): array
    {
        $report=$this->report($type);$key=$type==='houses'?'house_totals':'summary';foreach($report[$key] as &$value)$value=0;unset($value);return $report;
    }

    private function pdfHtml(array $report): string
    {
        return Blade::render(file_get_contents(resource_path('views/reports/pdf.blade.php')),['report'=>$report,'actor'=>'Tester','generatedAt'=>'2026-09-30T00:15:00Z']);
    }

    private function workbook(array $report): Spreadsheet
    {
        $path=tempnam(sys_get_temp_dir(),'summary-').'.xlsx';file_put_contents($path,Excel::raw(new ReportExport($report,'Tester','2026-09-30T00:15:00Z'),ExcelFormat::XLSX));
        try{return IOFactory::load($path);}finally{unlink($path);}
    }

    private function findRow(Worksheet $sheet,string $a,string $b): int
    {
        for($row=1;$row<=$sheet->getHighestRow();$row++)if($sheet->getCell("A$row")->getValue()===$a&&$sheet->getCell("B$row")->getValue()===$b)return $row;
        $this->fail("Row [$a, $b] not found");
    }

    private function columnValues(Worksheet $sheet,string $column): array
    {
        $values=[];for($row=1;$row<=$sheet->getHighestRow();$row++)$values[]=$sheet->getCell("$column$row")->getValue();return $values;
    }
}
