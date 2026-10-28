<?php
/** Bounded grouped-history reads; all financial evidence is read afresh. */

function report_history_groups(array $report): array {
    return $report['_billing_groups']??report_billing_history_group_students($report['rows']);
}

/** Monthly detail is not displayed in the grouped matrix. Read sums and counts
 * from the same scoped bill query, before materializing its repeated months.
 * Credit still comes from the existing source reader; no cash is synthesized.
 */
function report_history_monthly_row(array $r,string $category):array {
    $period=sprintf('%04d-%02d',intdiv((int)$r['first_period'],100),(int)$r['first_period']%100);
    $status=$category==='spp'&&$r['read_bill_status']==='covered_psb'?'Tercakup Uang PSB'
        :($category==='spp'&&$r['read_bill_status']==='waived'?'Potongan Penuh'
            :report_billing_status((float)$r['read_bill'],(float)$r['read_paid'],$r['read_bill_status']));
    return [
        'nis'=>$r['nis'],'nis_diknas'=>$r['nis_diknas']??'','nama'=>$r['nama'],'kelas'=>$r['kelas']?:'Belum diatur',
        'master_kelas_id'=>$r['master_kelas_id']??0,'tingkat'=>$r['tingkat']??'',
        'komponen'=>$category==='spp'?'SPP':'Uang Komite','komponen_key'=>$category,
        'periode'=>(report_months()[substr($period,5,2)]??substr($period,5,2)).' '.substr($period,0,4),
        'periode_code'=>$period,'tahun_ajaran'=>$r['tahun_ajaran'],'tanggal_dibuat'=>$r['tanggal_dibuat'],
        'tagihan'=>(float)$r['tagihan'],'terbayar'=>(float)$r['terbayar'],'sisa'=>(float)$r['sisa'],
        'status'=>$status,'_read_count'=>(int)$r['read_count'],'_read_fractional'=>(bool)$r['read_fractional'],
    ];
}
function report_history_monthly_rollup(mysqli $db,string $sql,string $category,array $payments,array $filters,?callable $emit=null): array {
    $credits=[];
    foreach($payments as $nis=>$categories)foreach($categories[$category]??[] as $period=>$payment){
        if(($payment['paid']??0)!==0)$credits[]=[(string)$nis,(int)substr($period,0,4),(int)substr($period,5,2),(int)$payment['paid']];
    }
    $json=json_encode($credits,JSON_THROW_ON_ERROR);
    $paid='(q.terbayar+COALESCE(c.paid,0)/100)';
    $where='';
    if(!empty($filters['filter_tanggal_tagihan'])&&$filters['tanggal_awal']!==''){
        $where=" WHERE LEFT(q.tanggal_dibuat,10)>='".$db->real_escape_string($filters['tanggal_awal'])."' AND LEFT(q.tanggal_dibuat,10)<='".$db->real_escape_string($filters['tanggal_akhir'])."'";
    }
    $query="SELECT /*+ SET_VAR(join_buffer_size=33554432) SET_VAR(tmp_table_size=67108864) */
        MIN(q.nis) nis,ANY_VALUE(q.nis_diknas) nis_diknas,ANY_VALUE(q.nama) nama,MIN(q.kelas) kelas,q.master_kelas_id,q.tingkat,MIN(q.tahun_ajaran) tahun_ajaran,
        MIN(q.tahun*100+q.bulan) first_period,
        q.tagihan read_bill,$paid read_paid,q.bill_status read_bill_status,
        MAX(MOD(q.tagihan,1)<>0 OR MOD(q.terbayar,1)<>0 OR MOD(COALESCE(c.paid,0),100)<>0) read_fractional,
        MIN(q.tanggal_dibuat) tanggal_dibuat,SUM(q.tagihan) tagihan,SUM($paid) terbayar,
        SUM(CASE WHEN q.bill_status='cancelled' THEN 0 ELSE GREATEST(0,q.tagihan-$paid) END) sisa,COUNT(*) read_count
        FROM ($sql) q LEFT JOIN (SELECT MIN(nis) nis,year,month,MAX(paid) paid FROM
          JSON_TABLE(?,'\$[*]' COLUMNS(nis VARCHAR(100) PATH '\$[0]',year INT PATH '\$[1]',month INT PATH '\$[2]',paid BIGINT PATH '\$[3]')) credit
          GROUP BY CAST(nis AS BINARY),year,month) c
          ON CAST(c.nis AS BINARY)=CAST(q.nis AS BINARY) AND c.year=q.tahun AND c.month=q.bulan$where
        GROUP BY CAST(q.nis AS BINARY),CAST(q.kelas AS BINARY),q.master_kelas_id,q.tingkat,CAST(q.tahun_ajaran AS BINARY),q.tagihan,$paid,q.bill_status";
    if($emit!==null&&isset($GLOBALS['report_history_pending'][$db->thread_id])){
        $GLOBALS['report_history_pending'][$db->thread_id][]=['sql'=>hp_sql($db,$query),'json'=>$json,'category'=>$category,'emit'=>$emit,'unit'=>report_query_unit_id()];
        return [];
    }
    $out=[];foreach(hp_rows($db,$query,[$json]) as $r)$out[]=report_history_monthly_row($r,$category);
    return $out;
}

