<?php

namespace App\Exports;

use App\Support\ReportPresentation as P;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ReportExport implements WithMultipleSheets
{
    public function __construct(private array $report, private string $actor, private string $generatedAt) {}

    public function sheets(): array
    {
        $identity=[['Portal Warga'],[P::title($this->report['type'])],['Dibuat',P::display($this->generatedAt,'datetime')],['Oleh',$this->actor]];
        foreach(P::filters($this->report['filters']) as $label=>$value)$identity[]=["Filter $label",$value];
        $identity[]=['Ringkasan','Nilai']; $summaryHeader=count($identity);
        $summaryTypes=[];
        foreach(P::summaryEntries($this->report) as $entry){$identity[]=[$entry['label'],P::excel($entry['value'],$entry['type'])];$summaryTypes[]=$entry['type'];}

        $columns=P::columns($this->report['type']); $data=[['Portal Warga'],[P::title($this->report['type'])],['Dibuat',P::display($this->generatedAt,'datetime')],['Oleh',$this->actor]];
        foreach(P::filters($this->report['filters']) as $label=>$value)$data[]=["Filter $label",$value];
        $headerRow=count($data)+1; $headers=array_merge(['No.'],array_column($columns,0));
        if (!$columns) $headers=['Keterangan'];
        $data[]=$headers;
        $rows=P::rows($this->report);
        foreach($rows as $i=>$row){$line=[$i+1];foreach($columns as $key=>$definition)$line[]=P::excel($row[$key]??null,$definition[1]);$data[]=$line;}
        if (!$rows) $data[]=['Tidak ada data laporan untuk filter yang dipilih.'];
        return [new ReportSheet('Ringkasan',$identity,$summaryHeader,$summaryTypes,false),new ReportSheet('Data',$data,$headerRow,$columns?array_merge(['integer'],array_column($columns,1)):['text'],true,!$rows)];
    }
}
