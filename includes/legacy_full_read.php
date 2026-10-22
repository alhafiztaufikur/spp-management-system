<?php
require_once __DIR__.'/legacy_full_schema.php';
require_once __DIR__.'/legacy_finance.php';
require_once __DIR__.'/legacy_operational_read.php';
require_once __DIR__.'/history_performance.php';
require_once __DIR__.'/payment_time.php';
function lful_read_ready(mysqli $db): bool {static $cache=[];$key=hp_connection_key($db);return $cache[$key]??=legacy_full_ready($db);}
function lful_operator_key(int $unit,?string $operator): string {return 'legacy:'.$unit.':'.bin2hex((string)$operator);}
function lful_operator_options(mysqli $db): array {
    if(!lful_read_ready($db))return [];
    $out=[];$batches=[];
    foreach(hp_rows($db,"SELECT id,unit_id FROM legacy_full_batches WHERE state='completed'") as $b)$batches[(int)$b['unit_id']][]="'".$db->real_escape_string($b['id'])."'";
    if(!$batches)return [];
    static $options=[];
    $revision=lop_ready($db)?(int)hp_one($db,'SELECT COALESCE(MAX(id),0) revision FROM legacy_op_audit')['revision']:0;
    $cacheKey=$db->thread_id.':'.hash('sha256',json_encode($batches).':'.$revision);
    if(isset($options[$cacheKey]))return $options[$cacheKey];
    $clauses=[];foreach($batches as $unit=>$ids)$clauses[]='(unit_id='.$unit.' AND batch_id IN ('.implode(',',$ids).'))';$where='('.implode(' OR ',$clauses).')';
    $tables=['legacy_full_payments','legacy_full_journals'];if(lop_ready($db))array_push($tables,'legacy_op_payments','legacy_op_journals');
    // Operator IDs encode the exact source bytes. A case-insensitive DISTINCT
    // can discard a valid ID (including case or trailing-space variants).
    $parts=[];foreach($tables as $table)$parts[]="SELECT DISTINCT unit_id,CAST(source_operator AS BINARY) source_operator FROM $table WHERE $where".($table==='legacy_op_journals'?" AND origin='legacy'":'');
    foreach(lf_rows($db,hp_operator_sql($db,implode(' UNION ',$parts))) as $r)$out[lful_operator_key((int)$r['unit_id'],$r['source_operator'])]='Legacy · '.unit_label((int)$r['unit_id']).' · '.($r['source_operator']?:'Tidak tercatat');
    return $options[$cacheKey]=$out;
}
/** Negative UI IDs are a disjoint read namespace, never written to bayar or authorization. */
function lful_payment_source(mysqli $db): string {
    if(!lful_read_ready($db))return 'bayar';$columns=lf_rows($db,"SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bayar' ORDER BY ORDINAL_POSITION");
    $time=payment_time_projection($db);
    $mapping=['id'=>'-p.id','unit_id'=>'p.unit_id','NO_INDUK'=>'s.NO_INDUK','KELAS'=>'p.class_snapshot','kelas_rombel_snapshot'=>'p.class_snapshot','TGL_BYR'=>$time['value'],'total_jumlah'=>'p.total','user_id'=>"CONCAT('legacy:',p.unit_id,':',LOWER(HEX(COALESCE(p.source_operator,''))))",'BULAN'=>"''",'TAHUN'=>"''",'KETERANGAN'=>"CONCAT('Legacy · ',COALESCE(p.source_period,''))",'sistem_pembayaran'=>"'Legacy'",'created_at'=>'NULL','updated_at'=>$time['updated']];
    $select=[];foreach($columns as $c){$col=$c['COLUMN_NAME'];if(!preg_match('/^[a-zA-Z_0-9]+$/D',$col))throw new LogicException('Invalid column');$select[]=($mapping[$col]??'0').' AS `'.$col.'`';}
    $op=lop_ready($db)?' UNION ALL '.lop_payment_select($db,$columns):'';
    return '(SELECT * FROM bayar UNION ALL SELECT '.implode(',',$select)." FROM legacy_full_payments p JOIN siswa s ON s.id=p.student_id AND s.unit_id=p.unit_id JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id".$time['join']." WHERE b.state='completed'".$op.')';
}
function lful_history_model(mysqli $db,int $id): array {
    if($id>1000000000&&lop_ready($db))return lop_payment_model($db,$id-1000000000);
    if(!lful_read_ready($db))throw new OutOfBoundsException('Histori tidak ditemukan.');$p=lf_one($db,"SELECT p.*,s.NO_INDUK,s.NAMA,s.NO_induk_diknas,b.cutoff,b.source_id,b.completed_at imported_at,src.backup_hash FROM legacy_full_payments p JOIN siswa s ON s.id=p.student_id AND s.unit_id=p.unit_id JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id JOIN legacy_finance_sources src ON src.id=b.source_id AND src.unit_id=b.unit_id WHERE p.id=? AND b.state='completed'",[$id]);
    if(!$p)throw new OutOfBoundsException('Histori tidak ditemukan pada unit ini.');$p+=payment_time_record($db,$p);$p['items']=lf_rows($db,'SELECT source_column,category,label,amount FROM legacy_full_items WHERE payment_id=? ORDER BY id',[$id]);return ['legacy'=>$p];
}
function lful_history_render(array $model): void {
    $p=$model['legacy'];$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
    echo '<section class="ph-payment-box"><h3>'.$e($p['NAMA']).'</h3><p>'.(!empty($p['operational'])&&empty($p['student_id'])?'Identitas sumber ':'NIS ').$e($p['NO_INDUK']).' · '.$e(unit_label((int)$p['unit_id'])).'</p><h4>Histori Legacy</h4><p>'.$e(payment_time_label($p)).'</p><p>Periode: '.$e($p['source_period']?:'Tidak tercatat').' · Kelas sumber: '.$e($p['class_snapshot']).'</p><dl class="ph-components">';
    foreach($p['items'] as $i)echo '<div><dt>'.$e(lcs_component_view($i)['display_label']).'</dt><dd>Rp '.$e(number_format((float)$i['amount'],2,',','.')).'</dd></div>';
    echo '</dl><strong>Total Rp '.$e(number_format((float)$p['total'],2,',','.')).'</strong><p>Operator Legacy: '.$e($p['source_operator']?:'Tidak tercatat').'</p>';
    if(!empty($p['operational']))echo '<p>Metode sumber: '.$e($p['method']?:'Tidak tercatat').'</p><p>'.$e($p['note']).'</p>';
    if(!empty($p['operational'])){echo '<p>Transaksi hasil migrasi'.(!empty($p['cancelled'])?' · Dibatalkan':'').'. Sumber asli tetap tersimpan.</p>';if(empty($model['readonly'])&&unit_is_super()&&unit_active_id()===(int)$p['unit_id']){require_once __DIR__.'/migrated_history.php';$return=mh_return_create('pembayaran/lihat.php',array_replace($_GET,['selected'=>-(1000000000+(int)$p['id'])]),(int)$p['unit_id'],'payment',(int)$p['id']);echo '<a class="btn btn-primary" href="../legacy_correction.php?kind=payment&amp;id='.(int)$p['id'].'&amp;hr='.$return.'">Koreksi Transaksi</a> ';}}
    else echo '<p>Histori sumber tidak dapat diedit atau dihapus melalui transaksi biasa.</p>';
    $printId=(int)$p['id']+(!empty($p['operational'])?1000000000:0);echo '<a class="btn btn-primary" target="_blank" rel="noopener" href="../laporan/cetak_legacy.php?id='.$printId.'&amp;unit_id='.(int)$p['unit_id'].'">Salinan Transaksi Legacy</a></section>';
}
function lful_report_matches_operator(array $f,int $unit,?string $operator): bool {
    $key=lful_operator_key($unit,$operator);if(($f['operator']??'')!==''&&$f['operator']!==$key)return false;
    if(isset($f['_multi']['operator'])&&!filter_is_all($f['_multi']['operator'])&&!in_array($key,$f['_multi']['operator'],true))return false;return true;
}
function lful_report_components(mysqli $db,array $f,bool $cashProjection=false): array {
    if(!lful_read_ready($db))return [];
    $out=[];$rows=hp_rows($db,"SELECT p.*,s.NO_INDUK,s.NAMA,s.NO_induk_diknas,s.is_active student_active,s.master_kelas_id,b.source_id,b.completed_at imported_at,i.source_column,i.category,i.label,i.amount FROM legacy_full_payments p JOIN legacy_full_items i ON i.payment_id=p.id AND i.unit_id=p.unit_id JOIN siswa s ON s.id=p.student_id AND s.unit_id=p.unit_id JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id WHERE b.state='completed' AND ((p.source_date>=? AND p.source_date<?)".(!empty($f['include_undated'])?' OR p.source_date IS NULL':'').") ORDER BY p.source_date,p.id,i.id",[$f['tanggal_awal'].' 00:00:00',date('Y-m-d',strtotime($f['tanggal_akhir'].' +1 day')).' 00:00:00']);
    foreach($rows as $p){if(!report_component_method_matches($f,'Legacy'))continue;if(empty($p['source_date'])&&!report_undated_matches($f,(string)$p['source_period']))continue;$p+=payment_time_record($db,$p);if(!lful_report_matches_operator($f,(int)$p['unit_id'],$p['source_operator']))continue;$view=lcs_component_view($p);$r=['id'=>-(int)$p['id'],'unit_id'=>$p['unit_id'],'tanggal'=>$p['payment_datetime'],'time_precision'=>$p['time_precision'],'nomor'=>'Legacy · '.substr($p['row_key'],0,12),'nis'=>$p['NO_INDUK'],'nis_diknas'=>$p['NO_induk_diknas'],'nama'=>$p['NAMA'],'kelas'=>$p['class_snapshot'],'master_kelas_id'=>$p['master_kelas_id']??null,'metode'=>'Legacy','operator'=>'Legacy · '.($p['source_operator']?:'Tidak tercatat'),'kategori_key'=>$cashProjection?$view['cash_category']:$p['category'],'source_category'=>$p['category'],'komponen'=>$view['display_label'].' (Legacy)','nominal'=>(float)$p['amount'],'source_period'=>$p['source_period'],'student_id'=>(int)$p['student_id'],'student_active'=>$p['student_active'],'legacy'=>true];if(report_component_class_matches($db,$f,$r)&&report_component_matches($r,$f))$out[]=$r;}
    if(lop_ready($db)){
        foreach(hp_rows($db,"SELECT p.*,o.source_identity,o.name,o.student_id,s.NO_INDUK,s.NO_induk_diknas,s.is_active student_active,COALESCE(s.master_kelas_id,g.master_kelas_id) master_kelas_id,b.source_id,b.completed_at imported_at FROM legacy_op_payments p JOIN legacy_op_owners o ON o.id=p.owner_id AND o.unit_id=p.unit_id LEFT JOIN siswa s ON s.id=o.student_id AND s.unit_id=o.unit_id LEFT JOIN legacy_op_groups g ON g.id=o.group_id AND g.unit_id=o.unit_id JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id WHERE b.state='completed' AND p.cancelled=0 AND ((p.source_date>=? AND p.source_date<?)".(!empty($f['include_undated'])?' OR p.source_date IS NULL':'').")",[$f['tanggal_awal'].' 00:00:00',date('Y-m-d',strtotime($f['tanggal_akhir'].' +1 day')).' 00:00:00']) as $p){$method=in_array($p['method']??'',['Tunai','VA','Qris'],true)?$p['method']:'Legacy';if(!report_component_method_matches($f,$method))continue;if(empty($p['source_date'])&&!report_undated_matches($f,(string)$p['source_period']))continue;$p+=payment_time_record($db,$p);if(!lful_report_matches_operator($f,(int)$p['unit_id'],$p['source_operator']))continue;foreach(json_decode($p['items'],true) as $item){$view=lcs_component_view($item);$r=['id'=>-(1000000000+(int)$p['id']),'unit_id'=>$p['unit_id'],'tanggal'=>$p['payment_datetime'],'time_precision'=>$p['time_precision'],'nomor'=>'Legacy · '.substr($p['row_key'],0,12),'nis'=>$p['NO_INDUK']??'rekening:'.$p['owner_id'],'nis_diknas'=>$p['NO_induk_diknas'],'nama'=>$p['name'],'kelas'=>$p['class_snapshot'],'master_kelas_id'=>$p['master_kelas_id']??null,'metode'=>$method,'operator'=>'Legacy · '.($p['source_operator']?:'Tidak tercatat'),'kategori_key'=>$cashProjection?$view['cash_category']:$item['category'],'source_category'=>$item['category'],'komponen'=>$view['display_label'].' (Legacy)','nominal'=>(float)$item['amount'],'source_period'=>$p['source_period'],'student_id'=>(int)($p['student_id']??0),'legacy_owner_id'=>(int)$p['owner_id'],'student_active'=>$p['student_active'],'legacy'=>true];if(report_component_class_matches($db,$f,$r)&&report_component_matches($r,$f))$out[]=$r;}}
    }
    return $out;
}
function lful_journals(mysqli $db,int $student,int $unit): array {
    if(!lful_read_ready($db))return [];$rows=lf_rows($db,"SELECT j.id,j.source_date tanggal,j.incoming masuk,j.outgoing keluar,CASE WHEN j.incoming>0 THEN 0 ELSE 1 END urutan_mutasi,j.source_operator,j.row_key FROM legacy_full_journals j JOIN legacy_full_batches b ON b.id=j.batch_id AND b.unit_id=j.unit_id WHERE j.student_id=? AND j.unit_id=? AND b.state='completed' ORDER BY j.source_date,j.id",[$student,$unit]);
    if(lop_ready($db))$rows=array_merge($rows,lf_rows($db,"SELECT -(1000000000+j.id) id,j.source_date tanggal,j.incoming masuk,j.outgoing keluar,CASE WHEN j.incoming>0 THEN 0 ELSE 1 END urutan_mutasi,j.source_operator,j.row_key,j.origin FROM legacy_op_journals j JOIN legacy_op_owners o ON o.id=j.owner_id AND o.unit_id=j.unit_id JOIN legacy_full_batches b ON b.id=j.batch_id AND b.unit_id=j.unit_id WHERE o.student_id=? AND o.unit_id=? AND b.state='completed' AND j.cancelled=0 AND j.origin IN ('legacy','adjustment') ORDER BY j.source_date,(j.origin='adjustment'),j.id",[$student,$unit]));return $rows;
}
function lful_is_covered(mysqli $db,string $nis,string $category,string $period): bool {
    if(!lful_read_ready($db))return false;$s=lf_one($db,"SELECT f.student_id,f.settings FROM legacy_full_students f JOIN siswa s ON s.id=f.student_id AND s.unit_id=f.unit_id WHERE s.NO_INDUK=?",[$nis]);if(!$s)return false;
    $settings=json_decode($s['settings'],true,512,JSON_THROW_ON_ERROR);if(in_array($category,['spp','komite'],true)&&$period<($settings['start_period']??''))return true;
    if(str_starts_with($category,'biaya_lain:'))return (bool)lf_one($db,'SELECT id FROM legacy_full_coverage WHERE student_id=? AND category=?',[$s['student_id'],$category]);
    return (bool)lf_one($db,'SELECT id FROM legacy_full_coverage WHERE student_id=? AND category=? AND period=?',[$s['student_id'],$category,$period]);
}
/** Request-local evidence for the same monthly cells; never retained across writes. */
function lful_monthly_coverage_snapshot(mysqli $db,array $identities,string $category,array $periods): array {
    $snapshot=['students'=>[],'coverage'=>[]];
    if(!in_array($category,['spp','komite'],true))throw new InvalidArgumentException('Monthly coverage category required.');
    if(!$identities||!$periods||!lful_read_ready($db))return $snapshot;
    $marks=implode(',',array_fill(0,count($identities),'?'));
    foreach(hp_rows($db,"SELECT s.NO_INDUK,f.student_id,f.review_id,f.settings FROM legacy_full_students f JOIN siswa s ON s.id=f.student_id AND s.unit_id=f.unit_id WHERE s.NO_INDUK IN ($marks)",$identities) as $student){
        $student['settings']=json_decode($student['settings'],true,512,JSON_THROW_ON_ERROR);
        $snapshot['students'][$student['NO_INDUK']]=$student;
    }
    $ids=array_column($snapshot['students'],'student_id');if(!$ids)return $snapshot;
    $studentMarks=implode(',',array_fill(0,count($ids),'?'));$periodMarks=implode(',',array_fill(0,count($periods),'?'));
    foreach(hp_rows($db,"SELECT * FROM legacy_full_coverage WHERE student_id IN ($studentMarks) AND category=? AND period IN ($periodMarks)",array_merge($ids,[$category],$periods)) as $coverage)
        $snapshot['coverage'][$coverage['student_id']][$category][$coverage['period']]=$coverage;
    return $snapshot;
}
function lful_coverage_status(mysqli $db,string $nis,string $category,string $period,?array $snapshot=null): ?array {
    if($snapshot===null){
        if(!lful_is_covered($db,$nis,$category,$period))return null;
        $c=lf_one($db,'SELECT c.*,fs.review_id FROM legacy_full_coverage c JOIN legacy_full_students fs ON fs.student_id=c.student_id AND fs.unit_id=c.unit_id JOIN siswa s ON s.id=c.student_id AND s.unit_id=c.unit_id WHERE s.NO_INDUK=? AND c.category=? AND c.period=?',[$nis,$category,$period]);
    }else{
        $student=$snapshot['students'][$nis]??null;if(!$student)return null;
        $c=$snapshot['coverage'][$student['student_id']][$category][$period]??null;
        if(!$c&&!(in_array($category,['spp','komite'],true)&&$period<($student['settings']['start_period']??'')))return null;
        if($c)$c['review_id']=$student['review_id'];
    }
    if(!$c)return ['label'=>'Histori Legacy · kewajiban tidak tercatat','bill'=>0.0,'paid'=>0.0];
    if($c['disposition']!=='legacy_debt')return ['label'=>$c['disposition']==='settled'?'Sudah dibayar (Legacy)':'Tidak ditagihkan (Legacy)','bill'=>0.0,'paid'=>0.0];
    $label=['spp'=>'SPP','komite'=>'Komite','daftar_ulang'=>'Daftar Ulang','psb'=>'Uang PSB'][$category]??null;
    if(!$label){$master=lf_one($db,'SELECT nama FROM master_biaya_lain WHERE id=?',[(int)substr($category,11)]);$label=$master['nama']??'';}
    $debt=lf_one($db,"SELECT t.nominal_tagihan,t.status,COALESCE((SELECT SUM(nominal_snapshot) FROM bayar_biaya_lain WHERE tagihan_biaya_lain_id=t.id),0) paid FROM legacy_debts d JOIN legacy_debt_masters dm ON dm.id=d.master_id AND dm.unit_id=d.unit_id JOIN tagihan_biaya_lain t ON t.id=d.bill_id AND t.unit_id=d.unit_id WHERE d.review_id=? AND dm.component=? AND dm.period=?",[$c['review_id'],$label,$period]);
    if(!$debt)return ['label'=>'Tunggakan Legacy · perlu pemeriksaan','bill'=>0.0,'paid'=>0.0];
    $bill=$debt['status']==='cancelled'?0.0:(float)$debt['nominal_tagihan'];$paid=(float)$debt['paid'];return ['label'=>$debt['status']==='cancelled'?'Dibatalkan (Legacy)':($paid+.001>=$bill?'Lunas (Tunggakan Legacy)':($paid>.001?'Cicilan Tunggakan Legacy':'Tunggakan Legacy')),'bill'=>$bill,'paid'=>$paid];
}

