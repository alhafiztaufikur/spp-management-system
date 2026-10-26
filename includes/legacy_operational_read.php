<?php
require_once __DIR__.'/legacy_component_semantics.php';
require_once __DIR__.'/legacy_operational_schema.php';
function lop_read_one(mysqli $db,string $sql,array $values): ?array {$s=$db->prepare($sql);$s->bind_param(str_repeat('s',count($values)),...$values);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();return $row;}
/** Request-local batch for Master SPP's read-only preview; writers query live rows. */
function lop_preview_tariffs(mysqli $db): void {
    if(($_SERVER['REQUEST_METHOD']??'')!=='GET'||!lop_ready($db))return;
    require_once __DIR__.'/history_performance.php';
    $key=$db->thread_id.':'.(int)($GLOBALS['app_unit_id']??unit_active_id());
    $GLOBALS['lop_preview_tariffs'][$key]=array_column(hp_rows($db,"SELECT s.NO_INDUK,t.amount FROM legacy_op_tariffs t JOIN siswa s ON s.id=t.student_id AND s.unit_id=t.unit_id WHERE t.basis='legacy'"),'amount','NO_INDUK');
}
function lop_tariff(mysqli $db,string $nis,?string $year=null): ?float {
    require_once __DIR__.'/legacy_billing.php';if(lb_spp_override($db,$nis,$year??(function_exists('du_current_academic_year')?du_current_academic_year():((int)date('Y')-((int)date('n')<7?1:0)).'/'.((int)date('Y')+((int)date('n')<7?0:1)))))return null;
    if(!lop_ready($db))return null;
    $student=lb_student($db,$nis);
    if($year!==null&&$student&&$year!==$student['migration_year']){
        $rates=[];foreach(lb_monthly_plan($db,$nis)['positions'] as $position)if($position['category']==='spp'&&$position['year']===$year)$rates[$position['gross']]=true;
        return count($rates)===1?(int)array_key_first($rates)/100:null;
    }
    $key=$db->thread_id.':'.(int)($GLOBALS['app_unit_id']??unit_active_id());
    if(($_SERVER['REQUEST_METHOD']??'')==='GET'&&isset($GLOBALS['lop_preview_tariffs'][$key]))return isset($GLOBALS['lop_preview_tariffs'][$key][$nis])?(float)$GLOBALS['lop_preview_tariffs'][$key][$nis]:null;
    $r=lop_read_one($db,"SELECT t.amount FROM legacy_op_tariffs t JOIN siswa s ON s.id=t.student_id AND s.unit_id=t.unit_id WHERE s.NO_INDUK=? AND t.basis='legacy'",[$nis]);return $r?(float)$r['amount']:null;
}
function lop_opening(mysqli $db,int $student,int $unit): ?array {
    if(!lop_ready($db))return null;return lop_read_one($db,'SELECT a.opening amount,a.effective_date FROM legacy_op_accounts a JOIN legacy_op_owners o ON o.id=a.owner_id AND o.unit_id=a.unit_id WHERE o.student_id=? AND o.unit_id=?',[$student,$unit]);
}
function lop_bank_assert_ready(mysqli $db,string $nis,int $unit=0): void {
    if(!lop_ready($db))return;$r=lop_read_one($db,"SELECT o.id,a.id account_id FROM legacy_op_owners o JOIN siswa s ON s.id=o.student_id AND s.unit_id=o.unit_id LEFT JOIN legacy_op_accounts a ON a.owner_id=o.id AND a.unit_id=o.unit_id WHERE s.NO_INDUK=? AND (?=0 OR s.unit_id=?) AND EXISTS(SELECT 1 FROM legacy_op_pending p WHERE p.unit_id=o.unit_id AND p.batch_id=o.batch_id AND p.source_identity=o.source_identity AND p.section='savings' AND p.resolved=0)",[$nis,$unit,$unit]);if($r&&!$r['account_id'])throw new DomainException('Tabungan Legacy rekening ini masih perlu diperiksa. Rekening lain tetap dapat digunakan.');
}
function lop_held_student_accounts(mysqli $db): array {
    if(!lop_ready($db))return [];$rows=$db->query("SELECT o.student_id,o.id owner_id,o.batch_id FROM legacy_op_owners o WHERE o.student_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM legacy_op_accounts a WHERE a.owner_id=o.id AND a.unit_id=o.unit_id) AND EXISTS(SELECT 1 FROM legacy_op_pending p WHERE p.batch_id=o.batch_id AND p.unit_id=o.unit_id AND p.source_identity=o.source_identity AND p.section='savings' AND p.resolved=0)")->fetch_all(MYSQLI_ASSOC);$result=[];foreach($rows as $r)$result[(int)$r['student_id']]=$r;return $result;
}
function lop_payment_select(mysqli $db,array $columns): string {
    require_once __DIR__.'/payment_time.php';$time=payment_time_projection($db);
    $mapping=['id'=>'-(1000000000+p.id)','unit_id'=>'p.unit_id','NO_INDUK'=>'s.NO_INDUK','KELAS'=>'p.class_snapshot','kelas_rombel_snapshot'=>'p.class_snapshot','TGL_BYR'=>$time['value'],'total_jumlah'=>'p.total','user_id'=>"CONCAT('legacy:',p.unit_id,':',LOWER(HEX(COALESCE(p.source_operator,''))))",'BULAN'=>"''",'TAHUN'=>"''",'KETERANGAN'=>"CONCAT('Migrasi Legacy · ',p.source_period,' · ',p.note)",'sistem_pembayaran'=>"'Legacy'",'created_at'=>'NULL','updated_at'=>$time['updated']];
    $select=[];foreach($columns as $c){$name=$c['COLUMN_NAME'];if(!preg_match('/^[a-zA-Z0-9_]+$/D',$name))throw new LogicException('Invalid column');$select[]=($mapping[$name]??'0').' AS `'.$name.'`';}
    // Source identities are display values, never substitute student foreign keys.
    foreach($columns as $n=>$c)if($c['COLUMN_NAME']==='NO_INDUK')$select[$n]='COALESCE(s.NO_INDUK,o.source_identity) AS `NO_INDUK`';
    return 'SELECT '.implode(',',$select)." FROM legacy_op_payments p JOIN legacy_op_owners o ON o.id=p.owner_id AND o.unit_id=p.unit_id LEFT JOIN siswa s ON s.id=o.student_id AND s.unit_id=o.unit_id JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id".$time['join']." WHERE b.state='completed' AND p.cancelled=0";
}
function lop_payment_model(mysqli $db,int $id): array {
    $p=lop_read_one($db,"SELECT p.*,o.student_id,o.name NAMA,o.source_identity NO_INDUK,s.NO_induk_diknas,b.cutoff,b.source_id,b.completed_at imported_at,src.backup_hash FROM legacy_op_payments p JOIN legacy_op_owners o ON o.id=p.owner_id AND o.unit_id=p.unit_id LEFT JOIN siswa s ON s.id=o.student_id AND s.unit_id=o.unit_id JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id JOIN legacy_finance_sources src ON src.id=b.source_id AND src.unit_id=b.unit_id WHERE p.id=? AND b.state='completed'",[$id]);if(!$p)throw new OutOfBoundsException('Transaksi migrasi tidak ditemukan pada unit ini.');
    require_once __DIR__.'/payment_time.php';require_once __DIR__.'/legacy_fee_trace_read.php';$p+=payment_time_record($db,$p);$p['items']=json_decode($p['items'],true,64,JSON_THROW_ON_ERROR);$p['operational']=true;$p=lfet_hydrate_receipts($db,[$p])[0];return ['legacy'=>$p];
}
