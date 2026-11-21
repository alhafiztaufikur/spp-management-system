<?php
/** Future receipts remain visible; unpaid future months never inflate the arrears matrix. */
function billing_history_period_visible(array $row,array $filters): bool {
    if(!empty($filters['_billing_period_projection'])&&billing_history_is_future($row,$filters)){
        if(!empty($filters['_debt_only']))return false;
        if(!empty($filters['_billing_show_future']))return true;
        return (float)($row['terbayar']??0)>.001&&array_key_exists('sisa',$row)&&($row['sisa']===null||(float)$row['sisa']<=.001);
    }
    if(empty($filters['_billing_as_of'])||!in_array($row['komponen_key']??'',['spp','komite'],true)||($row['periode_code']??'')<=substr($filters['_billing_as_of'],0,7))return true;
    return empty($filters['_debt_only'])&&(float)($row['terbayar']??0)>.001&&array_key_exists('sisa',$row)&&($row['sisa']===null||(float)$row['sisa']<=.001);
}
function billing_history_is_future(array $row,array $filters): bool {
    $today=$filters['_billing_as_of']??'';
    if($today===''||($row['komponen_key']??'')==='psb')return false;
    if(array_key_exists('_billing_future',$row))return (bool)$row['_billing_future'];
    $period=(string)($row['periode_code']??'');
    if(preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$period))return $period>substr($today,0,7);
    $year=report_academic_year_start((string)($row['tahun_ajaran']??''));
    return $year!==null&&($year.'-07-01')>$today;
}
function billing_history_month_bounds(array $source): array {
    $month=static fn($value)=>is_string($value)&&preg_match('/^[12]\d{3}-(0[1-9]|1[0-2])$/D',$value)?$value:'';
    $start=$month($source['periode_awal']??null);$end=$month($source['periode_akhir']??null);
    if($start==='')$start=$end;
    if($end==='')$end=$start;
    return $start>$end?[$end,$start]:[$start,$end];
}
function billing_history_year_overlaps(string $year,array $filters): bool {
    $start=$filters['periode_awal']??'';$yearStart=report_academic_year_start($year);
    return $start===''||($yearStart!==null&&$yearStart.'-07'<=($filters['periode_akhir']??$start)&&($yearStart+1).'-06'>=$start);
}
function billing_history_matches_period(array $row,array $filters): bool {
    if(empty($filters['_billing_period_projection'])||($row['komponen_key']??'')==='psb')return true;
    $year=(string)($row['tahun_ajaran']??'');$years=$filters['_billing_years']??['*'];
    if(!filter_is_all($years)&&!in_array($year,$years,true))return false;
    $start=$filters['periode_awal']??'';
    if($start==='')return true;
    $period=(string)($row['periode_code']??'');
    if(preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$period))return $period>=$start&&$period<=$filters['periode_akhir'];
    if(str_starts_with((string)($row['komponen_key']??''),'biaya_lain:')&&$period==='')return false;
    return billing_history_year_overlaps($year,$filters);
}
function billing_history_month_label(string $month): string {
    return (report_months()[substr($month,5,2)]??'').' '.substr($month,0,4);
}
function billing_history_column_label(string $key,string $label):string{return $key==='daftar_ulang'?'D.ULANG':$label;}
/** Read-only projection for the arrears report. Original bill/credit models stay intact. */
function billing_history_defaults(array $source): array {
    if(($source['template']??'')!=='riwayat-tagihan')return $source;
    if(!array_key_exists('komponen_tagihan',$source))$source['komponen_tagihan']=['psb','daftar_ulang','spp','komite'];
    return $source;
}
function billing_history_years(mysqli $db,array $source): array {
    $options=billing_history_year_options(report_years($db));$current=du_current_academic_year();
    $requested=$source['billing_year']??null;
    if(is_string($requested)&&!isset($options[$requested])&&!in_array($requested,['*','','all','semua','0'],true))$requested=$current;
    return filter_register('billing_year',$requested,$options,$current);
}
function billing_history_year_options(array $years): array {
    $options=array_column($years,'label','label');$current=du_current_academic_year();$next=du_next_year($current);
    $options[$current]??=$current;$options[$next]??=$next.' · Belum terdaftar';
    krsort($options,SORT_STRING);return $options;
}
function billing_history_year_label(array $years):string {
    if(filter_is_all($years))return 'Semua tahun ajaran';
    sort($years,SORT_STRING);
    return implode(', ',$years);
}
function billing_history_period_label(array $filters): string {
    $selected=$filters['_billing_years']??['*'];
    $years=filter_is_all($selected)?'Semua tahun ajaran':'Tahun ajaran '.billing_history_year_label($selected);
    if(!empty($filters['filter_tanggal_tagihan'])&&($filters['tanggal_awal']??'')!=='')
        return 'Tanggal Tagihan Dibuat '.report_date_range_label($filters['tanggal_awal'],$filters['tanggal_akhir']).' · '.$years;
    $start=$filters['periode_awal']??'';$end=$filters['periode_akhir']??'';
    $range=$start===''?'Semua bulan':billing_history_month_label($start).($start===$end?'':' – '.billing_history_month_label($end));
    return 'Periode tagihan '.$range.' · '.$years;
}
/** Absent components display zero; unknown financial evidence stays unknown. */
function billing_history_display_amount(array $amount,string $component=''):array {
    if($component!==''&&empty($amount['known'])&&empty($amount['unknown']))return ['value'=>0.0,'known'=>1,'unknown'=>false];
    return $amount;
}
function billing_history_amount(array $amount,string $component=''): string {
    $amount=billing_history_display_amount($amount,$component);
    return !empty($amount['known'])?report_money($amount['value']):'-';
}
function billing_history_add(array &$total,?float $value,int $count): void {
    $total+=['value'=>0.0,'known'=>0,'unknown'=>false];
    if($value===null)$total['unknown']=true;else{$total['value']+=$value;$total['known']+=$count;}
}
function billing_history_grand_amount(array $amount): string {
    return billing_history_amount($amount);
}
function billing_history_period_notes(string $label,array $periods): array {
    sort($periods,SORT_STRING);
    $academicYears=[];$other=[];
    foreach($periods as $period){
        if(preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/D',$period,$parts)){
            $year=du_academic_year_label((int)$parts[2],(int)$parts[1]);
            $academicYears[$year][]=mb_substr(report_months()[$parts[2]],0,3);
        }elseif(report_academic_year_start($period)!==null){
            $academicYears[$period]??=[];
        }else $other[]=$period;
    }
    ksort($academicYears,SORT_STRING);
    $notes=[];
    foreach($academicYears as $year=>$months)$notes[]=$label.' '.$year.($months?' ('.implode(', ',$months).')':'');
    if($other)$notes[]=$label.' ('.implode(', ',$other).')';
    return $notes?:[$label];
}
function billing_history_view(mysqli $db,array $report,array $filters): array {
    $years=$filters['_billing_years']??[du_current_academic_year()];$all=filter_is_all($years);$items=[];
    if(isset($report['_billing_groups']))foreach($report['_billing_groups'] as $student)array_push($items,...$student['items']);
    else $items=$report['rows'];
    $placements=[];
    foreach(hp_rows($db,"SELECT p.id,p.unit_id,p.no_induk,y.label,p.master_kelas_id,p.kelas_rombel_snapshot kelas,p.kelas tingkat FROM siswa_tahun_ajaran p JOIN tahun_ajaran y ON y.id=p.tahun_ajaran_id AND y.unit_id=p.unit_id WHERE p.status<>'dibatalkan' ORDER BY y.label DESC,p.id DESC") as $p){
        if(!$all&&!in_array($p['label'],$years,true))continue;
        if(!empty($filters['_billing_period_projection'])){
            if(!billing_history_year_overlaps($p['label'],$filters))continue;
            if(($filters['_billing_as_of']??'')!==''&&billing_history_is_future(['tahun_ajaran'=>$p['label']],$filters))continue;
        }
        $placements[$p['unit_id'].'|'.$p['no_induk']]??=$p;
    }
    return billing_history_project($items,$filters,$placements,report_query_unit_id());
}
/** Pure projection, shared by full reads, rollups, and regression fixtures. */
function billing_history_project(array $items,array $filters,array $placements,int $scope):array {
    $years=$filters['_billing_years']??[du_current_academic_year()];$all=filter_is_all($years);
    $defaults=['psb'=>'PSB','daftar_ulang'=>'Daftar Ulang','spp'=>'SPP','komite'=>'Komite'];$columns=[];
    $choices=$filters['_multi']['komponen_tagihan']??(($filters['komponen_tagihan']??'')!==''?[$filters['komponen_tagihan']]:['*']);
    foreach($defaults as $key=>$label)if(filter_is_all($choices)||in_array($key,$choices,true))$columns[$key]=$label;
    $groups=[];$detailCount=0;
    $grand=$futureGrand=['amounts'=>[],'total'=>[]];
    $periodTotals=['tagihan'=>[],'terbayar'=>[],'sisa'=>[]];
    $fullPeriods=!empty($filters['_billing_period_projection']);
    $mergePeriods=$fullPeriods&&($filters['periode_awal']??'')!=='';
    foreach($items as $row){
        $unit=(int)($row['unit_id']??$scope);$nis=(string)$row['nis'];$key=$unit.'|'.$nis;$component=(string)$row['komponen_key'];
        if(!billing_history_matches_period($row,$filters)||!billing_history_period_visible($row,$filters))continue;
        $year=trim((string)($row['tahun_ajaran']??''));$class=trim((string)($row['kelas_asli']??$row['kelas']??''));$level=(int)($row['tingkat']??0);$rombel=(int)($row['master_kelas_id']??0);
        if($component==='psb'){
            $p=$placements[$key]??null;
            // This year is the report placement context, never a fabricated PSB obligation year.
            if($p){$year=$p['label'];$class=$p['kelas'];$level=(int)$p['tingkat'];$rombel=(int)$p['master_kelas_id'];}
            else{
                if(!empty($filters['_billing_period_projection'])&&((!$all&&!in_array(du_current_academic_year(),$years,true))||($filters['periode_awal']??'')!==''))continue;
                $year='';$class='Rombel belum tercatat';$level=0;$rombel=0;
            }
        }elseif(!$all&&!in_array($year,$years,true))continue;
        if(!filter_is_all($choices)&&!in_array($component,$choices,true))continue;
        $itemCount=(int)($row['_read_count']??1);$detailCount+=$itemCount;
        $year=$year?:'Tidak tercatat';$class=$class?:'Rombel belum tercatat';
        $future=$fullPeriods&&billing_history_is_future($row,$filters);
        $separateFuture=$future&&!$mergePeriods;
        $identity=[$unit,$year,$rombel,$class];if($separateFuture)$identity[]='future';
        $sectionKey=json_encode($identity,JSON_THROW_ON_ERROR);$groupKey=$sectionKey.'|'.$nis;
        $columns[$component]??=(string)$row['komponen'];
        $groups[$groupKey]??=['key'=>$groupKey,'section_key'=>$sectionKey,'unit_id'=>$unit,'year'=>$year,'kelas'=>$class,'tingkat'=>$level,'nis'=>$nis,'nis_diknas'=>$row['nis_diknas']??'','nama'=>$row['nama'],'amounts'=>[],'total'=>[],'notes'=>[],'future'=>$separateFuture];
        $g=&$groups[$groupKey];
        if(!empty($row['_advance_paid'])||(!empty($filters['_billing_as_of'])&&in_array($component,['spp','komite'],true)&&($row['periode_code']??'')>substr($filters['_billing_as_of'],0,7)&&(float)($row['terbayar']??0)>.001))$g['advance_paid']=true;
        $remaining=array_key_exists('sisa',$row)&&$row['sisa']!==null?(float)$row['sisa']:null;
        if(in_array($row['status']??'',['Dibatalkan','Potongan Penuh','Tercakup Uang PSB','Tercakup PSB'],true)){
            $remaining=0.0;$g['settled_states'][(string)$row['status']]=true;
        }else $g['ordinary_items']=true;
        $billed=isset($row['tagihan'])?(float)$row['tagihan']:null;
        if(($row['status']??'')==='Dibatalkan'&&$billed!==null)$billed=0.0;
        billing_history_add($periodTotals['tagihan'],$billed,$itemCount);
        billing_history_add($periodTotals['terbayar'],isset($row['terbayar'])?(float)$row['terbayar']:null,$itemCount);
        billing_history_add($periodTotals['sisa'],$remaining,$itemCount);
        if($future)$summary=&$futureGrand;else $summary=&$grand;
        foreach([&$g,&$summary] as &$target){
            $target['amounts'][$component]??=[];
            billing_history_add($target['amounts'][$component],$remaining,$itemCount);
            billing_history_add($target['total'],$remaining,$itemCount);
        }
        unset($target,$summary);
        if($component!=='psb'&&$remaining!==null&&$remaining>.001){
            $periods=$row['_outstanding_periods']??[($row['periode_code']??'')?:($row['periode']??'')];
            foreach($periods as $period)if($period!==null&&$period!=='')$g['notes'][$component]['periods'][(string)$period]=true;
            $g['notes'][$component]['debt']=true;
        }
        unset($g);
    }
    $order=array_flip(array_keys($defaults));uksort($columns,static fn($a,$b)=>[($order[$a]??100),$a]<=>[($order[$b]??100),$b]);
    $groups=array_values($groups);usort($groups,static function($a,$b){
        $first=[$a['unit_id'],$a['year'],$a['future'],$a['tingkat']]<=>[$b['unit_id'],$b['year'],$b['future'],$b['tingkat']];
        return $first?:strnatcasecmp($a['kelas'],$b['kelas'])?:([mb_strtolower($a['nama']),$a['nis']]<=>[mb_strtolower($b['nama']),$b['nis']]);
    });
    $sections=[];
    foreach($groups as &$g){
        $notes=[];
        foreach($columns as $component=>$label){
            $n=$g['notes'][$component]??null;
            if(!empty($n['debt']))array_push($notes,...billing_history_period_notes($label,array_keys($n['periods']??[])));
        }
        if($notes)$g['keterangan']=implode(' + ',$notes);
        elseif(!empty($g['total']['unknown'])||($g['total']['value']??0)>.001)$g['keterangan']='-';
        elseif(!empty($g['advance_paid']))$g['keterangan']='Pembayaran di muka';
        else $g['keterangan']=!empty($g['ordinary_items'])?'Lunas':implode(' + ',array_keys($g['settled_states']??['Lunas'=>true]));
        if($g['future']&&$g['keterangan']!=='-')$g['keterangan']='Mendatang · '.$g['keterangan'];
        $section=&$sections[$g['section_key']];$section??=['key'=>$g['section_key'],'unit_id'=>$g['unit_id'],'year'=>$g['year'],'kelas'=>$g['kelas'],'rows'=>[],'amounts'=>[],'total'=>[],'future'=>$g['future']];$g['no']=count($section['rows'])+1;$section['rows'][]=$g;
        foreach($g['amounts'] as $key=>$amount){
            $section['amounts'][$key]??=['value'=>0.0,'known'=>0,'unknown'=>false];
            $section['amounts'][$key]['value']+=$amount['value'];
            $section['amounts'][$key]['known']+=$amount['known'];
            $section['amounts'][$key]['unknown']=$section['amounts'][$key]['unknown']||$amount['unknown'];
        }
        $section['total']+=['value'=>0.0,'known'=>0,'unknown'=>false];
        $section['total']['value']+=$g['total']['value'];
        $section['total']['known']+=$g['total']['known'];
        $section['total']['unknown']=$section['total']['unknown']||$g['total']['unknown'];
        unset($section);
    }unset($g);
    $emptyYears=$all||!$fullPeriods?[]:array_values(array_diff($years,array_column($groups,'year')));
    return ['rows'=>$groups,'sections'=>$sections,'columns'=>$columns,'grand'=>$grand,'future_grand'=>$futureGrand,'years'=>$years,'empty_years'=>$emptyYears,'detail_count'=>$detailCount,'merged_periods'=>$mergePeriods,'period_totals'=>$periodTotals];
}
function billing_history_screen_totals(array $view): array {
    $totals=[];
    foreach(['tagihan'=>'Jumlah Tagihan','terbayar'=>'Sudah Dibayar','sisa'=>'Sisa Tagihan'] as $key=>$label){
        $amount=$view['period_totals'][$key]??[];
        $totals[]=['key'=>$key,'label'=>$label,'value'=>!empty($amount['known'])?$amount['value']:null];
    }
    return $totals;
}
function billing_history_section_caption(array $section,int $scope): string {
    return 'Tahun Ajaran: '.$section['year'].' · Kelas: '.$section['kelas']
        .($scope===0?' · '.unit_label((int)$section['unit_id']):'').(!empty($section['future'])?' · Mendatang':'');
}
const BILLING_HISTORY_CURRENT_TOTAL_LABEL='Total Tunggakan Berjalan';

