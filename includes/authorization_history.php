<?php
require_once __DIR__.'/payment_activity.php';
require_once __DIR__.'/filter_choices.php';

function authorization_history_source(): string
{
    return "SELECT a.unit_id,a.payment_id,a.id,a.action,a.actor_id,a.actor_name,a.actor_username,a.actor_role,
        a.occurred_at,a.authorization_id,a.note,a.before_snapshot,a.after_snapshot,a.reconstructed
        FROM pembayaran_aktivitas a WHERE a.action NOT IN ('created','baseline')
        UNION ALL
        SELECT r.unit_id,COALESCE(r.bayar_id,CAST(JSON_UNQUOTE(JSON_EXTRACT(r.before_snapshot,'$.payment.id')) AS UNSIGNED)),
        -r.id*2,IF(r.action='edit','request_edit','request_delete'),r.requested_by,
        COALESCE(u.nama,''),COALESCE(u.username,''),COALESCE(u.role,''),r.requested_at,r.id,r.request_reason,r.before_snapshot,NULL,1
        FROM transaksi_otorisasi r LEFT JOIN admin u ON u.id=r.requested_by
        WHERE NOT EXISTS(SELECT 1 FROM pembayaran_aktivitas a WHERE a.authorization_id=r.id AND a.action IN ('request_edit','request_delete'))
        UNION ALL
        SELECT r.unit_id,COALESCE(r.bayar_id,CAST(JSON_UNQUOTE(JSON_EXTRACT(r.before_snapshot,'$.payment.id')) AS UNSIGNED)),
        -r.id*2+1,r.status,r.decided_by,COALESCE(u.nama,''),COALESCE(u.username,''),COALESCE(u.role,''),
        r.decided_at,r.id,r.decision_note,r.before_snapshot,NULL,1
        FROM transaksi_otorisasi r LEFT JOIN admin u ON u.id=r.decided_by
        WHERE r.status<>'pending' AND r.decided_at IS NOT NULL
        AND NOT EXISTS(SELECT 1 FROM pembayaran_aktivitas a WHERE a.authorization_id=r.id AND a.action=r.status)";
}

