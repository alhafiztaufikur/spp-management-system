<?php
/** Recognize one specific old GUI bug without trusting an arbitrary replacement digest.
 * An audited SPP-master save updated derived obligations but omitted manifest invalidation.
 * Reconstruct its exact pre-save rows; every other byte must match the stored digest.
 */
function lop_prior_billing_digest(mysqli $db,string $batch): ?array {
    require_once __DIR__.'/legacy_billing.php';$data=lb_cache($db);$hash=hash_init('sha256');$changed=0;
    foreach(['owners','payments','journals','accounts','obligations','tariffs'] as $kind){
        $sql=$kind==='owners'?'SELECT * FROM legacy_op_owners WHERE batch_id=? ORDER BY id':(in_array($kind,['payments','journals'],true)?"SELECT * FROM legacy_op_$kind WHERE batch_id=? ORDER BY id":"SELECT a.* FROM legacy_op_$kind a JOIN legacy_op_owners o ON o.id=a.owner_id AND o.unit_id=a.unit_id WHERE o.batch_id=? ORDER BY a.id");
        foreach(lf_stream_rows($db,hp_sql($db,$sql,true),[$batch]) as $row){
            if($kind==='obligations'&&$row['category']==='spp'&&(int)$row['version']===2){
                $e=json_decode($row['evidence'],true);$s=$data['owner_students'][$row['owner_id']]??null;$year=lb_period_year_for_import($row['period']);
                $old=isset($e['gross'],$e['discount'])?lb_result_cents($e['gross'])-lb_result_cents($e['discount']):null;
                if($s&&($e['planner']??0)===2&&($e['rule']??0)===2&&isset($s['settings']['billing_v1']['spp_master'][$year])&&$old!==null&&$old>=0&&$old!==lf_cents($row['principal'])){
                    $position=lb_obligation_position($db,$row);if(!$position||$position['principal']!==lf_cents($row['principal'])||$position['remaining']!==lf_cents($row['remaining']))return null;
                    $paid=($data['paid'][$row['owner_id']]['spp'][$row['period']]??0)+lf_cents($data['native_monthly'][$s['unit_id'].'|'.$s['NO_INDUK']]['spp|'.$row['period']]['paid']??'0');
                    $row['principal']=lf_money($old);$row['remaining']=lf_money(max(0,$old-$paid));$row['version']=1;$changed++;
                }
            }
            hash_update($hash,lf_json($row));
        }
    }
    return $changed?['digest'=>hash_final($hash),'derived_rows'=>$changed]:null;
}