function lful_savings_source(mysqli $db,string $table): string {
    if(!in_array($table,['transaksi_m','transaksi_k'],true))throw new LogicException('Invalid journal table');if(!lful_read_ready($db))return $table;
    $cols=lf_rows($db,'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION',[$table]);$map=['id'=>'-j.id','unit_id'=>'j.unit_id','NO_INDUK'=>'s.NO_INDUK','TANGGAL'=>'j.source_date','MASUK'=>'j.incoming','KELUAR'=>'j.outgoing','user_id'=>"CONCAT('Legacy · ',COALESCE(j.source_operator,'Tidak tercatat'))",'keterangan'=>"'Histori Legacy'"]; $select=[];
    foreach($cols as $c)$select[]=($map[$c['COLUMN_NAME']]??'NULL').' AS `'.$c['COLUMN_NAME'].'`';
    $op='';if(lop_ready($db)){$map['id']='-(1000000000+j.id)';$opSelect=[];foreach($cols as $c)$opSelect[]=($map[$c['COLUMN_NAME']]??'NULL').' AS `'.$c['COLUMN_NAME'].'`';$op=' UNION ALL SELECT '.implode(',',$opSelect)." FROM legacy_op_journals j JOIN legacy_op_owners o ON o.id=j.owner_id AND o.unit_id=j.unit_id JOIN siswa s ON s.id=o.student_id AND s.unit_id=o.unit_id JOIN legacy_full_batches b ON b.id=j.batch_id AND b.unit_id=j.unit_id WHERE b.state='completed' AND j.cancelled=0 AND j.origin<>'adjustment' AND ".($table==='transaksi_m'?'j.incoming>0':'j.outgoing>0');}
    return '(SELECT * FROM '.$table.' UNION ALL SELECT '.implode(',',$select)." FROM legacy_full_journals j JOIN siswa s ON s.id=j.student_id AND s.unit_id=j.unit_id JOIN legacy_full_batches b ON b.id=j.batch_id AND b.unit_id=j.unit_id WHERE b.state='completed' AND ".($table==='transaksi_m'?'j.incoming>0':'j.outgoing>0').$op.')';
}
