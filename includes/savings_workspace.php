<?php
require_once __DIR__.'/account_letter_ui.php';

function savings_e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function savings_money($value): string { return 'Rp '.number_format((float)$value, 0, ',', '.'); }
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
        $ids[(int)$row['unit_id'].'|'.$row['jenis']][]=(int)$row['id'];
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
    $roles=['super_admin'=>'Super Admin','admin'=>'Admin','kasir'=>'Kasir','bendahara'=>'Bendahara'];
    foreach ($rows as &$row) {
        $proof=$evidence[(int)$row['unit_id'].'|'.$row['jenis'].'|'.$row['id']]??null;
        $owner=$proof ? ((int)$proof['n']===1?(int)$proof['actor']:0) : (preg_match('/^[1-9][0-9]*$/D',(string)$row['user_id'])?(int)$row['user_id']:0);
        $account=$accounts[$owner]??null;
        $row['owner_id']=$owner;
        $row['operator_name']=$account['nama']??'Tidak tercatat';
        $row['operator_username']=$account['username']??'';
        $row['operator_role']=$roles[$account['role']??'']??'';
        $row['can_print']=unit_is_super() || ($owner>0 && $owner===(int)($_SESSION['admin_id']??0));
        $row['reference']='TB-'.($row['jenis']==='masuk'?'M':'K').'-'.$row['unit_id'].'-'.str_pad((string)$row['id'],6,'0',STR_PAD_LEFT);
        $row['receipt_url']='cetak_struk.php?'.http_build_query(['jenis'=>$row['jenis'],'id'=>(int)$row['id']]);
    }unset($row);
    return $rows;
}

function savings_transaction(mysqli $db, string $kind, int $id): ?array {
    if (!in_array($kind,['masuk','keluar'],true) || $id<1) throw new InvalidArgumentException('Identitas transaksi tabungan tidak valid.');
    $table=$kind==='masuk'?'transaksi_m':'transaksi_k';
    $s=$db->prepare("SELECT t.id,t.unit_id,t.NO_INDUK,t.TANGGAL,t.MASUK nominal,t.KELUAR keluar,t.user_id,t.keterangan,s.NAMA,s.KELAS,s.NO_induk_diknas FROM $table t LEFT JOIN siswa s ON s.NO_INDUK=t.NO_INDUK AND s.unit_id=t.unit_id WHERE t.id=?");
    $s->bind_param('i',$id);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
    if (!$row) return null;
    $row['jenis']=$kind;
    return savings_enrich_rows($db,[$row])[0];
}

function savings_detail_html(array $row): string {
    $incoming=$row['jenis']==='masuk';
    ob_start(); ?>
    <header class="sw-card-head"><div><?= savings_icon('document') ?><div><h3>Detail Transaksi</h3><small>Informasi lengkap transaksi tabungan.</small></div></div><span class="sw-status <?= $incoming?'is-in':'is-out' ?>"><?= savings_icon($incoming?'up':'down') ?> Tabungan <?= $incoming?'Masuk':'Keluar' ?></span></header>
    <div class="sw-detail-body">
      <div class="sw-identity"><div><small>Nomor Induk</small><strong class="sw-accent"><?= savings_e($row['NO_INDUK']) ?></strong></div><div><small>Nama Siswa</small><strong><?= savings_e($row['NAMA']??'Tidak tercatat') ?></strong></div><div><small>NIS Diknas</small><span><?= savings_e($row['NO_induk_diknas']??'—') ?></span></div><div><small>Kelas / Unit</small><span class="sw-class"><?= savings_e($row['KELAS']??'—') ?> · <?= savings_e(unit_label((int)$row['unit_id'])) ?></span></div></div>
      <dl class="sw-detail-grid"><div><dt><?= savings_icon('calendar') ?>Tanggal Transaksi</dt><dd><?= savings_e(spp_date_label($row['TANGGAL'],true)) ?></dd></div><div><dt><?= savings_icon('user') ?>Operator</dt><dd><?= savings_e($row['operator_name']) ?><?php if($row['operator_username']): ?><small>@<?= savings_e($row['operator_username']) ?> · <?= savings_e($row['operator_role']) ?></small><?php endif; ?></dd></div><div><dt><?= savings_icon('up') ?>Jumlah Masuk</dt><dd class="sw-accent"><?= savings_money($row['nominal']) ?></dd></div><div><dt><?= savings_icon('down') ?>Jumlah Keluar</dt><dd class="sw-out"><?= savings_money($row['keluar']) ?></dd></div><div><dt><?= savings_icon('document') ?>Referensi Transaksi</dt><dd><?= savings_e($row['reference']) ?></dd></div><div><dt><?= savings_icon('check') ?>Status</dt><dd>Tersimpan</dd></div></dl>
      <div class="sw-note"><strong>Keterangan</strong><p><?= nl2br(savings_e($row['keterangan']?:'—')) ?></p></div>
      <?php if ($row['can_print']): ?><a class="btn btn-primary" href="<?= savings_e($row['receipt_url']) ?>" target="_blank" rel="noopener"><?= savings_icon('print') ?>Cetak Buku Tabungan</a><?php else: ?><p class="sw-lock"><?= savings_icon('lock') ?>Cetak terkunci. <?= savings_e($row['owner_id']?'Transaksi milik '.$row['operator_name'].'.':'Pemilik tidak tercatat.') ?> Anda tetap dapat melihat detailnya.</p><?php endif; ?>
    </div>
    <?php return (string)ob_get_clean();
}

function savings_http_identity(): array {
    $kind=$_GET['jenis']??'';$id=$_GET['id']??'';
    if (!is_string($kind) || !in_array($kind,['masuk','keluar'],true) || !is_string($id) || !preg_match('/^[1-9][0-9]{0,17}$/D',$id)) throw new InvalidArgumentException('Identitas transaksi tabungan tidak valid.');
    return [$kind,(int)$id];
}
