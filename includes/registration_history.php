<?php
require_once __DIR__.'/filter_choices.php';
require_once __DIR__.'/payment_history.php';
require_once __DIR__.'/payment_return.php';
require_once __DIR__.'/pagination.php';
require_once __DIR__.'/kelas.php';
require_once __DIR__.'/history_operator_filter.php';

function registration_filter_query(array $f): array
{
    return ['view'=>$f['view'],'q'=>$f['q'],'student_id'=>$f['student_id'],'kelas'=>$f['values']['kelas'],
        'tahun_ajaran'=>$f['values']['tahun_ajaran'],'status'=>$f['values']['status'],'operator'=>$f['values']['operator']??['*'],'per_page'=>$f['per_page'],'page'=>$f['page']];
}

function registration_filters(mysqli $db, array $input): array
{
    $view = $input['view'] ?? 'active';
    if (!is_string($view) || !in_array($view, ['active','deleted'], true)) throw new InvalidArgumentException('Jenis riwayat tidak valid.');
    foreach (['q','student_id','per_page','page','selected','selected_unit'] as $key) {
        if (isset($input[$key]) && !is_scalar($input[$key])) throw new InvalidArgumentException('Parameter '.$key.' tidak valid.');
    }
    if (isset($input['student_id']) && (string)$input['student_id']!=='' && !preg_match('/^[0-9]+$/D',(string)$input['student_id']))
        throw new InvalidArgumentException('Identitas siswa tidak valid. Pilih siswa yang tersedia pada unit ini.');
    $classes = class_all($db, true); $classOptions = [];
    foreach (range(...unit_level_bounds()) as $level) $classOptions['tingkat:'.$level] = 'Kelas '.$level;
    foreach ($classes as $class) $classOptions['rombel:'.$class['id']] = $class['label'];
    // Archived rombels can be inactive. Only expose those within the same unit scope.
    if ($view === 'deleted') foreach (class_all($db, false) as $class) $classOptions['rombel:'.$class['id']] = $class['label'];
    $years = array_column($db->query("SELECT label FROM tahun_ajaran WHERE status IN ('published','closed') ORDER BY label DESC")->fetch_all(MYSQLI_ASSOC), 'label');
    if ($view === 'deleted') {
        foreach (registration_archive_groups($db) as $group) if ($group['th_ajaran'] !== 'Tidak tercatat') $years[] = $group['th_ajaran'];
        $years = array_values(array_unique($years)); rsort($years);
    }
    $classInput = $input['kelas'] ?? null;
    if (is_scalar($classInput) && ctype_digit((string)$classInput) && isset($classOptions['tingkat:'.$classInput])) $classInput = 'tingkat:'.$classInput;
    $operatorOptions=history_operator_options($db);
    $operators=history_operator_choices($input['operator']??null,$operatorOptions);
    $options = ['kelas'=>$classOptions,'tahun_ajaran'=>array_combine($years,$years),'status'=>['lunas'=>'Lunas','cicilan'=>'Belum Lunas']];
    $values = [];
    foreach ($options as $key=>$allowed) {
        $raw = $key==='kelas' ? $classInput : ($input[$key] ?? null);
        if (is_array($raw)) {
            if (!$raw || count($raw)>10000) throw new InvalidArgumentException('Pilih minimal satu opsi '.$key.'.');
            foreach ($raw as $value) if (!is_scalar($value) || ((string)$value!=='*' && !array_key_exists((string)$value,$allowed)))
                throw new InvalidArgumentException('Pilihan '.$key.' tidak tersedia pada unit ini.');
            if (in_array('*',$raw,true) && count(array_unique($raw))!==1) throw new InvalidArgumentException('Pilihan Semua tidak dapat digabung dengan opsi '.$key.' lain.');
        } elseif ($raw!==null && (!is_scalar($raw) || !in_array((string)$raw,['','*','all','semua','0'],true) && !array_key_exists((string)$raw,$allowed))) {
            throw new InvalidArgumentException('Pilihan '.$key.' tidak tersedia pada unit ini.');
        }
        $values[$key] = filter_register($key,$raw,$allowed);
        foreach ($values[$key] as $value) if ($value!=='*' && !array_key_exists($value,$allowed)) throw new InvalidArgumentException('Pilihan '.$key.' tidak tersedia pada unit ini.');
    }
    $values['operator']=$operators;$options['operator']=$operatorOptions;
    $studentId = max(0,(int)($input['student_id'] ?? 0)); $student = null;
    if ($studentId) {
        $s=$db->prepare('SELECT id,unit_id,NO_INDUK,NAMA FROM siswa WHERE id=?');$s->bind_param('i',$studentId);$s->execute();$student=$s->get_result()->fetch_assoc();$s->close();
        if (!$student) throw new InvalidArgumentException('Siswa tidak tersedia pada unit ini.');
    }
    return ['view'=>$view,'q'=>trim((string)($input['q']??'')),'student_id'=>$studentId,'student'=>$student,
        'values'=>$values,'options'=>$options,'classes'=>$classes,'per_page'=>in_array((int)($input['per_page']??10),[10,25,50,100],true)?(int)($input['per_page']??10):10,
        'page'=>max(1,(int)($input['page']??1))];
}

