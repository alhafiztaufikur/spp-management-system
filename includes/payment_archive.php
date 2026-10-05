<?php

/** Archive filters use deletion time and stored identity, never require a live student row. */
function payment_archive_page(mysqli $db, string $start, string $end, string $search, int $studentId, int $page, int $perPage): array
{
    if (!payment_activity_ready($db)) return ['rows'=>[],'total'=>0,'page'=>1,'pages'=>1,'offset'=>0];
    $where="a.action='deleted' AND a.occurred_at>=? AND a.occurred_at<?";
    $params=[$start.' 00:00:00',date('Y-m-d H:i:s',strtotime($end.' +1 day'))]; $types='ss';
    if ($search!=='') {
        $where.=" AND (JSON_UNQUOTE(JSON_EXTRACT(a.before_snapshot,'$.payment.NAMA')) LIKE ?
            OR JSON_UNQUOTE(JSON_EXTRACT(a.before_snapshot,'$.payment.NO_INDUK')) LIKE ?
            OR JSON_UNQUOTE(JSON_EXTRACT(a.before_snapshot,'$.payment.NO_induk_diknas')) LIKE ?)";
        $like='%'.$search.'%'; array_push($params,$like,$like,$like); $types.='sss';
    }
    if($studentId>0) {
        $s=$db->prepare('SELECT NO_INDUK,unit_id FROM siswa WHERE id=?');$s->bind_param('i',$studentId);$s->execute();$student=$s->get_result()->fetch_assoc();$s->close();
        if(!$student)return ['rows'=>[],'total'=>0,'page'=>1,'pages'=>1,'offset'=>0];
        $where.=" AND a.unit_id=? AND JSON_UNQUOTE(JSON_EXTRACT(a.before_snapshot,'$.payment.NO_INDUK'))=?";
        array_push($params,(int)$student['unit_id'],$student['NO_INDUK']);$types.='is';
    }
    // Only one deletion per transaction is shown, even if multiple old requests exist.
    $where.=" AND NOT EXISTS (SELECT 1 FROM pembayaran_aktivitas newer WHERE newer.action='deleted'
        AND newer.unit_id=a.unit_id AND newer.payment_id=a.payment_id AND newer.id>a.id)";
    $s=$db->prepare('SELECT COUNT(*) FROM pembayaran_aktivitas a WHERE '.$where);
    $s->bind_param($types,...$params);$s->execute();$total=(int)$s->get_result()->fetch_row()[0];$s->close();
    $pages=total_pages($total,$perPage);$page=min($page,$pages);$offset=($page-1)*$perPage;
    $s=$db->prepare('SELECT a.* FROM pembayaran_aktivitas a WHERE '.$where.' ORDER BY a.occurred_at DESC,a.id DESC LIMIT ? OFFSET ?');
    $paged=array_merge($params,[$perPage,$offset]);$pagedTypes=$types.'ii';$s->bind_param($pagedTypes,...$paged);$s->execute();$rows=[];
    foreach($s->get_result()->fetch_all(MYSQLI_ASSOC) as $event) {
        $snap=json_decode($event['before_snapshot'],true);$p=$snap['payment']??[];if(!$p)continue;
        $p['id']=(int)$event['payment_id'];$p['unit_id']=(int)$event['unit_id'];
        $p['kelas_transaksi']=$p['kelas_rombel_snapshot']?:($p['KELAS']??'');
        $p['NAMA']=$p['NAMA']??'Siswa tidak tercatat';$p['_deleted_event']=$event;$rows[]=$p;
    }
    $s->close();return compact('rows','total','page','pages','offset');
}

function payment_operator_html(array $summary): string
{
    $e=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');
    $creator=$summary['creator']??null; $last=$summary['last']??null;
    $html='<div class="payment-operator-summary"><div><span>Pembuat transaksi</span><strong>'.$e($creator['actor_name']??'Tidak tercatat').'</strong>';
    if(!empty($creator['actor_username']))$html.='<small>@'.$e($creator['actor_username']).'</small>';
    $html.='</div><div><span>Aktivitas terakhir</span><strong>'.$e(($last['actor_name']??'')?:'Tidak tercatat').'</strong>';
    if($last)$html.='<small>'.$e(payment_activity_labels()[$last['action']]??$last['action']).'</small><small>'.$e(spp_date_label($last['occurred_at'],true)).'</small>';
    return $html.'</div></div>';
}
