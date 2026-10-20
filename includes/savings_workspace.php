<?php
require_once __DIR__.'/account_letter_ui.php';
require_once __DIR__.'/legacy_full_read.php';
require_once __DIR__.'/migrated_history.php';

function savings_e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function savings_money($value): string { $v=round((float)$value,2);return 'Rp '.number_format($v,$v===floor($v)?0:2,',','.'); }
function savings_activity_labels(): array {
    return ['created'=>'Tabungan dicatat','baseline'=>'Data awal tabungan','edited'=>'Tabungan dikoreksi',
        'deleted'=>'Tabungan dihapus','request_edit'=>'Pengajuan koreksi tabungan','request_delete'=>'Pengajuan hapus tabungan',
        'approved'=>'Pengajuan disetujui','rejected'=>'Pengajuan ditolak','cancelled'=>'Pengajuan dibatalkan','failed'=>'Otorisasi gagal'];
}
function savings_icon(string $name): string {
    $paths=['up'=>'M12 20V4m-6 6 6-6 6 6','down'=>'M12 4v16m-6-6 6 6 6-6','wallet'=>'M3 6h17v15H3V6Zm0 0V3h14v3M16 11h5v5h-5v-5','calendar'=>'M4 5h16v16H4V5ZM8 3v4M16 3v4M4 10h16','check'=>'m5 12 4 4 10-10'];
    if (!isset($paths[$name])) return account_letter_icon($name);
    return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="'.$paths[$name].'"/></svg>';
}

/** Only numeric stored IDs or matching financial-request evidence establish ownership. */
function savings_enrich_rows(mysqli $db, array $rows): array {
    if (!$rows) return [];
    $ids=[];$actors=[];
    foreach ($rows as $row) {
        $ids[(int)$row['unit_id'].'|'.$row['jenis']][]=(int)$row['id'];if(($row['origin']??'')==='new'&&ctype_digit((string)($row['operator_key']??'')))$actors[]=(int)$row['operator_key'];
        if (preg_match('/^[1-9][0-9]*$/D',(string)$row['user_id'])) $actors[]=(int)$row['user_id'];
    }
    $evidence=[];
    foreach ($ids as $group=>$groupIds) {
        [$unit,$kind]=explode('|',$group);$action='tabungan_'.$kind;
        $sql='SELECT referensi_id,COUNT(DISTINCT operator_id) n,MIN(operator_id) actor FROM keuangan_request WHERE unit_id=? AND aksi=? AND referensi_id IN ('.implode(',',array_unique($groupIds)).') GROUP BY referensi_id';
        $s=$db->prepare($sql);$s->bind_param('is',$unit,$action);$s->execute();
        foreach ($s->get_result()->fetch_all(MYSQLI_ASSOC) as $request) {
            $evidence[$group.'|'.$request['referensi_id']]=$request;
            if ((int)$request['n']===1) $actors[]=(int)$request['actor'];
        }$s->close();
    }
    $accounts=[];$actors=array_unique(array_filter($actors));
    if ($actors) foreach ($db->query('SELECT id,nama,username,role FROM admin WHERE id IN ('.implode(',',$actors).')')->fetch_all(MYSQLI_ASSOC) as $account) $accounts[(int)$account['id']]=$account;
    $historical=[];
    if($actors&&function_exists('savings_authorization_ready')&&savings_authorization_ready($db))foreach($db->query("SELECT actor_id,actor_name nama,actor_username username,actor_role role FROM tabungan_aktivitas WHERE action IN ('created','baseline') AND actor_id IN (".implode(',',$actors).") ORDER BY occurred_at,id")->fetch_all(MYSQLI_ASSOC) as $a)$historical[(int)$a['actor_id']]??=$a;
    $roles=['super_admin'=>'Super Admin','admin'=>'Admin','kasir'=>'Kasir','bendahara'=>'Bendahara'];
    foreach ($rows as &$row) {
        $row['book_url']=savings_book_url($row);
        $proof=$evidence[(int)$row['unit_id'].'|'.$row['jenis'].'|'.$row['id']]??null;
        $owner=$proof ? ((int)$proof['n']===1?(int)$proof['actor']:0) : (preg_match('/^[1-9][0-9]*$/D',(string)$row['user_id'])?(int)$row['user_id']:0);
        $account=$accounts[$owner]??$historical[$owner]??null;
        if((int)$row['id']<0){$newActor=($row['origin']??'')==='new'?($accounts[(int)($row['operator_key']??0)]??null):null;$row['owner_id']=$newActor?(int)$newActor['id']:0;$row['operator_name']=$newActor['nama']??$row['user_id'];$row['operator_username']='';$row['operator_role']=($row['origin']??'')==='new'?'SistemSPP':'Legacy';$row['can_print']=true;$row['reference']=($row['origin']??'')==='new'?'TB-'.($row['jenis']==='masuk'?'M':'K').'-'.$row['unit_id'].'-L'.(-1000000000-(int)$row['id']):'Legacy / '.abs((int)$row['id']);$row['receipt_url']='cetak_struk.php?'.http_build_query(['id'=>$row['id'],'jenis'=>$row['jenis'],'unit_id'=>$row['unit_id']]);continue;}
        $row['owner_id']=$owner;
        $row['operator_name']=$account['nama']??($owner>0?'Operator #'.$owner:'Tidak tercatat');
        $row['operator_username']=$account['username']??'';
        $row['operator_role']=$roles[$account['role']??'']??'';
        $row['can_print']=empty($row['deleted']) && (operational_role() || ($owner>0 && $owner===(int)($_SESSION['admin_id']??0)));
        $row['reference']='TB-'.($row['jenis']==='masuk'?'M':'K').'-'.$row['unit_id'].'-'.str_pad((string)$row['id'],6,'0',STR_PAD_LEFT);
        $row['receipt_url']='cetak_struk.php?'.http_build_query(['jenis'=>$row['jenis'],'id'=>(int)$row['id']]);
    }unset($row);
    return $rows;
}

