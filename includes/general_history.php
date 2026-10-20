<?php
/** Shared read model for the existing general report, its trend, and its exports. */
require_once __DIR__.'/migrated_history.php';
require_once __DIR__.'/savings_owners.php';
require_once __DIR__.'/legacy_billing.php';

function general_history_filter(string $alias,string $date,string $start,string $end,string $search): array {
    $where="$alias.$date>=? AND $alias.$date<?";$args=[$start,$end];
    if($search!==''){$where.=" AND ($alias.NO_INDUK LIKE ? OR $alias.NAMA LIKE ? OR $alias.NO_induk_diknas LIKE ?)";array_push($args,'%'.$search.'%','%'.$search.'%','%'.$search.'%');}
    if($id=max(0,(int)($_GET['student_id']??0)))$where.=" AND $alias.student_id=$id";
    return [$where,$args];
}
function general_payment_summary(mysqli $db,string $start,string $end,string $search='',bool $paidOnly=false): array {
    $source=mh_payment_source($db,false,null,false);[$where,$args]=general_history_filter('p','stored_datetime',$start,$end,$search);
    if($paidOnly)$where.=' AND p.total_jumlah>0';
    return hp_one($db,"SELECT COUNT(*) jml_tx,COALESCE(SUM(total_jumlah),0) total,SUM(source_kind='legacy') legacy_count,COALESCE(SUM(IF(source_kind='legacy',total_jumlah,0)),0) legacy_total FROM $source p WHERE $where",$args);
}
function general_payment_rows(mysqli $db,string $start,string $end,string $search='',string $sort='terbaru',?int $limit=null,int $offset=0,bool $paidOnly=false): array {
    $source=mh_payment_source($db);[$where,$args]=general_history_filter('p','stored_datetime',$start,$end,$search);
    if($paidOnly)$where.=' AND p.total_jumlah>0';
    $order=match($sort){'nama'=>'p.NAMA,p.TGL_BYR DESC,p.id DESC','kelas'=>'CAST(p.KELAS AS UNSIGNED),p.kelas_transaksi,p.NAMA,p.TGL_BYR DESC,p.id DESC','nominal_terbesar'=>'p.total_jumlah DESC,p.TGL_BYR DESC,p.id DESC',default=>'p.TGL_BYR DESC,p.id DESC'};
    $page=$limit===null?'':' LIMIT '.max(1,$limit).' OFFSET '.max(0,$offset);
    $rows=hp_rows($db,"SELECT p.*,COALESCE(n.sistem_pembayaran,'Legacy') sistem_pembayaran FROM $source p LEFT JOIN bayar n ON n.id=p.id AND n.unit_id=p.unit_id WHERE $where ORDER BY $order $page",$args);
    foreach($rows as &$r){$r['KELAS']=$r['kelas_transaksi'];$r['KELAS_SISWA']=$r['kelas_transaksi'];$r['U_PSB']=$r['U_SPP']=$r['U_KOMITE']=0;}
    unset($r);return $rows;
}
/** Sum actual item values once; Legacy labels keep historical cash identifiable. */
function general_payment_components(mysqli $db,string $start,string $end,string $search=''): array {
    $payments=mh_payment_source($db,false,null,false);[$where,$args]=general_history_filter('p','stored_datetime',$start,$end,$search);
    $selected="SELECT p.id,p.unit_id FROM $payments p WHERE $where";
    $parts=[];
    foreach(['U_PSB'=>'Uang PSB','U_SPP'=>'Uang SPP','U_KOMITE'=>'Uang Komite','potong_spp'=>'Potongan SPP'] as $column=>$label){
        $amount=$column==='potong_spp'?'-n.'.$column:'n.'.$column;
        $parts[]="SELECT n.id,n.unit_id,'$label' nama,$amount amount FROM bayar n WHERE n.$column<>0";
    }
    $parts[]='SELECT d.bayar_id,d.unit_id,d.nama_biaya_snapshot,d.nominal_snapshot FROM bayar_biaya_lain d';
    $parts[]="SELECT d.bayar_id,d.unit_id,'Daftar Ulang',d.jumlah FROM bayar_du d";
    if(lful_read_ready($db))$parts[]="SELECT -i.payment_id,i.unit_id,CONCAT(i.label,' (Legacy)'),i.amount FROM legacy_full_items i";
    if(lop_ready($db)){
        $parts[]="SELECT -(1000000000+n.id),n.unit_id,CONCAT(j.label,' (Legacy)'),j.amount FROM (SELECT id,unit_id,items FROM legacy_op_payments WHERE source_date>=? AND source_date<?) n JOIN JSON_TABLE(CAST(n.items AS JSON),'$[*]' COLUMNS(label VARCHAR(255) PATH '$.label',amount DECIMAL(15,2) PATH '$.amount')) j";
        $args=array_merge([$start,$end],$args);
    }
    $items=implode(' UNION ALL ',$parts);
    return hp_rows($db,"SELECT c.nama,COALESCE(SUM(c.amount),0) total FROM ($items) c JOIN ($selected) chosen ON chosen.id=c.id AND chosen.unit_id=c.unit_id GROUP BY c.nama ORDER BY FIELD(c.nama,'Uang PSB','Uang SPP','Uang Komite','Daftar Ulang','Potongan SPP')=0,FIELD(c.nama,'Uang PSB','Uang SPP','Uang Komite','Daftar Ulang','Potongan SPP'),c.nama",$args);
}
function general_savings_read(mysqli $db,string $start,string $end,string $search='',bool $rows=false): array {
    $source=mh_savings_source($db,false);[$where,$args]=general_history_filter('p','TANGGAL',$start,$end,$search);
    $where.=" AND p.origin<>'adjustment'";
    if(!$rows)return hp_one($db,"SELECT COALESCE(SUM(p.nominal),0) total_masuk,COALESCE(SUM(p.keluar),0) total_keluar,SUM(p.nominal>0) count_masuk,SUM(p.keluar>0) count_keluar FROM $source p WHERE $where",$args);
    return hp_rows($db,"SELECT p.*,IF(p.jenis='masuk',p.nominal,p.keluar) amount FROM $source p WHERE $where ORDER BY p.TANGGAL DESC,p.unit_id,p.id DESC",$args);
}
function general_savings_balance(mysqli $db,string $search=''): float {
    $source=so_source($db);$where='1=1';$args=[];
    if($search!==''){$where.=' AND (p.name LIKE ? OR p.identity LIKE ? OR p.NO_induk_diknas LIKE ?)';$args=array_fill(0,3,'%'.$search.'%');}
    if($id=max(0,(int)($_GET['student_id']??0)))$where.=' AND p.student_id='.$id;
    return (float)hp_one($db,"SELECT COALESCE(SUM(p.balance),0) total FROM $source p WHERE $where",$args)['total'];
}
/** Apply only the same proven historical credit used by the payment form. */
function general_unpaid_history(mysqli $db,array $rows,string $type,string $month,string $year,string $academic): array {
    if(!in_array($type,['belum_spp','belum_du'],true))return $rows;
    $scope=(int)($GLOBALS['app_unit_id']??unit_active_id());$out=[];
    try{foreach($rows as $row){
        unit_set_context($db,(int)$row['unit_id']);
        $credit=$type==='belum_spp'?lb_spp_credit($db,$row['NO_INDUK'],$month,$year):lb_du_bill_credit($db,$row['NO_INDUK'],$academic);
        $row['sudah_bayar']=(float)$row['sudah_bayar']+$credit;$row['sisa']=max(0,(float)$row['tagihan']-$row['sudah_bayar']);
        if($row['sisa']>0.001)$out[]=$row;
    }}finally{unit_set_context($db,$scope);}return $out;
}