/** Two connections at most, only for non-transactional HTTP matrix reads.
 * Exports and CLI/transaction tests keep the original connection/snapshot.
 */
function report_history_parallel_read(mysqli $db,array $tasks):void {
    if(!$tasks)return;
    $identity=$db->query('SELECT @@server_uuid uuid,@@port port,DATABASE() db,@@session.time_zone tz,@@session.sql_mode mode,@@collation_connection collation')->fetch_assoc();
    $extra=null;
    try{
        $extra=new mysqli(DB_HOST,DB_USER,DB_PASS,DB_NAME,(int)$identity['port']);
        $actual=$extra->query('SELECT @@server_uuid uuid,DATABASE() db')->fetch_assoc();
        if($actual['uuid']!==$identity['uuid']||$actual['db']!==$identity['db'])throw new RuntimeException('Read connection identity mismatch');
        $extra->set_charset($db->character_set_name());
        foreach(['time_zone'=>'tz','sql_mode'=>'mode','collation_connection'=>'collation'] as $name=>$key)$extra->query('SET SESSION '.$name."='".$extra->real_escape_string($identity[$key])."'");
        $extra->query('SET SESSION TRANSACTION READ ONLY');
    }catch(Throwable $e){if($extra instanceof mysqli)$extra->close();$extra=null;}
    if(!$extra){
        foreach($tasks as $task){unit_set_context($db,$task['unit']);foreach(hp_rows($db,$task['sql'],[$task['json']]) as $r)($task['emit'])(report_history_monthly_row($r,$task['category']));}
        return;
    }
    $connections=[$db,$extra];$busy=[];$done=[];$next=0;$failure=null;
    try{
        while($next<count($tasks)||$busy){
            foreach($connections as $i=>$connection)if(!isset($busy[$i])&&$next<count($tasks)){
                $task=$tasks[$next];$literal="'".$connection->real_escape_string($task['json'])."'";
                $marker='JSON_TABLE(?,';$position=strrpos($task['sql'],$marker);
                if($position===false)throw new RuntimeException('Missing read parameter');
                $query=substr_replace($task['sql'],'JSON_TABLE('.$literal.',',$position,strlen($marker));
                if(!is_string($query)||!str_starts_with($query,'SELECT ')||str_contains($query,'current_unit_id('))throw new RuntimeException('Unprojected asynchronous read');
                // Keep view/UDF scope correct even if a future schema cannot
                // use the optional base-table projection.
                $connection->query('SET @app_unit_id='.(int)$task['unit']);
                $connection->query($query,MYSQLI_ASYNC);$busy[$i]=$next++;
            }
            $ready=$errors=$reject=[];foreach(array_keys($busy) as $i)$ready[]=$connections[$i];
            if(!mysqli_poll($ready,$errors,$reject,1))continue;
            foreach(array_merge($ready,$errors,$reject) as $connection){
                $i=$connection===$db?0:1;if(!isset($busy[$i]))continue;
                $taskId=$busy[$i];unset($busy[$i]);
                try{$result=$connection->reap_async_query();$done[$taskId]=$result->fetch_all(MYSQLI_ASSOC);$result->free();}
                catch(Throwable $e){$failure??=$e;}
            }
            if($failure){$next=count($tasks);if(!$busy)throw $failure;}
        }
    }finally{
        while($busy){
            $ready=$errors=$reject=[];foreach(array_keys($busy) as $i)$ready[]=$connections[$i];
            if(!mysqli_poll($ready,$errors,$reject,1))continue;
            foreach(array_merge($ready,$errors,$reject) as $connection){$i=$connection===$db?0:1;if(!isset($busy[$i]))continue;unset($busy[$i]);try{$connection->reap_async_query();}catch(Throwable $ignored){}}
        }
        $extra->close();
    }
    foreach($tasks as $i=>$task){unit_set_context($db,$task['unit']);foreach($done[$i] as $r)($task['emit'])(report_history_monthly_row($r,$task['category']));}
}

