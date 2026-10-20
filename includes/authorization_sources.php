<?php
require_once __DIR__.'/savings_authorization.php';

function authorization_menu($raw='all'): string {
    if (!is_string($raw) || !in_array($raw,['all','pembayaran','daftar_ulang','tabungan'],true)) {
        throw new InvalidArgumentException('Menu otorisasi tidak valid.');
    }
    return $raw;
}
function authorization_menu_label(string $menu): string {
    return ['all'=>'Semua menu','pembayaran'=>'Riwayat Pembayaran','daftar_ulang'=>'Riwayat Daftar Ulang','tabungan'=>'Riwayat Tabungan'][$menu] ?? 'Semua menu';
}
/** DU is evidenced by its saved allocation or the proposed DU amount, including deleted payments. */
function authorization_du_evidence_sql(): string {
    return "(JSON_UNQUOTE(JSON_EXTRACT(e.before_snapshot,'$.payment.source_table'))='bayar_du' OR JSON_SEARCH(e.before_snapshot,'one','daftar_ulang',NULL,'$.details.legacy_items[*].category') IS NOT NULL OR COALESCE(JSON_LENGTH(JSON_EXTRACT(e.before_snapshot,'$.details.bayar_du')),0)>0
        OR COALESCE(JSON_LENGTH(JSON_EXTRACT(e.after_snapshot,'$.details.bayar_du')),0)>0
        OR COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(r.proposed_payload,'$.uang_du')) AS DECIMAL(20,2)),0)>0)";
}
function authorization_menu_sql(): string {
    return "CASE WHEN e.source<>'pembayaran' THEN 'tabungan' WHEN MAX(".authorization_du_evidence_sql().") OVER(PARTITION BY e.unit_id,e.source,e.payment_id)>0 THEN 'daftar_ulang' ELSE 'pembayaran' END";
}
function authorization_source($raw='pembayaran',bool $all=false):string {
    if(!is_string($raw)||!in_array($raw,$all?['all','pembayaran','tabungan_masuk','tabungan_keluar']:['pembayaran','tabungan_masuk','tabungan_keluar'],true))throw new InvalidArgumentException('Jenis transaksi tidak valid.');return $raw;
}
function authorization_savings_events(mysqli $db,string $kind,int $id):array {
    $s=$db->prepare('SELECT * FROM tabungan_aktivitas WHERE jenis=? AND transaction_id=? ORDER BY occurred_at,id');$s->bind_param('si',$kind,$id);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();return $rows;
}
function authorization_savings_event(array $row):array {
    $before=json_decode($row['before_snapshot']??'null',true)??[];$after=json_decode($row['after_snapshot']??'null',true)??[];$old=$before['transaction']??[];$new=$after['transaction']??[];
    $proposed=str_starts_with($row['action'],'request_');
    if(isset($after['proposal'])){$new=$old;$p=$after['proposal'];if($p){$new['TANGGAL']=$p['tanggal'];$new['keterangan']=$p['keterangan'];$new[$row['jenis']==='masuk'?'MASUK':'KELUAR']=$p['nominal'];}}
    $changes=[];$amount=$row['jenis']==='masuk'?'MASUK':'KELUAR';
    foreach([$amount=>'Nominal','TANGGAL'=>'Tanggal/waktu WIB','keterangan'=>'Keterangan'] as $key=>$label)if($new&&($old[$key]??'')!=($new[$key]??''))$changes[]=['label'=>$label,'before'=>$key===$amount?savings_money($old[$key]??0):(string)($old[$key]??''),'after'=>$key===$amount?savings_money($new[$key]??0):(string)($new[$key]??'')];
    if(in_array($row['action'],['deleted','request_delete'],true))$changes[]=['label'=>'Status transaksi','before'=>'Aktif','after'=>$proposed?'Diusulkan dihapus':'Dihapus (arsip audit)'];
    if(isset($before['balance'],$after['balance']))$changes[]=['label'=>'Saldo rekening','before'=>savings_money($before['balance']/100),'after'=>savings_money($after['balance']/100)];
    return ['action'=>$row['action'],'label'=>savings_activity_labels()[$row['action']]??$row['action'],'name'=>$row['actor_name']?:'Tidak tercatat','username'=>$row['actor_username'],'role'=>$row['actor_role'],'time'=>spp_date_label($row['occurred_at'],true),'authorization_id'=>$row['authorization_id'],'note'=>$row['note']??'','reconstructed'=>(bool)$row['reconstructed'],'changes'=>$changes,'proposed'=>$proposed];
}
function authorization_savings_model(mysqli $db,int $id,bool $queue,string $source):array {
    if(!savings_authorization_ready($db))throw new RuntimeException('Migrasi otorisasi tabungan belum diterapkan.');
    $kind=$source==='tabungan_masuk'?'masuk':'keluar';$request=$queue?savings_request_find($db,$id):null;
    if($queue&&(!$request||$request['jenis']!==$kind))throw new RuntimeException('Pengajuan tidak ditemukan.');
    $transaction=$queue?(int)$request['transaction_id']:$id;$events=authorization_savings_events($db,$kind,$transaction);if(!$events)throw new RuntimeException('Riwayat tidak ditemukan.');
    $identity=[];$models=[];foreach($events as $row){$b=json_decode($row['before_snapshot']??'null',true);$a=json_decode($row['after_snapshot']??'null',true);$identity=$a['transaction']??$b['transaction']??$identity;$models[]=authorization_savings_event($row);}
    return ['id'=>$transaction,'unit_id'=>(int)$events[0]['unit_id'],'unit'=>unit_label((int)$events[0]['unit_id']),'source'=>$source,'reference'=>'TB-'.($kind==='masuk'?'M':'K').'-'.$events[0]['unit_id'].'-'.str_pad((string)$transaction,6,'0',STR_PAD_LEFT),'student'=>$identity['NAMA']??'Tidak tercatat','nis'=>$identity['NO_INDUK']??'','latest'=>end($models),'events'=>$models,'request'=>$request];
}
/** Normalize all identities before filtering, ranking or pagination. */
function authorization_combined_page(mysqli $db,bool $history,string $source,string $kind,string $q,int $page,array $statuses,string $menu='all'):array {
    authorization_menu($menu);
    $payment=$history?"SELECT p.*, 'pembayaran' source FROM (".authorization_history_source().") p":
        "SELECT r.unit_id,COALESCE(r.bayar_id,CAST(JSON_UNQUOTE(JSON_EXTRACT(r.before_snapshot,'$.payment.id')) AS SIGNED),0) payment_id,r.id,IF(r.action='hapus','request_delete','request_edit') action,r.requested_by actor_id,a.nama actor_name,a.username actor_username,a.role actor_role,r.requested_at occurred_at,r.id authorization_id,r.request_reason note,r.before_snapshot,NULL after_snapshot,0 reconstructed,'pembayaran' source FROM transaksi_otorisasi r JOIN admin a ON a.id=r.requested_by WHERE r.status='pending'";
    $sql=$payment;
    require_once __DIR__.'/legacy_operational_schema.php';
    if($history&&lop_ready($db))$sql.=" UNION ALL SELECT a.unit_id,-1000000000-a.entity_id,-a.id,IF(JSON_EXTRACT(a.after_data,'$.cancelled')=1,'deleted','edited'),a.actor_id,u.nama,u.username,u.role,a.created_at,NULL,a.reason,JSON_OBJECT('payment',JSON_OBJECT('id',-1000000000-a.entity_id,'NAMA',o.name,'NO_INDUK',o.source_identity,'source_table',p.source_table)),NULL,0,'pembayaran' FROM legacy_op_audit a JOIN legacy_op_payments p ON p.id=a.entity_id AND p.unit_id=a.unit_id JOIN legacy_op_owners o ON o.id=p.owner_id AND o.unit_id=p.unit_id LEFT JOIN admin u ON u.id=a.actor_id WHERE a.entity_type='payment'";
    if(savings_authorization_ready($db))$sql.=$history?" UNION ALL SELECT unit_id,transaction_id,id,action,actor_id,actor_name,actor_username,actor_role,occurred_at,authorization_id,note,before_snapshot,after_snapshot,reconstructed,CONCAT('tabungan_',jenis) FROM tabungan_aktivitas WHERE action NOT IN ('created','baseline')":
        " UNION ALL SELECT r.unit_id,r.transaction_id,r.id,IF(r.action='hapus','request_delete','request_edit'),r.requested_by,a.nama,a.username,a.role,r.requested_at,r.id,r.request_reason,r.before_snapshot,NULL,0,CONCAT('tabungan_',r.jenis) FROM tabungan_otorisasi r JOIN admin a ON a.id=r.requested_by WHERE r.status='pending'";
    $where='1=1';$params=[];$types='';if($source!=='all'){$where.=' AND e.source=?';$params[]=$source;$types.='s';}
    if($menu!=='all'){$where.=' AND e.menu=?';$params[]=$menu;$types.='s';}
    if($kind!=='all'){$actions=$kind==='edit'?"'edited','request_edit'":"'deleted','request_delete'";$where.=" AND (e.action IN ($actions) OR r.action=? OR t.action=?)";array_push($params,$kind,$kind);$types.='ss';}
    if(!filter_is_all($statuses)){$where.=' AND ('.substr(filter_sql_values($statuses,'r.status'),5).' OR '.substr(filter_sql_values($statuses,'t.status'),5).')';}
    if($q!==''){$where.=" AND (CAST(e.payment_id AS CHAR) LIKE ? OR e.before_snapshot LIKE ? OR e.actor_name LIKE ?)";array_push($params,'%'.$q.'%','%'.$q.'%','%'.$q.'%');$types.='sss';}
    $join=' LEFT JOIN transaksi_otorisasi r ON e.source=\'pembayaran\' AND r.id=e.authorization_id';
    $join.=savings_authorization_ready($db)?" LEFT JOIN tabungan_otorisasi t ON e.source=CONCAT('tabungan_',t.jenis) AND t.id=e.authorization_id":" LEFT JOIN (SELECT NULL id,NULL action,NULL status) t ON 1=0";
    // A mixed payment containing DU belongs to Daftar Ulang once, with its complete chronology.
    $category=authorization_menu_sql();
    $cte="WITH raw_events AS ($sql), events AS (SELECT e.*,$category menu FROM raw_events e $join), matches AS (SELECT DISTINCT e.unit_id,e.source,".($history?'e.payment_id':'e.id')." identity_id FROM events e $join WHERE $where), ranked AS (SELECT e.*,ROW_NUMBER() OVER(PARTITION BY e.unit_id,e.source,".($history?'e.payment_id':'e.id')." ORDER BY e.occurred_at DESC,e.id DESC) rn FROM events e JOIN matches m ON m.unit_id=e.unit_id AND m.source=e.source AND m.identity_id=".($history?'e.payment_id':'e.id').') ';
    $s=$db->prepare($cte.'SELECT COUNT(*) FROM ranked WHERE rn=1');if($params)$s->bind_param($types,...$params);$s->execute();$total=(int)$s->get_result()->fetch_row()[0];$s->close();$pages=max(1,(int)ceil($total/25));$page=max(1,min($page,$pages));$offset=($page-1)*25;
    $s=$db->prepare($cte.'SELECT * FROM ranked WHERE rn=1 ORDER BY occurred_at DESC,source,id DESC LIMIT 25 OFFSET ?');$p=array_merge($params,[$offset]);$b=$types.'i';$s->bind_param($b,...$p);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();return compact('rows','total','pages','page');
}
function authorization_render_savings(array $model):void {
    echo '<div class="auth-savings-detail"><div class="auth-detail-summary"><div><strong>'.authorization_escape($model['reference']).'</strong><p>'.authorization_escape($model['student']).'</p><small>NIS '.authorization_escape($model['nis']).' / '.authorization_escape($model['unit']).'</small></div></div>';
    $r=$model['request'];if($r){echo '<h4>Usulan (belum diterapkan)</h4><p>'.nl2br(authorization_escape($r['request_reason'])).'</p>';$events=array_filter($model['events'],static fn($e)=>$e['proposed']&&(int)$e['authorization_id']===(int)$r['id']);foreach($events as $event)authorization_render_savings_changes($event['changes'],true);
        if($r['status']==='pending'&&empty($model['readonly'])&&!unit_all_readonly()&&(int)$r['requested_by']!==(int)$_SESSION['admin_id'])foreach(['approve'=>'Setujui dan Terapkan','reject'=>'Tolak Pengajuan'] as $action=>$label){?><form class="auth-decision" method="post" action="tabungan/koreksi.php" onsubmit="return confirm('<?= $label ?>?')"><input type="hidden" name="csrf_token" value="<?= authorization_escape($_SESSION['csrf_transaction_authorization']) ?>"><input type="hidden" name="_operational_unit" value="<?= unit_active_id() ?>"><input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="action" value="<?= $action ?>"><label>Alasan / catatan<textarea class="field-input" name="reason" rows="2" maxlength="1000" <?= $action==='reject'?'required minlength="5"':'' ?>></textarea></label><button class="btn <?= $action==='reject'?'btn-danger':'btn-primary' ?>"><?= $label ?></button></form><?php }
        elseif($r['status']==='pending')echo '<p>Pilih unit transaksi di sidebar untuk memberi keputusan.</p>';
    }
    echo '<h4>Aktivitas tabungan</h4><ol class="auth-savings-timeline">';
    foreach($model['events'] as $event){
        [, $tone]=authorization_badge($event['action']);
        echo '<li class="auth-savings-event auth-tone-border-'.$tone.'"><header><h5>'.authorization_escape($event['label']).'</h5><time>'.authorization_escape($event['time']).'</time></header>';
        echo '<div class="auth-savings-actor"><strong>'.authorization_escape($event['name']).'</strong><span>'.authorization_escape(authorization_role_label($event['role'])).'</span></div>';
        if($event['proposed'])echo '<span class="auth-status auth-tone-warning">Usulan</span>';
        if($event['reconstructed'])echo '<span class="auth-status auth-tone-muted">Snapshot rekonstruksi</span>';
        if(trim((string)$event['note'])!=='')echo '<div class="auth-savings-reason"><small>Alasan / catatan</small><p>'.nl2br(authorization_escape($event['note'])).'</p></div>';
        authorization_render_savings_changes($event['changes'],$event['proposed']);echo '</li>';
    }
    echo '</ol></div>';
}

/** Presentation only: snapshots remain untouched. */
function authorization_render_savings_changes(array $changes,bool $proposed):void {
    if(!$changes){echo '<p class="auth-muted">Rincian perubahan tidak tercatat.</p>';return;}
    echo '<div class="auth-savings-changes">';
    foreach($changes as $change){
        $values=[];foreach(['before','after'] as $side){$value=$change[$side]??'';
            if($value===null||trim((string)$value)==='')$value='Tidak tercatat';
            elseif($change['label']==='Tanggal/waktu WIB')$value=spp_date_label((string)$value,true);
            $values[$side]=authorization_escape($value);
        }
        echo '<section><h6>'.authorization_escape($change['label']).'</h6><div class="auth-savings-comparison"><div><small>Sebelum</small><span>'.$values['before'].'</span></div><div><small>'.($proposed?'Usulan':'Sesudah').'</small><span>'.$values['after'].'</span></div></div></section>';
    }
    echo '</div>';
}
