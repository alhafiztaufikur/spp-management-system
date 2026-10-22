<?php
/** One-cent cache invalidation fixture; exclusively the owned, post-cutover QA payment. */
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
if((int)$koneksi->query('SELECT @@port')->fetch_row()[0]!==58470||!str_contains($koneksi->query('SELECT @@datadir')->fetch_row()[0],'global_audit_20261021'))throw new RuntimeException('Owned clone required.');
$qa=realpath((string)getenv('SPP_GLOBAL_QA_DIR'));if(!$qa||str_starts_with(strtolower($qa),strtolower(realpath(__DIR__.'/..'))))throw new RuntimeException('Private fixture required.');
$f=json_decode(file_get_contents($qa.'/fixture.json'),true);$p=$f['native'][1];$id=(int)$p['ids'][0];unit_set_context($koneksi,1);
$change=($argv[1]??'')==='change';if(!$change&&($argv[1]??'')!=='restore')throw new RuntimeException('Explicit fixture action required.');
[$spp,$total,$oldSpp,$oldTotal]=$change?['20000.21','44001.70','20000.20','44001.69']:['20000.20','44001.69','20000.21','44001.70'];
$s=$koneksi->prepare('UPDATE bayar SET U_SPP=?,total_jumlah=? WHERE id=? AND unit_id=1 AND NO_INDUK=? AND U_SPP=? AND total_jumlah=?');
$s->bind_param('ssisss',$spp,$total,$id,$p['nis'],$oldSpp,$oldTotal);$s->execute();if($change&&$s->affected_rows!==1)throw new RuntimeException('QA payment baseline differs.');$s->close();
echo "Owned cache fixture updated.\n";
