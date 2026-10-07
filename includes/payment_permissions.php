<?php
require_once __DIR__.'/payment_activity.php';

/** Ownership is immutable: the first creation event, never the mutable payment operator. */
function payment_capabilities(mysqli $db, array $payment, ?array $events = null, bool $deleted = false, ?array $pending = null): array
{
    $id=(int)($payment['id']??0);
    $events ??= payment_activity_for_payments($db,[$id])[$id]??[];
    $creator=payment_activity_summary($events)['creator'];
    $owner=(int)($creator['actor_id']??0);
    $role=$_SESSION['admin_role']??'';
    $super=$role==='super_admin';
    $scope=unit_active_id(); $unit=(int)($payment['unit_id']??$scope);
    $inScope=$unit>=1 && $unit<=3 && ($scope===$unit || ($scope===0 && $super));
    $allowed=$inScope && ($super || ($owner>0 && $owner===(int)($_SESSION['admin_id']??0)));
    $pending ??= function_exists('transaction_authorization_schema_ready') && transaction_authorization_schema_ready($db) ? (transaction_authorization_pending_for_payments($db,[$id])[$id]??[]) : [];
    $mutable=$allowed && !$deleted && $scope!==0 && in_array($role,['super_admin','admin','kasir'],true)
        && (int)($payment['payment_link_version']??0)===1 && !$pending;
    $reason='';
    if (!$allowed) $reason=$owner>0 ? 'Transaksi milik '.(($creator['actor_name']??'')?:'akun lain').'. Hanya detail dan riwayat aktivitas yang dapat dilihat.' : 'Pemilik tidak tercatat. Hanya Super Admin yang dapat melakukan tindakan.';
    elseif($deleted) $reason='Transaksi dihapus. Arsip hanya untuk dilihat.';
    elseif($scope===0) $reason='Pilih SD, SMP, atau SMA sebelum melakukan perubahan.';
    elseif($pending) $reason='Pengajuan perubahan masih menunggu keputusan Super Admin.';
    elseif((int)($payment['payment_link_version']??0)!==1) $reason='Transaksi lama memerlukan rekonsiliasi sebelum dapat diubah.';
    elseif($role==='bendahara') $reason='Bendahara dapat melihat dan mencetak, tanpa edit atau hapus.';
    return ['owner_id'=>$owner?:null,'owner_name'=>($creator['actor_name']??'')?:'Tidak tercatat',
        'can_edit'=>$mutable,'can_delete'=>$mutable,'can_print'=>$allowed&&!$deleted&&in_array($role,['super_admin','admin','kasir','bendahara'],true),
        'can_read_activity'=>$inScope,'locked'=>!$allowed,'reason'=>$reason];
}

function payment_assert_owner(mysqli $db, int $id, int $actorId): void
{
    if ($actorId<=0 || $actorId!==(int)($_SESSION['admin_id']??0)) throw new RuntimeException('Identitas operator tidak valid.');
    $s=$db->prepare('SELECT role,is_active,unit_id FROM admin WHERE id=?');$s->bind_param('i',$actorId);$s->execute();$actor=$s->get_result()->fetch_assoc();$s->close();
    if(!$actor || !(int)$actor['is_active'] || $actor['role']!==($_SESSION['admin_role']??'')) throw new RuntimeException('Akun operator tidak aktif atau perannya tidak sesuai.');
    if($actor['role']!=='super_admin' && (int)$actor['unit_id']!==unit_active_id()) throw new RuntimeException('Unit operator tidak sesuai.');
    $s=$db->prepare('SELECT id,unit_id FROM bayar WHERE id=?');$s->bind_param('i',$id);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
    if(!$row) throw new RuntimeException('Transaksi tidak ditemukan pada unit ini.');
    if(unit_active_id()!==0 && (int)$row['unit_id']!==unit_active_id()) throw new RuntimeException('Transaksi tidak ditemukan pada unit ini.');
    $creator=payment_activity_summary(payment_activity_for_payments($db,[$id])[$id]??[])['creator'];
    if(($_SESSION['admin_role']??'')!=='super_admin' && (!(int)($creator['actor_id']??0) || (int)$creator['actor_id']!==$actorId))
        throw new RuntimeException('Transaksi terkunci: hanya pembuat awal yang dapat melakukan tindakan ini.');
}

function payment_can_print_batch(mysqli $db, string $token): bool
{
    if(!preg_match('/^[a-f0-9]{32}$/D',$token)) return false;
    $s=$db->prepare('SELECT * FROM bayar WHERE payment_batch_token=? ORDER BY payment_batch_sequence,id');$s->bind_param('s',$token);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    if(!$rows) return false;
    $groups=payment_activity_for_payments($db,array_column($rows,'id'));
    foreach($rows as $row) if(!payment_capabilities($db,$row,$groups[(int)$row['id']]??[],false,[])['can_print']) return false;
    return true;
}
