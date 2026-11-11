<?php
require_once __DIR__.'/registration_combined.php';
require_once __DIR__.'/payment_history.php';
require_once __DIR__.'/payment_return.php';

function rc_status_class(string $status): string {
    return match($status){'Lunas'=>'settled','Belum Lunas'=>'outstanding','Tercakup PSB'=>'covered',default=>'unknown'};
}

function rc_detail_model(mysqli $db,array $group,string $source): array {
    $receipts=rc_receipts($db,$group,$source);$capabilities=[];
    $requests=[];$isOut=false;
    if(!unit_all_readonly()){
        require_once __DIR__.'/legacy_transactions.php';require_once __DIR__.'/student_out.php';
        $identity=$group['student_id']?hp_one($db,'SELECT NO_INDUK FROM siswa WHERE id=? AND unit_id=?',[$group['student_id'],$group['unit_id']]):null;
        $nis=(string)($identity['NO_INDUK']??$group['identity']);$isOut=!empty(sout_student($db,$nis,false,(int)$group['unit_id'])['is_out']);
        $ids=[];foreach($receipts as $r)if($r['id']<-1000000000)$ids[]=-$r['id']-1000000000;
        if($ids&&lt_schema_ready($db))foreach(hp_rows($db,'SELECT legacy_payment_id,status FROM transaksi_otorisasi WHERE unit_id=? AND legacy_payment_id IN ('.implode(',',array_map('intval',$ids)).') ORDER BY id DESC',[$group['unit_id']]) as $request)$requests[(int)$request['legacy_payment_id']]??=$request;
    }
    foreach($receipts as $receipt){
        $caps=['can_edit'=>false,'can_delete'=>false,'can_print'=>true];
        if(!unit_all_readonly()){
            try{$caps=$receipt['id']<-1000000000?lt_capabilities($db,['NO_INDUK'=>$nis,'unit_id'=>$group['unit_id']],$requests[-$receipt['id']-1000000000]??null,false,$isOut):payment_history_model($db,(int)$receipt['id'],false)['capabilities'];}
            catch(OutOfBoundsException $error){$caps['can_print']=false;}
        }
        $capabilities[(int)$receipt['id']]=$caps;
    }
    return ['group'=>$group,'evidence'=>rc_tariff_evidence($db,$group),'receipts'=>$receipts,'capabilities'=>$capabilities];
}

function rc_render_record(mysqli $db,array $row,array $query,bool $selected,int $position): void {
    $e='authorization_escape';$ev=rc_tariff_evidence($db,$row);$state=rc_status_class($ev['status']);
    $href='?'.http_build_query(array_replace($query,['selected'=>$row['id'],'selected_unit'=>$row['unit_id'],'selected_year'=>$row['th_ajaran']??'']));
    ?>
    <a class="du-combined-record <?= $selected?'is-selected':'' ?>" href="<?= $e($href) ?>" data-registration-record data-id="<?= (int)$row['id'] ?>" data-unit="<?= (int)$row['unit_id'] ?>" data-year="<?= $e($row['th_ajaran']??'') ?>" <?= $selected?'aria-current="true"':'' ?>>
      <span class="ph-record-number"><?= $position ?></span>
      <div class="du-record-identity"><strong><?= $e($row['name']) ?></strong><small><?= $row['student_id']?'NIS':'Identitas' ?>: <?= $e($row['identity']) ?> · <?= unit_label((int)$row['unit_id']) ?></small><span class="du-record-source"><?= $row['legacy_count']?($row['native_count']?'SistemSPP + Legacy':'Legacy'):'SistemSPP' ?></span></div>
<div class="du-record-period"><small>Tahun ajaran</small><strong><?= $e($row['th_ajaran']?:'Tidak tercatat') ?></strong><span class="kelas-badge <?= empty($row['kelas'])?'du-class-unknown':'' ?>"><?= $e($row['kelas']?'Kelas '.$row['kelas']:'Kelas tidak tercatat') ?></span></div>
      <div class="du-record-money"><small>Terbayar</small><strong><?= payment_history_money((float)$row['native_paid']+(float)$row['legacy_paid']) ?></strong><span class="du-state du-state-<?= $state ?>"><?= $e($ev['status']) ?></span></div>
    </a>
    <?php
}

