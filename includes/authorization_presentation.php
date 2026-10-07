<?php
require_once __DIR__.'/authorization_history.php';
require_once __DIR__.'/transaction_authorization.php';

function authorization_escape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function authorization_role_label(string $role): string {
    return ['super_admin'=>'Super Admin','admin'=>'Admin','kasir'=>'Kasir','bendahara'=>'Bendahara'][$role] ?? 'Tidak tercatat';
}
function authorization_icon(string $name): string {
    $paths = [
        'close'=>'<path d="m6 6 12 12M18 6 6 18"/>',
        'lock'=>'<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/>',
        'student'=>'<path d="m2 9 10-5 10 5-10 5L2 9ZM6 11v6c4 3 8 3 12 0v-6M22 9v7"/>',
        'filter'=>'<path d="M3 4h18l-7 8v7l-4 2v-9L3 4Z"/>',
        'receipt'=>'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6ZM14 2v6h6M8 12h8M8 16h8"/>',
        'history'=>'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h7M14 2v6h6M14 2l6 6M8 12h4M8 16h3"/><circle cx="18" cy="17" r="4"/><path d="M18 15v2l1 1"/>',
        'edit'=>'<path d="m16 3 5 5-12 12H4v-5L16 3ZM14 5l5 5M3 22h18"/>',
        'delete'=>'<path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7M14 10v7"/>',
        'user'=>'<circle cx="12" cy="8" r="4"/><path d="M5 21v-3a7 7 0 0 1 14 0v3H5Z"/>',
        'calendar'=>'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18M8 15h2M14 15h2"/>',
        'chart'=>'<path d="M5 20V12M12 20V4M19 20V8"/>',
        'left'=>'<path d="m14 6-6 6 6 6M8 12h13"/>',
        'right'=>'<path d="m10 6 6 6-6 6M3 12h13"/>',
        'check'=>'<path d="m5 12 4 4L19 6"/>',
        'alert'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v6M12 17h.01"/>',
        'pdf'=>'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8L14 2ZM14 2v6h6M8 12h8M8 16h8"/>',
        'search'=>'<circle cx="10" cy="10" r="7"/><path d="m15 15 6 6"/>',
    ];
    return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.($paths[$name]??$paths['history']).'</svg>';
}
function authorization_badge(string $action): array {
    return match ($action) {
        'deleted'=>['Dihapus','danger','delete'], 'edited'=>['Diubah','warning','edit'],
        'request_delete'=>['Menunggu','warning','delete'], 'request_edit'=>['Menunggu','warning','edit'],
        'approved'=>['Disetujui','success','check'], 'rejected'=>['Ditolak','danger','alert'],
        'cancelled'=>['Dibatalkan','muted','alert'], 'failed'=>['Gagal','danger','alert'],
        default=>[payment_activity_labels()[$action]??'Tidak tercatat','muted','history'],
    };
}
/** Narrow a read request without ever widening the session's unit scope. */
function authorization_read_unit(mysqli $db, $raw): int {
    $scope=unit_active_id();
    if ($raw===null || $raw==='') return $scope;
    if (!is_scalar($raw) || !preg_match('/^[1-3]$/D',(string)$raw)) throw new InvalidArgumentException('Unit tidak valid.');
    $unit=(int)$raw;
    if ($scope!==0 && $unit!==$scope) throw new RuntimeException('Riwayat tidak ditemukan pada cakupan akses Anda.');
    if ($scope===0 && !unit_is_super()) throw new RuntimeException('Akses tidak diizinkan.');
    unit_set_context($db,$unit); return $unit;
}
function authorization_event_model(array $e): array {
    $before=json_decode($e['before_snapshot']??'null',true);
    $after=json_decode($e['after_snapshot']??'null',true);
    $changes=payment_activity_changes($before,$after);
    if ($e['action']==='request_delete') $changes=[['label'=>'Status transaksi','before'=>'Aktif','after'=>'Diusulkan dihapus']];
    elseif (is_array($e['_proposal']??null) && $before) $changes=payment_activity_changes($before,authorization_proposed_snapshot($before,$e['_proposal']));
    if ($e['action']==='deleted') $changes[]=['label'=>'Status transaksi','before'=>'Aktif','after'=>'Dihapus'];
    foreach($changes as &$change) if($change['label']==='Biaya Lain') $change['label']='Total Daftar Ulang dan Biaya Lain'; unset($change);
    return ['action'=>$e['action'],'label'=>payment_activity_labels()[$e['action']]??'Tidak tercatat',
        'name'=>$e['actor_name']?:'Tidak tercatat','username'=>$e['actor_username']??'','role'=>$e['actor_role']??'',
        'time'=>spp_date_label($e['occurred_at'],true),'authorization_id'=>$e['authorization_id']??null,
        'note'=>$e['note']??'','reconstructed'=>(bool)($e['reconstructed']??false),'changes'=>$changes,
        'proposed'=>str_starts_with($e['action'],'request_')];
}
function authorization_detail_model(mysqli $db, int $id, bool $queue): array {
    $request=null;
    if($queue) {
        $request=transaction_authorization_find($db,$id);
        if(!$request || (isRole('kasir') && (int)$request['requested_by']!==(int)$_SESSION['admin_id'])) throw new RuntimeException('Pengajuan tidak ditemukan pada cakupan akses Anda.');
        $snapshot=json_decode($request['before_snapshot'],true);
        $paymentId=(int)($request['bayar_id']??$snapshot['payment']['id']??0);
    } else $paymentId=$id;
    if(!authorization_history_can_read($db,$paymentId)) throw new RuntimeException('Riwayat tidak ditemukan pada cakupan akses Anda.');
    $events=authorization_history_events($db,$paymentId);
    if(!$events) throw new RuntimeException('Riwayat tidak ditemukan pada cakupan akses Anda.');
    $identity=[]; $rows=[];
    foreach($events as $e) {
        $snapshot=json_decode($e['after_snapshot']??$e['before_snapshot']??'null',true);
        $identity=$snapshot['payment']??$identity; $rows[]=authorization_event_model($e);
    }
    $latest=end($rows);
    if($request) {
        $latest=['action'=>$request['status']==='pending'?($request['action']==='hapus'?'request_delete':'request_edit'):$request['status'],
            'name'=>$request['requested_by_name']??'Tidak tercatat','username'=>$request['requested_by_username']??'',
            'role'=>$request['requested_by_role']??'','time'=>spp_date_label($request['requested_at'],true),
            'label'=>transaction_authorization_action_label($request['action']),'authorization_id'=>$request['id']];
    }
    return ['id'=>$paymentId,'unit_id'=>(int)$events[0]['unit_id'],'unit'=>unit_label((int)$events[0]['unit_id']),
        'reference'=>'TRX-'.str_pad((string)$paymentId,6,'0',STR_PAD_LEFT),'student'=>$identity['NAMA']??'Tidak tercatat',
        'nis'=>$identity['NO_INDUK']??'Tidak tercatat','latest'=>$latest,'events'=>$rows,'request'=>$request];
}
function authorization_render_detail(array $model): void {
    $e=$model['latest']; [$status,$tone,$icon]=authorization_badge($e['action']); $request=$model['request'];
    ?>
    <div class="auth-detail-summary"><span class="auth-action-icon auth-tone-<?= $tone ?>"><?= authorization_icon($icon) ?></span><div><strong><?= authorization_escape($model['reference']) ?></strong> <span class="auth-status auth-tone-<?= $tone ?>"><?= authorization_escape($status) ?></span><p><?= authorization_escape($model['student']) ?></p><small>NIS <?= authorization_escape($model['nis']) ?> · <?= authorization_escape($model['unit']) ?></small></div></div>
    <div class="auth-detail-grid"><div><small>Jenis perubahan</small><strong><?= authorization_escape($e['label']) ?></strong><span><?= $e['authorization_id']?'Pengajuan #'.(int)$e['authorization_id']:'Perubahan langsung' ?></span></div><div><small><?= $request?'Pemohon':'Operator' ?></small><strong><?= authorization_escape($e['name']) ?></strong><span><?= authorization_escape($e['username']?'@'.$e['username']:'Tidak tercatat') ?></span><span><?= authorization_escape(authorization_role_label($e['role'])) ?></span></div><div><small><?= $request?'Waktu pengajuan':'Waktu perubahan' ?></small><span><?= authorization_escape($e['time']) ?></span></div><div><small><?= $request?'Status pengajuan':'Aktivitas terakhir' ?></small><span class="auth-status auth-tone-<?= $tone ?>"><?= authorization_escape($status) ?></span></div></div>
    <?php if($request): $before=json_decode($request['before_snapshot'],true); $payload=transaction_authorization_decode_payload($request);
        $changes=$request['action']==='hapus'?[['label'=>'Status transaksi','before'=>'Aktif','after'=>'Diusulkan dihapus']]:payment_activity_changes($before,authorization_proposed_snapshot($before,$payload)); ?>
    <h4>Usulan perubahan</h4><p class="auth-muted">Usulan belum berarti perubahan telah diterapkan.</p>
    <?php authorization_render_changes($changes,true); ?>
    <div class="auth-reason"><small>Alasan pemohon</small><p><?= nl2br(authorization_escape($request['request_reason'])) ?></p></div>
    <?php if($request['status']==='pending' && !unit_all_readonly()):
        $token=authorization_escape($_SESSION['csrf_transaction_authorization']??''); ?>
        <?php if((int)$request['requested_by']===(int)$_SESSION['admin_id']): ?>
        <form data-auth-kind="<?= authorization_escape($request['action']) ?>" data-auth-reference="<?= authorization_escape($model['reference']) ?>" data-auth-student="<?= authorization_escape($model['student']) ?>" method="post" action="otorisasi_transaksi.php" data-auth-confirm="Batalkan permintaan ini?"><input type="hidden" name="csrf_token" value="<?= $token ?>"><input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>"><input type="hidden" name="action" value="cancel"><button class="btn btn-ghost" type="submit">Batalkan Permintaan</button></form>
        <?php elseif(unit_is_super()): ?>
        <div class="auth-decision"><p>Keputusan hanya dapat diberikan oleh Super Admin.</p><form data-auth-kind="<?= authorization_escape($request['action']) ?>" data-auth-reference="<?= authorization_escape($model['reference']) ?>" data-auth-student="<?= authorization_escape($model['student']) ?>" method="post" action="pembayaran/proses.php" data-auth-confirm="Setujui dan terapkan perubahan transaksi ini?"><input type="hidden" name="csrf_token" value="<?= $token ?>"><input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>"><input type="hidden" name="aksi" value="otorisasi_setujui"><label>Catatan persetujuan (opsional)<textarea class="field-input" name="decision_note" maxlength="1000" rows="2"></textarea></label><button class="btn btn-primary" type="submit">Setujui dan Terapkan</button></form><form data-auth-kind="<?= authorization_escape($request['action']) ?>" data-auth-reference="<?= authorization_escape($model['reference']) ?>" data-auth-student="<?= authorization_escape($model['student']) ?>" method="post" action="otorisasi_transaksi.php" data-auth-confirm="Tolak pengajuan ini?"><input type="hidden" name="csrf_token" value="<?= $token ?>"><input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>"><input type="hidden" name="action" value="reject"><label>Alasan penolakan<textarea class="field-input" name="decision_note" maxlength="1000" rows="2" required></textarea></label><button class="btn btn-danger" type="submit">Tolak</button></form></div>
        <?php else: ?><p class="auth-readonly">Hanya Super Admin yang dapat menyetujui atau menolak pengajuan.</p><?php endif; ?>
    <?php elseif($request['status']==='pending'): ?><p class="auth-readonly">Pilih unit pengajuan di sidebar untuk memberi keputusan.</p><?php endif; ?>
    <?php endif; ?>
    <h4>Riwayat langkah</h4><ol class="auth-timeline">
    <?php foreach($model['events'] as $event): [$s,$t]=authorization_badge($event['action']); ?>
        <li class="auth-event auth-tone-<?= $t ?>"><div><strong><?= authorization_escape($event['label']) ?></strong><small><?= authorization_escape($event['name']) ?> · <?= authorization_escape(authorization_role_label($event['role'])) ?></small><?php if($event['proposed']): ?><small>Usulan pemohon</small><?php endif; ?></div><time><?= authorization_escape($event['time']) ?></time></li>
    <?php endforeach; ?></ol>
    <button class="auth-full-history open-payment-activity" type="button" data-id="<?= (int)$model['id'] ?>" data-unit="<?= (int)$model['unit_id'] ?>"><?= authorization_icon('history') ?> Lihat Riwayat Lengkap <?= authorization_icon('right') ?></button>
    <?php
}
function authorization_render_changes(array $changes,bool $proposed): void {
    if(!$changes){ echo '<p class="auth-muted">Rincian perubahan tidak tercatat.</p>'; return; }
    echo '<div class="auth-changes">';
    foreach($changes as $change) echo '<div><strong>'.authorization_escape($change['label']).'</strong><span>Sebelum: '.authorization_escape($change['before']).'</span><span>'.($proposed?'Usulan: ':'Sesudah: ').authorization_escape($change['after']).'</span></div>';
    echo '</div>';
}
