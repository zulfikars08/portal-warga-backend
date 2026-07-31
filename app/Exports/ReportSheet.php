<?php

namespace App\Exports;

use App\Support\ReportPresentation as P;
use Maatwebsite\Excel\Concerns\{FromArray,WithTitle,WithStyles,ShouldAutoSize,WithEvents,WithStrictNullComparison};
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ReportSheet implements FromArray,WithTitle,WithStyles,ShouldAutoSize,WithEvents,WithStrictNullComparison
{
    public function __construct(private string $title,private array $data,private int $headerRow,private array $types=[],private bool $total=true,private bool $empty=false) {}
    public function title():string{return mb_substr($this->title,0,31);}
    public function array():array{return $this->data;}
    public function styles(Worksheet $s):array{return [$this->headerRow=>['font'=>['bold'=>true,'color'=>['argb'=>'FFFFFFFF']],'fill'=>['fillType'=>'solid','startColor'=>['argb'=>'FF1F4E78']]],1=>['font'=>['bold'=>true,'size'=>16,'color'=>['argb'=>'FF1F4E78']]]];}
    public function registerEvents():array{return [AfterSheet::class=>function(AfterSheet $e){$s=$e->sheet->getDelegate();$last=$this->title==='Ringkasan'?'B':Coordinate::stringFromColumnIndex(count($this->types));$lastRow=count($this->data);$s->freezePane('A'.($this->headerRow+1));$s->setAutoFilter("A{$this->headerRow}:{$last}{$this->headerRow}");$s->getStyle($s->calculateWorksheetDimension())->getAlignment()->setVertical('top')->setWrapText(true);foreach($this->types as $i=>$type){if($this->title==='Ringkasan'){$s->getStyle('B'.($this->headerRow+1+$i))->getNumberFormat()->setFormatCode(P::numberFormat($type));}else{$col=Coordinate::stringFromColumnIndex($i+1);$s->getStyle("{$col}".($this->headerRow+1).":{$col}{$lastRow}")->getNumberFormat()->setFormatCode(P::numberFormat($type));}}if($this->empty&&$last!=='A')$s->mergeCells("A".($this->headerRow+1).":{$last}".($this->headerRow+1));if($this->total&&!$this->empty&&$lastRow>$this->headerRow){$row=$lastRow+1;$s->setCellValue("A$row",'Total');foreach($this->types as $i=>$type)if($type==='money'){$col=Coordinate::stringFromColumnIndex($i+1);$s->setCellValue("$col$row","=SUM($col".($this->headerRow+1).":$col$lastRow)");$s->getStyle("$col$row")->getNumberFormat()->setFormatCode(P::numberFormat('money'));}$s->getStyle("A$row:$last$row")->getFont()->setBold(true);}}];}
}
