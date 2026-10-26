<?php
/** Read-only master usage. Historical receipts never become native receipts. */
require_once __DIR__.'/legacy_finance.php';
require_once __DIR__.'/history_performance.php';

function other_fee_usage_tables(mysqli $db): array {
    static $cache=[];$key=hp_connection_key($db);
    if(isset($cache[$key]))return $cache[$key];
    $tables=array_fill_keys(array_column(lf_rows($db,'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()'),'TABLE_NAME'),true);
    foreach(['legacy_op_payments'=>['legacy_op_payments','legacy_full_batches','legacy_op_audit','legacy_op_obligations'],'legacy_full_items'=>['legacy_full_items','legacy_full_payments','legacy_full_batches','legacy_full_coverage']] as $marker=>$required){
        if(isset($tables[$marker])||isset($tables[$marker.'_data']))foreach($required as $table)if(!isset($tables[$table]))throw new RuntimeException('Pemakaian biaya Legacy belum dapat dibaca. Penghapusan master tidak diizinkan.');
    }
    return $cache[$key]=$tables;
}

function other_fee_category_master_sql(string $category): string {
    return "CASE WHEN $category REGEXP '^biaya_lain:[1-9][0-9]*$' THEN CAST(SUBSTRING($category,12) AS UNSIGNED) END";
}

/** One row per slot, positive native / negative archived receipt IDs, scoped by connection. */
function other_fee_items_sql(mysqli $db): string {
    $tables=other_fee_usage_tables($db);
    $parts=["SELECT d.unit_id,COALESCE(d.master_biaya_lain_id,t.master_biaya_lain_id) master_id,d.bayar_id payment_id,d.id detail_id,d.nominal_snapshot amount,'active' state,'system' source_kind FROM bayar_biaya_lain d JOIN bayar p ON p.id=d.bayar_id AND p.unit_id=d.unit_id LEFT JOIN tagihan_biaya_lain t ON t.id=d.tagihan_biaya_lain_id AND t.unit_id=d.unit_id"];
    if(isset($tables['legacy_full_items'])){
        $master=other_fee_category_master_sql('i.category');
        $parts[]="SELECT i.unit_id,$master,-i.payment_id,i.id,i.amount,IF(b.state='completed','active','pending'),'legacy' FROM legacy_full_items i JOIN legacy_full_payments p ON p.id=i.payment_id AND p.unit_id=i.unit_id JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id";
    }
    if(isset($tables['legacy_op_payments'])){
        $master=other_fee_category_master_sql('j.category');
        $parts[]="SELECT p.unit_id,$master,-1000000000-p.id,j.slot,j.amount,CASE WHEN p.cancelled=1 THEN 'cancelled' WHEN b.state='completed' THEN 'active' ELSE 'pending' END,'legacy' FROM legacy_op_payments p JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id JOIN JSON_TABLE(CAST(p.items AS JSON),'$[*]' COLUMNS(slot FOR ORDINALITY,category VARCHAR(100) PATH '$.category',amount DECIMAL(20,2) PATH '$.amount')) j";
    }
    if(isset($tables['pembayaran_aktivitas']))$parts[]="SELECT a.unit_id,j.master_id,a.payment_id,j.slot,j.amount,'cancelled','system' FROM pembayaran_aktivitas a JOIN JSON_TABLE(CAST(a.before_snapshot AS JSON),'$.details.bayar_biaya_lain[*]' COLUMNS(slot FOR ORDINALITY,master_id INT PATH '$.master_biaya_lain_id',amount DECIMAL(20,2) PATH '$.nominal_snapshot')) j WHERE a.action='deleted' AND NOT EXISTS(SELECT 1 FROM pembayaran_aktivitas n WHERE n.unit_id=a.unit_id AND n.payment_id=a.payment_id AND n.action='deleted' AND n.id>a.id)";
    return implode(' UNION ALL ',$parts);
}

/** References retained by correction/deletion audit and obligations also protect masters. */
function other_fee_history_refs_sql(mysqli $db): string {
    $tables=other_fee_usage_tables($db);$parts=[];
    if(isset($tables['pembayaran_aktivitas']))foreach(['before_snapshot','after_snapshot'] as $column){
        $parts[]="SELECT a.unit_id,j.master_id FROM pembayaran_aktivitas a JOIN JSON_TABLE(CAST(COALESCE(a.$column,JSON_OBJECT()) AS JSON),'$.details.bayar_biaya_lain[*]' COLUMNS(master_id INT PATH '$.master_biaya_lain_id' NULL ON EMPTY)) j";
    }
    if(isset($tables['legacy_op_audit']))foreach(['before_data','after_data'] as $column){
        $master=other_fee_category_master_sql('j.category');
        $parts[]="SELECT a.unit_id,$master FROM legacy_op_audit a JOIN JSON_TABLE(CAST(COALESCE(a.$column,JSON_OBJECT()) AS JSON),'$.items[*]' COLUMNS(category VARCHAR(100) PATH '$.category')) j WHERE a.entity_type IN ('payment','fee_trace','obligation_binding')";
    }
    foreach(['legacy_op_obligations','legacy_full_coverage'] as $table)if(isset($tables[$table])){
        $master=other_fee_category_master_sql('r.category');$parts[]="SELECT r.unit_id,$master FROM $table r";
    }
    return $parts?implode(' UNION ALL ',$parts):'SELECT NULL unit_id,NULL master_id WHERE FALSE';
}