/** Book identity belongs to the account, never to an individual journal/receipt. */
function savings_book_url(array $row): string {
    $identity=!empty($row['legacy_owner_id'])&&empty($row['student_id'])
        ? ['owner_id'=>(int)$row['legacy_owner_id']]
        : ['nis'=>$row['NO_INDUK'],'student_id'=>(int)($row['student_id']??0)];
    return 'cetak_buku.php?'.http_build_query($identity);
}

function savings_transaction(mysqli $db, string $kind, int $id): ?array {
    if (!in_array($kind,['masuk','keluar'],true) || $id===0) throw new InvalidArgumentException('Identitas transaksi tabungan tidak valid.');
    $table=mh_savings_source($db,true,['id'=>$id,'kind'=>$kind]);
    $s=$db->prepare("SELECT * FROM $table t WHERE t.id=? AND t.jenis=?");
    $s->bind_param('is',$id,$kind);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
    if (!$row) {
        require_once __DIR__.'/savings_authorization.php';
        if(!savings_authorization_ready($db))return null;
        $s=$db->prepare("SELECT before_snapshot FROM tabungan_aktivitas WHERE jenis=? AND transaction_id=? AND action='deleted' ORDER BY id DESC LIMIT 1");$s->bind_param('si',$kind,$id);$s->execute();$audit=$s->get_result()->fetch_assoc();$s->close();
        $row=$audit?(json_decode($audit['before_snapshot'],true)['transaction']??null):null;if(!$row)return null;
        $row['nominal']=$row['MASUK'];$row['keluar']=$row['KELUAR'];$row['deleted']=true;
        $s=$db->prepare('SELECT id FROM siswa WHERE NO_INDUK=? AND unit_id=?');$s->bind_param('si',$row['NO_INDUK'],$row['unit_id']);$s->execute();$row['student_id']=$s->get_result()->fetch_row()[0]??0;$s->close();
    }
    require_once __DIR__.'/kelas.php';
    $row['kelas_label']=class_label($row);
    $row['jenis']=$kind;
    return savings_enrich_rows($db,[$row])[0];
}

