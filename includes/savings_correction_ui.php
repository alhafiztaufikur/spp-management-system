<?php
require_once __DIR__.'/savings_authorization.php';
function savings_correction_controls(mysqli $db,array $row):void {
    require_once __DIR__.'/student_out.php';
    if(empty($_SESSION['csrf_savings']))$_SESSION['csrf_savings']=bin2hex(random_bytes(32));
    if(!savings_authorization_ready($db)){if(operational_role())echo '<p class="sw-note">Koreksi tabungan belum tersedia. Hubungi pengelola sistem.</p>';return;}
    $id=(int)$row['id'];
    $request=$id>0?lf_one($db,'SELECT * FROM tabungan_otorisasi WHERE jenis=? AND transaction_id=? ORDER BY id DESC LIMIT 1',[$row['jenis'],$id]):null;
    $target=null;$expectedHash='';
    if(empty($row['deleted'])&&operational_role()&&unit_active_id()===(int)$row['unit_id']){
        if($id>0){
            $student=sout_student($db,(string)$row['NO_INDUK'],false,(int)$row['unit_id']);
            if($student&&empty($student['is_out'])){
                $table=$row['jenis']==='masuk'?'transaksi_m':'transaksi_k';
                $target=lf_one($db,"SELECT id,unit_id,NO_INDUK,TANGGAL,MASUK,KELUAR,user_id,keterangan FROM $table WHERE id=?",[$id]);
                if($target){
                    $target['jenis']=$row['jenis'];
                    $expectedHash=savings_snapshot($target,$student)['hash'];
                }
            }
        }else{
            $target=lf_one($db,"SELECT j.id journal_id,j.version journal_version,j.unit_id,j.source_date TANGGAL,
                j.incoming MASUK,j.outgoing KELUAR,IF(j.incoming>0,'masuk','keluar') jenis
                FROM legacy_op_journals j JOIN legacy_op_owners o ON o.id=j.owner_id AND o.unit_id=j.unit_id
                WHERE j.id=? AND j.origin='new' AND j.cancelled=0 AND o.state='ready' AND o.student_id IS NULL AND o.kind<>'out'",
                [-1000000000-$id]);
            if($target)$expectedHash=savings_account_edit_token($target);
        }
        if($target&&$target['jenis']!==$row['jenis'])$target=null;
    }
    $editAmount=(float)($target[$row['jenis']==='masuk'?'MASUK':'KELUAR']??0);
    $hidden=static function(string $action)use($row,$expectedHash):void{ ?>
        <input type="hidden" name="expected_hash" value="<?= $expectedHash ?>">
        <input type="hidden" name="action" value="<?= savings_e($action) ?>"><input type="hidden" name="jenis" value="<?= savings_e($row['jenis']) ?>"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="_operational_unit" value="<?= unit_active_id() ?>"><input type="hidden" name="csrf_token" value="<?= savings_e($_SESSION['csrf_savings']) ?>">
        <?php foreach(['nis','tanggal_awal','tanggal_akhir','page','per_page','student_id','owner_id','view','all_periods','back_context'] as $key)if(is_scalar($_GET[$key]??null)): ?><input type="hidden" name="return_<?= $key ?>" value="<?= savings_e($_GET[$key]) ?>"><?php endif;
        foreach((array)($_GET['operator']??['*']) as $operator)if(is_scalar($operator)): ?><input type="hidden" name="return_operator[]" value="<?= savings_e($operator) ?>"><?php endif;
        foreach((array)($_GET['rombel']??['*']) as $rombel)if(is_scalar($rombel)): ?><input type="hidden" name="return_rombel[]" value="<?= savings_e($rombel) ?>"><?php endif;
    };
    if($request): ?>
    <section class="sw-request-status"><strong>Pengajuan #<?= (int)$request['id'] ?> · <?= savings_e(['pending'=>'Menunggu','approved'=>'Disetujui','rejected'=>'Ditolak','cancelled'=>'Dibatalkan','failed'=>'Gagal'][$request['status']]) ?></strong><p><?= savings_e($request['request_reason']) ?></p><?php if($request['decision_note']): ?><p>Keputusan: <?= savings_e($request['decision_note']) ?></p><?php elseif($request['status']==='pending'&&$target): ?><p>Pengajuan ini akan ditutup setelah koreksi langsung berhasil.</p><?php endif; ?>
    <?php if($request['status']==='pending'&&!unit_all_readonly()&&(int)$request['requested_by']===(int)$_SESSION['admin_id']): ?><form method="post" action="koreksi.php"><?php $hidden('cancel'); ?><input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>"><button class="btn btn-ghost" type="submit">Batalkan Pengajuan Saya</button></form><?php endif; ?></section>
    <?php endif;
    ?><div class="sw-transaction-actions"><?php
    if(!empty($row['deleted']))echo '<p class="sw-lock">Transaksi dihapus. Arsip audit tidak dihitung dalam saldo.</p>';
    elseif($target){ ?>
        <button type="button" class="btn btn-ghost" data-savings-open="edit"><?= savings_icon('edit') ?>Edit</button><dialog class="sw-confirm-dialog sw-editor-dialog" data-savings-editor="edit" aria-label="Koreksi transaksi tabungan"><div class="sw-editor-stage"><header class="sw-confirm-heading"><h3>Edit Transaksi</h3></header>
        <form method="post" action="koreksi.php" class="sw-correction-form" data-savings-confirm data-student="<?= savings_e($row['NAMA']) ?>" data-reference="<?= savings_e($row['reference']) ?>" data-original-amount="<?= $editAmount ?>" data-original-date="<?= savings_e($target['TANGGAL']) ?>">
          <?php $hidden('direct_edit'); ?>
          <label>Nominal (Rp)<input class="field-input" name="nominal" type="text" inputmode="decimal" data-savings-amount maxlength="18" pattern="(?:[0-9]{1,12}|[1-9][0-9]{0,2}(?:\.[0-9]{3}){1,3})(?:,[0-9]{1,2})?" required value="<?= number_format($editAmount,fmod($editAmount,1.0)==0.0?0:2,',','.') ?>"></label>
          <label>Tanggal/waktu WIB<input class="field-input" name="tanggal" type="datetime-local" data-correction-datetime step="1" required value="<?= savings_e(str_replace(' ','T',$target['TANGGAL'])) ?>"></label>
          <label class="sw-field-wide">Alasan koreksi (opsional)<textarea class="field-input" name="reason" maxlength="500" rows="3" placeholder="Jelaskan alasan perubahan transaksi ini"></textarea></label><div class="sw-form-footer"><small>Saldo akan diperiksa ulang saat koreksi disimpan.</small><button class="btn btn-primary" type="submit"><?= savings_icon('check') ?>Tinjau Perubahan</button><button class="btn btn-ghost" type="button" data-savings-close>Batal</button></div>
        </form></div><div class="sw-review-stage" hidden></div></dialog>
        <button type="button" class="btn btn-danger" data-savings-open="hapus"><?= savings_icon('delete') ?>Hapus</button><dialog class="sw-confirm-dialog sw-editor-dialog" data-savings-editor="hapus" aria-label="Hapus transaksi tabungan" data-danger="true"><div class="sw-editor-stage"><header class="sw-confirm-heading"><h3>Hapus Transaksi</h3></header><form method="post" action="koreksi.php" class="sw-correction-form" data-savings-confirm data-student="<?= savings_e($row['NAMA']) ?>" data-reference="<?= savings_e($row['reference']) ?>" data-original-amount="<?= $editAmount ?>"><?php $hidden('direct_hapus'); ?><p class="sw-delete-explanation">Penghapusan memperbarui saldo dan tercatat di riwayat aktivitas.</p><label class="sw-field-wide">Alasan penghapusan (opsional)<textarea class="field-input" name="reason" maxlength="500" rows="3" placeholder="Jelaskan alasan penghapusan transaksi ini"></textarea></label><div class="sw-form-footer"><button class="btn btn-danger" type="submit"><?= savings_icon('delete') ?>Tinjau Penghapusan</button><button class="btn btn-ghost" type="button" data-savings-close>Batal</button></div></form></div><div class="sw-review-stage" hidden></div></dialog>
    <?php }
    ?><button type="button" class="btn btn-ghost" data-savings-activity data-kind="<?= savings_e($row['jenis']) ?>" data-id="<?= $id ?>"><?= savings_icon('history') ?>Riwayat Aktivitas</button></div><noscript><style>.sw-editor-dialog{display:block;position:static;width:100%;max-height:none;margin:16px 0}.sw-editor-dialog .sw-review-stage{display:none}</style></noscript><?php
}
function savings_account_events(mysqli $db,array $row): array {
    $events=[];
    $snapshot=static fn(array $journal)=>['transaction'=>[
        'MASUK'=>$journal['incoming'],'KELUAR'=>$journal['outgoing'],'TANGGAL'=>$journal['source_date'],'keterangan'=>$journal['note'],
    ]];
    foreach(lf_rows($db,"SELECT a.entity_type,a.created_at occurred_at,a.reason note,a.before_data,a.after_data,
        u.nama actor_name,u.role actor_role FROM legacy_op_audit a LEFT JOIN admin u ON u.id=a.actor_id
        WHERE a.unit_id=? AND a.entity_id=? AND a.entity_type IN ('bank_new','journal') ORDER BY a.created_at,a.id",
        [(int)$row['unit_id'],-1000000000-(int)$row['id']]) as $event){
        if($event['entity_type']==='bank_new'){
            $events[]=$event+['action'=>'created'];
            continue;
        }
        $before=json_decode($event['before_data'],true);$after=json_decode($event['after_data'],true);
        if(!isset($before['incoming'],$after['incoming']))continue;
        $deleted=!empty($after['cancelled']);
        $events[]=$event+['action'=>$deleted?'deleted':'edited','before_snapshot'=>lf_json($snapshot($before)),
            'after_snapshot'=>$deleted?null:lf_json($snapshot($after))];
    }
    return $events;
}
function savings_activity_html(mysqli $db,array $row):string {
    if(!savings_authorization_ready($db))return '<p class="sw-empty">Riwayat aktivitas belum tersedia.</p>';
    if(($row['origin']??'')==='new')$events=savings_account_events($db,$row);
    else $events=lf_rows($db,'SELECT action,actor_name,actor_role,occurred_at,note,before_snapshot,after_snapshot FROM tabungan_aktivitas WHERE jenis=? AND transaction_id=? ORDER BY occurred_at,id',[$row['jenis'],(int)$row['id']]);
    ob_start();if(!$events)echo '<p class="sw-empty">Belum ada jejak aktivitas untuk transaksi ini. Pembuat awal: '.savings_e($row['operator_name']).'.</p>';
    ?>      <ol class="sw-audit-list"><?php foreach(array_reverse($events) as $event):
        $action=$event['action'];$isDelete=in_array($action,['deleted','request_delete'],true);
        $labels=['created'=>'Transaksi dibuat','baseline'=>'Data awal tercatat','request_edit'=>'Koreksi diajukan','request_delete'=>'Penghapusan diajukan','approved'=>'Pengajuan disetujui','rejected'=>'Pengajuan ditolak','cancelled'=>'Pengajuan dibatalkan','edited'=>'Transaksi dikoreksi','deleted'=>'Transaksi dihapus'];
        $before=json_decode($event['before_snapshot']??'null',true)??[];$after=json_decode($event['after_snapshot']??'null',true)??[];$old=$before['transaction']??[];$new=$after['transaction']??[];
        $proposed=!empty($after['proposal']);
        if($proposed){$new=$old;$proposal=$after['proposal'];$new[$row['jenis']==='masuk'?'MASUK':'KELUAR']=$proposal['nominal'];$new['TANGGAL']=$proposal['tanggal'];$new['keterangan']=$proposal['keterangan'];}
        $changes=[];foreach(['MASUK'=>'Jumlah masuk','KELUAR'=>'Jumlah keluar','TANGGAL'=>'Tanggal / waktu WIB','keterangan'=>'Keterangan'] as $key=>$label){
          $equal=in_array($key,['MASUK','KELUAR'],true)?(float)($old[$key]??0)===(float)($new[$key]??0):($old[$key]??'')===($new[$key]??'');
          if(!$new||$equal)continue;
          $format=static fn($value)=>in_array($key,['MASUK','KELUAR'],true)?savings_money($value):($key==='TANGGAL'&&$value!==''?spp_date_label($value,true):($value!==''?$value:'-'));
          $changes[]=['label'=>$label,'before'=>$format($old[$key]??''),'after'=>$format($new[$key]??'')];
        }
        if($isDelete)$changes[]=['label'=>'Status transaksi','before'=>'Aktif','after'=>$action==='deleted'?'Dihapus':'Diusulkan dihapus'];
      ?><li class="<?= $isDelete?'is-danger':'' ?>"><span class="sw-audit-dot"><?= savings_icon($isDelete?'delete':($action==='edited'?'edit':'check')) ?></span><div class="sw-audit-content">
        <div class="sw-audit-event-head"><strong><?= savings_e($labels[$action]??$action) ?></strong><time><?= savings_e(spp_date_label($event['occurred_at'],true)) ?></time></div>
        <p class="sw-audit-actor"><?= savings_e($event['actor_name']?:'Tidak tercatat') ?><span><?= savings_e(['super_admin'=>'Super Admin','admin'=>'Administrator','kasir'=>'Kasir','bendahara'=>'Bendahara'][$event['actor_role']]??'') ?></span></p>
        <?php if(trim($event['note']??'')!==''): ?><div class="sw-audit-reason"><small>Alasan / catatan</small><p><?= nl2br(savings_e($event['note'])) ?></p></div><?php endif; ?>
        <?php if($changes): ?><div class="sw-audit-changes"><?php foreach($changes as $change): ?><div><strong><?= savings_e($change['label']) ?></strong><dl><div><dt>Sebelum</dt><dd><?= savings_e($change['before']) ?></dd></div><div><dt><?= $proposed?'Usulan':'Sesudah' ?></dt><dd><?= savings_e($change['after']) ?></dd></div></dl></div><?php endforeach; ?></div><?php endif; ?>
      </div></li><?php endforeach; ?></ol><?php return (string)ob_get_clean();
}
