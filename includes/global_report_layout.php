<?php
/** Bound Dompdf's HTML/frame tree while retaining one PDF and the existing HTML styles. */
function global_report_render_pdf_fragments(iterable $documents,string $orientation): string {
    $canvas=null;$fontMetrics=null;
    foreach($documents as $document){
        $options=new \Dompdf\Options();$options->set('isRemoteEnabled',false);$options->set('isHtml5ParserEnabled',true);$options->setDefaultMediaType('print');$options->setChroot(realpath(__DIR__.'/..'));
        $pdf=new \Dompdf\Dompdf($options);$pdf->setPaper('A4',$orientation);
        if($canvas!==null){$canvas->new_page();$pdf->setCanvas($canvas);}
        $pdf->loadHtml($document,'UTF-8');$pdf->render();
        if($canvas===null){$canvas=$pdf->getCanvas();$fontMetrics=$pdf->getFontMetrics();}
        elseif($pdf->getCanvas()!==$canvas)throw new RuntimeException('Ukuran halaman laporan berubah.');
        unset($pdf);gc_collect_cycles();
    }
    if($canvas===null)throw new RuntimeException('Laporan tidak memiliki halaman.');
    // CSS counters restart with each HTML tree; number the completed document once.
    $canvas->page_text($canvas->get_width()-85,$canvas->get_height()-23,'Halaman {PAGE_NUM}',$fontMetrics->getFont('Helvetica'),8,[.4,.4,.4]);
    return $canvas->output();
}
/** Global Reports only. Widths are presentation metadata, never financial calculations. */
function global_report_column_width(array $column, bool $print = false, array $rows = []): float {
    $key = $column['source'] ?? $column[0];
    $type = $column[2] ?? 'text';
    $identity = $print
        ? ['no'=>22,'unit'=>32,'nis'=>86,'nama'=>112,'kelas'=>40]
        : ['no'=>48,'unit'=>74,'nis'=>170,'nama'=>220,'kelas'=>98];
    if (isset($identity[$key])) return $identity[$key];
    if (!$print) return $type==='status' ? 240 : ($type==='datetime' ? 184 : (in_array($type,['money','money_optional','html'],true) ? 155 : 145));
    if (in_array($type,['money','money_optional','html'],true)) {
        $width = 72;
        foreach ($rows as $row) {
            $value = $row[$column[0]] ?? null;
            $text = is_array($value) ? (string)($value['text'] ?? '') : (is_numeric($value) ? report_money($value) : (string)$value);
            // Arial 9pt; pad conservatively so amounts never need to shrink.
            $width = max($width, strlen($text)*5.1+12);
        }
        return $width;
    }
    return $type==='status' ? 85 : ($type==='datetime' ? 102 : 76);
}

function global_report_print_orientation(array $matrix): string {
    $width=0;
    foreach($matrix['columns'] as $column)$width+=global_report_column_width($column,true,$matrix['rows'])+10;
    return $width<=538 ? 'portrait' : 'landscape';
}

/** Billing history already supplies grouped amounts; copy them without aggregation. */
function global_report_billing_print_matrix(array $students,array $components,int $unit): array {
    $identity=[['no','No','number']];if($unit===0)$identity[]=['unit','Unit'];$identity=array_merge($identity,[['nis','NIS','nis'],['nama','Nama Siswa'],['kelas','Kelas','kelas']]);
    $groups=[];$columns=$identity;$rows=[];
    foreach($components as $index=>$component){$column=['billing_'.$index,$component['komponen'],'money'];$columns[]=$column;$groups[]=['label'=>$component['komponen'],'subtitle'=>'','columns'=>[$column]];}
    $column=['total_tagihan','Total Tagihan','money'];$columns[]=$column;$groups[]=['label'=>'Total Tagihan','subtitle'=>'','columns'=>[$column]];
    foreach($students as $index=>$student){
        $row=['no'=>$index+1,'unit'=>unit_label((int)$student['unit_id']),'nis'=>$student['nis'],'nis_diknas'=>$student['nis_diknas'],'nama'=>$student['nama'],'kelas'=>$student['kelas'],'total_tagihan'=>$student['total_tagihan']];
        $map=array_column($student['components'],null,'komponen_key');
        foreach($components as $ci=>$component)$row['billing_'.$ci]=report_billing_component_value($map[$component['komponen_key']]??null);
        $rows[]=$row;
    }
    return ['columns'=>$columns,'identity_count'=>count($identity),'groups'=>$groups,'rows'=>$rows,'subtotals'=>[],'totals'=>[]];
}

