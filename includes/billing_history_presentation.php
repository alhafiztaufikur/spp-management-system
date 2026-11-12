<?php
/** Future receipts remain visible; unpaid future months never inflate the arrears matrix. */
function billing_history_period_visible(array $row,array $filters): bool {
    if(empty($filters['_billing_as_of'])||!in_array($row['komponen_key']??'',['spp','komite'],true)||($row['periode_code']??'')<=substr($filters['_billing_as_of'],0,7))return true;
    return empty($filters['_debt_only'])&&(float)($row['terbayar']??0)>.001&&array_key_exists('sisa',$row)&&($row['sisa']===null||(float)$row['sisa']<=.001);
}
function billing_history_column_label(string $key,string $label):string{return $key==='daftar_ulang'?'D.ULANG':$label;}
/** Read-only projection for the arrears report. Original bill/credit models stay intact. */
function billing_history_defaults(array $source): array {
    if(($source['template']??'')!=='riwayat-tagihan')return $source;
    if(!array_key_exists('komponen_tagihan',$source))$source['komponen_tagihan']=['psb','daftar_ulang','spp','komite'];
    return $source;
}
function billing_history_years(mysqli $db,array $source): array {
    $options=array_column(report_years($db),'label','label');$current=du_current_academic_year();$options[$current]??=$current;
    return filter_register('billing_year',$source['billing_year']??null,$options,$current);
}
function billing_history_year_label(array $years):string{return filter_is_all($years)?'Semua tahun ajaran':implode(', ',$years);}
/** Absent components display zero; unknown financial evidence stays unknown. */
function billing_history_display_amount(array $amount,string $component=''):array {
    if($component!==''&&empty($amount['known'])&&empty($amount['unknown']))return ['value'=>0.0,'known'=>1,'unknown'=>false];
    return $amount;
}
function billing_history_amount(array $amount,string $component=''): string {
    $amount=billing_history_display_amount($amount,$component);
    $known=(int)($amount['known']??0);$unknown=!empty($amount['unknown']);
    if(!$known)return $unknown?'Belum diketahui':'—';
    return report_money($amount['value']).($unknown?' + belum diketahui':'');
}
function billing_history_add(array &$total,?float $value): void {
    $total+=['value'=>0.0,'known'=>0,'unknown'=>false];
    if($value===null)$total['unknown']=true;else{$total['value']+=$value;$total['known']++;}
}
function billing_history_grand_amount(array $amount): string {
    return !empty($amount['known'])?report_money($amount['value']):'—';
}
function billing_history_view(mysqli $db,array $report,array $filters): array {
    $years=$filters['_billing_years']??[du_current_academic_year()];$all=filter_is_all($years);$items=[];
    if(isset($report['_billing_groups']))foreach($report['_billing_groups'] as $student)array_push($items,...$student['items']);
    else $items=$report['rows'];
    $placements=[];
    foreach(hp_rows($db,"SELECT p.id,p.unit_id,p.no_induk,y.label,p.master_kelas_id,p.kelas_rombel_snapshot kelas,p.kelas tingkat FROM siswa_tahun_ajaran p JOIN tahun_ajaran y ON y.id=p.tahun_ajaran_id AND y.unit_id=p.unit_id WHERE p.status<>'dibatalkan' ORDER BY y.label DESC,p.id DESC") as $p){
        if(!$all&&!in_array($p['label'],$years,true))continue;
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
    $groups=[];
    foreach($items as $row){
        $unit=(int)($row['unit_id']??$scope);$nis=(string)$row['nis'];$key=$unit.'|'.$nis;$component=(string)$row['komponen_key'];
        if(!billing_history_period_visible($row,$filters))continue;
        $year=trim((string)($row['tahun_ajaran']??''));$class=trim((string)($row['kelas_asli']??$row['kelas']??''));$level=(int)($row['tingkat']??0);$rombel=(int)($row['master_kelas_id']??0);
        if($component==='psb'){
            $p=$placements[$key]??null;
            // This year is the report placement context, never a fabricated PSB obligation year.
            if($p){$year=$p['label'];$class=$p['kelas'];$level=(int)$p['tingkat'];$rombel=(int)$p['master_kelas_id'];}
            else{$year='';$class='Rombel belum tercatat';$level=0;$rombel=0;}
        }elseif(!$all&&!in_array($year,$years,true))continue;
        if(!filter_is_all($choices)&&!in_array($component,$choices,true))continue;
        $year=$year?:'Tidak tercatat';$class=$class?:'Rombel belum tercatat';
        $sectionKey=json_encode([$unit,$year,$rombel,$class],JSON_THROW_ON_ERROR);$groupKey=$sectionKey.'|'.$nis;
        $columns[$component]??=(string)$row['komponen'];
        $groups[$groupKey]??=['key'=>$groupKey,'section_key'=>$sectionKey,'unit_id'=>$unit,'year'=>$year,'kelas'=>$class,'tingkat'=>$level,'nis'=>$nis,'nis_diknas'=>$row['nis_diknas']??'','nama'=>$row['nama'],'amounts'=>[],'total'=>[],'notes'=>[]];
        $g=&$groups[$groupKey];$g['amounts'][$component]??=[];
        if(!empty($row['_advance_paid'])||(!empty($filters['_billing_as_of'])&&in_array($component,['spp','komite'],true)&&($row['periode_code']??'')>substr($filters['_billing_as_of'],0,7)&&(float)($row['terbayar']??0)>.001))$g['advance_paid']=true;
        $remaining=array_key_exists('sisa',$row)&&$row['sisa']!==null?(float)$row['sisa']:null;
        if(in_array($row['status']??'',['Dibatalkan','Potongan Penuh','Tercakup Uang PSB','Tercakup PSB'],true)){
            $remaining=0.0;$g['settled_states'][(string)$row['status']]=true;
        }else $g['ordinary_items']=true;
        billing_history_add($g['amounts'][$component],$remaining);billing_history_add($g['total'],$remaining);
        if($remaining===null)$g['notes'][$component]['unknown']=true;
        elseif($remaining>.001){
            $periods=$row['_outstanding_periods']??[($row['periode_code']??'')?:($row['periode']??'')];
            foreach($periods as $period)if($period!==null&&$period!=='')$g['notes'][$component]['periods'][(string)$period]=true;
            $g['notes'][$component]['debt']=true;
        }
        unset($g);
    }
    $order=array_flip(array_keys($defaults));uksort($columns,static fn($a,$b)=>[($order[$a]??100),$a]<=>[($order[$b]??100),$b]);
    $groups=array_values($groups);usort($groups,static function($a,$b){
        $first=[$a['unit_id'],$a['year'],$a['tingkat']]<=>[$b['unit_id'],$b['year'],$b['tingkat']];
        return $first?:strnatcasecmp($a['kelas'],$b['kelas'])?:([mb_strtolower($a['nama']),$a['nis']]<=>[mb_strtolower($b['nama']),$b['nis']]);
    });
    $sections=[];$grand=['amounts'=>[],'total'=>[]];
    foreach($groups as &$g){
        $notes=[];
        foreach($columns as $component=>$label){
            $n=$g['notes'][$component]??null;if(!$n)continue;
            if(!empty($n['debt'])){
                $periods=array_keys($n['periods']??[]);sort($periods,SORT_STRING);$months=[];$other=[];
                foreach($periods as $period){
                    if(preg_match('/^(\d{4})-(\d{2})$/D',$period,$m))$months[]=substr(report_months()[$m[2]]??$m[2],0,3);
                    else{
                        $text=preg_replace('/\b(?:19|20)\d{2}(?:\s*[\/–-]\s*(?:19|20)\d{2})?\b/u','',$period);
                        $text=trim(preg_replace('/\s+/u',' ',$text)," \t\n\r\0\x0B/-,;()");
                        if($text!=='')$other[]=$text;
                    }
                }
                $descriptions=$months?[implode(', ',array_unique($months))]:[];
                array_push($descriptions,...array_unique($other));
                $notes[]=$label.($component==='psb'?'':($descriptions?' ('.implode('; ',$descriptions).')':''));
            }
            if(!empty($n['unknown']))$notes[]=$label.' (belum diketahui)';
        }
        $g['keterangan']=$notes?implode(' + ',$notes):(!empty($g['advance_paid'])?'Pembayaran di muka':(!empty($g['ordinary_items'])?'Lunas':implode(' + ',array_keys($g['settled_states']??['Lunas'=>true]))));
        $section=&$sections[$g['section_key']];$section??=['key'=>$g['section_key'],'unit_id'=>$g['unit_id'],'year'=>$g['year'],'kelas'=>$g['kelas'],'rows'=>[],'amounts'=>[],'total'=>[]];$g['no']=count($section['rows'])+1;$section['rows'][]=$g;
        foreach($g['amounts'] as $key=>$amount){
            foreach([&$section['amounts'],&$grand['amounts']] as &$target){$target[$key]??=['value'=>0.0,'known'=>0,'unknown'=>false];$target[$key]['value']+=$amount['value'];$target[$key]['known']+=$amount['known'];$target[$key]['unknown']=$target[$key]['unknown']||$amount['unknown'];}unset($target);
        }
        foreach([&$section['total'],&$grand['total']] as &$target){$target+=['value'=>0.0,'known'=>0,'unknown'=>false];$target['value']+=$g['total']['value'];$target['known']+=$g['total']['known'];$target['unknown']=$target['unknown']||$g['total']['unknown'];}unset($target,$section);
    }unset($g);
    return ['rows'=>$groups,'sections'=>$sections,'columns'=>$columns,'grand'=>$grand,'years'=>$years];
}
function billing_history_table(array $view,?array $visible=null,bool $export=false,bool $finalSubtotalOnly=false,bool $showGrand=true): string {
    $visible??=$view['rows'];$sections=[];foreach($visible as $row)$sections[$row['section_key']][]=$row;
    ob_start();
    if(!$visible)echo '<p class="billing-empty" role="status">Tidak ada data sesuai filter.</p>';
    foreach($sections as $key=>$rows){$s=$view['sections'][$key];$subtotal=!$finalSubtotalOnly||end($rows)['key']===end($s['rows'])['key']; ?>
    <section class="billing-rombel"><?php if(!$export): ?><h3>Tahun Ajaran: <?= report_e($s['year']) ?> · Kelas: <?= report_e($s['kelas']) ?><?= report_query_unit_id()===0?' · '.report_e(unit_label($s['unit_id'])):'' ?></h3><?php endif; ?>
    <div class="billing-rombel-scroll"><table class="payment-table report-billing-matrix-table data">
    <colgroup><col class="billing-col-no"><col class="billing-col-nis"><col class="billing-col-name"><?php foreach($view['columns'] as $label): ?><col class="billing-col-component"><?php endforeach; ?><col class="billing-col-total"><col class="billing-col-description"></colgroup>
    <thead><?php if($export): ?><tr class="billing-context-row"><th class="billing-print-context" colspan="<?= 5+count($view['columns']) ?>">Tahun Ajaran: <?= report_e($s['year']) ?> · Kelas: <?= report_e($s['kelas']) ?><?= report_query_unit_id()===0?' · '.report_e(unit_label($s['unit_id'])):'' ?></th></tr><?php endif; ?><tr><th class="billing-row-no">No</th><th class="billing-row-nis">No Induk</th><th>Nama Siswa</th><?php foreach($view['columns'] as $columnKey=>$label): ?><th><?= report_e(billing_history_column_label($columnKey,$label)) ?></th><?php endforeach; ?><th class="billing-total-column">TOTAL</th><th class="billing-description-column">Keterangan Tagihan</th></tr></thead><tbody>
    <?php foreach($rows as $i=>$row): ?><tr>
      <td class="billing-row-no"><?= $row['no']??$i+1 ?></td><td class="billing-row-nis"><?= report_e($row['nis']) ?></td><td class="billing-row-name"><?= report_e($row['nama']) ?></td>
      <?php foreach($view['columns'] as $c=>$label): ?><td class="money billing-component"><span class="billing-matrix-amount"><?= report_e(billing_history_amount($row['amounts'][$c]??[],$c)) ?></span></td><?php endforeach; ?>
      <td class="money billing-total"><?= report_e(billing_history_amount($row['total'])) ?></td><td class="billing-description"><?= report_e($row['keterangan']) ?></td>
    </tr><?php endforeach; ?>
    </tbody><?php if($subtotal): ?><tfoot><tr><th colspan="3">Jumlah Rombel</th><?php foreach($view['columns'] as $c=>$label): ?><td class="money"><?= report_e(billing_history_amount($s['amounts'][$c]??[],$c)) ?></td><?php endforeach; ?><td class="money billing-total-column"><?= report_e(billing_history_amount($s['total'])) ?></td><td class="billing-description-column"></td></tr></tfoot><?php endif; ?></table></div></section>
    <?php } ?><?php if($showGrand): ?><p class="billing-grand-total"><strong>Total Tunggakan Keseluruhan: <?= report_e(billing_history_grand_amount($view['grand']['total'])) ?></strong></p><?php endif; ?>
    <?php return ob_get_clean();
}
