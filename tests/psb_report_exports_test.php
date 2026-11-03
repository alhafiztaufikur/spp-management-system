<?php
/** Validate downloaded HTTP spreadsheets against the independently built PSB models. */
if(PHP_SAPI!=='cli')exit(1);
$dir=(string)getenv('SPP_PSB_ARTIFACTS');
if(!$dir||str_starts_with(strtolower(str_replace('\\','/',$dir)),strtolower(str_replace('\\','/',realpath(__DIR__.'/..')))))throw new RuntimeException('External artifacts required.');
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../includes/units.php';
require_once __DIR__.'/../includes/reports.php';
require_once __DIR__.'/../includes/global_report_matrix.php';
$models=json_decode(file_get_contents($dir.'/models.json'),true,512,JSON_THROW_ON_ERROR)['models'];$checks=0;
foreach([1,2,3,0] as $unit){
    $report=$models[$unit]['report'];$matrix=global_report_matrix($report,'psb',$unit);
    $doc=global_report_matrix_excel($matrix,$report,'psb',$unit);$section=$doc['sheets'][0]['sections'][0];
    $book=\PhpOffice\PhpSpreadsheet\IOFactory::load($dir.'/http-'.($unit?:'all').'.xlsx');$sheet=$book->getActiveSheet();
    if(in_array('nis_diknas',array_column($section['columns'],'key'),true)||$section['identity_count']!==$matrix['identity_count'])throw new RuntimeException('PSB Excel must omit NIS Diknas and preserve identity spans.');
    foreach($section['columns'] as $j=>$column)if($sheet->getCellByColumnAndRow($j+1,11)->getValue()!==$column['label'])throw new RuntimeException('PSB Excel header differs.');
    if(count($section['rows'])<=25)throw new RuntimeException('Fixture must exceed one screen page.');
    foreach($section['rows'] as $index=>$row)foreach($section['columns'] as $j=>$column){
        $cell=$sheet->getCellByColumnAndRow($j+1,$index+12);$expected=$row[$column['key']]??null;$checks++;
        if(in_array($column['type'],['money','money_optional','number'],true)&&$expected!==null){
            if($cell->getDataType()!=='n'||abs((float)$cell->getValue()-(float)$expected)>.001)throw new RuntimeException('HTTP XLSX amount differs at unit '.$unit.' '.$cell->getCoordinate());
        }elseif((string)$cell->getValue()!==($expected===null?'—':spp_excel_text($expected)))throw new RuntimeException('HTTP XLSX identity/null differs at unit '.$unit.' '.$cell->getCoordinate());
    }
    $subtotal=12+count($section['rows']);
    foreach($section['columns'] as $j=>$column)if(isset($matrix['subtotals'][$column['key']])){
        $expected=$matrix['subtotals'][$column['key']]['value'];$actual=$sheet->getCellByColumnAndRow($j+1,$subtotal)->getValue();$checks++;
        if(abs((float)$actual-(float)$expected)>.001)throw new RuntimeException('HTTP XLSX subtotal differs.');
    }
    $book->disconnectWorksheets();echo 'PASS HTTP XLSX unit '.$unit.': all '.count($section['rows']).' students and totals match model; numeric/text types intact.'.PHP_EOL;
}
echo 'PASS '.$checks.' exported cell and subtotal comparisons.'.PHP_EOL;