function report_history_monthly_identities(mysqli $db,string $sql,string $category,array $payments,array $filters,array &$existing):void {
    if(!empty($filters['filter_tanggal_tagihan'])&&$filters['tanggal_awal']!=='')return;
    $numbers=[];foreach($payments as $nis=>$categories)if(!empty($categories[$category]))$numbers[]="'".$db->real_escape_string((string)$nis)."'";
    if(!$numbers)return;
    foreach(report_scoped_query($db,'SELECT DISTINCT q.nis,q.tahun,q.bulan FROM ('.$sql.') q WHERE q.nis IN ('.implode(',',$numbers).')') as $r){
        $existing[report_query_unit_id().'|'.$r['nis'].'|'.$category.'|'.sprintf('%04d-%02d',(int)$r['tahun'],(int)$r['bulan'])]=true;
    }
}

function report_history_rollup_data(mysqli $db,array $filters,bool $export):?array {
    $rows=[];$scope=report_query_unit_id();$prior=$GLOBALS['report_history_monthly_reader'][$db->thread_id]??null;
    $parallel=PHP_SAPI!=='cli'&&!$export&&($_SERVER['REQUEST_METHOD']??'')==='GET';
    if($parallel)$GLOBALS['report_history_pending'][$db->thread_id]=[];
    $GLOBALS['report_history_monthly_reader'][$db->thread_id]=true;
    $GLOBALS['report_history_sink'][$db->thread_id]=static function(array $row)use(&$rows):void{$rows[report_query_unit_id()][]=$row;};
    try{
        $report=report_build($db,'riwayat-tagihan',$filters);
        if($parallel)report_history_parallel_read($db,$GLOBALS['report_history_pending'][$db->thread_id]);
    }finally{
        unset($GLOBALS['report_history_sink'][$db->thread_id],$GLOBALS['report_history_pending'][$db->thread_id]);
        if($parallel||report_query_unit_id()!==$scope)unit_set_context($db,$scope);
        if($prior===null)unset($GLOBALS['report_history_monthly_reader'][$db->thread_id]);else $GLOBALS['report_history_monthly_reader'][$db->thread_id]=$prior;
    }
    // Integer Rupiah arithmetic is exact below 2^53. Preserve the full reader's
    // addition order for fractional input (including typed Excel values), rather
    // than silently changing floating-point rounding while grouping months.
    $magnitude=0.0;
    foreach($rows as $part)foreach($part as $row){
        if(!empty($row['_read_fractional']))return null;
        foreach(['tagihan','terbayar','sisa'] as $key)if(isset($row[$key])){
            $value=(float)$row[$key];if(fmod($value,1.0)!==0.0)return null;
            $magnitude+=abs($value);if($magnitude>=9007199254740991)return null;
        }
    }
    $all=[];
    ksort($rows);
    foreach($rows as $unit=>$part){
        report_billing_history_sort_rows($part);
        foreach($part as $row){
            if($scope===0){$row['unit_id']=$unit;$row['unit']=unit_label($unit);if(isset($row['kelas'])){$row['kelas_asli']=$row['kelas'];$row['kelas']=unit_label($unit).' · '.$row['kelas'];}}
            $all[]=$row;
        }
    }
    $groups=report_billing_history_group_students($all);$count=0;
    foreach($all as $r)$count+=(int)($r['_read_count']??1);
    $report['rows']=$all;$report=report_evidence_notes($report,'riwayat-tagihan',$filters);
    $totals=report_money_totals($report,'riwayat-tagihan');$summary=report_summaries($all);$summary['Baris']=$count;
    $report['rows']=[];$report['_billing_groups']=$groups;$report['_read_detail_count']=$count;$report['_read_totals']=$totals;
    return ['report'=>$report,'groups'=>$groups,'summary'=>$summary,'detail_count'=>$count];
}

/** Full builder/API callers retain individual rows. Only the grouped matrix
 * uses this smaller per-request read model. No cached financial data is read.
 */
function report_history_page_data(mysqli $db,array $filters,bool $export=false,bool $force=false): ?array {
    if(isset($GLOBALS['report_history_sink'][$db->thread_id]))return null;
    // Sectioned reports keep their existing independent ordering and totals.
    foreach($filters['_multi']??[] as $values)if(count($values)>1&&!filter_is_all($values))return null;
    if(!$force){
        if(($filters['student_id']??0)>0)return null;
        $unit=report_query_unit_id();$where=$unit?' WHERE unit_id='.(int)$unit:'';
        $count=0;foreach(['tagihan_spp_data','tagihan_komite_data','tagihan_daftar_ulang_data','tagihan_biaya_lain_data'] as $table)$count+=(int)$db->query("SELECT COUNT(*) FROM $table$where")->fetch_row()[0];
        if($count<50000)return null;
    }
    return report_history_rollup_data($db,$filters,$export);
}
