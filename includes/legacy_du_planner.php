<?php
/** Pure source evidence. Cash receipts alone never determine a DU principal. */
const LDU_VERSION=3;
function ldu_source_proof(array $student,array $tariffs,string $year): array {
    $unknown=['known'=>false,'basis'=>'legacy','reason'=>'Tarif, kelas, periode, atau potongan DU belum dapat dibuktikan.'];
    $raw=$student['raw']??[];
    if(!empty($student['ambiguous'])||!preg_match('/^([0-9]{1,2})[A-Za-z]?$/D',trim((string)($raw['KELAS']??'')),$m))return $unknown;
    $grade=$m[1];$years=array_keys($tariffs);sort($years,SORT_STRING);
    if($year!==($student['migration_year']??''))return $unknown;
    $declared=lb_amount($raw['DAFTAR_ULANG']??null);$discount=lb_amount($raw['potong_du']??null);$net=lb_amount($raw['tot_du']??null);
    // A complete individual header proves its own obligation, even if the class tariff is missing.
    if($declared!==null&&$declared>0&&$discount!==null&&$discount>=0&&$discount<=$declared&&$net!==null&&$net===$declared-$discount){
        return ['known'=>true,'basis'=>'legacy','total'=>$net/100,'gross'=>$declared/100,'discount'=>$discount/100,'year'=>$year,'grade'=>(int)$grade,'source_row_key'=>$student['source_row_key']??'','tariff_row_keys'=>array_values(array_filter(array_column($tariffs[$year][$grade]??[],'_source_row_key'))),'reason'=>'Pokok, potongan dan tagihan bersih individual konsisten pada header tahun peralihan.'];
    }
    if($year!==end($years))return $unknown;
    $fees=[];foreach($tariffs[$year][$grade]??[] as $row){$v=lb_amount($row['Jumlah']??$row['jumlah']??null);if($v===null||$v<0)return $unknown;$fees[$v]=true;}
    $discount=lb_amount($raw['potong_du']??null);if(count($fees)!==1||$discount===null||$discount<0)return $unknown;
    $gross=(int)array_key_first($fees);if($discount>$gross)return $unknown;
    if($declared!==null&&$declared>0&&($declared!==$gross||($net!==null&&$net!==$gross-$discount)))return $unknown;
    return ['known'=>true,'basis'=>'legacy','total'=>($gross-$discount)/100,'gross'=>$gross/100,'discount'=>$discount/100,'year'=>$year,'grade'=>(int)$grade,'source_row_key'=>$student['source_row_key']??'','tariff_row_keys'=>array_values(array_filter(array_column($tariffs[$year][$grade]??[],'_source_row_key'))),
        'reason'=>'Tarif tahun/kelas sumber unik; potongan eksplisit pada header tahun peralihan dan tahun terbaru sumber.'];
}
function ldu_position(array $proof,int $legacy,int $native,?int $principal=null): array {
    $known=!empty($proof['known'])||$principal!==null;$total=$principal??($known?lb_result_cents($proof['total']):null);$paid=$legacy+$native;
    return ['known'=>$known,'principal_cents'=>$total,'legacy_paid_cents'=>$legacy,'native_paid_cents'=>$native,'paid_cents'=>$paid,'remaining_cents'=>$known?max(0,$total-$paid):null,
        'excess_cents'=>$known?max(0,$paid-$total):null,'status'=>$known?($paid>=$total?'lunas':'belum_lunas'):'unknown'];
}
function ldu_source_preview(array $records,array $owners,array $payments,string $year,int $unit=0): array {
    $unit=$unit?:((int)($GLOBALS['app_unit_id']??(function_exists('unit_active_id')?unit_active_id():1)));
    $tariffs=[];foreach($records as $r)if(in_array($r['table'],['Daftar_ulang','du'],true)){$v=$r['raw'];$tariffs[trim((string)($v['th_ajaran']??''))][trim((string)($v['kelas']??''))][]=$v;}
    $paid=[];foreach($payments as $p)foreach($p['items'] as $i)if(($i['category']??'')==='daftar_ulang'&&lb_payment_year(lma_item_period($i,(string)$p['period']))===$year)$paid[$p['identity']]=($paid[$p['identity']]??0)+lf_cents($i['amount']);
    $out=['rule'=>LDU_VERSION,'year'=>$year,'ready'=>0,'unknown'=>0,'covered_psb'=>0,'conflicts'=>0,'principal_cents'=>0,'paid_cents'=>0,'remaining_cents'=>0];
    foreach($owners as $identity=>$o)if($o['ready']&&$o['kind']==='active'&&$o['student']){
        $proof=ldu_source_proof(['raw'=>$o['raw'],'migration_year'=>$year,'source_row_key'=>$o['source_key']],$tariffs,$year);
        if(function_exists('du_psb_initial_grade')&&$year>=du_current_academic_year()&&du_psb_initial_grade($unit,preg_replace('/[^0-9]/','',(string)($o['raw']['KELAS']??'')))&&empty($proof['known'])&&empty($paid[$identity])){$out['covered_psb']++;continue;}
        if(function_exists('du_psb_initial_grade')&&$year>=du_current_academic_year()&&du_psb_initial_grade($unit,preg_replace('/[^0-9]/','',(string)($o['raw']['KELAS']??'')))){$out['conflicts']++;continue;}
        if(!$proof['known']){$out['unknown']++;continue;}$p=ldu_position($proof,$paid[$identity]??0,0);$out['ready']++;
        foreach(['principal_cents','paid_cents','remaining_cents'] as $key)$out[$key]+=$p[$key];
    }return $out;
}
