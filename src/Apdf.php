<?php


namespace Vatttan\Apdf;

use Vatttan\Apdf\Pdf\TCPDF;

class Apdf extends TCPDF
{
    public function print($text)
    {
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetFont('dejavusans', '', 12);
        $pdf->AddPage();
        $htmlpersian = $text;
        $pdf->WriteHTML($htmlpersian, true, 0, true, 0);
        $pdf->setRTL(false);
        $pdf->Output();
    }

}
