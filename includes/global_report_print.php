<?php
/** Opt-in presentation for Global Reports; source rows and financial readers stay intact. */
require_once __DIR__.'/billing_history_print.php';
require_once __DIR__.'/global_report_presentation.php';
function global_report_clean_template(string $template):bool {
    return in_array($template,['status','penerimaan','spp-tahunan','per-item','tabungan-siswa','saldo-tabungan','setoran','kas-tabungan'],true);
}
function global_report_hide_context(string $template):bool{return global_report_clean_template($template);}
function global_report_large_address(string $template):bool{return in_array($template,['saldo-tabungan','status','per-item','spp-tahunan'],true);}
function global_report_print_money($value,bool $currency=true):string{$text=report_money($value);return $currency?$text:preg_replace('/^Rp /','',$text);}
function global_report_short_months(string $label):string {
    return preg_replace_callback('/\b(Januari|Februari|Maret|April|Mei|Juni|Juli|Agustus|September|Oktober|November|Desember)\b/iu',static function($m){
        return ['januari'=>'Jan','februari'=>'Feb','maret'=>'Mar','april'=>'Apr','mei'=>'Mei','juni'=>'Jun','juli'=>'Jul','agustus'=>'Agu','september'=>'Sep','oktober'=>'Okt','november'=>'Nov','desember'=>'Des'][mb_strtolower($m[0])];
    },$label);
}
function global_report_print_text($value,array $column,array $row=[],bool $currency=true):string {
    $key=$column['source']??$column[0];$type=$column[2]??'text';
    if($value===null)return '—';
    if(is_array($value))return trim((string)($value['text']??'').(($value['sub']??'')!==''?"\n".($currency?(string)$value['sub']:preg_replace('/^Rp /','',(string)$value['sub'])):''));
    if(in_array($type,['money','money_optional'],true))return $value===''?'—':global_report_print_money($value,$currency);
    if($type==='datetime'){$p=spp_date_parts($value,$row['time_precision']??null);return $p['date'].($p['time']==='—'?'':"\n".$p['time']);}
    if($type==='date')return spp_date_label($value);
    if($key==='nis'&&!empty($row['nis_diknas']))return (string)$value."\nDiknas ".$row['nis_diknas'];
    return (string)$value;
}
function global_report_print_cell($value,array $column,array $row=[],bool $currency=true):string {
    if(is_array($value)&&($value['sub']??'')!=='')return (($value['text']??'')!==''?report_e((string)$value['text']).'<br>':'').'<span class="global-clean-subvalue">'.report_e($currency?(string)$value['sub']:preg_replace('/^Rp /','',(string)$value['sub'])).'</span>';
    if($value==='Tercakup PSB')return '<span class="report-header-nowrap">Tercakup PSB</span>';
    return nl2br(report_e(global_report_print_text($value,$column,$row,$currency)));
}
function global_report_print_layout(array $matrix,?string $orientation=null):array {
    static $metrics=null,$normal=null,$bold=null;
    if($metrics===null){$font=billing_history_print_font();$pdf=new \Dompdf\Dompdf();$metrics=$pdf->getFontMetrics();$normal=$metrics->getFont($font['family'],'normal');$bold=$metrics->getFont($font['family'],'bold');}
    $widths=[];$currency=$matrix['currency_prefix']??true;
    foreach($matrix['columns'] as $column){
        $key=$column['source']??$column[0];$type=$column[2]??'text';$money=in_array($type,['money','money_optional'],true);
        $width=match($key){'no'=>24,'unit'=>34,'nis'=>72,'nis_diknas'=>82,'nama'=>116,'kelas'=>36,'tanggal'=>90,'jenis'=>42,'operator'=>76,'keterangan'=>76,default=>$money?($currency?54:44):(in_array($type,['html','status'],true)?54:70)};
        $header=global_report_short_months($column[1]);
        foreach(global_report_nowrap_header($header)?[$header]:preg_split('/\s+/u',$header) as $word)$width=max($width,$metrics->getTextWidth($word,$bold,9)+8);
        foreach($matrix['rows'] as $row){
            $value=$key==='no'?count($matrix['rows']):($row[$column[0]]??null);
            $r=!empty($matrix['separate_national_id'])?array_diff_key($row,['nis_diknas'=>true]):$row;
            $text=global_report_print_text($value,$column,$r,$currency);
            $parts=($money||$text==='Tercakup PSB'||in_array($type,['date','datetime'],true)||in_array($key,['no','nis','nis_diknas','kelas','unit'],true))?explode("\n",$text):preg_split('/\s+/u',$text);
            // Excel reserves the complete numeric date-format width even with a newline.
            if(!empty($matrix['excel_dates'])&&$type==='datetime'&&$value){$parts[]=str_replace("\n",' ',$text);if(($row['time_precision']??'')==='date')$parts[]=spp_date_label($value).' · Jam tidak tercatat';}
            foreach($parts as $part)$width=max($width,$metrics->getTextWidth($part,$normal,9)+8);
            if(is_array($value)&&($value['sub']??'')!=='')$width=max($width,$metrics->getTextWidth($currency?(string)$value['sub']:preg_replace('/^Rp /','',(string)$value['sub']),$normal,9)+8);
        }
        // Keep subtotal figures on one line as well as individual values.
        $total=$matrix['subtotals'][$column[0]]??null;
        if($total)$width=max($width,$metrics->getTextWidth(global_report_print_money($total['value'],$currency),$bold,9)+8);
        $widths[$column[0]]=$width;
    }
    $natural=array_sum($widths);$orientation??=$natural<=538?'portrait':'landscape';$available=$orientation==='landscape'?785:538;
    $scale=min(1,($available-2)/max(1,$natural));
    return ['widths'=>$widths,'orientation'=>$orientation,'font_pt'=>round(9*$scale,3),'padding_y'=>round(3*$scale,3),'padding_x'=>round(3*$scale,3),'natural_width'=>$natural];
}
function global_report_clean_table(array $matrix,?array $visible=null,int $start=0,bool $subtotal=true):string {
    $layout=$matrix['_clean_layout']??global_report_print_layout($matrix);$rows=$visible??$matrix['rows'];$identity=array_slice($matrix['columns'],0,$matrix['identity_count']);$groups=$matrix['groups'];$currency=$matrix['currency_prefix']??true;
    if(!empty($matrix['flat_header'])){$groups=[];foreach(array_slice($matrix['columns'],$matrix['identity_count']) as $c)$groups[]=['label'=>$c[1],'subtitle'=>'','columns'=>[$c]];}
    $single=true;foreach($groups as $g)if(count($g['columns'])!==1||($g['subtitle']??'')!==''||$g['label']!==$g['columns'][0][1])$single=false;
    $style='font-size:'.$layout['font_pt'].'pt';$pad='padding:'.$layout['padding_y'].'pt '.$layout['padding_x'].'pt';$totalWidth=array_sum($layout['widths']);
    // Dompdf splits a first-row colspan equally. Size each leaf column before grouped headings.
    $width=static fn(array $cols)=>100*array_sum(array_map(static fn($c)=>$layout['widths'][$c[0]],$cols))/max(1,$totalWidth);
    ob_start(); ?><div class="global-clean-scroll"><table class="global-clean-table<?= ($matrix['report_template']??'')==='saldo-tabungan'?' global-clean-balance':'' ?>" style="<?= $style ?>"><colgroup><?php foreach($matrix['columns'] as $c): ?><col style="width:<?= 100*$layout['widths'][$c[0]]/max(1,$totalWidth) ?>%"><?php endforeach; ?></colgroup><thead><tr class="global-clean-column-widths" aria-hidden="true"><?php foreach($matrix['columns'] as $c): ?><th style="width:<?= $width([$c]) ?>%"></th><?php endforeach; ?></tr><tr>
    <?php foreach($identity as $c): ?><th style="<?= $pad ?>;width:<?= $width([$c]) ?>%" rowspan="<?= $single?1:2 ?>"><?= report_e(global_report_short_months($c[1])) ?></th><?php endforeach; ?>
    <?php foreach($groups as $g): ?><th class="<?= global_report_nowrap_header($g['label'])?'report-header-nowrap':'' ?>" style="<?= $pad ?>;width:<?= $width($g['columns']) ?>%" colspan="<?= count($g['columns']) ?>"><?= report_e(global_report_short_months($g['label'])) ?><?= ($g['subtitle']??'')!==''?'<br>'.report_e(global_report_short_months($g['subtitle'])):'' ?></th><?php endforeach; ?></tr>
    <?php if(!$single): ?><tr><?php foreach($groups as $g)foreach($g['columns'] as $c): ?><th class="<?= global_report_nowrap_header($c[1])?'report-header-nowrap':'' ?>" style="<?= $pad ?>;width:<?= $width([$c]) ?>% "><?= report_e(global_report_short_months($c[1])) ?></th><?php endforeach; ?></tr><?php endif; ?></thead><tbody>
    <?php if(!$rows): ?><tr><td colspan="<?= count($matrix['columns']) ?>">Tidak ada data sesuai filter.</td></tr><?php endif; ?>
    <?php foreach($rows as $i=>$row): ?><tr><?php foreach($matrix['columns'] as $c):$key=$c['source']??$c[0];$type=$c[2]??'text';$value=$c[0]==='no'?$start+$i+1:($row[$c[0]]??null);$r=!empty($matrix['separate_national_id'])?array_diff_key($row,['nis_diknas'=>true]):$row;$class=in_array($type,['money','money_optional'],true)?'numeric':(in_array($key,['no','nis','nis_diknas','kelas','unit'],true)?'identity':'text'); ?><td class="<?= $class ?>" data-column="<?= report_e($key) ?>" style="<?= $pad ?>"><?= global_report_print_cell($value,$c,$r,$currency) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
    <?php if($subtotal&&!empty($matrix['subtotals'])): ?><tfoot><tr><th colspan="<?= count($identity) ?>"><?= global_report_short_subtotal($matrix['report_template']??'',$matrix['presentation_surface']??'print')?'Subtotal':'Subtotal seluruh hasil filter' ?></th><?php foreach(array_slice($matrix['columns'],count($identity)) as $c):$t=$matrix['subtotals'][$c[0]]??null; ?><td class="numeric" style="<?= $pad ?>"><?= $t?report_e(global_report_print_money($t['value'],$currency)):'—' ?></td><?php endforeach; ?></tr></tfoot><?php endif; ?></table></div><?php return ob_get_clean();
}
function global_report_clean_styles(bool $pdf=false):string {
    $font=billing_history_print_font();return billing_history_print_styles($pdf).'.global-clean-table,body.global-clean-report table.data{font-family:"'.$font['family'].'",serif}'.file_get_contents(__DIR__.'/../assets/css/global_report_print.css').file_get_contents(__DIR__.'/../assets/css/global_report_totals.css').file_get_contents(__DIR__.'/../assets/css/desktop_actions.css');
}
function global_report_clean_excel_document(array $doc,string $template):array {
    if(!global_report_clean_template($template))return $doc;
    $doc['global_clean']=true;$doc['report_template']=$template;$doc['hide_context']=global_report_hide_context($template);$doc['currency_prefix']=false;
    if($doc['hide_context'])$doc['subtitle']='';
    foreach($doc['sheets'] as &$sheet)foreach($sheet['sections'] as &$section){
        if($template==='kas-tabungan')$section['title']=str_replace('Arus Tabungan','Tabungan',$section['title']);
        $section['compact_totals']=global_report_compact_totals($template);
        $section['subtotal_label']=global_report_short_subtotal($template,'excel')?'Subtotal':'Subtotal seluruh hasil filter';
        if($template==='spp-tahunan')foreach($section['columns'] as &$column){
            if($column['type']!=='html')continue;$numeric=false;
            foreach($section['rows'] as &$row){$cell=$row[$column['key']]??null;
                if(!is_array($cell)||($cell['text']??'')!=='')continue;
                $amount=preg_replace('/^Rp\s+/u','',(string)($cell['sub']??''));
                if($amount==='—'||$amount===''){$row[$column['key']]=null;$numeric=true;}
                elseif(preg_match('/^-?[0-9.]+(?:,[0-9]+)?$/D',$amount)){$row[$column['key']]=(float)str_replace(',','.',str_replace('.','',$amount));$numeric=true;}
            }unset($row);
            if($numeric)$column['type']='money_optional';
        }unset($column);
        foreach($section['columns'] as &$column){$column['label']=global_report_short_months($column['label']);if($template==='kas-tabungan')$column['label']=str_replace('Arus Tabungan','Tabungan',$column['label']);}unset($column);
        foreach($section['header_groups']??[] as $i=>$g)$section['header_groups'][$i]['label']=global_report_short_months($g['label']);
    }unset($section,$sheet);
    return $doc;
}
function global_report_clean_excel_finish($sheet,array $doc,array $definition,int $n):void {
    $letter=static fn($c)=>\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);$last=$letter($n);$end=$sheet->getHighestDataRow();
    $layout=null;foreach($definition['sections'] as $section)if(count($section['columns'])===$n){
        $cols=[];foreach($section['columns'] as $c)$cols[]=[$c['key'],$c['label'],$c['type'],'source'=>$c['source']??$c['key']];
        $layout=global_report_print_layout(['columns'=>$cols,'rows'=>$section['rows'],'subtotals'=>$section['column_totals']??[],'separate_national_id'=>true,'excel_dates'=>true,'currency_prefix'=>$doc['currency_prefix']??true]);break;
    }
    $orientation=$layout['orientation']??'portrait';$budget=$orientation==='landscape'?780:535;$pointWidths=[];
    if($layout){$scale=min(1,$budget/$layout['natural_width']);$tableFont=round(9*$scale,2);$pointWidths=array_values(array_map(static fn($w)=>$w*$scale,$layout['widths']));}
    else{$tableFont=9;$weights=[];for($i=1;$i<=$n;$i++)$weights[]=$sheet->getColumnDimension($letter($i))->getWidth();foreach($weights as $w)$pointWidths[]=$budget*$w/array_sum($weights);}
    $defaultFont=$sheet->getParent()->getDefaultStyle()->getFont();$pdf=new \Dompdf\Dompdf();$metrics=$pdf->getFontMetrics();
    $defaultFace=$metrics->getFont($defaultFont->getName(),'normal');$zero=$defaultFace?$metrics->getTextWidth('0',$defaultFace,$defaultFont->getSize()):5.25;
    // Excel widths use the default font's zero glyph plus five pixels of cell padding.
    foreach($pointWidths as $i=>$points)$sheet->getColumnDimension($letter($i+1))->setWidth(max(.5,($points-3.75)/max(1,$zero)));
    spp_excel_print_heading($sheet,$doc,$definition,$n);
    $all='A1:'.$last.$end;$body='A9:'.$last.$end;
    $sheet->getStyle($all)->getFill()->setFillType('none');$sheet->getStyle($all)->getFont()->getColor()->setRGB('000000');
    $sheet->getStyle($all)->getBorders()->getAllBorders()->setBorderStyle('none');
    $sheet->getStyle($body)->getFont()->setName(billing_history_print_font()['family'])->setSize($tableFont);
    $sheet->getStyle($body)->getAlignment()->setVertical('center')->setWrapText(true)->setIndent(0);
    $headers=array_values(array_unique($definition['_clean_header_rows']??[]));sort($headers,SORT_NUMERIC);
    foreach($headers as $row){$range='A'.$row.':'.$last.$row;$sheet->getStyle($range)->getFont()->setBold(true);$sheet->getStyle($range)->getBorders()->getTop()->setBorderStyle('thin')->getColor()->setRGB('000000');$sheet->getStyle($range)->getBorders()->getBottom()->setBorderStyle('thin')->getColor()->setRGB('000000');$sheet->getRowDimension($row)->setRowHeight(28);}
    $totals=['Subtotal','Subtotal seluruh hasil filter'];foreach($definition['sections'] as $s)foreach($s['totals'] as $t)$totals[]=$t['label'];
    for($row=9;$row<=$end;$row++)if(in_array($sheet->getCell('A'.$row)->getValue(),$totals,true)){
        $range='A'.$row.':'.$last.$row;$sheet->getStyle($range)->getFont()->setBold(true);$sheet->getStyle($range)->getBorders()->getTop()->setBorderStyle('thin')->getColor()->setRGB('000000');$sheet->getStyle($range)->getBorders()->getBottom()->setBorderStyle('thin')->getColor()->setRGB('000000');
    }
    foreach($definition['sections'] as $s)foreach($s['columns'] as $i=>$c)if(count($s['columns'])===$n){
        $center=in_array($c['key'],['no','nis','nis_diknas','kelas','unit','export_unit','export_class','jenis'],true)||(($doc['report_template']??'')==='saldo-tabungan'&&$c['key']==='nama');$numeric=in_array($c['type'],['money','money_optional','number','count'],true);
        foreach($headers as $h){$stop=$end;foreach($headers as $later)if($later>$h){$stop=$later-2;break;}if($stop>$h)$sheet->getStyle($letter($i+1).($h+1).':'.$letter($i+1).$stop)->getAlignment()->setHorizontal($center?'center':($numeric?'right':'left'))->setWrapText(!$numeric&&(!$center||$c['key']==='nama'));}
    }
    $richColumns=[];foreach($definition['sections'] as $s)foreach($s['columns'] as $i=>$c)if($c['type']==='html')$richColumns[$i+1]=true;
    // Remove currency formatting from amounts, preserving literal names and notes.
    for($r=9;$r<=$end;$r++)for($c=1;$c<=$n;$c++){
        $cell=$sheet->getCell($letter($c).$r);$style=$cell->getStyle();$format=str_replace('[Red]','',$style->getNumberFormat()->getFormatCode());
        if(($doc['currency_prefix']??true)===false)$format=str_replace('"Rp "','',$format);
        if(($doc['currency_prefix']??true)===false&&isset($richColumns[$c])&&$cell->getDataType()==='s'&&is_string($cell->getValue())){
            $text=$cell->getValue();$clean=preg_replace('/(^|\n)Rp (?=-?\d)/','$1',$text);
            if($clean!==$text)$cell->setValueExplicit($clean,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
        if($cell->getDataType()==='n')$format=str_replace("\n",'" · "',$format);
        $style->getNumberFormat()->setFormatCode($format);
        // Native Excel otherwise replaces tight numeric/date formats with ####.
        if($cell->getDataType()==='n')$style->getAlignment()->setShrinkToFit(true)->setWrapText(false);
        if($cell->getValue()==='Tercakup PSB')$style->getAlignment()->setShrinkToFit(true)->setWrapText(false);
    }
    // Reflow rows at the final physical widths, including wrapped names and notes.
    $family=billing_history_print_font()['family'];
    for($r=9;$r<=$end;$r++){
        $height=0;
        for($c=1;$c<=$n;$c++){
            $cell=$sheet->getCell($letter($c).$r);if($cell->getValue()===null||$cell->getValue()==='')continue;
            $width=$pointWidths[$c-1];if($cell->isInMergeRange()){[$a,$b]=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::rangeBoundaries($cell->getMergeRange());$width=array_sum(array_slice($pointWidths,$a[0]-1,$b[0]-$a[0]+1));}
            $font=$metrics->getFont($family,$cell->getStyle()->getFont()->getBold()?'bold':'normal');$lines=0;
            foreach(explode("\n",(string)$cell->getFormattedValue()) as $line){$lines++;$used=0;foreach(preg_split('/\s+/u',$line) as $word){$w=$metrics->getTextWidth($word.' ',$font,$tableFont);if($used&&$used+$w>max(1,$width-6)){$lines++;$used=0;}$used+=$w;}}
            $height=max($height,ceil($lines*$tableFont*1.3+5));
        }
        if($height)$sheet->getRowDimension($r)->setRowHeight(max(15,$height));
    }
    foreach($headers as $r)for($c=1;$c<=$n;$c++)if(global_report_nowrap_header((string)$sheet->getCell($letter($c).$r)->getValue()))$sheet->getStyle($letter($c).$r)->getAlignment()->setWrapText(false)->setShrinkToFit(true);
    foreach($definition['_compact_total_blocks']??[] as $block){
        $r=$block['row'];$sheet->setCellValue('A'.$r,null);
        $first=$n;$sum=$pointWidths[$n-1];
        while($first>1&&$sum+$pointWidths[$first-2]<=269.29){$first--;$sum+=$pointWidths[$first-1];}
        $first=min($first,$n-1);$valueStart=$n;$valueWidth=$pointWidths[$n-1];
        while($valueStart>$first+1&&$valueWidth<$sum*.35){$valueStart--;$valueWidth+=$pointWidths[$valueStart-1];}
        $start=$letter($first);$valueCol=$letter($valueStart);
        $merge=static function($sheet,string $a,string $b):void{if($a!==$b)$sheet->mergeCells($a.':'.$b);};
        $merge($sheet,$start.$r,$last.$r);$sheet->setCellValueExplicit($start.$r,'Total Rupiah',\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->getStyle($start.$r.':'.$last.$r)->getFont()->setBold(true)->setSize(10);$sheet->getStyle($start.$r)->getAlignment()->setHorizontal('left')->setWrapText(false);$sheet->getRowDimension($r)->setRowHeight(22);
        foreach($block['totals'] as $i=>$total){$r++;$labelCell=$start.$r;$amountCell=$valueCol.$r;
            $merge($sheet,$labelCell,$letter($valueStart-1).$r);$merge($sheet,$amountCell,$last.$r);
            $sheet->setCellValueExplicit($labelCell,$total['label'],\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);$sheet->setCellValueExplicit($amountCell,(float)$total['value'],\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
            $sheet->getStyle($amountCell)->getNumberFormat()->setFormatCode(fmod((float)$total['value'],1.0)==0?'#,##0;-#,##0;0':'#,##0.00;-#,##0.00;0.00');
            $sheet->getStyle($labelCell.':'.$last.$r)->getFont()->setSize(9)->setBold($i===count($block['totals'])-1);$sheet->getStyle($labelCell)->getAlignment()->setHorizontal('left')->setWrapText(true);$sheet->getStyle($amountCell)->getFont()->setBold(true);$sheet->getStyle($amountCell)->getAlignment()->setHorizontal('right')->setWrapText(false)->setShrinkToFit(true);
            $sheet->getStyle($labelCell.':'.$last.$r)->getBorders()->getBottom()->setBorderStyle('thin')->getColor()->setRGB('777777');
            $sheet->getRowDimension($r)->setRowHeight(max(22,ceil(mb_strlen($total['label'])/max(12,($sum-$valueWidth)/4))*13+5));
        }
        $sheet->getStyle($start.$r.':'.$last.$r)->getBorders()->getTop()->setBorderStyle('thin');
    }
    if(!empty($doc['hide_context'])){$sheet->setCellValue('A6',null);$sheet->getRowDimension(6)->setRowHeight(0);}
    if(in_array($doc['report_template']??'',['tabungan-siswa','penerimaan'],true)){$sheet->getStyle('A3')->getFont()->setSize(10);$sheet->getRowDimension(3)->setRowHeight(24);}
    if(global_report_large_address($doc['report_template']??'')){$sheet->getStyle('A3')->getFont()->setSize(11);$sheet->getRowDimension(3)->setRowHeight(26);}
    $setup=$sheet->getPageSetup();$setup->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)->setOrientation($orientation)->setFitToPage(true)->setFitToWidth(1)->setFitToHeight(0)->setColumnsToRepeatAtLeft(['','']);
    foreach($sheet->getBreaks() as $cell=>$kind)if($kind===\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::BREAK_COLUMN)$sheet->setBreak($cell,\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::BREAK_NONE);
    if($headers)$setup->setRowsToRepeatAtTopByStartAndEnd(1,$definition['_clean_repeat_end']??$headers[0]);
    $sheet->getHeaderFooter()->setOddFooter('&RHalaman &P / &N')->setEvenFooter('&RHalaman &P / &N');
    $sheet->getPageMargins()->setLeft(.4)->setRight(.4)->setTop(.4)->setBottom(.5);
    if(!empty($definition['_compact_total_blocks'])){
        // Excel has no keep-with-next setting. Plan vertical breaks using the fixed
        // row heights, reserving the repeated heading and printer rounding space.
        $height=static function(int $r)use($sheet):float{$d=$sheet->getRowDimension($r);if(!$d->getVisible())return 0;return max(0,$d->getRowHeight()<0?15:$d->getRowHeight());};
        $repeatEnd=$definition['_clean_repeat_end']??($headers[0]??8);$repeatHeight=0;
        for($r=1;$r<=$repeatEnd;$r++)$repeatHeight+=$height($r);
        $capacity=($orientation==='landscape'?595.28:841.89)-(.4+.5)*72-25;
        $blocks=[];foreach($definition['_compact_total_blocks'] as $block){$h=0;for($r=$block['row'];$r<=$block['row']+count($block['totals']);$r++)$h+=$height($r);$blocks[$block['row']]=$h;}
        $used=0;
        for($r=1;$r<=$end;$r++){
            $h=$height($r);$keep=$blocks[$r]??$h;
            // Native Excel interprets the serialized row-break ID as after that row.
            if($r>$repeatEnd&&$used+$keep>$capacity&&$keep<=$capacity-$repeatHeight){$sheet->setBreak('A'.($r-1),\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::BREAK_ROW);$used=$repeatHeight;}
            $used+=$h;
        }
    }
}
function global_report_clean_excel_preview(array $doc):string {
    $logo=__DIR__.'/../assets/img/school-logo.png';$image=is_file($logo)?'data:image/png;base64,'.base64_encode((string)file_get_contents($logo)):'';
    ob_start(); ?><!doctype html><html lang="id"><meta charset="utf-8"><style>body{padding:24px;margin:0;font:14px "Segoe UI",Arial,sans-serif}.kop{width:100%;border-bottom:1pt solid #000;margin-bottom:16px}.kop img{width:58px}.kop h1{text-align:center;font-size:20px}.kop p{text-align:center}.title{text-align:center}.meta{font-size:13px}.excel-section{margin:20px 0}h3{font-size:16px}.excel-total{margin:10px 0}.excel-total span{margin-right:16px}.sign{display:flex;justify-content:space-around;text-align:center;padding-top:24px}.sign p{margin-top:48px}<?= global_report_clean_styles(false) ?></style><body id="global-report-workspace" class="global-clean-report<?= in_array($doc['report_template']??'',['tabungan-siswa','penerimaan'],true)?' global-clean-address':'' ?><?= global_report_large_address($doc['report_template']??'')?' global-clean-large-address':'' ?>"><table class="kop"><tr><td style="width:70px"><img src="<?= $image ?>" alt="Logo sekolah"></td><td><h1><?= report_e($doc['school']) ?></h1><p>Perum Bekasi Griya Asri II, Tambun Selatan | Telp. 021-88363466</p></td></tr></table><div class="title"><h2><?= report_e($doc['title']) ?></h2><?php if(empty($doc['hide_context'])): ?><p>Unit: <?= report_e($doc['unit_label']) ?> | <?= report_e($doc['subtitle']) ?></p><?php endif; ?></div><p class="meta">Dibuat: <?= report_e($doc['generated']) ?> | Petugas: <?= report_e($doc['operator']) ?></p>
    <?php foreach($doc['sheets'] as $sheet):foreach($sheet['sections'] as $s):
        $columns=[];foreach($s['columns'] as $c)$columns[]=[$c['key'],$c['label'],$c['type']];$id=$s['identity_count']??1;$by=array_column($columns,null,0);$groups=[];
        foreach($s['header_groups']??[] as $g)$groups[]=['label'=>$g['label'],'subtitle'=>'','columns'=>array_map(static fn($key)=>$by[$key],$g['keys'])];
        if(!$groups)foreach(array_slice($columns,$id) as $c)$groups[]=['label'=>$c[1],'subtitle'=>'','columns'=>[$c]];
        $matrix=['columns'=>$columns,'identity_count'=>$id,'groups'=>$groups,'rows'=>$s['rows'],'subtotals'=>$s['column_totals']??[],'separate_national_id'=>true,'flat_header'=>$s['flat_header']??false,'currency_prefix'=>$doc['currency_prefix']??true,'report_template'=>$doc['report_template']??'','presentation_surface'=>'excel']; ?>
        <section class="excel-section"><h3><?= report_e($s['title']) ?></h3><?= global_report_clean_table($matrix) ?><?php if(global_report_compact_totals($doc['report_template']??'')): ?><?= global_report_totals_html($s['totals'],$doc['report_template'],false,$doc['currency_prefix']??true) ?><?php else: ?><div class="excel-total"><?php foreach($s['totals'] as $t): ?><span><?= report_e($t['label']) ?> <strong><?= report_e(global_report_print_money($t['value'],$doc['currency_prefix']??true)) ?></strong></span><?php endforeach; ?></div><?php endif; ?><?php foreach($s['notes']??[] as $note): ?><p class="meta"><?= report_e($note) ?></p><?php endforeach; ?></section>
    <?php endforeach;if(!empty($sheet['signatures'])): ?><div class="sign"><div>Kasir/Petugas,<p>(________________________)</p></div><div>Bagian Keuangan,<p>(________________________)</p></div></div><?php endif;endforeach; ?></body></html><?php return ob_get_clean();
}
