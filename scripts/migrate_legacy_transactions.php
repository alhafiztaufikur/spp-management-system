<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$apply=in_array('--apply',$argv,true);$expect=null;$backup=null;
foreach(array_slice($argv,1) as $arg){if(preg_match('/^--expect-db=([A-Za-z0-9_]+)$/D',$arg,$m))$expect=$m[1];elseif(str_starts_with($arg,'--backup='))$backup=substr($arg,9);elseif(!in_array($arg,['--check','--apply'],true))exit("Opsi tidak valid. --check|--apply --expect-db=... --backup=...\n");}
require __DIR__.'/../koneksi.php';
try {
    $db=$koneksi;$actual=$db->query('SELECT DATABASE()')->fetch_row()[0];if(!$expect||$expect!==$actual)throw new RuntimeException('Target tidak sesuai --expect-db.');
    $has=fn($t,$c)=>(bool)$db->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t' AND COLUMN_NAME='$c'")->num_rows;
    $changes=[];if(!$has('transaksi_otorisasi_data','transaction_source'))$changes[]="ALTER TABLE transaksi_otorisasi_data ADD transaction_source VARCHAR(20) NOT NULL DEFAULT 'system', ADD legacy_payment_id BIGINT NULL, ADD KEY lt_payment_status(unit_id,legacy_payment_id,status), ADD CONSTRAINT lt_authorization_source CHECK(transaction_source IN ('system','legacy_op') AND ((transaction_source='system' AND legacy_payment_id IS NULL) OR (transaction_source='legacy_op' AND legacy_payment_id>0)))";
    foreach(['legacy_op_owners_data'=>'lt_owner_search(unit_id,name(100),id)','legacy_op_payments_data'=>'lt_du_source(unit_id,source_table,owner_id,source_period)'] as $table=>$index){$name=strtok($index,'(');if($db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table'")->num_rows&&!$db->query("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table' AND INDEX_NAME='$name'")->num_rows)$changes[]="ALTER TABLE $table ADD KEY $index";}
    if($has('transaksi_otorisasi_data','transaction_source')&&!$db->query("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='transaksi_otorisasi_data' AND CONSTRAINT_NAME='lt_authorization_source'")->num_rows)$changes[]="ALTER TABLE transaksi_otorisasi_data ADD CONSTRAINT lt_authorization_source CHECK(transaction_source IN ('system','legacy_op') AND ((transaction_source='system' AND legacy_payment_id IS NULL) OR (transaction_source='legacy_op' AND legacy_payment_id>0)))";
    echo json_encode(['database'=>$actual,'planned'=>$changes,'apply'=>$apply],JSON_PRETTY_PRINT)."\n";
    if($apply){if(!$backup||!is_file($backup)||filesize($backup)<1000)throw new RuntimeException('Backup SQL terverifikasi diperlukan.');
        if((int)$db->query("SELECT GET_LOCK('spp_legacy_transactions_schema',0)")->fetch_row()[0]!==1)throw new RuntimeException('Pemasangan lain berjalan.');
        try{foreach($changes as $sql)$db->query($sql);
            // MySQL views created with SELECT * freeze their columns until refreshed.
            $db->query('CREATE OR REPLACE VIEW transaksi_otorisasi AS SELECT * FROM transaksi_otorisasi_data WHERE current_unit_id()=0 OR unit_id=current_unit_id() WITH CASCADED CHECK OPTION');
        }finally{$db->query("SELECT RELEASE_LOCK('spp_legacy_transactions_schema')");}
        echo "Metadata terpasang. Tidak ada transaksi diubah. Backup SHA256: ".hash_file('sha256',$backup)."\n";
    }
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