function authorization_history_can_read(mysqli $db,int $id): bool
{
    if (!isRole('kasir')) return true;
    $actor=(int)$_SESSION['admin_id'];
    $s=$db->prepare("SELECT 1 FROM transaksi_otorisasi WHERE requested_by=? AND
        COALESCE(bayar_id,CAST(JSON_UNQUOTE(JSON_EXTRACT(before_snapshot,'$.payment.id')) AS UNSIGNED))=? LIMIT 1");
    $s->bind_param('ii',$actor,$id);$s->execute();$allowed=(bool)$s->get_result()->fetch_row();$s->close();return $allowed;
}

function authorization_history_page(mysqli $db,string $status,string $kind,string $search,int $page,array $statuses=['*']): array
{
    $where='e.payment_id>0';$params=[];$types='';
    if(isRole('kasir')) {
        $where.=" AND EXISTS(SELECT 1 FROM transaksi_otorisasi owned WHERE owned.requested_by=? AND owned.unit_id=e.unit_id
            AND COALESCE(owned.bayar_id,CAST(JSON_UNQUOTE(JSON_EXTRACT(owned.before_snapshot,'$.payment.id')) AS UNSIGNED))=e.payment_id)";
        $params[]=(int)$_SESSION['admin_id'];$types.='i';
    }
    if(!filter_is_all($statuses))$where.=filter_sql_values($statuses,'r.status');
    elseif($status!=='all'){$where.=' AND r.status=?';$params[]=$status;$types.='s';}
    if($kind==='edit')$where.=" AND e.action IN ('edited','request_edit','approved','rejected','cancelled','failed') AND (r.action='edit' OR r.id IS NULL)";
    if($kind==='hapus')$where.=" AND e.action IN ('deleted','request_delete','approved','rejected','cancelled','failed') AND (r.action='hapus' OR r.id IS NULL)";
    if($search!==''){
        $where.=" AND (CONCAT('TRX-',LPAD(e.payment_id,6,'0')) LIKE ? OR
        JSON_UNQUOTE(JSON_EXTRACT(COALESCE(e.after_snapshot,e.before_snapshot),'$.payment.NAMA')) LIKE ? OR
        JSON_UNQUOTE(JSON_EXTRACT(COALESCE(e.after_snapshot,e.before_snapshot),'$.payment.NO_INDUK')) LIKE ? OR e.actor_name LIKE ?)";
        $like='%'.$search.'%';array_push($params,$like,$like,$like,$like);$types.='ssss';
    }
    $cte='WITH events AS ('.authorization_history_source().'), matches AS (SELECT DISTINCT e.unit_id,e.payment_id FROM events e
        LEFT JOIN transaksi_otorisasi r ON r.id=e.authorization_id WHERE '.$where.'), ranked AS (
        SELECT e.*,ROW_NUMBER() OVER(PARTITION BY e.unit_id,e.payment_id ORDER BY e.occurred_at DESC,e.id DESC) rn
        FROM events e JOIN matches m ON m.unit_id=e.unit_id AND m.payment_id=e.payment_id) ';
    $s=$db->prepare($cte.'SELECT COUNT(*) FROM ranked WHERE rn=1');if($params)$s->bind_param($types,...$params);
    $s->execute();$total=(int)$s->get_result()->fetch_row()[0];$s->close();
    $pages=max(1,(int)ceil($total/25));$page=max(1,min($page,$pages));$offset=($page-1)*25;
    $s=$db->prepare($cte.'SELECT * FROM ranked WHERE rn=1 ORDER BY occurred_at DESC,id DESC LIMIT 25 OFFSET ?');
    $paged=array_merge($params,[$offset]);$bind=$types.'i';$s->bind_param($bind,...$paged);$s->execute();
    $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();return compact('rows','total','page','pages');
}