function registration_active_sql(array $f, ?mysqli $db=null): array
{
    $where=["tdu.status='open'"]; $params=[];$types='';
    if ($f['student_id']) {$where[]='s.id=?';$params[]=$f['student_id'];$types.='i';}
    elseif ($f['q']!=='') {$where[]='(s.NAMA LIKE ? OR s.NO_INDUK LIKE ? OR s.NO_induk_diknas LIKE ?)';$like='%'.$f['q'].'%';array_push($params,$like,$like,$like);$types.='sss';}
    if (!filter_is_all($f['values']['kelas'])) {
        $clauses=[];foreach ($f['values']['kelas'] as $value) {
            $rombel=str_starts_with($value,'rombel:');$clauses[]=($rombel?'s.master_kelas_id':'COALESCE(mk.tingkat,s.KELAS,tdu.kelas_snapshot)').'=?';
            $params[]=substr($value,$rombel?7:8);$types.='s';
        }$where[]='('.implode(' OR ',$clauses).')';
    }
    if (!filter_is_all($f['values']['tahun_ajaran'])) {$where[]='ta.label IN ('.implode(',',array_fill(0,count($f['values']['tahun_ajaran']),'?')).')';array_push($params,...$f['values']['tahun_ajaran']);$types.=str_repeat('s',count($f['values']['tahun_ajaran']));}
    $operatorPaid='';
    if (!filter_is_all($f['values']['operator']??['*'])) {
        $db??=$GLOBALS['koneksi'];
        $ownerWhere=history_operator_where($db,$f['values']['operator'],'ob.id','ob.unit_id');
        $operatorSql="SELECT od.jumlah FROM bayar_du od JOIN bayar ob ON ob.id=od.bayar_id AND ob.unit_id=od.unit_id
            WHERE od.tagihan_daftar_ulang_id=tdu.id AND od.unit_id=tdu.unit_id".$ownerWhere;
        $where[]='EXISTS('.$operatorSql.')';
        $operatorPaid=",COALESCE((SELECT SUM(od.jumlah) FROM bayar_du od JOIN bayar ob ON ob.id=od.bayar_id AND ob.unit_id=od.unit_id
            WHERE od.tagihan_daftar_ulang_id=tdu.id AND od.unit_id=tdu.unit_id".$ownerWhere."),0) operator_paid";
    }
    $sql="SELECT tdu.unit_id,tdu.id tagihan_id,tdu.no_induk,ta.label th_ajaran,s.NAMA nama,s.NO_induk_diknas,
        tdu.kelas_snapshot kelas,COALESCE(mk.tingkat,s.KELAS,tdu.kelas_snapshot) tingkat,s.master_kelas_id,
        tdu.nominal_tagihan total,COALESCE(SUM(bd.jumlah),0) paid,
        GREATEST(0,tdu.nominal_tagihan-COALESCE(SUM(bd.jumlah),0)) remaining $operatorPaid
        FROM tagihan_daftar_ulang tdu JOIN tahun_ajaran ta ON ta.id=tdu.tahun_ajaran_id
        JOIN siswa s ON s.NO_INDUK=tdu.no_induk AND s.unit_id=tdu.unit_id
        LEFT JOIN master_kelas mk ON mk.id=s.master_kelas_id
        LEFT JOIN bayar_du bd ON bd.tagihan_daftar_ulang_id=tdu.id AND bd.unit_id=tdu.unit_id
        WHERE ".implode(' AND ',$where)." GROUP BY tdu.unit_id,tdu.id,tdu.no_induk,ta.label,s.NAMA,s.NO_induk_diknas,
        tdu.kelas_snapshot,mk.tingkat,s.KELAS,s.master_kelas_id,tdu.nominal_tagihan";
    if ($f['values']['status']===['lunas']) $sql.=' HAVING remaining<=0.001';
    if ($f['values']['status']===['cicilan']) $sql.=' HAVING remaining>0.001';
    return [$sql,$params,$types];
}

