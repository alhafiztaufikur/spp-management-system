<?php
/** Real cloned data only, UUID/port/datadir guarded; all fixture changes roll back. */
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/legacy_full.php';require_once __DIR__.'/../includes/legacy_migration_verification.php';
if((int)getenv('SPP_DB_PORT')===3306)throw new RuntimeException('Never run on target');
$file=(string)getenv('SPP_EXCEPTION_CLOSURE_REQUEST');if(!$file||!is_file($file))throw new RuntimeException('Explicit cloned closure request required');
$request=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR)['exception_closure'];$db=$koneksi;$checks=0;
function lec_assert(bool $ok,string $message):void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function lec_reject(mysqli $db,array $request,string $message):void {
    $db->query('SAVEPOINT rejected_decision');try{lec_reconcile($db,1,$request);throw new RuntimeException($message);}catch(DomainException $expected){}finally{$db->query('ROLLBACK TO SAVEPOINT rejected_decision');}
    lec_assert(true,$message);
}
$snapshot=lmr_snapshot($db);$db->begin_transaction();
try{
    unit_set_context($db,1);$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>1];
    $bad=$request;$bad['fees']=[$request['fees'][0]];$bad['owners']=[];$bad['fees'][0]['amount']='1.00';lec_reject($db,$bad,'Amount mismatch accepted');
    $bad['fees'][0]=$request['fees'][0];$bad['fees'][0]['source_key']=str_repeat('0',64);lec_reject($db,$bad,'Wrong source hash accepted');
    $bad['fees'][0]=$request['fees'][0];$bad['fees'][0]['version']++;lec_reject($db,$bad,'Stale version accepted');
    $count=0;$owners=0;$amount=0;
    foreach([1,2,3] as $unit){unit_set_context($db,$unit);$_SESSION['active_unit_id']=$unit;$r=lec_reconcile($db,1,$request);$count+=$r['fees_closed'];$owners+=$r['owners_removed'];$amount+=$r['amount_cents'];
        foreach(lf_rows($db,'SELECT id,manifest FROM legacy_full_batches') as $batch){$m=json_decode($batch['manifest'],true);lec_verify($db,$batch['id'],$m['exception_closures']??[]);}
    }
    lec_assert($count===59&&$owners===2&&$amount===543700000,'Actual closure scope differs');
    lec_assert(lmr_snapshot($db)===$snapshot,'Protected source, identities, cash, or savings changed');
    $after=lmr_database_checksums($db);
    foreach([1,2,3] as $unit){unit_set_context($db,$unit);$_SESSION['active_unit_id']=$unit;$r=lec_reconcile($db,1,$request);lec_assert($r['changes']===0,'Closure replay writes changes');}
    lec_assert(lmr_database_checksums($db)===$after,'Closure replay changes table checksums');
    foreach([1,2,3] as $unit){unit_set_context($db,$unit);$_SESSION['active_unit_id']=$unit;
        foreach(lf_rows($db,'SELECT id FROM legacy_full_batches') as $batch){$draft=lful_draft($batch['id']);lec_assert(!empty($draft['settings']['_exception_closures']),'GUI draft misses accepted decisions');
            $catalog=lful_catalog_read($batch['id']);[$records]=lop_review_source($draft['job_info']);$plan=lop_preview_load($draft,$catalog,$records,$draft['settings']);
            lec_assert($unit===1?isset($plan['owners']['TEST']):!isset($plan['owners']['TEST']),'GUI preview changes the wrong TEST identity in unit '.$unit);
            lec_assert(($plan['summary']['fee_unresolved']??0)===0,'GUI preview still reports closed fees as unresolved');
        }
    }
    $db->rollback();lec_assert(lmr_snapshot($db)===$snapshot,'Rollback changed protected state');
    echo "PASS: $checks isolated checks; wrong amounts/hashes/versions rejected, exact scope, protected data, audit, replay checksums and cached GUI previews.\n";
}catch(Throwable $e){$db->rollback();throw $e;}