/** Read old evidence without inventing missing events or rewriting the journal. */
function authorization_history_events(mysqli $db,int $id): array
{
    $events=payment_activity_for_payments($db,[$id])[$id]??[];
    $s=$db->prepare("SELECT r.*,u.nama,u.username,u.role,v.nama reviewer_name,v.username reviewer_username,v.role reviewer_role
        FROM transaksi_otorisasi r LEFT JOIN admin u ON u.id=r.requested_by LEFT JOIN admin v ON v.id=r.decided_by
        WHERE COALESCE(r.bayar_id,CAST(JSON_UNQUOTE(JSON_EXTRACT(r.before_snapshot,'$.payment.id')) AS UNSIGNED))=?");
    $s->bind_param('i',$id);$s->execute();$requests=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    foreach($requests as $r){
        $requestAction=$r['action']==='edit'?'request_edit':'request_delete';
        foreach([[$requestAction,$r['requested_at'],$r['request_reason'],false],[$r['status'],$r['decided_at'],$r['decision_note'],true]] as [$action,$time,$note,$decision]){
            if(!$time || !isset(payment_activity_labels()[$action]))continue;
            $present=false;foreach($events as $e)if((int)($e['authorization_id']??0)===(int)$r['id'] && $e['action']===$action)$present=true;
            if(!$present)$events[]=['id'=>-$r['id'],'unit_id'=>$r['unit_id'],'action'=>$action,'occurred_at'=>$time,'authorization_id'=>$r['id'],
                'actor_name'=>($decision?$r['reviewer_name']:$r['nama'])??'','actor_username'=>($decision?$r['reviewer_username']:$r['username'])??'',
                'actor_role'=>($decision?$r['reviewer_role']:$r['role'])??'','note'=>$note,'before_snapshot'=>$r['before_snapshot'],'after_snapshot'=>null,'reconstructed'=>1];
        }
    }
    usort($events,static fn($a,$b)=>strcmp($a['occurred_at'],$b['occurred_at'])?:($a['id']<=>$b['id']));
    foreach($events as &$e){
        if(!in_array($e['action'],['request_edit','request_delete'],true))continue;
        foreach($requests as $r)if((int)$r['id']===(int)$e['authorization_id']){
            $e['_proposal']=json_decode($r['proposed_payload']??'null',true);break;
        }
    }unset($e);
    return $events;
}

function authorization_proposed_snapshot(array $before,array $payload): array
{
    $after=$before;
    $amount=static function($raw):float {
        $raw=trim((string)$raw);return is_numeric($raw)?(float)$raw:(float)str_replace(['.',','],['','.'],$raw);
    };
    foreach(['no_induk'=>'NO_INDUK','tanggal_bayar'=>'TGL_BYR','bulan_bayar'=>'BULAN','tahun_bayar'=>'TAHUN','sistem_pembayaran'=>'sistem_pembayaran','catatan'=>'KETERANGAN'] as $key=>$field)
        if(isset($payload[$key]))$after['payment'][$field]=$payload[$key];
    foreach(['uang_psb'=>'U_PSB','uang_spp'=>'U_SPP','uang_komite'=>'U_KOMITE','potongan_spp'=>'potong_spp'] as $key=>$field)
        if(isset($payload[$key]))$after['payment'][$field]=$amount($payload[$key]);
    $du=array_key_exists('uang_du',$payload)?$amount($payload['uang_du']):array_sum(array_column($before['details']['bayar_du']??[],'jumlah'));
    $other=0.0;$details=[];
    foreach($payload['biaya_lain_nominal']??[] as $i=>$raw){
        $nominal=$amount($raw);if($nominal<=0)continue;$other+=$nominal;
        $tag=(int)($payload['biaya_lain_tagihan_id'][$i]??0);$detail=(int)($payload['biaya_lain_detail_id'][$i]??0);$name='';
        foreach($before['details']['bayar_biaya_lain']??[] as $old)
            if(($tag>0&&(int)($old['tagihan_biaya_lain_id']??0)===$tag)||($detail>0&&(int)($old['id']??0)===$detail)){$name=$old['nama_biaya_snapshot']??'';break;}
        $row=['tagihan_biaya_lain_id'=>$tag,'jumlah'=>$nominal,'keterangan'=>$payload['biaya_lain_keterangan'][$i]??''];
        if($name!=='')$row['nama_biaya_snapshot']=$name;$details[]=$row;
    }
    if(array_key_exists('biaya_lain_nominal',$payload))$after['details']['bayar_biaya_lain']=$details;
    else $other=array_sum(array_map(static fn($r)=>(float)($r['jumlah']??$r['nominal']??0),$before['details']['bayar_biaya_lain']??[]));
    if(array_key_exists('uang_du',$payload))$after['details']['bayar_du']=$du>0?[['tagihan_daftar_ulang_id'=>(int)($payload['tagihan_daftar_ulang_id']??0),'jumlah'=>$du,'th_ajaran'=>$payload['tahun_ajaran_du']??'']]:[];
    if(array_key_exists('uang_du',$payload)||array_key_exists('biaya_lain_nominal',$payload))$after['payment']['U_LAIN']=$du+$other;
    if(array_key_exists('total_jumlah',$payload))$after['payment']['total_jumlah']=$amount($payload['total_jumlah']);
    elseif(array_intersect(['uang_psb','uang_spp','uang_komite','uang_du','biaya_lain_nominal','potongan_spp'],array_keys($payload)))
        $after['payment']['total_jumlah']=max(0,($after['payment']['U_PSB']??0)+($after['payment']['U_SPP']??0)+($after['payment']['U_KOMITE']??0)+($after['payment']['U_LAIN']??0)-($after['payment']['potong_spp']??0));
    return $after;
}