function registration_query(mysqli $db,string $sql,array $params=[],string $types=''): array
{
    $s=$db->prepare($sql);if($params)$s->bind_param($types,...$params);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();return $rows;
}

/** Only deletion snapshots with explicit Daftar Ulang detail are evidence of this archive. */
function registration_archive_groups(mysqli $db, array $operators=['*']): array
{
    $groups=[];
    if (!payment_activity_ready($db)) return [];
    $ownerWhere=history_operator_where($db,$operators,'a.payment_id','a.unit_id');
    $events=$db->query("SELECT a.* FROM pembayaran_aktivitas a WHERE a.action='deleted' $ownerWhere
        AND NOT EXISTS(SELECT 1 FROM pembayaran_aktivitas n WHERE n.action='deleted' AND n.unit_id=a.unit_id AND n.payment_id=a.payment_id AND n.id>a.id)
        ORDER BY a.occurred_at DESC,a.id DESC")->fetch_all(MYSQLI_ASSOC);
    foreach ($events as $event) {
        $snapshot=json_decode($event['before_snapshot']??'null',true);$p=$snapshot['payment']??[];
        if (!$p) continue;
        foreach ($snapshot['details']['bayar_du']??[] as $detail) {
            $bill=(int)($detail['tagihan_daftar_ulang_id']??0);if($bill<=0)continue;
            $year=(string)(($detail['th_ajaran']??'')?:'Tidak tercatat');$nis=(string)($detail['no_induk']??$p['NO_INDUK']??'');$unit=(int)$event['unit_id'];
            $key=$unit.'|'.$nis.'|'.$year.'|'.$bill;
            $groups[$key] ??= ['tagihan_id'=>$bill,'unit_id'=>$unit,'no_induk'=>$nis,'nama'=>$p['NAMA']??'Tidak tercatat',
                'NO_induk_diknas'=>$p['NO_induk_diknas']??'','kelas'=>($detail['kelas']??'')?:($p['KELAS']??'Tidak tercatat'),
                'master_kelas_id'=>(int)($p['master_kelas_id']??0),'th_ajaran'=>$year,'deleted_amount'=>0,'transactions'=>[]];
            $p['id']=(int)$event['payment_id'];$p['unit_id']=$unit;$p['_deleted_event']=$event;
            $amount=(float)($detail['jumlah']??0);
            if (!isset($groups[$key]['transactions'][$p['id']])) $groups[$key]['transactions'][$p['id']]=['payment'=>$p,'jumlah'=>0,'details'=>$snapshot['details']??[]];
            $groups[$key]['transactions'][$p['id']]['jumlah']+=$amount;$groups[$key]['deleted_amount']+=$amount;
        }
    }
    $groups=array_values($groups);
    usort($groups,static fn($a,$b)=>strcmp($b['th_ajaran'],$a['th_ajaran']) ?: ((int)$a['kelas']<=>(int)$b['kelas']) ?: strcmp($a['nama'],$b['nama']) ?: ($a['tagihan_id']<=>$b['tagihan_id']));
    return $groups;
}

function registration_archive_matches(array $g,array $f): bool
{
    if($f['student'] && ((int)$g['unit_id']!==(int)$f['student']['unit_id'] || $g['no_induk']!==$f['student']['NO_INDUK']))return false;
    if(!$f['student'] && $f['q']!=='' && mb_stripos(implode(' ',[$g['nama'],$g['no_induk'],$g['NO_induk_diknas']]),$f['q'])===false)return false;
    if(!filter_is_all($f['values']['tahun_ajaran']) && !in_array($g['th_ajaran'],$f['values']['tahun_ajaran'],true))return false;
    if(!filter_is_all($f['values']['kelas'])) {
        $matched=false;foreach($f['values']['kelas'] as $choice) {
            if(str_starts_with($choice,'rombel:') && (int)substr($choice,7)===(int)$g['master_kelas_id'])$matched=true;
            if(str_starts_with($choice,'tingkat:') && substr($choice,8)===(string)$g['kelas'])$matched=true;
        }if(!$matched)return false;
    }return true;
}

function registration_page(mysqli $db,array $f): array
{
    if($f['view']==='deleted') {
        $all=array_values(array_filter(registration_archive_groups($db,$f['values']['operator']??['*']),static fn($g)=>registration_archive_matches($g,$f)));
        $transactionIds=[];foreach($all as $g)foreach($g['transactions'] as $t)$transactionIds[$g['unit_id'].'|'.$t['payment']['id']]=true;
        $summary=['students'=>count($all),'transactions'=>count($transactionIds),'deleted_amount'=>array_sum(array_column($all,'deleted_amount'))];
        $pages=total_pages(count($all),$f['per_page']);$page=min($f['page'],$pages);$offset=($page-1)*$f['per_page'];$rows=array_slice($all,$offset,$f['per_page']);
    } else {
        [$sql,$params,$types]=registration_active_sql($f,$db);
        $operatorSummary=filter_is_all($f['values']['operator']??['*'])?'':',COALESCE(SUM(operator_paid),0) operator_paid';
        $summary=registration_query($db,"SELECT COUNT(*) students,COALESCE(SUM(total),0) bill,COALESCE(SUM(paid),0) paid,COALESCE(SUM(remaining),0) remaining $operatorSummary FROM ($sql) x",$params,$types)[0];
        $pages=total_pages((int)$summary['students'],$f['per_page']);$page=min($f['page'],$pages);$offset=($page-1)*$f['per_page'];
        $rows=registration_query($db,"SELECT * FROM ($sql) x ORDER BY th_ajaran DESC,CAST(kelas AS UNSIGNED),nama,tagihan_id LIMIT ? OFFSET ?",array_merge($params,[$f['per_page'],$offset]),$types.'ii');
    }return compact('summary','pages','page','offset','rows');
}

function registration_detail(mysqli $db,int $bill,int $unit,string $view,array $operators=['*']): array
{
    if($bill<=0 || !in_array($unit,[1,2,3],true) || (unit_active_id()!==0 && unit_active_id()!==$unit))throw new OutOfBoundsException('Tagihan tidak ditemukan pada unit ini.');
    if(!in_array($view,['active','deleted'],true))throw new InvalidArgumentException('Jenis riwayat tidak valid.');
    if($view==='deleted') {
        $matches=array_values(array_filter(registration_archive_groups($db,$operators),static fn($g)=>(int)$g['tagihan_id']===$bill && (int)$g['unit_id']===$unit));
        if(count($matches)!==1)throw new OutOfBoundsException('Arsip tidak ditemukan atau identitasnya tidak lengkap.');$g=$matches[0];
    } else {
        $f=['q'=>'','student_id'=>0,'values'=>['kelas'=>['*'],'tahun_ajaran'=>['*'],'status'=>['*'],'operator'=>$operators]];
        [$sql,$params,$types]=registration_active_sql($f,$db);
        $rows=registration_query($db,"SELECT * FROM ($sql) x WHERE tagihan_id=? AND unit_id=?",[$bill,$unit],'ii');
        if(!$rows)throw new OutOfBoundsException('Tagihan tidak ditemukan pada unit ini.');$g=$rows[0];
        $ownerWhere=history_operator_where($db,$operators,'b.id','b.unit_id');
        $rows=registration_query($db,'SELECT b.*,s.NAMA,s.NO_induk_diknas,bd.jumlah FROM bayar_du bd JOIN bayar b ON b.id=bd.bayar_id AND b.unit_id=bd.unit_id LEFT JOIN siswa s ON s.NO_INDUK=b.NO_INDUK AND s.unit_id=b.unit_id WHERE bd.tagihan_daftar_ulang_id=? AND bd.unit_id=?'.$ownerWhere.' ORDER BY b.TGL_BYR DESC,b.id DESC',[$bill,$unit],'ii');
        $g['transactions']=array_map(static fn($p)=>['payment'=>$p,'jumlah'=>(float)$p['jumlah']],$rows);
    }
    $events=payment_activity_for_payments($db,array_map(static fn($t)=>(int)$t['payment']['id'],$g['transactions']));
    foreach($g['transactions'] as &$t) {
        $p=$t['payment'];$t['events']=$events[(int)$p['id']]??[];$t['summary']=payment_activity_summary($t['events']);
        $t['capabilities']=payment_capabilities($db,$p,$t['events'],$view==='deleted');
    }unset($t);$g['view']=$view;$g['operator_filtered']=!filter_is_all($operators);return $g;
}

function registration_render_detail(array $g,array $query): void
{
    $deleted=$g['view']==='deleted';$e='authorization_escape';
    ?>
    <div class="ph-student"><span class="ph-student-icon"><?= authorization_icon('student') ?></span><div><h3><?= $e($g['nama']) ?></h3><small>NIS: <?= $e($g['no_induk']) ?></small><small>NIS Diknas: <?= $e($g['NO_induk_diknas']?:'Tidak tercatat') ?></small><?php if(unit_all_readonly()): ?><small>Unit <?= unit_label((int)$g['unit_id']) ?></small><?php endif; ?></div><div class="ph-student-period"><span class="kelas-badge">Kelas <?= $e($g['kelas']) ?></span><small><?= $e($g['th_ajaran']) ?></small></div></div>
    <section class="ph-payment-box"><h4><?= authorization_icon('chart') ?> <?= $deleted?'Daftar Ulang Dihapus':'Rekap Tagihan' ?></h4><dl class="ph-components">
    <?php foreach($deleted?['Nominal Daftar Ulang dihapus'=>$g['deleted_amount']]:['Tagihan'=>$g['total'],'Sudah dibayar'=>$g['paid'],'Sisa'=>$g['remaining']] as $label=>$amount): ?><div><dt><?= $label ?></dt><dd><?= payment_history_money($amount) ?></dd></div><?php endforeach; ?></dl></section>
    <?php if(!$deleted && !empty($g['operator_filtered'])): ?><p class="du-summary-caption">Saldo tagihan menghitung pembayaran semua operator. Cicilan di bawah hanya dari operator terpilih.</p><section class="du-operator-summary"><small>Pembayaran operator terpilih</small><strong><?= payment_history_money($g['operator_paid']) ?></strong></section><?php endif; ?>
    <h3 class="du-installment-title"><?= $deleted?'Transaksi Dihapus':'Rincian Cicilan' ?> <small><?= count($g['transactions']) ?> transaksi</small></h3>
    <?php if(!$g['transactions']): ?><p class="ph-empty">Belum ada pembayaran untuk tagihan ini.</p><?php endif; ?>
    <?php foreach($g['transactions'] as $t): $p=$t['payment'];$id=(int)$p['id'];$caps=$t['capabilities'];$token=(!$deleted && ($caps['can_edit']||$caps['can_delete']))?payment_return_create($id,(int)$g['unit_id'],$query):''; ?>
    <article class="du-installment" data-du-transaction="<?= $id ?>"><header><strong>TRX-<?= str_pad((string)$id,6,'0',STR_PAD_LEFT) ?></strong><span class="ph-status <?= $deleted?'is-deleted':'' ?>"><?= $deleted?'Dihapus':'Aktif' ?></span></header>
    <dl class="ph-components"><div><dt>Daftar Ulang · <?= $e($g['th_ajaran']) ?></dt><dd><?= payment_history_money($t['jumlah']) ?></dd></div><div><dt>Total transaksi<?= abs((float)$p['total_jumlah']-$t['jumlah'])>.001?' (semua komponen)':'' ?></dt><dd><?= payment_history_money($p['total_jumlah']) ?></dd></div></dl>
    <p class="du-transaction-meta">Tanggal bayar: <?= $e(spp_date_label($p['TGL_BYR'],true)) ?><br>Metode: <?= $e($p['sistem_pembayaran']??'Tidak tercatat') ?></p>
    <div class="ph-info-grid"><section><h4><?= authorization_icon('user') ?> Pembuat awal</h4><?php payment_history_actor($t['summary']['creator']); ?></section><section><h4><?= authorization_icon('history') ?> Aktivitas terakhir</h4><?php payment_history_actor($t['summary']['last']); ?><small><?= $e(payment_activity_labels()[$t['summary']['last']['action']??'']??'Tidak tercatat') ?></small><small><?= !empty($t['summary']['last']['occurred_at'])?$e(spp_date_label($t['summary']['last']['occurred_at'],true)):'Tidak tercatat' ?></small></section></div>
    <?php if($deleted): ?><p class="du-transaction-meta">Dihapus: <?= $e(spp_date_label($p['_deleted_event']['occurred_at'],true)) ?> · <?= $e($p['_deleted_event']['actor_name']?:'Tidak tercatat') ?></p><?php endif; ?>
    <?php if($caps['reason']): ?><p class="ph-lock-note" <?= $caps['locked']?'data-payment-locked':'' ?>><?= authorization_icon($caps['locked']?'lock':'alert') ?> <?= $e($caps['reason']) ?></p><?php endif; ?>
    <div class="ph-detail-actions">
    <?php if($caps['can_edit']): ?><a class="btn-tbl btn-tbl-edit" href="<?= $e(payment_return_edit_url($token,$id)) ?>"><?= authorization_icon('edit') ?> <?= transaction_authorization_requires_request()?'Ajukan Edit':'Edit' ?></a><?php endif; ?>
    <?php if($caps['can_delete']): ?><button type="button" class="btn-tbl btn-tbl-del open-du-delete" data-id="<?= $id ?>" data-context="<?= $e($token) ?>" data-student="<?= $e($g['nama']) ?>"><?= authorization_icon('delete') ?> <?= transaction_authorization_requires_request()?'Ajukan Hapus':'Hapus' ?></button><?php endif; ?>
    <?php if($caps['can_print']): ?><a class="btn-tbl btn-tbl-print" href="../laporan/cetak_struk.php?id=<?= $id ?>" target="_blank" rel="noopener"><?= authorization_icon('pdf') ?> Cetak</a><?php endif; ?>
    <button type="button" class="btn-tbl open-payment-activity" data-id="<?= $id ?>" data-unit="<?= (int)$g['unit_id'] ?>"><?= authorization_icon('history') ?> Riwayat Aktivitas</button></div>
    <noscript><details><summary>Riwayat Aktivitas</summary><?php foreach($t['events'] as $event): ?><p class="du-transaction-meta"><?= $e(payment_activity_labels()[$event['action']]??$event['action']) ?> · <?= $e($event['actor_name']?:'Tidak tercatat') ?> · <?= $e(spp_date_label($event['occurred_at'],true)) ?><br><?= $e($event['note']??'') ?></p><?php endforeach; ?></details>
    <?php if($caps['can_delete']): ?><details><summary><?= transaction_authorization_requires_request()?'Ajukan Hapus':'Hapus Transaksi' ?></summary><p>Seluruh komponen transaksi ini akan dihapus<?= transaction_authorization_requires_request()?' setelah disetujui Super Admin':'' ?>. Jurnal tetap tersimpan.</p><form method="post" action="proses.php"><input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="return_context" value="<?= $e($token) ?>"><input type="hidden" name="csrf_token" value="<?= $e($_SESSION['csrf_payment']) ?>"><?php if(transaction_authorization_requires_request()): ?><label>Alasan <textarea class="field-input" name="authorization_reason" minlength="5" maxlength="500" required></textarea></label><?php endif; ?><button class="btn btn-danger" type="submit">Konfirmasi penghapusan</button></form></details><?php endif; ?></noscript>
    </article><?php endforeach;
}
