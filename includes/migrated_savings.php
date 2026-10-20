<?php
require_once __DIR__.'/migrated_history.php';
require_once __DIR__.'/pagination.php';

/** Cached balances plus original ledgers; no balance is recomputed or written here. */

/** The history screen and exports share owner IDs, unit scoping and predicates. */
function mh_savings_read_query(mysqli $db,array $input,array $classFilter,array $operators,string $start,string $end,bool $summaryOnly=false): array {
    $source=mh_savings_source($db,!$summaryOnly||!filter_is_all($operators));$where=' WHERE 1=1';$args=[];
    if(($input['all_periods']??'')!=='1'){$where.=' AND t.TANGGAL>=? AND t.TANGGAL<?';$args=[$start,$end];}
    $q=is_string($input['nis']??'')?trim($input['nis']??''):'';
    if($q!==''){$where.=' AND (t.NO_INDUK=? OR t.NO_induk_diknas=? OR t.NAMA LIKE ?)';array_push($args,$q,$q,'%'.$q.'%');}
    foreach(['owner_id'=>'legacy_owner_id','student_id'=>'student_id'] as $key=>$col)if((int)($input[$key]??0)>0){$where.=" AND t.$col=?";$args[]=(int)$input[$key];}
    $where.=filter_sql_values($classFilter['values'],'t.current_class_id');
    $where.=filter_sql_values(array_map(static fn($v)=>$v==='unknown'?'0':$v,$operators),'t.operator_key');
    return ['sql'=>"SELECT t.* FROM $source t $where",'args'=>$args];
}

function mh_account_source(mysqli $db,bool $activity=true): string {
    $journal=mh_savings_source($db);$students=mh_students_source($db);
    $student="SELECT s.id student_id,0 legacy_owner_id,'' batch_id,s.unit_id,s.NO_INDUK,s.NO_induk_diknas,s.NAMA,s.KELAS,s.master_kelas_id,COALESCE(t.SALDO,0) SALDO,'student' owner_type FROM $students s LEFT JOIN tabungan t ON t.NO_INDUK=s.NO_INDUK AND t.unit_id=s.unit_id WHERE s.legacy_pending=0";
    if(lop_ready($db))$student.=" UNION ALL SELECT 0,o.id,o.batch_id,o.unit_id,o.source_identity,'',o.name,o.source_class,g.master_kelas_id,a.balance,'account' FROM legacy_op_owners o JOIN legacy_op_accounts a ON a.owner_id=o.id AND a.unit_id=o.unit_id LEFT JOIN legacy_op_groups g ON g.id=o.group_id AND g.unit_id=o.unit_id JOIN legacy_full_batches b ON b.id=o.batch_id AND b.unit_id=o.unit_id WHERE o.student_id IS NULL AND o.state='ready' AND b.state='completed'";
    if(!$activity)return hp_sql($db,"(SELECT a.*,mk.jenis rombel_jenis,mk.nama_custom rombel_nama,mk.tingkat,mk.kode_rombel,mk.is_placeholder FROM ($student) a LEFT JOIN master_kelas mk ON mk.id=a.master_kelas_id AND mk.unit_id=a.unit_id)");
    // Student journals use the immutable student ID; source identity strings never join owners.
    $key="IF(student_id IS NOT NULL,CONCAT('s:',student_id),CONCAT('o:',legacy_owner_id))";
    return "(SELECT a.*,mk.jenis rombel_jenis,mk.nama_custom rombel_nama,mk.tingkat,mk.kode_rombel,mk.is_placeholder,j.last_activity,COALESCE(j.journal_count,0) journal_count FROM ($student) a LEFT JOIN master_kelas mk ON mk.id=a.master_kelas_id AND mk.unit_id=a.unit_id LEFT JOIN (SELECT unit_id,$key owner_key,MAX(TANGGAL) last_activity,COUNT(*) journal_count FROM $journal t WHERE origin<>'adjustment' GROUP BY unit_id,owner_key) j ON j.unit_id=a.unit_id AND j.owner_key=IF(a.student_id>0,CONCAT('s:',a.student_id),CONCAT('o:',a.legacy_owner_id)))";
}
/** Enrich only a bounded account page, without operator or class display joins. */
function mh_account_activity_many(mysqli $db,array $rows): array {
    if(!$rows)return [];$studentIds=[];$ownerIds=[];$parts=[];
    foreach($rows as $r){if($r['student_id'])$studentIds[]=(int)$r['student_id'];else $ownerIds[]=(int)$r['legacy_owner_id'];}
    if($studentIds){$ids=implode(',',array_unique($studentIds));
        foreach(['transaksi_m','transaksi_k'] as $table)$parts[]="SELECT s.unit_id,CONCAT('s:',s.id) owner_key,MAX(t.TANGGAL) last_activity,COUNT(*) journal_count FROM $table t JOIN siswa s ON s.NO_INDUK=t.NO_INDUK AND s.unit_id=t.unit_id WHERE s.id IN ($ids) GROUP BY s.unit_id,s.id";
        if(lful_read_ready($db))$parts[]="SELECT j.unit_id,CONCAT('s:',j.student_id) owner_key,MAX(j.source_date) last_activity,COUNT(*) journal_count FROM legacy_full_journals j JOIN legacy_full_batches b ON b.id=j.batch_id AND b.unit_id=j.unit_id WHERE j.student_id IN ($ids) AND b.state='completed' GROUP BY j.unit_id,j.student_id";
    }
    if(lop_ready($db)){
        $filters=[];if($studentIds)$filters[]='o.student_id IN ('.implode(',',array_unique($studentIds)).')';if($ownerIds)$filters[]='o.id IN ('.implode(',',array_unique($ownerIds)).')';
        $parts[]="SELECT j.unit_id,IF(o.student_id IS NULL,CONCAT('o:',o.id),CONCAT('s:',o.student_id)) owner_key,MAX(j.source_date) last_activity,COUNT(*) journal_count FROM legacy_op_journals j JOIN legacy_op_owners o ON o.id=j.owner_id AND o.unit_id=j.unit_id JOIN legacy_full_batches b ON b.id=j.batch_id AND b.unit_id=j.unit_id WHERE (".implode(' OR ',$filters).") AND j.cancelled=0 AND j.origin<>'adjustment' AND b.state='completed' GROUP BY j.unit_id,owner_key";
    }
    $activity=[];if($parts)foreach(hp_rows($db,'SELECT unit_id,owner_key,MAX(last_activity) last_activity,SUM(journal_count) journal_count FROM ('.implode(' UNION ALL ',$parts).') a GROUP BY unit_id,owner_key') as $j)$activity[$j['unit_id'].'|'.$j['owner_key']]=$j;
    foreach($rows as &$r){$j=$activity[$r['unit_id'].'|'.($r['student_id']?'s:'.$r['student_id']:'o:'.$r['legacy_owner_id'])]??[];$r['last_activity']=$j['last_activity']??null;$r['journal_count']=(int)($j['journal_count']??0);}unset($r);return $rows;
}