function savings_detail_html(array $row): string {
    if(empty($row['legacy_owner_id'])&&empty($row['student_id'])&&isset($GLOBALS['koneksi'])){$s=$GLOBALS['koneksi']->prepare('SELECT id FROM siswa WHERE NO_INDUK=? AND unit_id=?');$s->bind_param('si',$row['NO_INDUK'],$row['unit_id']);$s->execute();$row['student_id']=$s->get_result()->fetch_row()[0]??0;$s->close();}
    $incoming=$row['jenis']==='masuk';
    ob_start(); ?>
    <header class="sw-card-head"><div><?= savings_icon('document') ?><div><h3>Detail Transaksi</h3><small>Informasi lengkap transaksi tabungan.</small></div></div><span class="sw-status <?= $incoming?'is-in':'is-out' ?>"><?= savings_icon($incoming?'up':'down') ?> <?= ($row['origin']??'')==='adjustment'?'Penyesuaian Saldo Migrasi':'Tabungan '.($incoming?'Masuk':'Keluar') ?></span></header>
    <div class="sw-detail-body">
      <div class="sw-identity"><div><small>Nomor Induk</small><strong class="sw-accent"><?= savings_e($row['NO_INDUK']) ?></strong></div><div><small><?= empty($row['student_id'])?'Pemilik rekening':'Nama Siswa' ?></small><strong><?= savings_e($row['NAMA']??'Tidak tercatat') ?></strong></div><div><small>NIS Diknas</small><span><?= savings_e($row['NO_induk_diknas']??'—') ?></span></div><div><small>Kelas / Unit</small><span class="sw-class"><?= savings_e($row['kelas_label']??$row['KELAS']??'—') ?> · <?= savings_e(unit_label((int)$row['unit_id'])) ?></span></div></div>
      <dl class="sw-detail-grid"><div><dt><?= savings_icon('calendar') ?>Tanggal Transaksi</dt><dd><?= savings_e(spp_date_label($row['TANGGAL'],true)) ?></dd></div><div><dt><?= savings_icon('user') ?>Operator</dt><dd><?= savings_e($row['operator_name']) ?><?php if($row['operator_username']): ?><small>@<?= savings_e($row['operator_username']) ?> · <?= savings_e($row['operator_role']) ?></small><?php endif; ?></dd></div><div><dt><?= savings_icon('up') ?>Jumlah Masuk</dt><dd class="sw-accent"><?= savings_money($row['nominal']) ?></dd></div><div><dt><?= savings_icon('down') ?>Jumlah Keluar</dt><dd class="sw-out"><?= savings_money($row['keluar']) ?></dd></div><div><dt><?= savings_icon('document') ?>Referensi Transaksi</dt><dd><?= savings_e($row['reference']) ?></dd></div><div><dt><?= savings_icon('check') ?>Status</dt><dd><?= !empty($row['deleted'])?'Arsip (dihapus)':'Tersimpan' ?></dd></div></dl>
      <?php if(($row['origin']??'')==='adjustment'): ?><p class="sw-note">Penyesuaian Saldo Migrasi: <?= savings_money($row['adjustment_delta']) ?>. Bukan setoran atau penarikan kas.</p><?php endif; ?><div class="sw-note"><strong>Keterangan</strong><p><?= nl2br(savings_e($row['keterangan']?:'—')) ?></p></div>
      <div class="sw-detail-print-actions"><?php if ($row['can_print']): ?><a class="btn btn-primary" href="<?= savings_e($row['receipt_url']) ?>" target="_blank" rel="noopener"><?= savings_icon('print') ?>Cetak Bukti Transaksi</a><?php elseif(!empty($row['deleted'])): ?><p class="sw-lock">Transaksi diarsipkan. Bukti transaksi aktif tidak tersedia.</p><?php else: ?><p class="sw-lock"><?= savings_icon('lock') ?>Cetak terkunci. <?= savings_e($row['owner_id']?'Transaksi milik '.$row['operator_name'].'.':'Pemilik tidak tercatat.') ?> Anda tetap dapat melihat detailnya.</p><?php endif; ?>
      <a class="btn btn-ghost" href="<?= savings_e(savings_book_url($row)) ?>" target="_blank" rel="noopener"><?= savings_icon('document') ?>Cetak Buku Tabungan</a></div>
      <?php if((int)$row['id']<-1000000000){if(unit_is_super()&&!unit_all_readonly()){$jid=-1000000000-(int)$row['id'];$return=mh_return_create('tabungan/riwayat.php',array_replace($_GET,['id'=>$row['id'],'jenis'=>$row['jenis']]),(int)$row['unit_id'],'journal',$jid);echo '<a class="btn btn-ghost" href="../legacy_correction.php?kind=journal&amp;id='.$jid.'&amp;hr='.$return.'">Koreksi Jurnal Legacy</a>';};}elseif((int)$row['id']>0&&is_file(__DIR__.'/savings_correction_ui.php')){require_once __DIR__.'/savings_correction_ui.php';savings_correction_controls($GLOBALS['koneksi'],$row);} ?>
    </div>
    <?php return (string)ob_get_clean();
}

function savings_http_identity(): array {
    $kind=$_GET['jenis']??'';$id=$_GET['id']??'';
    if (!is_string($kind) || !in_array($kind,['masuk','keluar'],true) || !is_string($id) || !preg_match('/^-?[1-9][0-9]{0,17}$/D',$id)) throw new InvalidArgumentException('Identitas transaksi tabungan tidak valid.');
    return [$kind,(int)$id];
}
