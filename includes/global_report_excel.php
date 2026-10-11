<?php
/** Opt-in finishing for Global Reports; ordinary spreadsheet exports stay untouched. */
function global_report_excel_body_style($sheet,array $section,array $positions,int $first,int $last,string $dark,string $pale): void {
    $letter=static fn($c)=>\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
    foreach($section['columns'] as $i=>$column){
        [$start,$end]=$positions[$i];$key=$column['key'];$type=$column['type'];
        $money=in_array($type,['money','money_optional'],true);
        $center=in_array($type,['number','count','html','status','kelas'],true)||in_array($key,['no','nis','nis_diknas','export_diknas','NO_INDUK','NO_induk_diknas','kelas','KELAS','export_class','unit','export_unit','jenis','status','periode'],true);
        $alignment=$money?'right':($center?'center':'left');
        $style=['alignment'=>['horizontal'=>$alignment,'vertical'=>'center','wrapText'=>true,'indent'=>$center?0:1]];
        if($money)$style['numberFormat']=['formatCode'=>'"Rp "#,##0;[Red]"Rp "-#,##0;"Rp "0'];
        elseif(in_array($type,['number','count'],true))$style['numberFormat']=['formatCode'=>'#,##0'];
        elseif(in_array($type,['date','datetime'],true))$style['numberFormat']=['formatCode'=>$type==='date'?'dd/mm/yyyy':"dd/mm/yyyy\nhh:mm:ss \"WIB\""];
        if(in_array($key,['nis','kelas','status'],true)||$type==='status'){
            $style['font']=['bold'=>true,'color'=>['rgb'=>$key==='kelas'?'A66300':$dark]];
            $style['fill']=['fillType'=>'solid','startColor'=>['rgb'=>$key==='kelas'?'FFF4E4':$pale]];
        }
        $sheet->getStyle($letter($start).$first.':'.$letter($end).$last)->applyFromArray($style);
        if($type==='status')foreach($section['rows'] as $offset=>$data)if(($data[$key]??'')==='Lunas'){
            $row=$first+$offset;
            $sheet->getStyle($letter($start).$row.':'.$letter($end).$row)->getFill()->setFillType('solid')->getStartColor()->setRGB('DFF5E8');
        }
    }
}

function global_report_excel_identity_count(array $section): int {
    if(isset($section['identity_count']))return $section['identity_count'];
    foreach($section['columns'] as $i=>$column)if(in_array($column['key'],['kelas','KELAS','export_class'],true))return $i+1;
    return 1;
}

