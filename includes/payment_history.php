<?php
require_once __DIR__.'/payment_permissions.php';
require_once __DIR__.'/payment_return.php';
require_once __DIR__.'/authorization_presentation.php';

function payment_history_money($value): string { return 'Rp '.number_format((float)$value,0,',','.'); }
function payment_history_period(array $p): string
{
    $months=['Januari'=>'01','Februari'=>'02','Maret'=>'03','April'=>'04','Mei'=>'05','Juni'=>'06','Juli'=>'07','Agustus'=>'08','September'=>'09','Oktober'=>'10','November'=>'11','Desember'=>'12'];
    $raw=(string)($p['BULAN']??'');
    return ($months[$raw]??str_pad($raw,2,'0',STR_PAD_LEFT)).' '.($p['TAHUN']??'Tidak tercatat');
}
function payment_history_model(mysqli $db,int $id,bool $deleted): array
{
    if($id<=0) throw new OutOfBoundsException('Transaksi tidak ditemukan pada unit ini.');
    $details=[];
    if($deleted) {
        $s=$db->prepare("SELECT * FROM pembayaran_aktivitas WHERE payment_id=? AND action='deleted' ORDER BY occurred_at DESC,id DESC LIMIT 1");
        $s->bind_param('i',$id);$s->execute();$event=$s->get_result()->fetch_assoc();$s->close();
        $snap=$event?json_decode($event['before_snapshot'],true):null;$p=$snap['payment']??null;
        if(!$p) throw new OutOfBoundsException('Arsip tidak ditemukan pada unit ini.');
        $p['id']=$id;$p['unit_id']=(int)$event['unit_id'];$p['_deleted_event']=$event;$details=$snap['details']??[];
    } else {
        $s=$db->prepare("SELECT b.*,s.NAMA,s.NO_induk_diknas,COALESCE(NULLIF(b.kelas_rombel_snapshot,''),NULLIF(b.KELAS,''),s.KELAS) kelas_transaksi FROM bayar b LEFT JOIN siswa s ON s.NO_INDUK=b.NO_INDUK AND s.unit_id=b.unit_id WHERE b.id=?");
        $s->bind_param('i',$id);$s->execute();$p=$s->get_result()->fetch_assoc();$s->close();
        if(!$p) throw new OutOfBoundsException('Transaksi tidak ditemukan pada unit ini.');
        foreach(['bayar_du','bayar_biaya_lain'] as $table) {
            $s=$db->prepare('SELECT * FROM '.$table.' WHERE bayar_id=? ORDER BY id');$s->bind_param('i',$id);$s->execute();$details[$table]=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        }
    }
    $p['kelas_transaksi']=$p['kelas_transaksi']??(($p['kelas_rombel_snapshot']??'')?:($p['KELAS']??'Tidak tercatat'));
    $events=authorization_history_events($db,$id);
    $caps=payment_capabilities($db,$p,$events,$deleted);
    $du=array_sum(array_column($details['bayar_du']??[],'jumlah'));
    $components=[['Uang PSB',(float)($p['U_PSB']??0)],['SPP',(float)($p['U_SPP']??0)],['Komite',(float)($p['U_KOMITE']??0)]];
    foreach($details['bayar_du']??[] as $d) $components[]=['Daftar Ulang'.(!empty($d['th_ajaran'])?' · '.$d['th_ajaran']:''),(float)$d['jumlah']];
    foreach($details['bayar_biaya_lain']??[] as $d) $components[]=[($d['nama_biaya_snapshot']??'')?:'Biaya Lain',(float)($d['nominal_snapshot']??$d['jumlah']??0)];
    if(empty($details['bayar_biaya_lain']) && (float)($p['U_LAIN']??0)-$du>0) $components[]=['Daftar Ulang / Biaya Lain (rincian tidak tercatat)',(float)$p['U_LAIN']-$du];
    if((float)($p['potong_spp']??0)!==0) $components[]=['Potongan SPP',-(float)$p['potong_spp']];
    return ['payment'=>$p,'deleted'=>$deleted,'events'=>$events,'summary'=>payment_activity_summary($events),'capabilities'=>$caps,
        'components'=>$components,'batch_print'=>$caps['can_print']&&(int)($p['payment_batch_count']??1)===12&&payment_can_print_batch($db,(string)($p['payment_batch_token']??''))];
}
function payment_history_actor(?array $event): void
{
    echo '<strong>'.authorization_escape(($event['actor_name']??'')?:'Tidak tercatat').'</strong><small>'.authorization_escape(!empty($event['actor_username'])?'@'.$event['actor_username']:'Username tidak tercatat').'</small><small>'.authorization_escape(authorization_role_label($event['actor_role']??'')).'</small>';
}
function payment_history_render(array $model, ?array $query=null): void
{
    $p=$model['payment'];$caps=$model['capabilities'];$deleted=$model['deleted'];$summary=$model['summary'];$id=(int)$p['id'];
    $token=$query!==null && !$deleted && ($caps['can_edit']||$caps['can_delete'])?payment_return_create($id,(int)$p['unit_id'],$query,'lihat.php'):'';
    ?>
    <div class="ph-student"><span class="ph-student-icon"><?= authorization_icon('student') ?></span><div><h3><?= authorization_escape($p['NAMA']??'Tidak tercatat') ?></h3><small>NIS: <?= authorization_escape($p['NO_INDUK']) ?></small><small>NIS Diknas: <?= authorization_escape($p['NO_induk_diknas']??'Tidak tercatat') ?></small><?php if(unit_all_readonly()): ?><small>Unit <?= authorization_escape(unit_label((int)$p['unit_id'])) ?></small><?php endif; ?></div><div class="ph-student-period"><span class="kelas-badge">Kelas <?= authorization_escape($p['kelas_transaksi']) ?></span><small><?= authorization_icon('calendar') ?> <?= authorization_escape(payment_history_period($p)) ?></small></div></div>
    <section class="ph-payment-box"><h4><?= authorization_icon('chart') ?> Detail Pembayaran <span class="ph-status <?= $deleted?'is-deleted':'' ?>"><?= $deleted?'Dihapus':'Aktif' ?></span></h4><div class="ph-payment-overview"><div><small>Total Bayar</small><strong class="ph-total"><?= payment_history_money($p['total_jumlah']) ?></strong></div><div><small>Metode: <?= authorization_escape($p['sistem_pembayaran']??'Tidak tercatat') ?></small><small>Tanggal bayar: <?= authorization_escape(spp_date_label($p['TGL_BYR'],true)) ?></small></div></div><dl class="ph-components"><?php foreach($model['components'] as [$label,$amount]): if(abs($amount)<.001)continue; ?><div><dt><?= authorization_escape($label) ?></dt><dd><?= payment_history_money($amount) ?></dd></div><?php endforeach; ?></dl><?php if(!empty($p['KETERANGAN'])): ?><p class="ph-note"><?= nl2br(authorization_escape($p['KETERANGAN'])) ?></p><?php endif; ?></section>
    <div class="ph-info-grid"><section><h4><?= authorization_icon('user') ?> Operator</h4><small>Dibuat oleh</small><?php payment_history_actor($summary['creator']); ?><small class="ph-info-label">Aktivitas terakhir</small><?php payment_history_actor($summary['last']); ?><small><?= authorization_escape(payment_activity_labels()[$summary['last']['action']??'']??'Tidak tercatat') ?></small><small><?= !empty($summary['last']['occurred_at'])?authorization_escape(spp_date_label($summary['last']['occurred_at'],true)):'Tidak tercatat' ?></small></section><section><h4><?= authorization_icon('history') ?> Informasi Sistem</h4><small>ID Transaksi</small><strong>TRX-<?= str_pad((string)$id,6,'0',STR_PAD_LEFT) ?></strong><small class="ph-info-label">Dibuat pada</small><span><?= authorization_escape(!empty($p['created_at'])?spp_date_label($p['created_at'],true):'Tidak tercatat') ?></span><small class="ph-info-label">Diubah pada</small><span><?= authorization_escape(!empty($p['updated_at'])?spp_date_label($p['updated_at'],true):'Tidak tercatat') ?></span><?php if($deleted): ?><small class="ph-info-label">Dihapus oleh</small><?php payment_history_actor($p['_deleted_event']); ?><small><?= authorization_escape(spp_date_label($p['_deleted_event']['occurred_at'],true)) ?></small><?php endif; ?></section></div>
    <?php if($caps['reason']): ?><p class="ph-lock-note" <?= $caps['locked']?'data-payment-locked':'' ?>><?= authorization_icon($caps['locked']?'lock':'alert') ?> <?= authorization_escape($caps['reason']) ?></p><?php endif; ?>
    <div class="ph-detail-actions">
    <?php if($caps['can_edit']): ?><a class="btn-tbl btn-tbl-edit" href="<?= authorization_escape(payment_return_edit_url($token,$id)) ?>"><?= authorization_icon('edit') ?> <?= transaction_authorization_requires_request()?'Ajukan Edit':'Edit' ?></a><?php endif; ?>
    <?php if($caps['can_delete']): ?><button type="button" class="btn-tbl btn-tbl-del open-payment-delete-request" data-id="<?= $id ?>" data-context="<?= authorization_escape($token) ?>" data-student="<?= authorization_escape($p['NAMA']??$p['NO_INDUK']) ?>"><?= authorization_icon('delete') ?> <?= transaction_authorization_requires_request()?'Ajukan Hapus':'Hapus' ?></button><?php endif; ?>
    <?php if($caps['can_print']): ?><a class="btn-tbl btn-tbl-print" data-print-payment href="../laporan/cetak_struk.php?id=<?= $id ?>" target="_blank" rel="noopener"><?= authorization_icon('pdf') ?> Cetak</a><?php endif; ?>
    <?php if($model['batch_print']): ?><a class="btn-tbl btn-tbl-print" href="../laporan/cetak_struk_tahunan.php?batch=<?= authorization_escape($p['payment_batch_token']) ?>" target="_blank" rel="noopener">12 Struk</a><?php endif; ?>
    <button type="button" class="btn-tbl open-payment-activity" data-id="<?= $id ?>" data-unit="<?= (int)$p['unit_id'] ?>"><?= authorization_icon('history') ?> Riwayat Aktivitas</button></div>
    <?php
}