function mh_account_page(mysqli $db,array $input): array {
    $source=mh_account_source($db,false);$q=is_string($input['saldo_q']??'')?mb_substr(trim($input['saldo_q']??''),0,100):'';
    $where=' WHERE 1=1';$args=[];
    if($q!==''){$where.=' AND (a.NAMA LIKE ? OR a.NO_INDUK LIKE ? OR a.NO_induk_diknas LIKE ?)';$args=array_fill(0,3,'%'.$q.'%');}
    $statusInput=$input['saldo_status']??'all';if(is_array($statusInput))$statusInput=count($statusInput)===1&&$statusInput[0]==='positive'?'positive':(count($statusInput)===1&&$statusInput[0]==='zero'?'zero':'all');$status=mh_choice(['saldo_status'=>$statusInput],'saldo_status',['all','positive','zero'],'all');
    if($status==='positive')$where.=' AND a.SALDO>0';elseif($status==='zero')$where.=' AND a.SALDO=0';
    $class=max(0,(int)($input['saldo_class']??0));if($class){$where.=' AND a.master_kelas_id=?';$args[]=$class;}
    $summary=lf_one($db,"SELECT COUNT(*) n,COALESCE(SUM(SALDO),0) balance FROM $source a $where",$args);
    $total=(int)$summary['n'];$size=25;$pages=max(1,(int)ceil($total/$size));$page=min(max(1,(int)($input['saldo_page']??1)),$pages);$offset=($page-1)*$size;
    $rows=lf_rows($db,"SELECT a.* FROM $source a $where ORDER BY a.NAMA,a.unit_id,a.student_id,a.legacy_owner_id LIMIT $size OFFSET $offset",$args);
    $rows=mh_account_activity_many($db,$rows);
    return compact('rows','total','page','pages','offset','q','status','class','summary');
}
function mh_account_recap(mysqli $db,array $input): void {
    $p=mh_account_page($db,$input);$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $classes=class_all($db,false);$context=array_intersect_key($input,array_flip(['nis','student_id','tanggal_awal','tanggal_akhir','operator','rombel','all_periods','per_page','page','id','jenis']));
    ?><section class="main-card savings-recap-card" id="account-recap"><header class="savings-recap-head"><div><span class="section-kicker">REKAP TABUNGAN</span><h3 class="card-title">Siswa &amp; Pemilik Rekening</h3><p><?= number_format($p['total'],0,',','.') ?> pemilik sesuai filter. Saldo peralihan tidak dihitung sebagai setoran.</p></div><div class="savings-recap-total"><span>Total saldo sesuai filter</span><strong><?= savings_money($p['summary']['balance']) ?></strong></div></header>
    <form method="get" class="mh-recap-filter">
    <?php foreach($context as $key=>$value)foreach(is_array($value)?$value:[$value] as $v): ?><input type="hidden" name="<?= $e($key).(is_array($value)?'[]':'') ?>" value="<?= $e($v) ?>"><?php endforeach; ?>
    <label>Nama / identitas<input class="field-input" name="saldo_q" value="<?= $e($p['q']) ?>" maxlength="100"></label><label>Kelas / kelompok<select class="field-input field-select" name="saldo_class"><option value="0">Semua kelas</option><?php foreach($classes as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $p['class']===(int)$c['id']?'selected':'' ?>><?= $e((unit_all_readonly()?unit_label((int)$c['unit_id']).' · ':'').class_label($c)) ?></option><?php endforeach; ?></select></label><label>Saldo<select class="field-input field-select" name="saldo_status"><?php foreach(['all'=>'Semua saldo','positive'=>'Ada saldo','zero'=>'Saldo nol'] as $v=>$label): ?><option value="<?= $v ?>" <?= $p['status']===$v?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label><button class="btn btn-primary">Cari Rekening</button></form>
    <div class="table-container"><table class="payment-table responsive-table"><thead><tr><th>Pemilik</th><th>Kelas / kelompok</th><th>Saldo</th><th>Aktivitas terakhir</th><th>Tindakan</th></tr></thead><tbody>
    <?php foreach($p['rows'] as $r):$link='riwayat.php?'.http_build_query(['all_periods'=>'1']+($r['student_id']?['student_id'=>$r['student_id']]:['owner_id'=>$r['legacy_owner_id']])); ?><tr><td data-label="Pemilik"><strong><?= $e($r['NAMA']) ?></strong><small class="mh-block"><?= $e($r['NO_INDUK']) ?> · <?= $r['owner_type']==='student'?'Siswa':'Rekening non-siswa' ?></small><?= unit_record_badge($r) ?></td><td data-label="Kelompok"><?= $e(class_label($r)) ?></td><td data-label="Saldo"><?= savings_money($r['SALDO']) ?></td><td data-label="Aktivitas terakhir"><?= $r['last_activity']?$e(spp_date_label($r['last_activity'])):'Belum ada transaksi' ?><small class="mh-block"><?= (int)$r['journal_count'] ?> jurnal</small></td><td data-label="Tindakan"><a class="btn btn-ghost btn-sm" href="<?= $e($link) ?>">Riwayat</a><a class="btn btn-ghost btn-sm" href="cetak_buku.php?<?= $e(http_build_query($r['student_id']?['nis'=>$r['NO_INDUK'],'student_id'=>$r['student_id']]:['owner_id'=>$r['legacy_owner_id']])) ?>">Buku Tabungan</a></td></tr><?php endforeach; ?>
    <?php if(!$p['rows']): ?><tr><td colspan="5">Tidak ada rekening sesuai filter. Ubah nama, kelas, atau status saldo.</td></tr><?php endif; ?></tbody></table></div>
    <?php render_pagination('riwayat.php',$context+['saldo_q'=>$p['q'],'saldo_status'=>$p['status'],'saldo_class'=>$p['class']],$p['page'],$p['pages'],$p['total'],25,'pemilik','saldo_page'); ?></section><?php
}
