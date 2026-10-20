<?php
require_once __DIR__.'/date_format.php';
require_once __DIR__.'/report_pdf_text.php';
/** PDF presentation of the existing typed Excel model. */
function transaction_report_document_html(array $doc):string {
    $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');[$accent,$dark,$pale]=spp_excel_palette($doc['unit']);
    $html='<!doctype html><html lang="id"><head><meta charset="utf-8"><style>@page{size:A4 landscape;margin:12mm 12mm 15mm}body{margin:0;font-family:DejaVu Sans,sans-serif;font-size:9pt;line-height:1.4;color:#24372f}h1{font-size:19pt;line-height:1.25;color:#'.$dark.';margin:8px 0}h2{font-size:13pt;color:#'.$dark.';margin:18px 0 10px;page-break-after:avoid}h3{font-size:10pt;margin:12px 0 7px;padding:7px 9px;background:#'.$pale.';page-break-after:avoid}.identity{padding-bottom:10px;border-bottom:2pt solid #'.$dark.'}.school{font-weight:bold;font-size:10pt}.meta{font-size:8pt;color:#60736a;margin:8px 0}.report-table{width:100%;table-layout:fixed;border-collapse:collapse;margin-bottom:8px}th{background:#'.$dark.';color:white;text-align:left;font-size:8pt}td,th{padding:4px 7px;border:1px solid #dce6df;vertical-align:top;word-wrap:break-word}thead{display:table-header-group;page-break-after:avoid}tr{page-break-inside:avoid}tbody tr:nth-child(even){background:#'.$pale.'}.money{text-align:right;font-weight:bold}.subtotals{margin:8px 0 14px;text-align:right;page-break-inside:avoid;font-size:9pt}.subtotals p{margin:3px 0}.sheet-heading{border-bottom:1px solid #dce6df;padding-bottom:6px}</style></head><body><header class="identity"><div class="school">'.$e($doc['school']).'</div><h1>'.$e($doc['title']).'</h1><div>'.$e($doc['subtitle']).'</div><div class="meta">Unit '.$e($doc['unit_label']).' · '.$e($doc['generated']).' · Petugas '.$e($doc['operator']).'</div></header>';
    foreach($doc['sheets'] as $sheet){
        $html.='<h2 class="sheet-heading">'.$e($sheet['name']).'</h2>';
        foreach($sheet['sections'] as $section){
            $columns=array_values(array_filter($section['columns'],static fn($c)=>$c['key']!=='no'));
            $weights=[];foreach($columns as $c){$weights[]=in_array($c['type'],['money','money_optional','datetime'],true)?20:(in_array($c['key'],['NAMA','nama','label','keterangan','komponen'],true)?36:(in_array($c['key'],['KELAS','kelas','unit','export_unit'],true)?10:22));}
            $total=array_sum($weights);
            $html.='<h3>'.$e($section['title']).'</h3><table class="report-table"><thead><tr><th style="width:4%">No</th>';
            foreach($columns as $i=>$c)$html.='<th'.(in_array($c['type'],['money','money_optional'],true)?' class="money"':'').' style="width:'.($weights[$i]/$total*96).'%">'.$e($c['label']).'</th>';
            $html.='</tr></thead><tbody>';
            if(!$section['rows'])$html.='<tr><td colspan="'.(count($columns)+1).'">Tidak ada data sesuai filter.</td></tr>';
            foreach($section['rows'] as $i=>$row){
                $values=[];$parts=1;
                foreach($columns as $c){
                    $value=$row[$c['key']]??'';$isMoney=in_array($c['type'],['money','money_optional'],true);
                    if($isMoney&&$value!==''){$amount=round((float)$value,2);$value='Rp '.number_format($amount,$amount===floor($amount)?0:2,',','.');}
                    elseif(in_array($c['type'],['date','datetime'],true))$value=spp_date_label($value,$c['type']==='datetime',$row['time_precision']??null);
                    $values[]=report_pdf_text_chunks((string)$value);$parts=max($parts,count($values[count($values)-1]));
                }
                for($part=0;$part<$parts;$part++){
                    $html.='<tr><td>'.($part===0?$i+1:'').'</td>';
                    foreach($columns as $ci=>$c)$html.='<td'.(in_array($c['type'],['money','money_optional'],true)?' class="money"':'').'>'.nl2br($e($values[$ci][$part]??'')).'</td>';
                    $html.='</tr>';
                }
            }
            $html.='</tbody></table><div class="subtotals">';foreach($section['totals'] as $totalRow){$amount=round((float)$totalRow['value'],2);$html.='<p>'.$e($totalRow['label']).': <strong>Rp '.number_format($amount,$amount===floor($amount)?0:2,',','.').'</strong></p>';}$html.='</div>';
        }
    }
    return $html.'</body></html>';
}
