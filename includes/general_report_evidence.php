<?php
require_once __DIR__.'/reports.php';

/** Same approved monthly evidence as Global; absence of a bill is not settlement. */
function general_monthly_positions(mysqli $db,string $type,string $month,string $year,string $studentWhere,array $params):array {
    static $cache=[];$scope=(int)$GLOBALS['app_unit_id'];$key=$db->thread_id.':'.hash('sha256',serialize([$scope,$type,$month,$year,$studentWhere,$params]));
    $canCache=PHP_SAPI!=='cli'&&($_SERVER['REQUEST_METHOD']??'')==='GET';if($canCache&&isset($cache[$key]))return $cache[$key];
    $allowed=[];foreach(hp_rows($db,"SELECT s.id,s.unit_id FROM siswa s WHERE s.is_active=1 $studentWhere",$params) as $r)$allowed[$r['unit_id'].'|'.$r['id']]=true;
    $category=$type==='belum_komite'?'komite':'spp';$rows=[];$coverage=[];$period=sprintf('%04d-%02d',(int)$year,(int)$month);
    $filters=['siswa_status'=>'active','_multi'=>[],'kelas'=>'','q'=>'','student_id'=>0,'status'=>''];
    try{foreach($scope===0?[1,2,3]:[$scope] as $unit){
        unit_set_context($db,$unit);
        foreach(report_monthly_evidence($db,$category,[[(int)$month,(int)$year]],$filters) as $r){
            if(!isset($allowed[$unit.'|'.$r['student_id']]))continue;$cell=$r['periods'][$period];
            $state=$cell['evidence_state']??($cell['remaining']===null?'rekonsiliasi':$cell['status']);$coverage[$state]=($coverage[$state]??0)+1;
            if($cell['remaining']===null||$cell['remaining']<=.001)continue;
            $rows[]=['student_id'=>$r['student_id'],'unit_id'=>$unit,'NO_INDUK'=>$r['nis'],'NO_induk_diknas'=>$r['nis_diknas'],'NAMA'=>$r['nama'],'KELAS'=>$r['kelas'],'tagihan'=>$cell['bill'],'sudah_bayar'=>$cell['paid'],'sisa'=>$cell['remaining']];
        }
    }}finally{unit_set_context($db,$scope);}
    $result=compact('rows','coverage');if($canCache)$cache[$key]=$result;return $result;
}
function general_unpaid_order(array &$rows,string $sort):void {
    usort($rows,static function($a,$b)use($sort){
        $aName=mb_strtolower($a['NAMA']);$bName=mb_strtolower($b['NAMA']);
        $tie=[$aName,(int)$a['unit_id'],(int)$a['student_id'],(string)($a['komponen']??'')]<=>[$bName,(int)$b['unit_id'],(int)$b['student_id'],(string)($b['komponen']??'')];
        return match($sort){'nama'=>$tie,'nominal_terkecil'=>((float)$a['sisa']<=>(float)$b['sisa'])?:$tie,'nominal_terbesar','sisa_terbesar'=>((float)$b['sisa']<=>(float)$a['sisa'])?:$tie,default=>((int)$a['KELAS']<=>(int)$b['KELAS'])?:$tie};
    });
}
function general_debt_coverage(mysqli $db,string $type,string $month,string $year,string $academic,string $where,array $params):array {
    if(in_array($type,['belum_spp','belum_komite'],true))return general_monthly_positions($db,$type,$month,$year,$where,$params)['coverage'];
    $students=[];foreach(hp_rows($db,"SELECT s.id,s.unit_id FROM siswa s WHERE s.is_active=1 $where",$params) as $r)$students[$r['unit_id'].'|'.$r['id']]=true;
    if($type==='belum_du'){$sql="SELECT s.id student_id,t.unit_id,t.no_induk,t.status,t.nominal_tagihan,COALESCE(SUM(d.jumlah),0) paid FROM tagihan_daftar_ulang t JOIN siswa s ON s.NO_INDUK=t.no_induk AND s.unit_id=t.unit_id LEFT JOIN bayar_du d ON d.tagihan_daftar_ulang_id=t.id AND d.unit_id=t.unit_id WHERE t.tahun_ajaran_snapshot=? GROUP BY t.id";$args=[$academic];}
    else{$sql="SELECT t.id other_bill_id,s.id student_id,t.unit_id,t.no_induk,t.status,t.nominal_tagihan,COALESCE(SUM(d.nominal_snapshot),0) paid FROM tagihan_biaya_lain t JOIN siswa s ON s.NO_INDUK=t.no_induk AND s.unit_id=t.unit_id LEFT JOIN bayar_biaya_lain d ON d.tagihan_biaya_lain_id=t.id AND d.unit_id=t.unit_id GROUP BY t.id";$args=[];}
    $counts=[];$found=[];$scope=(int)$GLOBALS['app_unit_id'];
    try{foreach(hp_rows($db,$sql,$args) as $r){$key=$r['unit_id'].'|'.$r['student_id'];if(!isset($students[$key]))continue;$found[$key]=true;
        if($type==='belum_du'){if((int)$GLOBALS['app_unit_id']!==(int)$r['unit_id'])unit_set_context($db,(int)$r['unit_id']);$r['paid']+=lb_du_bill_credit($db,$r['no_induk'],$academic);}
        if($type==='belum_biaya_lain')$r['paid']=ofb_paid($db,(int)$r['other_bill_id']);
        $state=$r['status']==='cancelled'?'dibatalkan':($r['status']==='waived'?'tidak_ditagihkan':((float)$r['paid']+.001>=(float)$r['nominal_tagihan']?'lunas':((float)$r['paid']>0?'cicilan':'belum_bayar')));
        $counts[$state]=($counts[$state]??0)+1;
    }}finally{if((int)$GLOBALS['app_unit_id']!==$scope)unit_set_context($db,$scope);}
    $counts[$type==='belum_du'?'unissued':'no_bill']=count(array_diff_key($students,$found));return $counts;
}
function general_coverage_text(array $coverage):string {
    $labels=['lunas'=>'lunas / tercakup','unissued'=>'belum diterbitkan','no_bill'=>'tanpa tagihan komponen ini','tidak_ditagihkan'=>'tidak ditagihkan','dibatalkan'=>'dibatalkan','rekonsiliasi'=>'bukti belum lengkap','ada_pembayaran'=>'ada pembayaran; pokok belum diketahui','cicilan'=>'cicilan','belum_bayar'=>'belum bayar'];$parts=[];
    foreach($coverage as $key=>$count)if($count)$parts[]=$count.' '.($labels[$key]??'bukti belum lengkap');
    return $parts?'Status bukti: '.implode(' · ',$parts).'. Angka nol tunggakan tidak otomatis berarti seluruh siswa lunas.':'Tidak ada posisi tagihan atau siswa sesuai filter.';
}
