<?php
/** Read-only UI navigation regression against an explicitly isolated database. */
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/../koneksi.php';require __DIR__.'/http_form_scope.php';
spp_test_assert_database_target($koneksi);
require __DIR__.'/../includes/legacy_full.php';
$base=getenv('SPP_TEST_BASE_URL');spp_test_assert_http_clone($base,DB_NAME);
$actor=lf_one($koneksi,"SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1");
$sessions=[];
foreach([0,1,2,3] as $unit){$sid='legacyui'.bin2hex(random_bytes(16));session_id($sid);session_start();$_SESSION=['admin_id'=>(int)$actor['id'],'admin_role'=>'super_admin','active_unit_id'=>$unit];session_write_close();$sessions[$unit]=$sid;}
function lui_get(int $unit,string $route,int $expected=200): string {
    global $base,$sessions;$curl=curl_init($base.'/'.$route);
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIE=>'PHPSESSID='.$sessions[$unit],CURLOPT_TIMEOUT=>60]);
    $body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
    if($status!==$expected||preg_match('/Fatal error|Warning:|Notice:/',$body))throw new RuntimeException('UI request failed: '.$route.' HTTP '.$status);
    return $body;
}
function lui_assert(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
$body=lui_get(1,'master_kelas.php');$dom=new DOMDocument();@$dom->loadHTML($body);$xp=new DOMXPath($dom);
lui_assert($xp->query('//*[@id="legacy-groups"]/ancestor::*[contains(concat(" ",normalize-space(@class)," ")," class-workspace-content ")]')->length===1,'Legacy groups escaped the master workspace');
lui_assert($xp->query('//*[@id="legacy-groups"]//tbody/tr')->length<=25,'Group pagination missing');
$groups=lf_rows($koneksi,"SELECT DISTINCT g.*,o.batch_id FROM legacy_op_groups g JOIN legacy_op_owners o ON o.group_id=g.id AND o.unit_id=g.unit_id JOIN legacy_full_batches b ON b.id=o.batch_id AND b.unit_id=o.unit_id WHERE b.state='completed' ORDER BY g.id",[]);
lui_assert((bool)$groups,'UI fixture must have published Legacy groups');$g=$groups[0];
$owner=lf_one($koneksi,'SELECT * FROM legacy_op_owners WHERE group_id=? AND batch_id=? ORDER BY student_id IS NULL,id LIMIT 1',[$g['id'],$g['batch_id']]);
$ctx=['batch'=>$g['batch_id'],'group'=>$g['id'],'lg_q'=>$g['label'],'lg_kind'=>$g['kind'],'lg_page'=>'1'];
$body=lui_get(1,'legacy_accounts.php?'.http_build_query($ctx));
$dom=new DOMDocument();@$dom->loadHTML($body);$xp=new DOMXPath($dom);
$count=(int)lf_one($koneksi,'SELECT COUNT(*) n FROM legacy_op_owners WHERE group_id=? AND batch_id=?',[$g['id'],$g['batch_id']])['n'];
lui_assert($xp->query('//table[contains(@class,"legacy-directory-table")]/tbody/tr')->length===min(25,$count),'Account group filter returned wrong identities');
lui_assert(str_contains($body,'lg_q=')&&str_contains($body,'#legacy-groups'),'Back navigation lost the group context');
lui_get(1,'legacy_accounts.php?'.http_build_query($ctx+['owner'=>$owner['id']]));
lui_get(1,'legacy_accounts.php?'.http_build_query(array_replace($ctx,['group'=>2147483647])),404);
lui_get(3,'legacy_accounts.php?'.http_build_query($ctx),404);
$empty=lui_get(1,'master_kelas.php?lg_q=does-not-exist-9375');lui_assert(str_contains($empty,'Kelompok tidak ditemukan'),'No search empty state');
lui_get(1,'master_kelas.php?lg_page=999999&lg_kind=alumni');
// Exercise a real second page, using only temporary identities in the isolated fixture.
$seed='UIQA'.bin2hex(random_bytes(5));$seedGroups=[];
try{
    for($i=1;$i<=26;$i++){
        $code=$seed.str_pad((string)$i,2,'0',STR_PAD_LEFT);
        lf_query($koneksi,"INSERT INTO legacy_op_groups(unit_id,code,label,kind,aliases) VALUES(1,?,?,'legacy',?)",[$code,$code,json_encode([$code])]);$gid=(int)$koneksi->insert_id;$seedGroups[]=$gid;
        lf_query($koneksi,"INSERT INTO legacy_op_owners(unit_id,batch_id,source_identity,name,kind,group_id,source_class,state) VALUES(1,?,?,?,'legacy',?,?,'held')",[$g['batch_id'],$code,$code,$gid,$code]);
    }
    $page1=lui_get(1,'master_kelas.php?'.http_build_query(['lg_q'=>$seed,'lg_page'=>1]));
    $page2=lui_get(1,'master_kelas.php?'.http_build_query(['lg_q'=>$seed,'lg_page'=>2]));
    foreach([[$page1,25],[$page2,1]] as [$html,$expected]){$d=new DOMDocument();@$d->loadHTML($html);$x=new DOMXPath($d);lui_assert($x->query('//*[@id="legacy-groups"]//tbody/tr')->length===$expected,'25-row pagination lost identities');}
    lui_assert(str_contains($page2,'lg_q='.$seed),'Pagination lost search');
}finally{
    foreach($seedGroups as $gid){lf_query($koneksi,'DELETE FROM legacy_op_owners WHERE group_id=?',[$gid]);lf_query($koneksi,'DELETE FROM legacy_op_groups WHERE id=?',[$gid]);}
}
$all=lui_get(0,'master_kelas.php');lui_assert(str_contains($all,'Kelompok dari Legacy'),'All-unit read directory missing');
$p=lf_one($koneksi,'SELECT id,owner_id FROM legacy_op_payments WHERE batch_id=? LIMIT 1',[$g['batch_id']]);
$t=lf_one($koneksi,'SELECT t.student_id FROM legacy_op_tariffs t JOIN legacy_op_owners o ON o.id=t.owner_id AND o.unit_id=t.unit_id WHERE o.batch_id=? LIMIT 1',[$g['batch_id']]);
$routes=['master_kelas.php','legacy_accounts.php?'.http_build_query($ctx+['owner'=>$owner['id']]),'legacy_import.php?mode=students','legacy_import.php?mode=finance','legacy_finance.php','legacy_operational_review.php?id='.$g['batch_id'].'&section=confirm','legacy_history.php?id='.$g['batch_id']];
if($p)$routes[]='legacy_correction.php?kind=payment&id='.$p['id'];
if($t)$routes[]='legacy_tariff.php?student='.$t['student_id'];
foreach($routes as $route)lui_get(1,$route);
$fixture=['base'=>$base,'sessions'=>$sessions,'job'=>$g['batch_id'],'routes'=>$routes,'group'=>$g['id']];
file_put_contents(dirname(getenv('SPP_TEST_ISOLATED_INSTANCE')).'/legacy-workspace-fixture.json',json_encode($fixture));
echo "PASS: workspace containment, bounded group directory, exact owner filter, context return, empty states, read-only all units and cross-unit rejection.\n";
