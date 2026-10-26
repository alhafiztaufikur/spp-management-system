<?php
/** Guarded integration oracle; SQL fixture writes, when present, always roll back. */
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
if((int)getenv('SPP_DB_PORT')===3306||!getenv('SPP_KOMITE_TEST_UUID'))throw new RuntimeException('Owned isolated MySQL server required.');
require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
if($koneksi->query('SELECT @@server_uuid')->fetch_row()[0]!==getenv('SPP_KOMITE_TEST_UUID'))throw new RuntimeException('Test server identity differs.');
require_once __DIR__.'/../includes/transaction_report.php';require_once __DIR__.'/../includes/reports.php';require_once __DIR__.'/../includes/auth.php';require_once __DIR__.'/../includes/legacy_transactions.php';
$db=$koneksi;$checks=0;$results=[];$base=(string)getenv('SPP_QA_DIR');$start='2026-10-01 00:00:00';$end='2026-10-23 00:00:00';
function kr_assert(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function kr_money(mixed $value):int{$s=(string)$value;$negative=str_starts_with($s,'-');[$whole,$fraction]=array_pad(explode('.',ltrim($s,'-'),2),2,'');return ($negative?-1:1)*((int)$whole*100+(int)str_pad(substr($fraction,0,2),2,'0'));}
/** Independent source reads: no production item SQL or component-view helper. */
function kr_oracle(mysqli $db,array $payments):array{
    $out=[];$add=static function(string $key,mixed $amount)use(&$out):void{$value=kr_money($amount);if($value)$out[$key]=($out[$key]??0)+$value;};
    $sorga='histori:'.substr(hash('sha256','bayar.U_SORGA'),0,32);
    foreach(array_chunk($payments,500) as $chunk){$native=[];$full=[];$op=[];foreach($chunk as $p){$id=(int)$p['id'];if($id>0)$native[]=$id;elseif($id>-1000000000)$full[]=-$id;else $op[]=-1000000000-$id;}
        if($native){$ids=implode(',',$native);foreach(hp_rows($db,"SELECT * FROM bayar WHERE id IN ($ids)") as $p)foreach(['U_SPP'=>'spp','U_KOMITE'=>'komite','U_PSB'=>'psb','potong_spp'=>'potongan_spp'] as $column=>$key)$add($key,$column==='potong_spp'?-(float)$p[$column]:round((float)$p[$column],2));
            foreach(hp_rows($db,"SELECT d.*,t.master_biaya_lain_id bill_master FROM bayar_biaya_lain d LEFT JOIN tagihan_biaya_lain t ON t.id=d.tagihan_biaya_lain_id AND t.unit_id=d.unit_id WHERE d.bayar_id IN ($ids)") as $d){$master=$d['master_biaya_lain_id']??$d['bill_master'];$key=$master?'biaya_lain:'.$master:(trim((string)$d['legacy_key'])!==''?'snapshot_key:'.$d['unit_id'].':'.$d['legacy_key']:'snapshot_item:'.$d['unit_id'].':'.$d['id']);$add($key,$d['nominal_snapshot']);}
            foreach(hp_rows($db,"SELECT jumlah FROM bayar_du WHERE bayar_id IN ($ids)") as $d)$add('daftar_ulang',$d['jumlah']);
        }
        if($full)foreach(hp_rows($db,'SELECT source_column,category,amount FROM legacy_full_items WHERE payment_id IN ('.implode(',',$full).')') as $i){$key=$i['category']===$sorga?'komite':$i['category'];$add($key,$i['amount']);}
        if($op)foreach(hp_rows($db,'SELECT items FROM legacy_op_payments WHERE id IN ('.implode(',',$op).')') as $p)foreach(json_decode($p['items'],true) as $i){$key=$i['category']===$sorga?'komite':$i['category'];$add($key,$i['amount']);}
    }ksort($out);return $out;
}
$actor=(int)$db->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_row()[0];$_SESSION=['admin_id'=>$actor,'admin_role'=>'super_admin'];$_GET=[];
$db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY|MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
try{
    $manifestBefore=hash('sha256',json_encode($db->query('SELECT id,manifest FROM legacy_full_batches_data ORDER BY id')->fetch_all(MYSQLI_ASSOC)));
    foreach([1,2,3,0] as $unit){$_SESSION['active_unit_id']=$unit;unit_set_context($db,$unit);$_GET=[];$payments=general_payment_rows($db,$start,$end);$expected=kr_oracle($db,$payments);$total=array_sum(array_map(fn($p)=>kr_money($p['total_jumlah']),$payments));
        foreach(['on','off'] as $optimizer){$db->query("SET SESSION optimizer_switch='derived_merge=$optimizer,derived_condition_pushdown=$optimizer'");$rows=transaction_report_component_totals($db,$start,$end);$actual=[];foreach($rows as $r)$actual[$r['component_key']]=$r['cents'];ksort($actual);kr_assert($actual===$expected&&array_sum($actual)===$total,'Aggregate differs from independent source items/unit '.$unit.'/'.$optimizer);kr_assert(!in_array('U_SORGA',array_column($rows,'label'),true),'Technical name leaked into recap');}
        $db->query("SET SESSION optimizer_switch='derived_merge=on,derived_condition_pushdown=on'");
        foreach(['search','undated','student'] as $profile){$_GET=[];$query='';if($profile==='search')$query=(string)$payments[0]['NO_INDUK'];elseif($profile==='undated')$_GET=['include_undated'=>'1'];else{$students=array_values(array_filter($payments,fn($p)=>(int)($p['student_id']??0)>0));if(!$students)continue;$_GET=['student_id'=>(int)$students[0]['student_id']];}
            $selected=general_payment_rows($db,$start,$end,$query);$want=kr_oracle($db,$selected);$got=[];foreach(transaction_report_component_totals($db,$start,$end,$query) as $r)$got[$r['component_key']]=$r['cents'];ksort($got);kr_assert($got===$want,'Filtered component selection differs/'.$unit.'/'.$profile);
        }$_GET=[];
        foreach([10,25] as $size){$page=transaction_report_section($db,$start,$end,'','terbaru',$size,2);kr_assert($page['row_count']===count($payments),'Pagination changed selected receipts');$details=transaction_report_components($db,$page['rows']);kr_assert(count($details)===count($page['rows']),'Bounded detail read lost items');foreach($details as $text)kr_assert(!str_contains($text,'U_SORGA:'),'Export detail kept technical Komite name');}
        $_GET=['all_periods'=>'1'];$all=transaction_report_component_totals($db,$start,$end);$oracle=hp_one($db,"SELECT SUM(amount) amount FROM (SELECT CAST(total_jumlah AS DECIMAL(20,2)) amount FROM bayar UNION ALL SELECT p.total FROM legacy_full_payments p JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id WHERE b.state='completed' UNION ALL SELECT p.total FROM legacy_op_payments p JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id WHERE b.state='completed' AND p.cancelled=0) payments");kr_assert(array_sum(array_column($all,'cents'))===kr_money($oracle['amount']),'Full history cash differs');
        $_GET=[];kr_assert(!transaction_report_component_totals($db,'2090-01-01 00:00:00','2090-02-01 00:00:00'),'Empty filter contains components');$results[$unit]=['payments'=>count($payments),'cents'=>$total,'components'=>count($expected)];
    }
    $_SESSION['active_unit_id']=1;unit_set_context($db,1);$_GET=[];
    foreach(['utf8mb4_0900_ai_ci','utf8mb4_unicode_ci','utf8mb4_general_ci'] as $collation){$db->query("SET NAMES utf8mb4 COLLATE $collation");$rows=transaction_report_component_totals($db,$start,$end);kr_assert(array_sum(array_column($rows,'cents'))===15650820000,'Connection collation changed recap');}
    $f=report_filters($db,['tanggal_awal'=>'2026-10-01','tanggal_akhir'=>'2026-10-22','siswa_status'=>'all','kategori'=>'komite']);$raw=report_payment_components($db,$f);$cash=report_payment_components($db,$f,true);$history=lcs_sorga_history_key();$rawSorga=array_filter($raw,fn($p)=>$p['kategori_key']===$history);$cashSorga=array_filter($cash,fn($p)=>($p['source_category']??'')===$history);
    kr_assert(count($rawSorga)>0&&count($cashSorga)===count($rawSorga),'Raw billing category disappeared');kr_assert(array_sum(array_column($rawSorga,'nominal'))===array_sum(array_column($cashSorga,'nominal')),'Cash projection changed Komite amount');foreach($cashSorga as $p)kr_assert($p['kategori_key']==='komite'&&$p['komponen']==='Komite (Legacy)','Cash Komite classification missing');
    $receipt=report_receipt_data($db,$f);kr_assert(in_array('komite',array_column($receipt['columns'],0),true)&&!in_array($history,array_column($receipt['columns'],0),true),'Cash report split historical Komite');
    $legacyFilter=$f;$legacyFilter['kategori']=$history;$legacyFilter['_multi']['kategori']=[$history];$historic=report_receipt_data($db,$legacyFilter);kr_assert(array_sum(array_column($historic['rows'],'total_penerimaan'))===array_sum(array_column($rawSorga,'nominal')),'Historical filter alias changed its original selection');
    $selected=reset($cashSorga);$model=lt_model($db,-(int)$selected['id']-1000000000);kr_assert(in_array('Komite',array_column($model['components'],0),true)&&in_array('U_SORGA',array_column($model['legacy_record']['items'],'label'),true),'Receipt projection overwrote the stored record');
    kr_assert(hash('sha256',json_encode($db->query('SELECT id,manifest FROM legacy_full_batches_data ORDER BY id')->fetch_all(MYSQLI_ASSOC)))===$manifestBefore,'Read projections changed publication manifests');
    file_put_contents($base.'/model-result.json',json_encode(['checks'=>$checks,'units'=>$results,'sample_id'=>$selected['id']],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
}finally{$db->rollback();$db->query("SET SESSION optimizer_switch='derived_merge=on,derived_condition_pushdown=on'");}
echo "PASS: $checks isolated aggregate, optimizer/collation, exact source cash, pagination, raw/cash separation, historical filters and receipt compatibility checks.\n";
