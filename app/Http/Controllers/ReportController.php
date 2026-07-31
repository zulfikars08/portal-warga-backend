<?php

namespace App\Http\Controllers;

use App\Exports\ReportExport;
use App\Http\Requests\ReportRequest;
use App\Services\ReportService;
use App\Support\ReportPresentation as P;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends Controller
{
    public function __construct(private ReportService $service) {}
    public function show(ReportRequest $request,string $type){$data=$this->service->generate($type,$request->validated());return response()->json(['data'=>$data,'meta'=>$this->meta()]);}
    public function export(ReportRequest $request,string $type,string $format)
    {
        if(!in_array($format,['pdf','xlsx'],true))throw ValidationException::withMessages(['format'=>'Format ekspor tidak valid.']);
        $requestedFilters=$request->validated();$data=$this->service->generate($type,$requestedFilters,true);$generated=now('Asia/Jakarta');$actor=$request->user()->name;
        $name=P::filename($type,$requestedFilters,$format,$data['house']['house_code']??null);
        if($format==='pdf'){
            $pdf=Pdf::loadView('reports.pdf',['report'=>$data,'actor'=>$actor,'generatedAt'=>$generated])->setPaper('a4','landscape');
            $pdf->render();$canvas=$pdf->getDomPDF()->getCanvas();$canvas->page_script(function($page,$count,$canvas,$fontMetrics)use($type){$text='Portal Warga · '.P::title($type).' · Halaman '.$page.' dari '.$count;$canvas->text(28,$canvas->get_height()-22,$text,$fontMetrics->getFont('DejaVu Sans'),8,[.35,.4,.45]);});
            return response($pdf->output(),200,['Content-Type'=>'application/pdf','Content-Disposition'=>P::disposition($name,true)]);
        }
        $response=Excel::download(new ReportExport($data,$actor,$generated->toIso8601String()),$name);
        $response->headers->set('Content-Disposition',P::disposition($name,false));return $response;
    }
    private function meta():array{return ['generated_at'=>now('Asia/Jakarta')->toIso8601String(),'currency'=>'IDR','timezone'=>'Asia/Jakarta'];}
}