function global_report_excel_finish($sheet,array $doc,array $definition,int $n): void {
    $letter=static fn($c)=>\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
    $detail=null;foreach($definition['sections'] as $section)if($section['detail']){$detail=$section;break;}
    $identity=$detail?global_report_excel_identity_count($detail):$n;
    $headEnd=$letter(max(3,$identity));
    // All merged heading blocks stay on the frozen side of the worksheet.
    foreach(array_keys($sheet->getMergeCells()) as $range)if(preg_match('/^[A-Z]+([1-9]):[A-Z]+([1-9])$/D',$range))$sheet->unmergeCells($range);
    foreach(['B1','B3','A5','A6','A7','A9'] as $cell)$sheet->setCellValue($cell,null);
    $schoolStart=$identity>=4?'C':'B';
    $sheet->mergeCells($schoolStart.'1:'.$headEnd.'2')->setCellValueExplicit($schoolStart.'1',$doc['school'],\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->getStyle($schoolStart.'1')->getFont()->setSize(12)->setBold(true);
    $sheet->mergeCells('A3:'.$headEnd.'3')->setCellValue('A3','Perum Bekasi Griya Asri II, Tambun Selatan | Telp. 021-88363466');
    $sheet->getStyle('A3')->getFont()->setSize(8);
    foreach([5=>$doc['title'],6=>'Unit: '.$doc['unit_label'].' | '.$doc['subtitle'],7=>'Dibuat: '.$doc['generated'].' | Petugas: '.$doc['operator'],9=>$definition['sections'][0]['title']] as $row=>$value){
        $sheet->mergeCells('A'.$row.':'.$headEnd.$row)->setCellValueExplicit('A'.$row,$value,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    }
    $sheet->getStyle('A5')->getFont()->setSize(12)->setBold(true);
    $sheet->getStyle('A6:A7')->getFont()->setSize(9);
    $sheet->getStyle('A1:'.$headEnd.'9')->getAlignment()->setWrapText(true)->setVertical('center');
    $chars=0;foreach(array_slice($detail['columns']??$definition['sections'][0]['columns'],0,max(3,$identity)) as $column)$chars+=$column['width'];
    $filterHeight=max(26,12*ceil(mb_strlen('Unit: '.$doc['unit_label'].' | '.$doc['subtitle'])/max(30,$chars)));
    foreach([1=>19,2=>19,3=>15,4=>4,5=>24,6=>$filterHeight,7=>18,8=>4,9=>20] as $row=>$height)$sheet->getRowDimension($row)->setRowHeight($height);
    foreach($sheet->getDrawingCollection() as $drawing){$drawing->setHeight(42)->setOffsetX(6)->setOffsetY(4);if($identity<4)$drawing->setHeight(27);}
    $sheet->getDefaultRowDimension()->setRowHeight(25);
    $sheet->getSheetView()->setZoomScale(100);
    $setup=$sheet->getPageSetup();
    $setup->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
    // Actual sheet widths, font and print scale determine horizontal page ranges.
    $widths=[];foreach(range(1,$n) as $i)$widths[$i]=$sheet->getColumnDimension($letter($i))->getWidth()*5.25+4;
    $landscape=array_sum($widths)>538;$setup->setOrientation($landscape?'landscape':'portrait')->setFitToPage(false)->setScale(90);
    if($detail&&$identity<$n){
        $setup->setColumnsToRepeatAtLeftByStartAndEnd('A',$letter($identity));
        $budget=($landscape?785:538)-array_sum(array_slice($widths,0,$identity));
        $groups=$detail['header_groups']??[];$keys=array_column($detail['columns'],'key');$ranges=[];
        foreach($groups as $group){$ranges[]=array_map(static fn($key)=>array_search($key,$keys,true)+1,$group['keys']);}
        if(!$ranges)foreach(range($identity+1,$n) as $i)$ranges[] = [$i];
        $used=0;
        foreach($ranges as $range){
            $groupWidth=array_sum(array_intersect_key($widths,array_flip($range)));
            if($used&&$used+$groupWidth>$budget){$sheet->setBreak($letter($range[0]).'1',\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::BREAK_COLUMN);$used=0;}
            foreach($range as $i){if($used&&$used+$widths[$i]>$budget){$sheet->setBreak($letter($i).'1',\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::BREAK_COLUMN);$used=0;}$used+=$widths[$i];}
        }
    }
    $sheet->getPageMargins()->setTop(.4)->setBottom(.5)->setLeft(.4)->setRight(.4);
}

function global_report_excel_preview(array $doc): string {
    require_once __DIR__.'/global_report_matrix.php';
    [$accent,$dark,$pale]=spp_excel_palette($doc['unit']);$accent=[1=>'165C44',2=>'203E80',3=>'832A35',0=>'513080'][$doc['unit']];
    $logo=dirname(__DIR__).'/assets/img/school-logo.png';$image=is_file($logo)?'data:image/png;base64,'.base64_encode(file_get_contents($logo)):'';
    ob_start(); ?>
    <!doctype html><html lang="id"><meta charset="utf-8"><style>
    body{margin:0;padding:24px;font:14px Arial,sans-serif;color:#17231d;--bg-surface:#fff;--text-primary:#17231d;--text-secondary:#53616e;--matrix-stripe:#<?= $pale ?>;--unit-table-head-bg:#<?= $accent ?>;--accent:#<?= $accent ?>}
    *{box-sizing:border-box}.excel-head{display:flex;gap:18px;align-items:center;margin-bottom:18px}.excel-head img{width:56px;height:56px;object-fit:contain}.excel-head h1{font-size:20px;margin:0 0 6px}.excel-head p{font-size:12px;margin:0}.excel-title{background:#<?= $accent ?>;color:#fff;padding:15px;font-size:20px;margin:0}.excel-meta{background:#<?= $pale ?>;padding:12px 15px;line-height:1.6;font-size:13px}.excel-section{margin-top:22px}.excel-section h2{font-size:18px}.excel-total{display:flex;gap:18px;flex-wrap:wrap;padding:14px 0}.excel-total span{padding:10px;background:#<?= $pale ?>}.sign{display:flex;justify-content:space-around;text-align:center;padding:25px 0}.sign p{margin-top:55px}
    <?= file_get_contents(dirname(__DIR__).'/assets/css/global_report_matrix.css') ?>
    </style><body id="global-report-workspace" class="global-report-matrix-workspace"><div class="excel-head"><img src="<?= $image ?>" alt="Logo sekolah"><div><h1><?= report_e($doc['school']) ?></h1><p>Perum Bekasi Griya Asri II, Tambun Selatan | Telp. 021-88363466</p></div></div><h1 class="excel-title"><?= report_e($doc['title']) ?></h1><div class="excel-meta">Unit: <?= report_e($doc['unit_label']) ?> | <?= report_e($doc['subtitle']) ?><br>Dibuat: <?= report_e($doc['generated']) ?> | Petugas: <?= report_e($doc['operator']) ?></div>
    <?php foreach($doc['sheets'] as $sheet): foreach($sheet['sections'] as $section):
        $identity=global_report_excel_identity_count($section);$columns=[];foreach($section['columns'] as $column)$columns[]=[$column['key'],$column['label'],$column['type']];
        $groups=[];$byKey=array_column($columns,null,0);
        if(!empty($section['header_groups']))foreach($section['header_groups'] as $group)$groups[]=['label'=>$group['label'],'subtitle'=>'','columns'=>array_map(static fn($key)=>$byKey[$key],$group['keys'])];
        else $groups[]=['label'=>$section['title'],'subtitle'=>'','columns'=>array_slice($columns,$identity)];
        $matrix=['columns'=>$columns,'identity_count'=>$identity,'groups'=>$groups,'rows'=>$section['rows'],'separate_national_id'=>true,'subtotals'=>$section['column_totals']??[],'flat_header'=>$section['flat_header']??false]; ?>
        <section class="excel-section"><h2><?= report_e($section['title']) ?></h2><?= global_report_matrix_html($matrix) ?><div class="excel-total"><?php foreach($section['totals'] as $total): ?><span><?= report_e($total['label']) ?> <strong><?= report_money($total['value']) ?></strong></span><?php endforeach; ?></div></section>
    <?php endforeach;if(!empty($sheet['signatures'])): ?><div class="sign"><div>Kasir/Petugas,<p>(________________________)</p></div><div>Bagian Keuangan,<p>(________________________)</p></div></div><?php endif;endforeach; ?>
    <script src="../assets/js/global_report_workspace.js"></script></body></html>
    <?php return ob_get_clean();
}
