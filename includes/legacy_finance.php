<?php
require_once __DIR__.'/legacy_finance_schema.php';
require_once __DIR__.'/legacy_source.php';

/** All monetary source values are strings. Never round a source float into a debt/balance. */
function lf_cents(mixed $value, bool $negative=false): int {
    if (!is_string($value) && !is_int($value)) throw new DomainException('Nominal sumber tidak tepat.');
    $value=(string)$value;
    if (!preg_match('/^(-?)([0-9]{1,13})(?:\.([0-9]+))?$/D',$value,$m)) throw new DomainException('Nominal tidak valid atau presisi belum diterima.');
    $fraction=rtrim($m[3]??'','0');
    if (strlen($fraction)>2 || (!$negative && $m[1]==='-')) throw new DomainException('Nominal negatif atau memiliki lebih dari dua desimal.');
    $c=(int)$m[2]*100+(int)str_pad($fraction,2,'0');
    if ($c>999999999999999) throw new DomainException('Nominal melampaui batas.');
    return $m[1]==='-' ? -$c : $c;
}
function lf_money(int $c): string { return ($c<0?'-':'').intdiv(abs($c),100).'.'.str_pad((string)(abs($c)%100),2,'0',STR_PAD_LEFT); }
function lf_json(mixed $value): string { return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
/** Hash the exact lf_json representation without allocating a second copy of a large plan. */
function lf_json_digest(mixed $value): string {
    $hash=hash_init('sha256');lf_json_hash_update($hash,$value);return hash_final($hash);
}
function lf_json_hash_update(HashContext $hash,mixed $value,int $depth=0): void {
    if(!is_array($value)){hash_update($hash,lf_json($value));return;}
    if($depth>=512)throw new JsonException('Maximum stack depth exceeded',JSON_ERROR_DEPTH);
    $list=array_is_list($value);hash_update($hash,$list?'[':'{');$first=true;
    foreach($value as $key=>$item){if(!$first)hash_update($hash,',');$first=false;if(!$list){hash_update($hash,lf_json((string)$key));hash_update($hash,':');}lf_json_hash_update($hash,$item,$depth+1);}
    hash_update($hash,$list?']':'}');
}
function lf_canonical(array $raw): string { ksort($raw,SORT_STRING); return lf_json($raw); }
function lf_query(mysqli $db,string $sql,array $args=[]): mysqli_result|bool {
    $s=$db->prepare($sql); if($args)$s->bind_param(str_repeat('s',count($args)),...$args); $s->execute(); $result=$s->get_result();$s->close(); return $result;
}
function lf_rows(mysqli $db,string $sql,array $args=[]): array { return lf_query($db,$sql,$args)->fetch_all(MYSQLI_ASSOC); }
/** Retain at most one decoded SQL row during fingerprint checks. */
function lf_iter_rows(mysqli $db,string $sql,array $args=[]): Generator {
    $result=lf_query($db,$sql,$args);try{while($row=$result->fetch_assoc())yield $row;}finally{$result->free();}
}
function lf_one(mysqli $db,string $sql,array $args=[]): ?array { return lf_query($db,$sql,$args)->fetch_assoc(); }
function lf_actor(mysqli $db,int $actor,int $unit,bool $write=false,bool $lock=false): void {
    if($unit<0||$unit>3||($write&&$unit===0)) throw new DomainException('Pilih SD, SMP, atau SMA untuk perubahan.');
    if(!lf_one($db,"SELECT id FROM admin WHERE id=? AND is_active=1 AND role='super_admin'".($lock?' FOR UPDATE':''),[$actor])) throw new DomainException('Akun Super Admin aktif diperlukan.',403);
    unit_set_context($db,$unit);
}
function lf_audit(mysqli $db,int $unit,int $actor,?int $review,string $action,array $details): void {
    lf_query($db,'INSERT INTO legacy_finance_audit(unit_id,review_id,actor_id,action,details) VALUES(?,?,?,?,?)',[$unit,$review,$actor,$action,lf_json($details)]);
}
function lf_require_schema(mysqli $db): void { if(!legacy_finance_ready($db)) throw new DomainException('Keuangan Legacy belum siap. Pasang skema melalui CLI pada waktu pemeliharaan.'); }

/** Exact reconciliation of one original NIS, independent from target account/cache. */
function lf_reconcile(array $records, ?string $snapshotDate=null): array {
    $accounts=[];$accountRaw=[];$accountIssues=[];$journalIssues=[];$in=0;$out=0;$journals=0;$issues=[];$dates=[];
    foreach($records as $record){
        $raw=$record['raw']??json_decode($record['raw_data'],true,512,JSON_THROW_ON_ERROR);$table=$record['table']??$record['source_table'];
        try {
            if($table==='tabungan') {
                $accountRaw[]=$raw['SALDO']??null;$accounts[]=null;
                try{$accounts[array_key_last($accounts)]=lf_cents($raw['SALDO']??null);}catch(DomainException $e){$accountIssues[]=$e->getMessage();}
            }
            if(in_array($table,['transaksi_m','transaksi_k'],true)){
                // Audited journals leave the opposite-direction column NULL; the primary amount may never be NULL.
                $m=lf_cents($raw['MASUK']??($table==='transaksi_k'?'0':null));$k=lf_cents($raw['KELUAR']??($table==='transaksi_m'?'0':null));
                if(($table==='transaksi_m'&&($k!==0||$m<=0))||($table==='transaksi_k'&&($m!==0||$k<=0))) $issues[]='Arah jurnal tidak sesuai.';
                if($m>PHP_INT_MAX-$in||$k>PHP_INT_MAX-$out)throw new DomainException('Jumlah jurnal melampaui batas perhitungan.');
                $in+=$m;$out+=$k;$journals++;
                if(empty($raw['TANGGAL']))$issues[]='Tanggal jurnal kosong.'; else {
                    $day=substr((string)$raw['TANGGAL'],0,10);$date=DateTimeImmutable::createFromFormat('!Y-m-d',$day);
                    if(!$date||$date->format('Y-m-d')!==$day||($snapshotDate!==null&&$day>substr($snapshotDate,0,10)))$issues[]='Tanggal jurnal tidak valid atau melampaui snapshot.';
                    $dates[]=$raw['TANGGAL'];
                }
            }
        }catch(DomainException $e){$issues[]=$e->getMessage();}
    }
    $journalIssues=$issues;$issues=array_merge($accountIssues,$journalIssues);
    if(count($accounts)!==1)$issues[]='Rekening sumber tidak unik atau tidak tersedia.';
    if(!$journals)$issues[]='Jurnal sumber tidak lengkap.';
    $balance=count($accounts)===1?$accounts[0]:null;
    if($balance!==null && $balance!==$in-$out)$issues[]='Saldo sumber berbeda dengan jumlah jurnal.';
    sort($dates,SORT_STRING);
    return ['ready'=>!$issues,'balance'=>$balance===null?null:lf_money($balance),'source_balance_raw'=>count($accountRaw)===1?$accountRaw[0]:null,'account_issues'=>array_values(array_unique($accountIssues)),'journal_issues'=>array_values(array_unique($journalIssues)),'incoming'=>lf_money($in),'outgoing'=>lf_money($out),'accounts'=>count($accounts),'journals'=>$journals,'last_source_date'=>$dates?end($dates):null,'issues'=>array_values(array_unique($issues))];
}
function lf_extract_manifest(array $job): array {
    $dir=legacy_root().'/'.$job['id'];
    if(hash_file('sha256',$dir.'/source.dat')!==$job['hash'])throw new DomainException('Backup sumber berubah.');
    $manifest=json_decode(ltrim(file_get_contents($dir.'/finance_manifest.json'),"\xEF\xBB\xBF"),true,512,JSON_THROW_ON_ERROR);
    legacy_source_manifest_check($manifest,(int)$job['unit']);
    if(($manifest['profile']??'')!=='legacy-finance-v1'||(int)$manifest['unit']!==(int)$job['unit']||strtolower($manifest['backup_hash']??'')!==strtolower($job['hash'])||!hash_equals(strtolower($manifest['rows_sha256']??''),hash_file('sha256',$dir.'/finance.jsonl')))throw new DomainException('Manifest ekstraksi tidak sesuai.');
    return $manifest;
}
function lf_extract_stream(array $job,?array &$manifest=null): Generator {
    $dir=legacy_root().'/'.$job['id'];$manifest=lf_extract_manifest($job);
    $counts=[];$duplicates=[];$hashes=[];$file=fopen($dir.'/finance.jsonl','r');
    try { while(($line=fgets($file))!==false){
        $record=json_decode($line,true,512,JSON_THROW_ON_ERROR);$table=$record['table'];$raw=$record['raw'];
        if(!isset($manifest['counts'][$table])||!is_array($raw))throw new DomainException('Tabel ekstraksi tidak dikenali.');
        $digest=hash('sha256',lf_canonical($raw));$occ=($duplicates[$table][$digest]??0)+1;$duplicates[$table][$digest]=$occ;
        $key=hash('sha256',implode('|',[$job['unit'],strtolower($job['hash']),$table,$digest,$occ]));
        $record['row_hash']=$digest;$record['occurrence']=$occ;$record['row_key']=$key;$record['money_columns']=legacy_source_money_columns($record);$counts[$table]=($counts[$table]??0)+1;$hashes[]=$key;yield $record;
    }}finally{fclose($file);}
    foreach($manifest['counts'] as $table=>$count)if(($counts[$table]??0)!==(int)$count)throw new DomainException('Jumlah baris ekstraksi tidak cocok.');
    sort($hashes,SORT_STRING);$manifest['fingerprint']=hash('sha256',implode("\n",$hashes));$manifest['total']=array_sum($counts);
}
function lf_extract_read(array $job,bool $operationalOnly=false): array {
    $records=[];$manifest=null;foreach(lf_extract_stream($job,$manifest) as $record)if(!$operationalOnly||!$record['reference_only']||in_array($record['table'],['lain'],true)||legacy_source_additional_savings($record))$records[]=$record;
    return [$records,$manifest];
}
/** A bounded display sample, not an input to publication or simulation. */
function lf_extract_preview(array $job,string $identity,int $limit=12): array {
    lf_extract_manifest($job);$rows=[];$file=fopen(legacy_root().'/'.$job['id'].'/finance.jsonl','rb');
    try{while(($line=fgets($file))!==false){$r=json_decode($line,true,512,JSON_THROW_ON_ERROR);if(mb_strtoupper(trim(legacy_source_identity($r['raw'])))===$identity){$rows[]=$r;if(count($rows)>=$limit)break;}}}finally{fclose($file);}return $rows;
}
function lf_stage(mysqli $db,array $job): void {
    lf_require_schema($db);$manifest=null;$counts=[];
    $identities=[];foreach(lf_extract_stream($job,$manifest) as $record)if($record['table']==='siswa'){$key=mb_strtoupper(trim(legacy_source_identity($record['raw'])));$identities[$key]=($identities[$key]??0)+1;}
    $q=legacy_queue();$q->beginTransaction();
    try{
        $q->prepare('DELETE FROM rows WHERE job=?')->execute([$job['id']]);
        $s=$q->prepare('INSERT INTO rows(job,ordinal,nis,name,class,raw,result,reason) VALUES(?,?,?,?,?,?,?,?)');
        foreach(lf_extract_stream($job,$manifest) as $i=>$record){$raw=$record['raw'];$nis=legacy_source_identity($raw);$kind=$record['reference_only']?'reference':'archive';$issues=lf_record_issues($record);
            if(!$record['reference_only'] && ($identities[mb_strtoupper(trim($nis))]??0)!==1)$issues[]='Identitas sumber tidak tunggal atau tidak ditemukan.';
            if($issues)$kind.='_issues';$counts[$kind]=($counts[$kind]??0)+1;
            $s->execute([$job['id'],$i+1,$nis,(string)($raw['NAMA']??''),$record['table'],lf_json($record),$kind,$issues?implode(' ',$issues):'Arsip saja; tidak mengubah keuangan aktif.']);
        }$q->commit();
    }catch(Throwable $e){$q->rollBack();throw $e;}
    file_put_contents(legacy_root().'/'.$job['id'].'/finance_checked.json',lf_json($manifest));
    legacy_update($job['id'],['state'=>'ready','stage'=>'Siap Ditinjau','done'=>$manifest['total'],'total'=>$manifest['total'],'message'=>'Jumlah dan fingerprint sumber cocok. Konfirmasi hanya menerbitkan arsip.']);
}
function lf_record_issues(array $record): array {
    $raw=$record['raw'];$table=$record['table'];$issues=legacy_source_identity_issues($raw);
    foreach($record['money_columns'] as $column){$value=legacy_source_money_value($record,$column);if($value===null)continue;try{lf_cents($value);}catch(DomainException $e){$issues[]=$column.': '.$e->getMessage();}}
    $primary=match($table){'tabungan'=>'SALDO','transaksi_m'=>'MASUK','transaksi_k'=>'KELUAR',default=>null};
    if($primary && ($raw[$primary]??null)===null)$issues[]='Nominal utama sumber kosong.';
    if(in_array($table,['bayar','bayar_du','transaksi_m','transaksi_k'],true) && empty($raw['TGL_BYR']??$raw['TANGGAL']??null))$issues[]='Tanggal sumber kosong atau tidak tersedia.';
    return array_values(array_unique($issues));
}
function lf_publish(mysqli $db,array $job,bool $outerTransaction=false): void {
    lf_require_schema($db);lf_actor($db,(int)$job['actor'],(int)$job['unit'],true);
    $manifest=null;foreach(lf_extract_stream($job,$manifest) as $record){}unset($record);$checked=json_decode(file_get_contents(legacy_root().'/'.$job['id'].'/finance_checked.json'),true,512,JSON_THROW_ON_ERROR);
    if($checked['fingerprint']!==$manifest['fingerprint'])throw new DomainException('Hasil pratinjau berubah.');
    $unit=(int)$job['unit'];$actor=(int)$job['actor'];if(!$outerTransaction)$db->begin_transaction();
    try{
        lf_actor($db,$actor,$unit,true,true);
        $old=lf_one($db,'SELECT * FROM legacy_finance_sources WHERE backup_hash=? FOR UPDATE',[$job['hash']]);
        if($old){$actual=lf_source_fingerprint($db,$unit,(int)$old['id']);if(!$old['published']||$old['fingerprint']!==$manifest['fingerprint']||(int)$old['row_count']!==$manifest['total']||$actual['count']!==$manifest['total']||$actual['fingerprint']!==$manifest['fingerprint'])throw new DomainException('Sumber existing tidak lengkap atau berbeda.');if(!$outerTransaction){$db->commit();legacy_update($job['id'],['state'=>'imported','stage'=>'Arsip Tersimpan','message'=>'Backup ini sudah diarsipkan; tidak digandakan.']);}return;}
        lf_query($db,'INSERT INTO legacy_finance_sources(unit_id,backup_hash,source_name,source_date,manifest,fingerprint,row_count,imported_by) VALUES(?,?,?,?,?,?,?,?)',[$unit,strtolower($job['hash']),$job['name'],$manifest['source_date']??null,lf_json($manifest),$manifest['fingerprint'],$manifest['total'],$actor]);$source=(int)$db->insert_id;
        $groups=[];$students=[];
        foreach(lf_extract_stream($job,$manifest) as $record){
            $raw=$record['raw'];$nis=legacy_source_identity($raw);$amounts=[];
            foreach($record['money_columns'] as $column)$amounts[$column]=$raw[$column]??null;
            lf_query($db,'INSERT INTO legacy_finance_rows(unit_id,source_id,source_table,row_key,row_hash,occurrence,source_nis,source_date,source_operator,amounts,raw_data,reference_only) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$unit,$source,$record['table'],$record['row_key'],$record['row_hash'],$record['occurrence'],$nis,$raw['TGL_BYR']??$raw['TANGGAL']??($record['table']==='tbg'?($raw['tgl']??null):null),$raw['user_id']??null,lf_json($amounts),lf_json($raw),$record['reference_only']?1:0]);
            if($nis!==null && trim((string)$nis)!==''){$identity=mb_strtoupper(trim((string)$nis));$groups[$identity]??=[];if(in_array($record['table'],['siswa','tabungan','transaksi_m','transaksi_k'],true))$groups[$identity][]=$record;if($record['table']==='siswa')$students[$identity][]=$record;}
        }
        foreach($groups as $nis=>$rows){
            $identities=$students[$nis]??[];$student=null;$reason='Identitas sumber tidak tunggal atau belum memiliki pasangan manifest.';$status='held';
            if(count($identities)===1&&!legacy_source_identity_issues($identities[0]['raw'])){$ordinal=(int)$identities[0]['student_ordinal'];
                $match=lf_rows($db,'SELECT s.id FROM legacy_student_import m JOIN siswa s ON s.id=m.student_id AND s.unit_id=m.unit_id WHERE m.source_hash=? AND m.source_row=?',[$job['hash'],$ordinal]);
                if(count($match)===1){$student=(int)$match[0]['id'];$status='manifest';$reason='Sesuai manifest impor siswa pada backup yang sama.';}
            }
            lf_query($db,'INSERT INTO legacy_finance_maps(unit_id,source_id,source_nis,source_name,student_id,match_status,reason) VALUES(?,?,?,?,?,?,?)',[$unit,$source,(string)$nis,count($identities)===1?($identities[0]['raw']['NAMA']??null):null,$student,$status,$reason]);$map=(int)$db->insert_id;$reconciliation=lf_reconcile($rows,$manifest['source_date']??null);
            lf_query($db,'INSERT INTO legacy_finance_reviews(unit_id,map_id,status,savings_result) VALUES(?,?,?,?)',[$unit,$map,$student?'ready':'held',lf_json($reconciliation)]);
        }
        $actual=lf_source_fingerprint($db,$unit,$source);if($actual['count']!==$manifest['total']||$actual['fingerprint']!==$manifest['fingerprint'])throw new DomainException('Arsip belum lengkap atau fingerprint berbeda.');
        lf_query($db,'UPDATE legacy_finance_sources SET published=1 WHERE id=?',[$source]);lf_audit($db,$unit,$actor,null,'publish_archive',['source_id'=>$source,'fingerprint'=>$manifest['fingerprint'],'rows'=>$actual['count']]);if(!$outerTransaction)$db->commit();
    }catch(Throwable $e){if(!$outerTransaction)$db->rollback();throw $e;}
    if(!$outerTransaction)legacy_update($job['id'],['state'=>'imported','stage'=>'Arsip Tersimpan','done'=>$manifest['total'],'total'=>$manifest['total'],'message'=>'Histori tersimpan sebagai arsip. Saldo dan tunggakan belum diterapkan.']);
}
function lf_review_load(mysqli $db,int $id,bool $lock=false): array {
    $review=lf_one($db,'SELECT r.*,m.source_id,m.source_nis,m.source_name,m.student_id,m.match_status,m.reason,s.backup_hash,s.source_date FROM legacy_finance_reviews r JOIN legacy_finance_maps m ON m.id=r.map_id AND m.unit_id=r.unit_id JOIN legacy_finance_sources s ON s.id=m.source_id AND s.unit_id=m.unit_id WHERE r.id=? AND s.published=1'.($lock?' FOR UPDATE':''),[$id]);
    if(!$review)throw new DomainException('Peninjauan tidak ditemukan.');
    $review['savings_result']=json_decode($review['savings_result'],true,512,JSON_THROW_ON_ERROR);
    $review['savings_result']=lf_reconcile(lf_rows($db,"SELECT source_table,raw_data FROM legacy_finance_rows WHERE unit_id=? AND source_id=? AND source_identity=? AND source_table IN ('tabungan','transaksi_m','transaksi_k')",[$review['unit_id'],$review['source_id'],$review['source_nis']]),$review['source_date']);
    $review['additional_savings']=lf_rows($db,"SELECT source_table,raw_data FROM legacy_finance_rows WHERE unit_id=? AND source_id=? AND source_identity=? AND source_table IN ('tb_total','tb_masuk','tb_keluar')",[$review['unit_id'],$review['source_id'],$review['source_nis']]);
    $review['review_data']=$review['review_data']===null?null:json_decode($review['review_data'],true,512,JSON_THROW_ON_ERROR);
    $review['student']=$review['student_id']?lf_one($db,'SELECT id,unit_id,NO_INDUK,NAMA,KELAS,legacy_pending,is_active FROM siswa WHERE id=?',[$review['student_id']]):null;
    return $review;
}
function lf_version(array $review,int $version): void { if((int)$review['version']!==$version)throw new DomainException('Peninjauan berubah. Muat ulang dan periksa kembali.'); }
function lf_map(mysqli $db,int $actor,int $unit,int $reviewId,int $version,int $student,string $reason): void {
    lf_require_schema($db);if(mb_strlen(trim($reason))<10||mb_strlen($reason)>1000)throw new DomainException('Alasan pencocokan minimal 10 karakter.');
    $db->begin_transaction();try{
        lf_actor($db,$actor,$unit,true,true);$r=lf_review_load($db,$reviewId,true);lf_version($r,$version);
        if($r['status']==='applied')throw new DomainException('Pencocokan yang sudah diterapkan tidak dapat diubah.');
        if(!lf_one($db,'SELECT id FROM siswa WHERE id=? FOR UPDATE',[$student]))throw new DomainException('Siswa tujuan tidak ada pada unit ini.');
        if(lf_one($db,'SELECT id FROM legacy_finance_maps WHERE source_id=? AND student_id=? AND id<>? FOR UPDATE',[$r['source_id'],$student,$r['map_id']]))throw new DomainException('Siswa tujuan telah dipasangkan dengan identitas lain pada sumber ini.');
        lf_query($db,"UPDATE legacy_finance_maps SET student_id=?,match_status='manual',reason=? WHERE id=?",[$student,$reason,$r['map_id']]);
        lf_query($db,"UPDATE legacy_finance_reviews SET status='ready',version=version+1,review_data=NULL,reviewed_by=? WHERE id=?",[$actor,$reviewId]);lf_audit($db,$unit,$actor,$reviewId,'manual_mapping',['before'=>$r['student_id'],'after'=>$student,'reason'=>$reason]);$db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function lf_validate_proposal(array $proposal,array $review): array {
    $date=$proposal['effective_date']??'';$dt=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$date);
    if(!$dt||$dt->format('Y-m-d')!==$date||$date>date('Y-m-d')||$date<substr((string)$review['source_date'],0,10))throw new DomainException('Tanggal penerapan tidak valid.');
    $evidence=trim((string)($proposal['evidence']??''));if(mb_strlen($evidence)<10||mb_strlen($evidence)>1000||($proposal['attested']??false)!==true)throw new DomainException('Referensi bukti dan pengesahan nominal yang masih berlaku diperlukan.');
    $savings=($proposal['savings']??false)===true;$debts=$proposal['debts']??[];
    if(!is_array($debts)||count($debts)>20||(!$savings&&!$debts))throw new DomainException('Pilih saldo atau rincian tunggakan.');
    if($savings&&!$review['savings_result']['ready'])throw new DomainException('Rekening sumber ditahan: '.implode(' ',$review['savings_result']['issues']));
    $clean=[];$seen=[];
    foreach($debts as $debt){
        $component=trim((string)($debt['component']??''));$period=trim((string)($debt['period']??''));$amount=lf_cents($debt['amount']??null);
        if($amount<=0||mb_strlen($component)<2||mb_strlen($component)>45||preg_match('/[\x00-\x1f]/u',$component)||!preg_match('/^(?:[12][0-9]{3}-(?:0[1-9]|1[0-2])|[12][0-9]{3}\/[12][0-9]{3})$/D',$period))throw new DomainException('Komponen, periode, atau nominal tunggakan tidak valid.');
        $kind=(string)($debt['kind']??'other');if(!in_array($kind,['spp','komite','du','other'],true))throw new DomainException('Jenis kewajiban tidak valid.');
        $recognized=match(mb_strtolower(preg_replace('/[ _-]+/u','',$component))){'spp','uspp'=>'spp','komite','uangkomite','pomg','upomg'=>'komite','daftarulang','uangdaftarulang'=>'du',default=>null};
        if($recognized && $kind!==$recognized)throw new DomainException('Jenis kewajiban harus sesuai komponen yang sudah jelas.');
        if(substr($period,0,7)>substr((string)$review['source_date'],0,7) && strlen($period)===7)throw new DomainException('Periode tunggakan melampaui snapshot sumber.');
        if((int)substr($period,0,4)>(int)substr((string)$review['source_date'],0,4))throw new DomainException('Tahun kewajiban melampaui snapshot sumber.');
        $key=hash('sha256',($kind==='other'?'source:'.mb_strtolower(preg_replace('/\s+/u',' ',$component)):'kind:'.$kind).'|'.$period);if(isset($seen[$key]))throw new DomainException('Kewajiban ganda dalam pengesahan.');$seen[$key]=true;
        $source=(string)($debt['source_row_key']??'');if(!preg_match('/^[a-f0-9]{64}$/D',$source))throw new DomainException('Pilih bukti baris sumber.');
        $distinction=trim((string)($debt['distinction']??''));if(mb_strlen($distinction)<10||mb_strlen($distinction)>1000)throw new DomainException('Jelaskan pemeriksaan benturan dengan kewajiban existing.');
        $clean[]=['component'=>$component,'period'=>$period,'amount'=>lf_money($amount),'kind'=>$kind,'obligation_key'=>$key,'source_row_key'=>$source,'distinction'=>$distinction];
    }
    return ['effective_date'=>$date,'evidence'=>$evidence,'attested'=>true,'savings'=>$savings,'debts'=>$clean];
}
function lf_save_review(mysqli $db,int $actor,int $unit,int $id,int $version,array $proposal): array {
    lf_require_schema($db);$db->begin_transaction();try{
        lf_actor($db,$actor,$unit,true,true);$r=lf_review_load($db,$id,true);lf_version($r,$version);
        if($r['status']==='applied'||!$r['student_id'])throw new DomainException('Pemetaan belum siap atau sudah diterapkan.');
        $proposal=lf_validate_proposal($proposal,$r);
        lf_query($db,"UPDATE legacy_finance_reviews SET review_data=?,version=version+1,reviewed_by=?,status='ready' WHERE id=?",[lf_json($proposal),$actor,$id]);lf_audit($db,$unit,$actor,$id,'save_review',['proposal'=>$proposal,'version'=>$version+1]);$db->commit();return lf_review_load($db,$id);
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function lf_existing_position(mysqli $db,array $student,bool $lock=false): array {
    $nis=$student['NO_INDUK'];$unit=$student['unit_id'];$account=lf_one($db,'SELECT id,SALDO FROM tabungan WHERE NO_INDUK=? AND unit_id=?'.($lock?' FOR UPDATE':''),[$nis,$unit]);
    $opening=lf_one($db,'SELECT * FROM legacy_savings_openings WHERE student_id=? AND unit_id=?'.($lock?' FOR UPDATE':''),[$student['id'],$unit]);
    $in=lf_rows($db,'SELECT id,MASUK FROM transaksi_m WHERE NO_INDUK=? AND unit_id=?'.($lock?' FOR UPDATE':''),[$nis,$unit]);$out=lf_rows($db,'SELECT id,KELUAR FROM transaksi_k WHERE NO_INDUK=? AND unit_id=?'.($lock?' FOR UPDATE':''),[$nis,$unit]);
    return ['balance'=>$account['SALDO']??'0','account'=>$account,'opening'=>$opening,'incoming_count'=>count($in),'outgoing_count'=>count($out)];
}
function lf_apply(mysqli $db,int $actor,int $unit,int $id,int $version,string $confirmation): array {
    lf_require_schema($db);if($confirmation!=='TERAPKAN LEGACY')throw new DomainException('Konfirmasi penerapan tidak sesuai.');
    $db->begin_transaction();try{
        lf_actor($db,$actor,$unit,true,true);$r=lf_review_load($db,$id,true);
        // Repeated delivery after a committed response is safe, but cannot mutate a new version.
        if($r['status']==='applied'&&(int)$r['version']===$version+1){$db->commit();return $r;}
        lf_version($r,$version);if($r['status']!=='ready'||!$r['review_data']||!$r['student_id'])throw new DomainException('Peninjauan belum disahkan.');
        $student=lf_one($db,'SELECT * FROM siswa WHERE id=? FOR UPDATE',[$r['student_id']]);
        if(!$student||!$student['is_active']||$student['legacy_pending'])throw new DomainException('Aktifkan siswa tujuan terlebih dahulu.');
        $p=lf_validate_proposal($r['review_data'],$r);$position=lf_existing_position($db,$student,true);
        if($p['savings']){
            if((float)$position['balance']!==0.0||$position['incoming_count']||$position['outgoing_count']||$position['opening'])throw new DomainException('Rekening tujuan sudah memiliki saldo, jurnal, atau saldo awal.');
            $amount=$r['savings_result']['balance'];
            lf_query($db,'INSERT INTO legacy_savings_openings(unit_id,student_id,review_id,amount,effective_date,evidence,applied_by) VALUES(?,?,?,?,?,?,?)',[$unit,$student['id'],$id,$amount,$p['effective_date'],$p['evidence'],$actor]);
            if($position['account'])lf_query($db,'UPDATE tabungan SET SALDO=? WHERE id=?',[$amount,$position['account']['id']]);else lf_query($db,'INSERT INTO tabungan(unit_id,NO_INDUK,SALDO) VALUES(?,?,?)',[$unit,$student['NO_INDUK'],$amount]);
        }
        foreach($p['debts'] as $debt)lf_create_debt($db,$unit,$actor,$r,$student,$p,$debt);
        lf_query($db,"UPDATE legacy_finance_reviews SET status='applied',version=version+1,reviewed_by=? WHERE id=?",[$actor,$id]);lf_audit($db,$unit,$actor,$id,'apply',['student_id'=>$student['id'],'proposal'=>$p,'previous_position'=>$position]);$db->commit();return lf_review_load($db,$id);
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function lf_create_debt(mysqli $db,int $unit,int $actor,array $r,array $student,array $proposal,array $debt): void {
    if(!lf_one($db,'SELECT id FROM legacy_finance_rows WHERE source_id=? AND TRIM(source_nis)=? AND row_key=?',[$r['source_id'],$r['source_nis'],$debt['source_row_key']]))throw new DomainException('Bukti sumber tidak sesuai siswa.');
    if(lf_one($db,'SELECT id FROM legacy_debts WHERE student_id=? AND obligation_key=? FOR UPDATE',[$student['id'],$debt['obligation_key']]))throw new DomainException('Kewajiban ini telah diterapkan sebelumnya.');
    lf_check_debt_conflicts($db,$student['NO_INDUK'],$debt);
    $master=lf_one($db,'SELECT * FROM legacy_debt_masters WHERE obligation_key=? FOR UPDATE',[$debt['obligation_key']]);
    $label='Tunggakan Legacy · '.$debt['component'].' · '.$debt['period'];
    if(!$master){
        // Nominal master is a protected placeholder; each reviewed bill owns its amount.
        lf_query($db,'INSERT INTO master_biaya_lain(unit_id,nama,nominal,is_active) VALUES(?,?,1,1)',[$unit,$label]);$masterId=(int)$db->insert_id;
        lf_query($db,'INSERT INTO legacy_debt_masters(id,unit_id,component,period,obligation_key) VALUES(?,?,?,?,?)',[$masterId,$unit,$debt['component'],$debt['period'],$debt['obligation_key']]);
    }else $masterId=(int)$master['id'];
    $db->query('SET @legacy_finance_apply=1');
    try{lf_query($db,"INSERT INTO tagihan_biaya_lain(unit_id,master_biaya_lain_id,no_induk,master_kelas_id,nama_snapshot,nominal_tagihan,kelas_rombel_snapshot,status,created_by) VALUES(?,?,?,NULLIF(?,0),?,?,?,'open',?)",[$unit,$masterId,$student['NO_INDUK'],$student['master_kelas_id']??0,$label,$debt['amount'],$student['KELAS'],$actor]);$bill=(int)$db->insert_id;}
    finally{$db->query('SET @legacy_finance_apply=0');}
    lf_query($db,'INSERT INTO legacy_debts(unit_id,student_id,review_id,bill_id,master_id,obligation_key,amount,effective_date,evidence,source_row_key,applied_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$unit,$student['id'],$r['id'],$bill,$masterId,$debt['obligation_key'],$debt['amount'],$proposal['effective_date'],$proposal['evidence'],$debt['source_row_key'],$actor]);
}
function lf_check_debt_conflicts(mysqli $db,string $nis,array $debt): void {
    // Current reads share the same student lock as all new payments. An indistinguishable obligation is held.
    $period=$debt['period'];
    $other=lf_rows($db,"SELECT id,nama_snapshot FROM tagihan_biaya_lain WHERE no_induk=? AND status<>'cancelled' FOR UPDATE",[$nis]);
    foreach($other as $bill)if(mb_stripos($bill['nama_snapshot'],$debt['component'])!==false && str_contains($bill['nama_snapshot'],$period))throw new DomainException('Kewajiban yang sama sudah ada pada Biaya Lain.');
    if($debt['kind']==='other')return;
    $year=substr($period,0,4);$month=strlen($period)===7?(int)substr($period,5,2):null;
    if(in_array($debt['kind'],['spp','komite'],true)){
        if(!$month)throw new DomainException('SPP/Komite memerlukan periode YYYY-MM untuk pemeriksaan benturan.');
        $table=$debt['kind']==='spp'?'tagihan_spp':'tagihan_komite';
        if(lf_one($db,"SELECT id FROM $table WHERE no_induk=? AND tahun=? AND bulan=? AND status<>'cancelled' FOR UPDATE",[$nis,$year,$month]))throw new DomainException('Tagihan periode yang sama sudah ada.');
    }else{
        if(!preg_match('/^([0-9]{4})\/([0-9]{4})$/D',$period,$m)||(int)$m[2]!==1+(int)$m[1])throw new DomainException('Daftar Ulang memerlukan tahun ajaran YYYY/YYYY.');
        if(lf_one($db,"SELECT t.id FROM tagihan_daftar_ulang t JOIN tahun_ajaran y ON y.id=t.tahun_ajaran_id AND y.unit_id=t.unit_id WHERE t.no_induk=? AND y.label=? AND t.status<>'cancelled' FOR UPDATE",[$nis,$period]))throw new DomainException('Tagihan Daftar Ulang tahun yang sama sudah ada.');
    }
}
function lf_cancel_opening(mysqli $db,int $actor,int $unit,int $id,int $version,string $reason,string $confirmation): void {
    lf_require_schema($db);if($confirmation!=='BATALKAN SALDO AWAL'||mb_strlen(trim($reason))<10||mb_strlen($reason)>1000)throw new DomainException('Konfirmasi dan alasan pembatalan diperlukan.');
    $db->begin_transaction();try{
        lf_actor($db,$actor,$unit,true,true);$r=lf_review_load($db,$id,true);lf_version($r,$version);
        if(isset($r['review_data']['full_batch']))throw new DomainException('Saldo peralihan migrasi penuh tidak dapat dibatalkan dari peninjauan arsip. Gunakan prosedur pemulihan administratif.');
        $student=lf_one($db,'SELECT * FROM siswa WHERE id=? FOR UPDATE',[$r['student_id']]);if(!$student)throw new DomainException('Siswa tidak ditemukan.');$p=lf_existing_position($db,$student,true);$o=$p['opening'];
        if(!$o||$o['cancelled_at']||$p['incoming_count']||$p['outgoing_count']||(int)$o['review_id']!==$id||lf_cents((string)$o['amount'])!==(int)round((float)$p['balance']*100))throw new DomainException('Saldo awal sudah digunakan atau tidak konsisten; pembatalan ditahan.');
        $db->query('SET @legacy_finance_cancel=1');
        lf_query($db,'UPDATE legacy_savings_openings SET cancelled_at=CURRENT_TIMESTAMP,cancelled_by=?,cancel_reason=? WHERE id=?',[$actor,$reason,$o['id']]);
        lf_query($db,'UPDATE tabungan SET SALDO=0 WHERE id=?',[$p['account']['id']]);lf_query($db,'UPDATE legacy_finance_reviews SET version=version+1 WHERE id=?',[$id]);lf_audit($db,$unit,$actor,$id,'cancel_opening',['reason'=>$reason,'opening_id'=>$o['id']]);$db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}finally{$db->query('SET @legacy_finance_cancel=0');}
}
/** Optional ledger adapter: compatible before installation, no HTTP DDL. */
function lf_opening_for_student(mysqli $db,int $unit,int $student): ?array {
    require_once __DIR__.'/legacy_operational_read.php';if($op=lop_opening($db,$student,$unit))return $op;
    if(!legacy_finance_ready($db))return null;
    return lf_one($db,'SELECT amount,effective_date FROM legacy_savings_openings WHERE unit_id=? AND student_id=? AND cancelled_at IS NULL',[$unit,$student]);
}
function lf_reserved_master(mysqli $db,int $master): bool {
    return legacy_finance_ready($db) && (bool)lf_one($db,'SELECT id FROM legacy_debt_masters WHERE id=?',[$master]);
}
function lf_source_fingerprint(mysqli $db,int $unit,int $source): array {
    $context=(int)$db->query('SELECT current_unit_id()')->fetch_row()[0];if(!in_array($unit,[1,2,3],true)||($context!==0&&$context!==$unit))throw new DomainException('Unit fingerprint sumber tidak sesuai.');
    // The explicit checked unit/source pair lets the index serve large archives without
    // evaluating the nondeterministic unit-view function for every historical row.
    $s=$db->prepare('SELECT row_key FROM legacy_finance_rows_data WHERE unit_id=? AND source_id=? ORDER BY row_key');$s->bind_param('ii',$unit,$source);$s->execute();$rows=$s->get_result();$hash=hash_init('sha256');$count=0;
    while($row=$rows->fetch_row()){hash_update($hash,($count?"\n":'').$row[0]);$count++;}$s->close();return ['count'=>$count,'fingerprint'=>hash_final($hash)];
}
function lf_cancel_debts(mysqli $db,int $actor,int $unit,int $id,int $version,string $reason,string $confirmation): void {
    lf_require_schema($db);if($confirmation!=='BATALKAN TUNGGAKAN LEGACY'||mb_strlen(trim($reason))<10||mb_strlen($reason)>1000)throw new DomainException('Konfirmasi dan alasan pembatalan diperlukan.');
    $db->begin_transaction();try{
        lf_actor($db,$actor,$unit,true,true);$r=lf_review_load($db,$id,true);lf_version($r,$version);
        if($r['status']!=='applied')throw new DomainException('Tunggakan belum diterapkan.');
        if(!lf_one($db,'SELECT id FROM siswa WHERE id=? FOR UPDATE',[$r['student_id']]))throw new DomainException('Siswa tidak ditemukan.');
        $debts=lf_rows($db,"SELECT t.id,t.status FROM legacy_debts d JOIN tagihan_biaya_lain t ON t.id=d.bill_id AND t.unit_id=d.unit_id WHERE d.review_id=? AND t.status='open' FOR UPDATE",[$id]);
        if(!$debts)throw new DomainException('Tidak ada tunggakan terbuka.');
        foreach($debts as $debt){if(lf_rows($db,'SELECT id FROM bayar_biaya_lain WHERE tagihan_biaya_lain_id=? FOR UPDATE',[$debt['id']]))throw new DomainException('Tunggakan sudah menerima pembayaran. Gunakan alur otorisasi pembayaran terlebih dahulu.');}
        foreach($debts as $debt)lf_query($db,"UPDATE tagihan_biaya_lain SET status='cancelled',cancel_reason=? WHERE id=?",[$reason,$debt['id']]);
        lf_query($db,'UPDATE legacy_finance_reviews SET version=version+1 WHERE id=?',[$id]);lf_audit($db,$unit,$actor,$id,'cancel_debts',['reason'=>$reason,'bills'=>array_column($debts,'id')]);$db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
}
