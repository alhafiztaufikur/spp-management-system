<?php
function lft_assert(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function lft_reject(callable $call,string $message): void {try{$call();}catch(DomainException|mysqli_sql_exception $e){return;}throw new RuntimeException($message);}
function lft_seed(mysqli $db,int $actor,int $unit,bool $pending=false,?string $nis=null): array {
    unit_set_context($db,$unit);$nis??=(string)random_int(9700000000,9799999999);$level=[1=>1,2=>7,3=>10][$unit];
    lf_query($db,'INSERT INTO siswa(unit_id,NO_INDUK,NAMA,KELAS,is_active,legacy_pending) VALUES(?,?,?,?,?,?)',[$unit,$nis,'UJI KEUANGAN LEGACY',$pending?'LEGACY':(string)$level,$pending?0:1,$pending?1:0]);$student=(int)$db->insert_id;
    $hash=hash('sha256',random_bytes(24));lf_query($db,"INSERT INTO legacy_finance_sources(unit_id,backup_hash,source_name,source_date,manifest,fingerprint,row_count,published,imported_by) VALUES(?,?,'Isolated QA','2019-02-03T00:00:00','{}',?,3,1,?)",[$unit,$hash,$hash,$actor]);$source=(int)$db->insert_id;
    if($pending)lf_query($db,"INSERT INTO legacy_student_import(student_id,unit_id,source_hash,source_row,source_name,source_class,raw_data,imported_by) VALUES(?,?,?,1,'UJI SUMBER','1','{}',?)",[$student,$unit,$hash,$actor]);
    $records=[['source_table'=>'tabungan','raw_data'=>lf_json(['NO_INDUK'=>$nis,'SALDO'=>'300.50'])],['source_table'=>'transaksi_m','raw_data'=>lf_json(['NO_INDUK'=>$nis,'MASUK'=>'400.50','KELUAR'=>null,'TANGGAL'=>'2019-02-01'])],['source_table'=>'transaksi_k','raw_data'=>lf_json(['NO_INDUK'=>$nis,'MASUK'=>null,'KELUAR'=>'100','TANGGAL'=>'2019-02-02'])]];
    $rowKey='';foreach($records as $i=>$record){$rowKey=hash('sha256',$hash.'|'.$i);lf_query($db,'INSERT INTO legacy_finance_rows(unit_id,source_id,source_table,row_key,row_hash,occurrence,source_nis,amounts,raw_data) VALUES(?,?,?,?,?,1,?,\'{}\',?)',[$unit,$source,$record['source_table'],$rowKey,$rowKey,$nis,$record['raw_data']]);}
    $actual=lf_source_fingerprint($db,$unit,$source);lf_query($db,'UPDATE legacy_finance_sources SET fingerprint=? WHERE id=?',[$actual['fingerprint'],$source]);
    lf_query($db,"INSERT INTO legacy_finance_maps(unit_id,source_id,source_nis,source_name,student_id,match_status,reason) VALUES(?,?,?,'UJI SUMBER',?,'manual','Isolated test fixture')",[$unit,$source,$nis,$student]);$map=(int)$db->insert_id;
    lf_query($db,"INSERT INTO legacy_finance_reviews(unit_id,map_id,status,savings_result) VALUES(?,?,'ready',?)",[$unit,$map,lf_json(lf_reconcile($records))]);return ['id'=>(int)$db->insert_id,'student'=>$student,'nis'=>$nis,'source'=>$source,'key'=>$rowKey,'unit'=>$unit];
}
