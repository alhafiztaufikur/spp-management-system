<?php
/** Decode real XLSX for existing report assertions; reject user formulas. */
function test_excel_read(string $binary): \PhpOffice\PhpSpreadsheet\Spreadsheet {
    require_once __DIR__.'/../vendor/autoload.php';
    if(!str_starts_with($binary,'PK'))throw new RuntimeException('Expected a real XLSX archive.');
    $file=tempnam(sys_get_temp_dir(),'spp_test_xlsx_');try{file_put_contents($file,$binary);$book=\PhpOffice\PhpSpreadsheet\IOFactory::load($file);foreach($book->getAllSheets() as $sheet)foreach($sheet->getCellCollection()->getCoordinates() as $coord)if($sheet->getCell($coord)->getDataType()==='f')throw new RuntimeException('Unexpected executable formula at '.$coord);return $book;}finally{unlink($file);}
}
function test_excel_html(string $binary,bool $bareMoney=false): string {
    $book=test_excel_read($binary);$html='';try{foreach($book->getAllSheets() as $sheet){$html.='<table>';for($r=1;$r<=$sheet->getHighestDataRow();$r++){$html.='<tr>';for($c=1;$c<=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());$c++){$cell=$sheet->getCellByColumnAndRow($c,$r);$v=$cell->getValue();if($v===null)continue;$format=$cell->getStyle()->getNumberFormat()->getFormatCode();if($cell->getDataType()==='n'&&str_contains($format,'Rp'))$v=($bareMoney?'':'Rp ').number_format((float)$v,0,',','.');elseif($cell->getDataType()==='n'&&\PhpOffice\PhpSpreadsheet\Shared\Date::isDateTime($cell))$v=$cell->getFormattedValue();$html.='<td>'.htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8').'</td>';}$html.='</tr>';}$html.='</table>';}}finally{$book->disconnectWorksheets();}return $html;
}