/** Shared by list, delete POST and verification; failures propagate, never become zero usage. */
function other_fee_master_usage(mysqli $db,?int $masterId=null): array {
    $where=$masterId===null?'':' WHERE m.id=?';$args=$masterId===null?[]:[$masterId];
    $masters=hp_rows($db,"SELECT m.* FROM master_biaya_lain m$where ORDER BY m.id",$args);if(!$masters)return [];
    $items=other_fee_items_sql($db);$history=other_fee_history_refs_sql($db);
    $stats=[];
    foreach(hp_rows($db,"SELECT i.unit_id,i.master_id,COUNT(DISTINCT CASE WHEN i.state='active' THEN i.payment_id END) receipts,SUM(i.state='active') details,COALESCE(SUM(IF(i.state='active',i.amount,0)),0) amount,COUNT(DISTINCT CASE WHEN i.state='cancelled' THEN i.payment_id END) cancelled,COUNT(*) refs FROM ($items) i JOIN master_biaya_lain m ON m.id=i.master_id AND m.unit_id=i.unit_id$where GROUP BY i.unit_id,i.master_id",$args) as $r)$stats[$r['unit_id'].'|'.$r['master_id']]=$r;
    $refs=[];foreach(hp_rows($db,"SELECT DISTINCT h.unit_id,h.master_id FROM ($history) h JOIN master_biaya_lain m ON m.id=h.master_id AND m.unit_id=h.unit_id$where",$args) as $r)$refs[$r['unit_id'].'|'.$r['master_id']]=true;
    $bills=[];foreach(hp_rows($db,"SELECT t.unit_id,t.master_biaya_lain_id,COUNT(*) n FROM tagihan_biaya_lain t JOIN master_biaya_lain m ON m.id=t.master_biaya_lain_id AND m.unit_id=t.unit_id$where GROUP BY t.unit_id,t.master_biaya_lain_id",$args) as $r)$bills[$r['unit_id'].'|'.$r['master_biaya_lain_id']]=(int)$r['n'];
    foreach($masters as &$m){$key=$m['unit_id'].'|'.$m['id'];$s=$stats[$key]??[];
        $m['jumlah_pemakaian']=(int)($s['receipts']??0);$m['jumlah_rincian']=(int)($s['details']??0);$m['nominal_pemakaian']=$s['amount']??'0.00';$m['jumlah_batal']=(int)($s['cancelled']??0);$m['jumlah_tagihan']=$bills[$key]??0;
        $m['history_used']=!empty($s['refs'])||isset($refs[$key]);$m['can_delete']=!$m['history_used']&&!$m['jumlah_tagihan']&&!lf_reserved_master($db,(int)$m['id']);
    }unset($m);return $masters;
}

/** Context is an existing master in the connection's authorized unit, never a name join. */
function other_fee_history_context(mysqli $db,array $input): ?array {
    if(!isset($input['fee_master_id']))return null;$id=$input['fee_master_id'];
    if(!is_scalar($id)||!preg_match('/^[1-9][0-9]*$/D',(string)$id)||strlen((string)$id)>10)throw new InvalidArgumentException('Master biaya tidak valid.');
    $master=hp_one($db,'SELECT id,unit_id,nama FROM master_biaya_lain WHERE id=?',[(int)$id]);
    if(!$master)throw new InvalidArgumentException('Master biaya tidak tersedia pada unit ini.');return $master;
}

/** Same slot projection as the master. EXISTS keeps duplicate slots from duplicating receipts. */
function other_fee_history_predicate(mysqli $db,array $master,bool $deleted=false): string {
    $items=other_fee_items_sql($db);$state=$deleted?'cancelled':'active';
    return " AND p.unit_id=".(int)$master['unit_id']." AND EXISTS(SELECT 1 FROM ($items) fee_items WHERE fee_items.unit_id=p.unit_id AND fee_items.payment_id=p.id AND fee_items.master_id=".(int)$master['id']." AND fee_items.state='$state')";
}
function other_fee_history_amount_sql(mysqli $db,array $master,bool $deleted=false): string {
    $items=other_fee_items_sql($db);$state=$deleted?'cancelled':'active';
    return "(SELECT SUM(fee_items.amount) FROM ($items) fee_items WHERE fee_items.unit_id=p.unit_id AND fee_items.payment_id=p.id AND fee_items.master_id=".(int)$master['id']." AND fee_items.state='$state')";
}
function other_fee_assert_history_payment(mysqli $db,array $input,int $id,bool $deleted=false): void {
    $master=other_fee_history_context($db,$input);if(!$master)return;
    $predicate=other_fee_history_predicate($db,$master,$deleted);
    if(!hp_one($db,'SELECT p.id FROM (SELECT ? id,? unit_id) p WHERE TRUE'.$predicate,[$id,$master['unit_id']]))throw new InvalidArgumentException('Transaksi tidak terkait dengan master biaya ini.');
}

/** Caller owns transaction. All writers and deletion lock masters before owner/payment rows. */
function other_fee_lock_items(mysqli $db,int $unit,array $items): void {
    $ids=[];foreach($items as $i)if(preg_match('/^biaya_lain:([1-9][0-9]*)$/D',(string)($i['category']??''),$m))$ids[(int)$m[1]]=true;
    if(!$ids)return;$ids=array_keys($ids);sort($ids,SORT_NUMERIC);
    $rows=lf_rows($db,'SELECT id FROM master_biaya_lain WHERE unit_id=? AND id IN ('.implode(',',$ids).') ORDER BY id FOR UPDATE',[$unit]);
    if(count($rows)!==count($ids))throw new DomainException('Master biaya berubah atau berbeda unit. Muat ulang sebelum menyimpan.');
}
