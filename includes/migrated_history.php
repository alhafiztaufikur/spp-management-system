<?php
/** Read projections only. Never copy historical receipts into native cash tables. */
require_once __DIR__.'/legacy_full_read.php';
require_once __DIR__.'/filter_choices.php';

function mh_has_column(mysqli $db,string $table,string $column): bool {
    static $cache=[];$key=$db->thread_id.'|'.$table.'|'.$column;
    return $cache[$key]??=(bool)lf_one($db,'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$table,$column]);
}

/** Older completed imports left OUT/PSB's FK empty. Resolve their stored classification for reads only. */
function mh_students_source(mysqli $db): string {
    if(!lop_ready($db))return 'siswa';
    $target="COALESCE(s.master_kelas_id,g.class_id,CASE g.owner_kind WHEN 'out' THEN (SELECT MIN(c.id) FROM master_kelas c WHERE c.unit_id=s.unit_id AND c.jenis='out') WHEN 'psb' THEN (SELECT MIN(c.id) FROM master_kelas c WHERE c.unit_id=s.unit_id AND c.jenis='psb') END)";
    $columns=[];foreach(lf_rows($db,"SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='siswa' ORDER BY ORDINAL_POSITION") as $r){$col=$r['COLUMN_NAME'];if(!preg_match('/^[A-Za-z0-9_]+$/D',$col))throw new LogicException('Invalid column');$columns[]=$col==='master_kelas_id'?"$target AS master_kelas_id":($col==='KELAS'?"CASE WHEN s.master_kelas_id IS NULL AND g.owner_kind='out' THEN 'OUT' ELSE s.KELAS END AS KELAS":"s.`$col`");}
    return '(SELECT '.implode(',',$columns)." FROM siswa s LEFT JOIN (SELECT o.unit_id,o.student_id,MAX(g.master_kelas_id) class_id,MIN(o.kind) owner_kind FROM legacy_op_owners o JOIN legacy_full_batches b ON b.id=o.batch_id AND b.unit_id=o.unit_id LEFT JOIN legacy_op_groups g ON g.id=o.group_id AND g.unit_id=o.unit_id WHERE o.student_id IS NOT NULL AND b.state='completed' GROUP BY o.unit_id,o.student_id HAVING COUNT(DISTINCT o.kind)=1 AND COUNT(DISTINCT g.master_kelas_id)<=1) g ON g.student_id=s.id AND g.unit_id=s.unit_id)";
}

/** Return destinations are server-owned; no caller-supplied redirect URL. */
function mh_return_create(string $route,array $query,int $unit,string $kind,int $id): string {
    if(!in_array($route,['pembayaran/lihat.php','pembayaran/riwayat_daftar_ulang.php','tabungan/riwayat.php','legacy_accounts.php','siswa/daftar.php'],true))throw new InvalidArgumentException('Tujuan kembali tidak valid.');
    $allowed=['owner','status','kelas','tingkat','back_context','special_members','special_member_page','saldo_q','saldo_status','saldo_class','saldo_page','page','per_page','selected','selected_unit','search','q','view','student_id','owner_id','nis','tanggal_awal','tanggal_akhir','all_periods','undated','source','owner_type','rombel','operator','batch','group','class_id','id','jenis','lg_open','lg_q','lg_kind','lg_page','special_q','special_kind','special_status','special_page'];
    $query=array_intersect_key($query,array_flip($allowed));
    foreach($_SESSION['history_returns']??[] as $key=>$r)if($r['expires']<time())unset($_SESSION['history_returns'][$key]);
    while(count($_SESSION['history_returns']??[])>=100)array_shift($_SESSION['history_returns']);
    $token=bin2hex(random_bytes(24));$_SESSION['history_returns'][$token]=['route'=>$route,'query'=>$query,'unit'=>$unit,'kind'=>$kind,'id'=>$id,'actor'=>(int)($_SESSION['admin_id']??0),'expires'=>time()+7200];return $token;
}
function mh_return_url(mixed $token,int $unit,string $kind,int $id): ?string {
    if(!is_string($token)||!preg_match('/^[a-f0-9]{48}$/D',$token))return null;$r=$_SESSION['history_returns'][$token]??null;
    if(!$r||$r['expires']<time()||$r['unit']!==$unit||$r['kind']!==$kind||$r['id']!==$id||$r['actor']!==(int)($_SESSION['admin_id']??0))return null;
    return $r['route'].'?'.http_build_query($r['query']);
}

function mh_payment_source(mysqli $db,bool $deleted=false): string {
    // Keep the list projection small; financial details are loaded for one selected ID.
    $parts=[];
    $parts[]="SELECT p.id,p.unit_id,p.NO_INDUK,p.TGL_BYR,p.total_jumlah,p.user_id,p.KELAS,p.kelas_rombel_snapshot,p.created_at,p.updated_at,p.BULAN,p.TAHUN,p.payment_link_version,
        s.id student_id,NULL legacy_owner_id,COALESCE(s.NAMA,'Tidak tercatat') NAMA,s.NO_induk_diknas,s.master_kelas_id current_class_id,IF(s.id IS NULL,'account','student') owner_type,'system' source_kind,
        COALESCE(NULLIF(p.kelas_rombel_snapshot,''),NULLIF(p.KELAS,''),s.KELAS,'Tidak tercatat') kelas_transaksi
        FROM bayar p LEFT JOIN siswa s ON s.NO_INDUK=p.NO_INDUK AND s.unit_id=p.unit_id";
    if(!$deleted&&lful_read_ready($db))$parts[]="SELECT -p.id,p.unit_id,s.NO_INDUK,p.source_date,p.total,CONCAT('legacy:',p.unit_id,':',LOWER(HEX(COALESCE(p.source_operator,'')))),p.class_snapshot,p.class_snapshot,p.source_date,NULL,'','',0,s.id,NULL,s.NAMA,s.NO_induk_diknas,s.master_kelas_id,'student','legacy',p.class_snapshot
        FROM legacy_full_payments p JOIN siswa s ON s.id=p.student_id AND s.unit_id=p.unit_id JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id WHERE b.state='completed'";
    if(lop_ready($db))$parts[]="SELECT -(1000000000+p.id),p.unit_id,COALESCE(s.NO_INDUK,o.source_identity),p.source_date,p.total,CONCAT('legacy:',p.unit_id,':',LOWER(HEX(COALESCE(p.source_operator,'')))),p.class_snapshot,p.class_snapshot,p.source_date,NULL,p.source_period,'',0,s.id,o.id,o.name,s.NO_induk_diknas,COALESCE(s.master_kelas_id,g.master_kelas_id),IF(s.id IS NULL,'account','student'),'legacy',p.class_snapshot
        FROM legacy_op_payments p JOIN legacy_op_owners o ON o.id=p.owner_id AND o.unit_id=p.unit_id LEFT JOIN siswa s ON s.id=o.student_id AND s.unit_id=o.unit_id LEFT JOIN legacy_op_groups g ON g.id=o.group_id AND g.unit_id=o.unit_id JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id WHERE b.state='completed' AND p.cancelled=".($deleted?'1':'0');
    if($deleted){
        $fields=['id'=>'BIGINT','unit_id'=>'INT','NO_INDUK'=>'VARCHAR(255)','TGL_BYR'=>'DATETIME','total_jumlah'=>'DECIMAL(15,2)','user_id'=>'VARCHAR(255)','KELAS'=>'VARCHAR(100)','kelas_rombel_snapshot'=>'VARCHAR(100)','created_at'=>'DATETIME','updated_at'=>'DATETIME','BULAN'=>'VARCHAR(100)','TAHUN'=>'VARCHAR(20)','payment_link_version'=>'INT'];
        $cols=[];foreach($fields as $n=>$type)$cols[]="`$n` $type PATH '$.payment.$n' NULL ON EMPTY NULL ON ERROR";
        $archive="(SELECT j.* FROM pembayaran_aktivitas a JOIN JSON_TABLE(CAST(a.before_snapshot AS JSON),'$' COLUMNS (".implode(',',$cols).")) j WHERE a.action='deleted' AND NOT EXISTS(SELECT 1 FROM pembayaran_aktivitas n WHERE n.payment_id=a.payment_id AND n.unit_id=a.unit_id AND n.action='deleted' AND n.id>a.id))";
        $parts[0]=str_replace('FROM bayar p','FROM '.$archive.' p',$parts[0]);
    }
    return '('.implode(' UNION ALL ',$parts).')';
}

function mh_choice(array $input,string $key,array $allowed,string $default): string {
    $v=$input[$key]??$default;
    if(!is_string($v)||!in_array($v,$allowed,true))throw new InvalidArgumentException('Filter '.$key.' tidak valid.');
    return $v;
}

/** Count, page and outside-period summary share exactly the same predicates. */
function mh_payment_page(mysqli $db,array $input,array $classFilter,array $operators,string $start,string $end,int $page,int $size): array {
    $source=mh_payment_source($db,($input['view']??'')==='deleted');$where=' WHERE 1=1';$args=[];
    $kind=mh_choice($input,'source',['all','system','legacy'],'all');
    $owner=mh_choice($input,'owner_type',['all','student','account'],'all');
    if($kind!=='all'){$where.=' AND p.source_kind=?';$args[]=$kind;}
    if($owner!=='all'){$where.=' AND p.owner_type=?';$args[]=$owner;}
    $where.=filter_sql_values($classFilter['values'],'p.current_class_id');
    $where.=history_operator_where($db,$operators,'p.id','p.unit_id');
    if((int)($input['student_id']??0)>0){$where.=' AND p.student_id=?';$args[]=(int)$input['student_id'];}
    if((int)($input['owner_id']??0)>0){$where.=' AND p.legacy_owner_id=?';$args[]=(int)$input['owner_id'];}
    $search=trim((string)($input['search']??''));
    if($search!==''){$where.=' AND (p.NAMA LIKE ? OR p.NO_INDUK LIKE ? OR p.NO_induk_diknas LIKE ?)';array_push($args,...array_fill(0,3,'%'.$search.'%'));}
    if(($input['du_only']??'')==='1'){
        $du=[];
        if(lful_read_ready($db))$du[]="(p.id<0 AND p.id>-1000000000 AND EXISTS(SELECT 1 FROM legacy_full_payments dp WHERE dp.id=-p.id AND dp.unit_id=p.unit_id AND (dp.source_table='bayar_du' OR EXISTS(SELECT 1 FROM legacy_full_items di WHERE di.payment_id=dp.id AND di.unit_id=dp.unit_id AND di.category='daftar_ulang'))))";
        if(lop_ready($db))$du[]="(p.id<-1000000000 AND EXISTS(SELECT 1 FROM legacy_op_payments dp WHERE dp.id=-p.id-1000000000 AND dp.unit_id=p.unit_id AND (dp.source_table='bayar_du' OR JSON_SEARCH(dp.items,'one','daftar_ulang',NULL,'$[*].category') IS NOT NULL)))";
        $where.=$du?' AND ('.implode(' OR ',$du).')':' AND 0=1';
    }
    $all=(int)lf_one($db,"SELECT COUNT(*) n FROM $source p $where",$args)['n'];
    $period='';$periodArgs=[];$dateColumn='p.TGL_BYR';
    if(($input['view']??'')==='deleted'){
        $legacyDate=lop_ready($db)?" WHEN p.id<-1000000000 THEN (SELECT MAX(a.created_at) FROM legacy_op_audit a WHERE a.entity_type='payment' AND a.entity_id=-p.id-1000000000 AND a.unit_id=p.unit_id AND JSON_EXTRACT(a.after_data,'$.cancelled')=1) ":'';
        $dateColumn="(CASE WHEN p.id>0 THEN (SELECT MAX(a.occurred_at) FROM pembayaran_aktivitas a WHERE a.payment_id=p.id AND a.unit_id=p.unit_id AND a.action='deleted') $legacyDate ELSE p.TGL_BYR END)";
    }
    if(($input['undated']??'')==='1')$period=' AND p.id<0 AND p.TGL_BYR IS NULL';
    elseif(($input['all_periods']??'')!=='1'){$period=" AND $dateColumn>=? AND $dateColumn<?";$periodArgs=[$start,$end];}
    $params=array_merge($args,$periodArgs);
    $total=$period===''?$all:(int)lf_one($db,"SELECT COUNT(*) n FROM $source p $where $period",$params)['n'];
    $pages=max(1,(int)ceil($total/$size));$page=min(max(1,$page),$pages);
    $selected=(int)($input['selected']??0);
    if($selected&&($target=lf_one($db,"SELECT p.id,p.unit_id,p.TGL_BYR FROM $source p $where $period AND p.id=?",array_merge($params,[$selected])))){
        $rankWhere=' AND (p.TGL_BYR>? OR (p.TGL_BYR<=>? AND (p.unit_id<? OR (p.unit_id=? AND p.id>?))) OR (? IS NULL AND p.TGL_BYR IS NOT NULL))';
        $rank=(int)lf_one($db,"SELECT COUNT(*) n FROM $source p $where $period $rankWhere",array_merge($params,[$target['TGL_BYR'],$target['TGL_BYR'],$target['unit_id'],$target['unit_id'],$target['id'],$target['TGL_BYR']]))['n'];
        $page=intdiv($rank,$size)+1;
    }
    $offset=($page-1)*$size;
    $rows=lf_rows($db,"SELECT p.* FROM $source p $where $period ORDER BY p.TGL_BYR DESC,p.unit_id,p.id DESC LIMIT $size OFFSET $offset",$params);
    return ['rows'=>$rows,'total'=>$total,'all'=>$all,'outside'=>$all-$total,'pages'=>$pages,'page'=>$page,'offset'=>$offset,
        'read_sql'=>"SELECT p.* FROM $source p $where $period ORDER BY p.TGL_BYR DESC,p.unit_id,p.id DESC",'read_args'=>$params];
}

function mh_savings_source(mysqli $db): string {
    require_once __DIR__.'/savings_operator.php';
    $parts=[];
    foreach(['transaksi_m'=>'masuk','transaksi_k'=>'keluar'] as $table=>$kind){
        $amount=$kind==='masuk'?'t.MASUK nominal,0 keluar':'0 nominal,t.KELUAR keluar';
        $actor=savings_operator_owner_sql('t.unit_id',"'$kind'",'t.id','t.user_id');
        $parts[]="SELECT t.id,t.unit_id,t.NO_INDUK,s.id student_id,NULL legacy_owner_id,s.NAMA,s.NO_induk_diknas,s.KELAS,s.master_kelas_id current_class_id,t.TANGGAL,$amount,'$kind' jenis,t.user_id,t.keterangan,CAST(($actor) AS CHAR) operator_key,'native' origin,0 adjustment_delta FROM $table t LEFT JOIN siswa s ON s.NO_INDUK=t.NO_INDUK AND s.unit_id=t.unit_id";
    }
    if(lful_read_ready($db))$parts[]="SELECT -j.id,j.unit_id,s.NO_INDUK,s.id,NULL,s.NAMA,s.NO_induk_diknas,s.KELAS,s.master_kelas_id,j.source_date,j.incoming,j.outgoing,IF(j.incoming>0,'masuk','keluar'),CONCAT('Legacy · ',COALESCE(j.source_operator,'Tidak tercatat')),'Histori Legacy',CONCAT('legacy:',j.unit_id,':',LOWER(HEX(COALESCE(j.source_operator,'')))),'legacy',0 FROM legacy_full_journals j JOIN siswa s ON s.id=j.student_id AND s.unit_id=j.unit_id JOIN legacy_full_batches b ON b.id=j.batch_id AND b.unit_id=j.unit_id WHERE b.state='completed'";
    if(lop_ready($db))$parts[]="SELECT -(1000000000+j.id),j.unit_id,COALESCE(s.NO_INDUK,o.source_identity),s.id,o.id,o.name,s.NO_induk_diknas,COALESCE(s.KELAS,o.source_class),COALESCE(s.master_kelas_id,g.master_kelas_id),j.source_date,IF(j.origin='adjustment',0,j.incoming),IF(j.origin='adjustment',0,j.outgoing),IF(j.incoming>0,'masuk','keluar'),IF(j.origin='legacy',CONCAT('Legacy · ',COALESCE(j.source_operator,'Tidak tercatat')),COALESCE(j.source_operator,'Tidak tercatat')),IF(j.origin='adjustment','Penyesuaian Saldo Migrasi',j.note),IF(j.origin='legacy',CONCAT('legacy:',j.unit_id,':',LOWER(HEX(COALESCE(j.source_operator,'')))),COALESCE(CAST((SELECT MIN(a.actor_id) FROM legacy_op_audit a WHERE a.unit_id=j.unit_id AND a.entity_type='bank_new' AND a.entity_id=j.id) AS CHAR),'0')),j.origin,IF(j.origin='adjustment',j.incoming-j.outgoing,0) FROM legacy_op_journals j JOIN legacy_op_owners o ON o.id=j.owner_id AND o.unit_id=j.unit_id LEFT JOIN siswa s ON s.id=o.student_id AND s.unit_id=o.unit_id LEFT JOIN legacy_op_groups g ON g.id=o.group_id AND g.unit_id=o.unit_id JOIN legacy_full_batches b ON b.id=j.batch_id AND b.unit_id=j.unit_id WHERE b.state='completed' AND j.cancelled=0";
    $union=implode(' UNION ALL ',$parts);
    return "(SELECT x.*,x.current_class_id master_kelas_id,mk.jenis rombel_jenis,mk.nama_custom rombel_nama,mk.tingkat,mk.kode_rombel,mk.is_placeholder FROM ($union) x LEFT JOIN master_kelas mk ON mk.id=x.current_class_id AND mk.unit_id=x.unit_id)";
}

/** Batch the bounded student page; DU detail rows never count as extra receipts. */
function mh_student_counts_many(mysqli $db,array $students): array {
    if(!$students)return [];$ids=implode(',',array_unique(array_map(static fn($s)=>(int)($s['id']??$s['student_id']),$students)));$parts=[];
    $parts[]="SELECT s.id,COUNT(*) payments,0 journals FROM bayar p JOIN siswa s ON s.unit_id=p.unit_id AND s.NO_INDUK=p.NO_INDUK WHERE s.id IN ($ids) GROUP BY s.id";
    foreach(['transaksi_m','transaksi_k'] as $table)$parts[]="SELECT s.id,0,COUNT(*) FROM $table p JOIN siswa s ON s.unit_id=p.unit_id AND s.NO_INDUK=p.NO_INDUK WHERE s.id IN ($ids) GROUP BY s.id";
    if(lful_read_ready($db))foreach(['payments','journals'] as $table){$amount=$table==='payments'?'COUNT(*),0':'0,COUNT(*)';$parts[]="SELECT p.student_id,$amount FROM legacy_full_$table p JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id WHERE p.student_id IN ($ids) AND b.state='completed' GROUP BY p.student_id";}
    if(lop_ready($db))foreach(['payments','journals'] as $table){$amount=$table==='payments'?'COUNT(*),0':'0,COUNT(*)';$extra=$table==='journals'?" AND p.origin<>'adjustment'":'';$parts[]="SELECT o.student_id,$amount FROM legacy_op_$table p JOIN legacy_op_owners o ON o.id=p.owner_id AND o.unit_id=p.unit_id JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id WHERE o.student_id IN ($ids) AND b.state='completed' AND p.cancelled=0 $extra GROUP BY o.student_id";}
    $out=[];foreach(lf_rows($db,'SELECT id,SUM(payments) payments,SUM(journals) journals FROM ('.implode(' UNION ALL ',$parts).') counts GROUP BY id') as $r)$out[(int)$r['id']]=['payments'=>(int)$r['payments'],'journals'=>(int)$r['journals'],'total'=>(int)$r['payments']+(int)$r['journals']];return $out;
}
function mh_student_counts(mysqli $db,int $unit,string $nis,?int $studentId=null): array {
    $s=lf_one($db,'SELECT id FROM siswa WHERE unit_id=? AND NO_INDUK=? AND (?=0 OR id=?)',[$unit,$nis,$studentId??0,$studentId??0]);
    return $s?(mh_student_counts_many($db,[$s])[(int)$s['id']]??['payments'=>0,'journals'=>0,'total'=>0]):['payments'=>0,'journals'=>0,'total'=>0];
}

/** Hydrate only receipt fields for a bounded page, in at most three queries. */
function mh_receipts_many(mysqli $db,array $rows): array {
    $full=[];$op=[];foreach($rows as $p){$id=(int)$p['id'];if($id<-1000000000)$op[]=-$id-1000000000;elseif($id<0)$full[]=-$id;}$out=[];
    if($op&&lop_ready($db))foreach(lf_rows($db,"SELECT p.*,o.student_id,o.name NAMA,o.source_identity NO_INDUK,s.NO_induk_diknas FROM legacy_op_payments p JOIN legacy_op_owners o ON o.id=p.owner_id AND o.unit_id=p.unit_id LEFT JOIN siswa s ON s.id=o.student_id AND s.unit_id=o.unit_id JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id WHERE p.id IN (".implode(',',$op).") AND b.state='completed'") as $p){$p['items']=json_decode($p['items'],true,64,JSON_THROW_ON_ERROR);$p['operational']=true;$out[-1000000000-(int)$p['id']]=$p;}
    if($full&&lful_read_ready($db)){
        foreach(lf_rows($db,"SELECT p.*,s.NAMA,s.NO_INDUK FROM legacy_full_payments p JOIN siswa s ON s.id=p.student_id AND s.unit_id=p.unit_id JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id WHERE p.id IN (".implode(',',$full).") AND b.state='completed'") as $p){$p['items']=[];$out[-(int)$p['id']]=$p;}
        foreach(lf_rows($db,'SELECT payment_id,label,amount FROM legacy_full_items WHERE payment_id IN ('.implode(',',$full).') ORDER BY id') as $i)if(isset($out[-(int)$i['payment_id']]))$out[-(int)$i['payment_id']]['items'][]=$i;
    }return $out;
}

function mh_student_legacy_history(mysqli $db,array $student): array {
    $source=mh_payment_source($db);$rows=[];
    $payments=lf_rows($db,"SELECT p.* FROM $source p WHERE p.id<0 AND p.student_id=? AND p.unit_id=? ORDER BY p.TGL_BYR DESC,p.id DESC",[$student['id'],$student['unit_id']]);$receipts=mh_receipts_many($db,$payments);
    foreach($payments as $p){
        $receipt=$receipts[(int)$p['id']];$components=array_column($receipt['items'],'label');
        $rows[]=['id'=>(int)$p['id'],'unit_id'=>(int)$p['unit_id'],'tanggal_raw'=>$p['TGL_BYR'],'period_key'=>0,'total_raw'=>(float)$p['total_jumlah'],'components'=>$components,'can_detail'=>true,'can_print'=>true,
            'detail_url'=>'detail.php?'.http_build_query(['id'=>$p['id'],'unit_id'=>$p['unit_id'],'readonly'=>'1']),
            'print_url'=>'../laporan/cetak_legacy.php?'.http_build_query(['id'=>-(int)$p['id'],'unit_id'=>$p['unit_id']]),
            'tanggal'=>$p['TGL_BYR']?spp_date_label($p['TGL_BYR'],true):'Tidak tercatat','periode'=>$receipt['source_period']?:'Tidak tercatat',
            'komponen'=>implode(', ',$components),'metode'=>'Legacy','operator'=>$receipt['source_operator']?:'Tidak tercatat',
            'total'=>'Rp '.number_format((float)$p['total_jumlah'],2,',','.'),'context_year'=>null,'context_class'=>$receipt['class_snapshot'],'source_kind'=>'legacy'];
    }return $rows;
}
