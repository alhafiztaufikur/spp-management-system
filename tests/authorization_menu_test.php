<?php
/** Read-only SQL fixtures: no school records are inserted or modified. */
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/authorization_presentation.php';
$checks=0;
function menu_check(bool $ok,string $message): void {
    global $checks;
    if(!$ok)throw new RuntimeException($message);
    $checks++;
}
foreach(['all','pembayaran','daftar_ulang','tabungan'] as $menu)menu_check(authorization_menu($menu)===$menu,'Valid menu');
foreach([null,[],1,'tabungan_masuk','invalid'] as $menu){
    try{authorization_menu($menu);throw new RuntimeException('Invalid menu accepted');}
    catch(InvalidArgumentException $expected){$checks++;}
}
$cases=[
    [1,'pembayaran',1,1,['details'=>['bayar_du'=>[]]],null,null,'pembayaran'],
    [1,'pembayaran',2,2,['details'=>['bayar_du'=>[['jumlah'=>50000]]]],null,null,'daftar_ulang'],
    // A later event without allocations still belongs to the same DU chronology.
    [1,'pembayaran',2,3,null,null,null,'daftar_ulang'],
    // Same payment number in another unit must not inherit DU membership.
    [2,'pembayaran',2,4,['details'=>['bayar_du'=>[]]],null,null,'pembayaran'],
    [1,'pembayaran',3,5,null,['details'=>['bayar_du'=>[['jumlah'=>1]]]],null,'daftar_ulang'],
    [1,'pembayaran',4,6,['details'=>['bayar_du'=>[]]],null,['uang_du'=>'100.000'],'daftar_ulang'],
    [1,'pembayaran',5,7,null,null,['uang_du'=>'0'],'pembayaran'],
    [1,'tabungan_masuk',2,8,null,null,null,'tabungan'],
    [1,'tabungan_keluar',2,9,null,null,null,'tabungan'],
];
$events=[];$requests=[];$params=[];$types='';
foreach($cases as [$unit,$source,$payment,$id,$before,$after,$payload,$expected]){
    $events[]='SELECT ? unit_id,? source,? payment_id,? id,? before_snapshot,? after_snapshot,? authorization_id';
    array_push($params,$unit,$source,$payment,$id,json_encode($before),json_encode($after),$id);$types.='isiissi';
}
foreach($cases as [$unit,$source,$payment,$id,$before,$after,$payload,$expected]){
    $requests[]='SELECT ? id,? proposed_payload';array_push($params,$id,json_encode($payload));$types.='is';
}
$sql='WITH raw_events AS ('.implode(' UNION ALL ',$events).'), requests AS ('.implode(' UNION ALL ',$requests).') SELECT e.id,'.authorization_menu_sql().' menu FROM raw_events e LEFT JOIN requests r ON r.id=e.authorization_id ORDER BY e.id';
$stmt=$koneksi->prepare($sql);$stmt->bind_param($types,...$params);$stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
foreach($rows as $index=>$row)menu_check($row['menu']===$cases[$index][7],'Wrong category at fixture '.$row['id']);
// Exercise the real union, source filters, queue/history and empty pagination without writes.
$_SESSION=['admin_role'=>'super_admin','active_unit_id'=>0];unit_set_context($koneksi,0);
$koneksi->begin_transaction(MYSQLI_TRANS_START_READ_ONLY);
try{
    foreach([false,true] as $history){
        $statuses=$history?['*']:['pending'];$totals=0;
        foreach(['pembayaran','daftar_ulang','tabungan'] as $menu){
            $page=authorization_combined_page($koneksi,$history,'all','all','',1,$statuses,$menu);$totals+=$page['total'];
            foreach($page['rows'] as $row)menu_check($row['menu']===$menu,'Cross-menu record');
        }
        $all=authorization_combined_page($koneksi,$history,'all','all','',1,$statuses);
        menu_check($totals===$all['total'],'Categories overlap or omit records');
        $empty=authorization_combined_page($koneksi,$history,'tabungan_masuk','all','',1,$statuses,'pembayaran');
        menu_check($empty['total']===0&&$empty['page']===1,'Incompatible source/category');
    }
}finally{$koneksi->rollback();}
echo "PASS $checks read-only category checks.\n";
