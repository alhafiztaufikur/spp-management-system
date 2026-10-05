<?php
if(PHP_SAPI!=='cli'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1')exit(1);
require_once __DIR__.'/../includes/units.php';
require_once __DIR__.'/../includes/financial_components_schema.php';
$dump=(string)getenv('SPP_COMPONENT_TEST_DUMP');$hash=strtolower(trim((string)getenv('SPP_COMPONENT_TEST_DUMP_HASH')));
if(!is_file($dump)||!hash_equals($hash,hash_file('sha256',$dump)))throw new RuntimeException('Verified external backup required');
$name='db_spp_audit_component_migration_'.bin2hex(random_bytes(5));$db=new mysqli('localhost','root','');$db->query("CREATE DATABASE $name CHARACTER SET utf8mb4");
function cm_assert(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);}
function cm_hash(mysqli $db,string $table):string{$rows=$db->query("SELECT * FROM $table")->fetch_all(MYSQLI_ASSOC);$strings=array_map(fn($r)=>json_encode($r),$rows);sort($strings);return hash('sha256',implode("\n",$strings));}
try {
 $sql=str_replace(chr(96).'db_spp'.chr(96),chr(96).$name.chr(96),file_get_contents($dump));$file=dirname($dump).'/'.$name.'.sql';file_put_contents($file,$sql);
 $p=proc_open(['C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe','--user=root',$name],[0=>['file',$file,'r'],1=>['file',$file.'.log','w'],2=>['file',$file.'.error','w']],$pipes);cm_assert(proc_close($p)===0,'Restore failed');
 $db->select_db($name);$db->set_charset('utf8mb4');unit_set_context($db,1);
 $legacy=(int)$db->query("SELECT id FROM siswa_data WHERE unit_id=1 AND legacy_pending=1 LIMIT 1")->fetch_row()[0];
 $db->query("UPDATE siswa_data SET potongan_spp_persen=10 WHERE id=$legacy");
 $before=cm_hash($db,'bayar_data');$failed=false;try{financial_components_preflight($db);}catch(RuntimeException $e){$failed=true;}
 cm_assert($failed&&!components_has_column($db,'siswa_data','potongan_spp_nominal')&&$before===cm_hash($db,'bayar_data'),'Unproven base changed schema/data');
 $db->query("UPDATE siswa_data SET potongan_spp_persen=0 WHERE id=$legacy");
 $student=$db->query("SELECT * FROM siswa_data WHERE unit_id=1 AND KELAS='1' AND legacy_pending=0 LIMIT 1")->fetch_assoc();$id=(int)$student['id'];
 $db->query("UPDATE siswa_data SET potongan_spp_persen=10 WHERE id=$id");
 $expected=components_student_discount($db,$db->query("SELECT * FROM siswa_data WHERE id=$id")->fetch_assoc());
 $cashBefore=$db->query('SELECT COUNT(*),SUM(total_jumlah) FROM bayar_data')->fetch_row();$paymentsBefore=cm_hash($db,'bayar_data');$savings=[];foreach(['tabungan_data','transaksi_m_data','transaksi_k_data','spp_alokasi_data'] as $t)$savings[$t]=cm_hash($db,$t);
 putenv('SPP_TEST_COMPONENT_FAIL_STAGE=during-data');$failed=false;try{financial_components_apply($db);}catch(RuntimeException $e){$failed=true;}
 cm_assert($failed&&cm_hash($db,'bayar_data')===$paymentsBefore,'Partial payment data escaped rollback');
 cm_assert((int)$db->query('SELECT COUNT(*) FROM financial_component_migration_row')->fetch_row()[0]===0,'Partial audit rows escaped rollback');
 putenv('SPP_TEST_COMPONENT_FAIL_STAGE=after-data');$failed=false;try{financial_components_apply($db);}catch(RuntimeException $e){$failed=true;}
 cm_assert($failed&&$db->query('SELECT stage FROM financial_component_migration')->fetch_row()[0]==='data_done','DDL checkpoint not recorded');
 $cashAfterData=$db->query('SELECT COUNT(*),SUM(total_jumlah) FROM bayar_data')->fetch_row();cm_assert($cashAfterData===$cashBefore,'Receipts changed');
 putenv('SPP_TEST_COMPONENT_FAIL_STAGE');
 financial_components_apply($db);cm_assert(financial_components_ready($db),'Final schema');
 $amount=(float)$db->query("SELECT potongan_spp_nominal FROM siswa_data WHERE id=$id")->fetch_row()[0];cm_assert(abs($amount-$expected)<.001,'Historical percent conversion incorrect');
 foreach($savings as $t=>$h)cm_assert(cm_hash($db,$t)===$h,'Untouched finance changed: '.$t);
 $beforeReplay=[];foreach(['bayar_data','siswa_data','tagihan_spp_data','financial_component_migration_row','financial_component_migration'] as $t)$beforeReplay[$t]=cm_hash($db,$t);
 financial_components_apply($db);foreach($beforeReplay as $t=>$h)cm_assert(cm_hash($db,$t)===$h,'Replay mutated: '.$t);
 echo "PASS: verified restore, missing tariff preflight, nonzero conversion, atomic rollback, DDL checkpoint/recovery, repeat and financial invariants\n";
}finally{putenv('SPP_TEST_COMPONENT_FAIL_STAGE');$db->query("DROP DATABASE $name");}