/** Applied filters once, with scalar fallbacks suppressed for multiselect fields. */
function global_report_filter_text(mysqli $db,array $filters,string $template,array $notes=[]): string {
    $itemMonthly=false;$itemAnnual=false;$itemOnce=false;$itemDated=false;
    if($template==='per-item'){
        $categories=report_multiple_values($filters,'kategori');if(filter_is_all($categories))$categories=array_keys(report_categories($db));
        foreach($categories as $category){if(report_item_is_monthly_category($category))$itemMonthly=true;elseif(report_item_is_annual_category($category))$itemAnnual=true;elseif(report_item_is_one_time_category($category))$itemOnce=true;else $itemDated=true;}
    }
    $labels=['kategori'=>'Kategori','komponen_tagihan'=>'Komponen','kelas'=>'Kelas','tahun_ajaran'=>'Tahun ajaran','bulan_awal'=>'Bulan tagihan','status'=>'Status pembayaran','siswa_status'=>'Status siswa','metode'=>'Metode','mutasi'=>'Mutasi','saldo_status'=>'Saldo','operator'=>'Operator','q'=>'Pencarian'];
    $fields=['kelas','siswa_status','q'];
    if(in_array($template,['status','per-item','penerimaan'],true))$fields=array_merge(['kategori'],$fields);
    if(in_array($template,['status','spp-tahunan'],true)||$itemAnnual)$fields[]='tahun_ajaran';
    if($template==='status')$fields=array_merge($fields,['bulan_awal','status']);
    if($template==='riwayat-tagihan')$fields=array_merge($fields,['komponen_tagihan','status']);
    if(in_array($template,['tabungan-siswa','setoran','kas-tabungan','penerimaan'],true))$fields=array_merge($fields,['operator','metode','mutasi']);
    if($template==='saldo-tabungan')$fields[]='saldo_status';
    $parts=[];
    foreach($fields as $key){
        $values=$filters['_multi'][$key]??[(string)($filters[$key]??'')];$choices=$GLOBALS['spp_filter_choices'][$key]['options']??[];$texts=[];
        foreach($values as $value){
            if(in_array($value,['','*','all','semua'],true))continue;
            $text=$choices[$value]??'';
            if($text==='')$text=['active'=>'Aktif','archived'=>'Arsip/Lulus','lunas'=>'Lunas','cicilan'=>'Cicilan','belum_bayar'=>'Belum Bayar','tidak_ditagihkan'=>'Tidak Ditagihkan'][$value]??ucfirst(str_replace('_',' ',$value));
            $texts[]=$text;
        }
        if($texts)$parts[]=$labels[$key].': '.implode(', ',array_unique($texts));
    }
    if($itemMonthly)$parts[]='Periode tagihan: '.report_month_range_label(report_month_range((int)$filters['bulan_awal'],(int)$filters['tahun_awal'],(int)$filters['bulan_akhir'],(int)$filters['tahun_akhir']));
    if($itemOnce)$parts[]='Periode: Sekali saat masuk';
    if(!in_array($template,['status','spp-tahunan','saldo-tabungan','riwayat-tagihan','per-item'],true)||$itemDated)$parts[]='Periode: '.(!empty($filters['all_periods'])?'Semua periode':spp_date_label($filters['tanggal_awal']).' - '.spp_date_label($filters['tanggal_akhir']));
    if($template==='riwayat-tagihan')$parts[]='Tagihan dibuat: '.(!empty($filters['filter_tanggal_tagihan'])&&($filters['tanggal_awal']??'')!==''?spp_date_label($filters['tanggal_awal']).' - '.spp_date_label($filters['tanggal_akhir']):'Semua tanggal');
    if(!empty($filters['include_undated'])&&($template!=='per-item'||$itemDated))$parts[]='Termasuk transaksi tanpa tanggal sesuai periode kewajiban';
    foreach($notes as $note)if(!in_array($note,$parts,true))$parts[]=$note;
    return implode(' | ',$parts);
}

/** Flat savings reports retain their column order, including the transaction date. */
function global_report_flat_print_matrix(array $report): array {
    $columns=array_merge([['no','No','number']],$report['columns']);$identityCount=1;
    foreach($columns as $index=>$column)if($column[0]==='kelas'){$identityCount=$index+1;break;}
    $rows=$report['rows'];foreach($rows as $index=>&$row){$row['no']=$index+1;$row['nis_diknas']=$row['diknas']??$row['nis_diknas']??'';}unset($row);
    return ['columns'=>$columns,'identity_count'=>$identityCount,'groups'=>[['label'=>$report['title'],'subtitle'=>'','columns'=>array_slice($columns,$identityCount)]],'rows'=>$rows,'subtotals'=>[],'totals'=>[]];
}