function billing_history_empty_years_html(array $view): string {
    $html='';
    foreach($view['empty_years']??[] as $year)$html.='<p class="billing-empty">Tahun ajaran '.report_e($year).' · Tidak ada tagihan sesuai filter.</p>';
    return $html;
}
function billing_history_summary_html(array $view): string {
    return billing_history_empty_years_html($view)
        .'<p class="billing-grand-total"><strong>'.BILLING_HISTORY_CURRENT_TOTAL_LABEL.': '.report_e(billing_history_amount($view['grand']['total'])).'</strong></p>';
}
function billing_history_table(array $view,?array $visible=null,bool $export=false,bool $finalSubtotalOnly=false,bool $showGrand=true): string {
    $visible??=$view['rows'];$sections=[];foreach($visible as $row)$sections[$row['section_key']][]=$row;
    ob_start();
    if(!$visible)echo '<p class="billing-empty" role="status">Tidak ada data sesuai filter.</p>';
    foreach($sections as $key=>$rows){$s=$view['sections'][$key];$subtotal=!$finalSubtotalOnly||end($rows)['key']===end($s['rows'])['key']; ?>
    <section class="billing-rombel"><?php if(!$export): ?><h3><?= report_e(billing_history_section_caption($s,report_query_unit_id())) ?></h3><?php endif; ?>
    <div class="billing-rombel-scroll"><table class="payment-table report-billing-matrix-table data">
    <colgroup><col class="billing-col-no"><col class="billing-col-nis"><col class="billing-col-name"><?php foreach($view['columns'] as $label): ?><col class="billing-col-component"><?php endforeach; ?><col class="billing-col-total"><col class="billing-col-description"></colgroup>
    <thead><?php if($export): ?><tr class="billing-context-row"><th class="billing-print-context" colspan="<?= 5+count($view['columns']) ?>"><?= report_e(billing_history_section_caption($s,report_query_unit_id())) ?></th></tr><?php endif; ?><tr><th class="billing-row-no">No</th><th class="billing-row-nis">No Induk</th><th>Nama Siswa</th><?php foreach($view['columns'] as $columnKey=>$label): ?><th><?= report_e(billing_history_column_label($columnKey,$label)) ?></th><?php endforeach; ?><th class="billing-total-column">TOTAL</th><th class="billing-description-column">Keterangan Tagihan</th></tr></thead><tbody>
    <?php foreach($rows as $i=>$row): ?><tr>
      <td class="billing-row-no"><?= $row['no']??$i+1 ?></td><td class="billing-row-nis"><?= report_e($row['nis']) ?></td><td class="billing-row-name"><?= report_e($row['nama']) ?></td>
      <?php foreach($view['columns'] as $c=>$label): ?><td class="money billing-component"><span class="billing-matrix-amount"><?= report_e(billing_history_amount($row['amounts'][$c]??[],$c)) ?></span></td><?php endforeach; ?>
      <td class="money billing-total"><?= report_e(billing_history_amount($row['total'])) ?></td><td class="billing-description"><?= report_e($row['keterangan']) ?></td>
    </tr><?php endforeach; ?>
    </tbody><?php if($subtotal): ?><tfoot><tr><th colspan="3">Jumlah Rombel</th><?php foreach($view['columns'] as $c=>$label): ?><td class="money"><?= report_e(billing_history_amount($s['amounts'][$c]??[],$c)) ?></td><?php endforeach; ?><td class="money billing-total-column"><?= report_e(billing_history_amount($s['total'])) ?></td><td class="billing-description-column"></td></tr></tfoot><?php endif; ?></table></div></section>
    <?php } ?><?php if($showGrand)echo billing_history_summary_html($view); ?>
    <?php return ob_get_clean();
}