function rc_render_detail(array $model,array $query): void {
    $e='authorization_escape';$g=$model['group'];$ev=$model['evidence'];$state=rc_status_class($ev['status']);
    $amount=static fn($v)=>$v===null?'Belum diketahui':payment_history_money($v);
    $query=array_replace($query,['selected'=>$g['id'],'selected_unit'=>$g['unit_id'],'selected_year'=>$g['th_ajaran']??'']);
    ?>
    <div class="du-detail-identity"><div><h3><?= $e($g['name']) ?></h3><p><?= $g['student_id']?'NIS':'Identitas' ?>: <?= $e($g['identity']) ?> · <?= unit_label((int)$g['unit_id']) ?></p></div><span class="kelas-badge <?= empty($g['kelas'])?'du-class-unknown':'' ?>"><?= $e($g['kelas']?'Kelas '.$g['kelas']:'Kelas tidak tercatat') ?></span></div>
    <section class="du-balance-card"><header><div><h4>Rekap Daftar Ulang</h4><small>Tahun ajaran <?= $e($g['th_ajaran']?:'tidak tercatat') ?></small></div><span class="du-state du-state-<?= $state ?>"><?= $e($ev['status']) ?></span></header>
      <dl class="du-balance-grid"><div><dt>Kewajiban</dt><dd><?= $state==='covered'?'Tercakup PSB':$amount($ev['tariff']) ?></dd></div><div><dt>Terbayar</dt><dd><?= payment_history_money((float)$g['native_paid']+(float)$g['legacy_paid']) ?></dd></div><div><dt>Sisa</dt><dd><?= $state==='covered'?'Tercakup PSB':$amount($ev['remaining']) ?></dd></div></dl>
      <?php if($g['native_count']&&$g['legacy_count']): ?><dl class="ph-components du-payment-sources"><div><dt>SistemSPP</dt><dd><?= payment_history_money($g['native_paid']) ?></dd></div><div><dt>Legacy</dt><dd><?= payment_history_money($g['legacy_paid']) ?></dd></div></dl><?php endif; ?>
      <?php if($state==='unknown'): ?><details class="du-evidence"><summary>Bukti kewajiban</summary><p><?= $e($ev['reason']) ?></p><?php if(isset($ev['source_tariff'])): ?><p>Tarif referensi: <?= payment_history_money($ev['source_tariff']) ?></p><?php endif; ?></details><?php endif; ?>
    </section>
    <h3 class="du-receipt-heading">Rincian Pembayaran <span><?= count($model['receipts']) ?></span></h3>
    <?php foreach($model['receipts'] as $p):$caps=$model['capabilities'][(int)$p['id']]; ?>
      <section class="du-receipt-card"><header><span class="du-record-source"><?= $p['id']<0?'Legacy':'SistemSPP' ?></span><time><?= $e(payment_time_label($p)) ?></time></header><div class="du-receipt-amount"><span>Daftar Ulang</span><strong><?= payment_history_money($p['du_amount']) ?></strong></div>
        <?php if(abs($p['du_amount']-$p['total_jumlah'])>.001): ?><p class="du-mixed-total">Total transaksi: <?= payment_history_money($p['total_jumlah']) ?></p><?php endif; ?>
        <div class="ph-detail-actions"><a class="btn btn-ghost btn-sm" href="lihat.php?<?= $e(http_build_query(['all_periods'=>'1','selected'=>$p['id'],'selected_unit'=>$p['unit_id']])) ?>">Lihat Transaksi</a><?php if(!empty($caps['can_print'])): ?><a class="btn btn-ghost btn-sm" target="_blank" rel="noopener" href="../laporan/cetak_struk.php?<?= $e(http_build_query(['id'=>$p['id'],'unit_id'=>$p['unit_id']])) ?>">Cetak Bukti Transaksi</a><?php endif; ?><?php if(!empty($caps['can_edit'])):$token=payment_return_create((int)$p['id'],(int)$p['unit_id'],$query); ?><a class="btn btn-ghost btn-sm" href="<?= $e(payment_return_edit_url($token,(int)$p['id'])) ?>">Edit</a><?php endif; ?></div>
      </section>
    <?php endforeach; ?>
    <?php if(!$model['receipts']): ?><p class="ph-empty">Belum ada transaksi Daftar Ulang pada catatan ini.</p><?php endif; ?>
    <?php
}
