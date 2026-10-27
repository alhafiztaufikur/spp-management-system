<?php
/** Prepared suggestions are not attestations. Every accepted decision is versioned. */
const LFUL_RULES_VERSION = 13;
require_once __DIR__.'/legacy_source_review.php';
function lful_next_period(mixed $cutoff): string {
    if(!is_string($cutoff)||$cutoff==='')return '';
    try{if(!lful_date($cutoff))return '';return (new DateTimeImmutable($cutoff,new DateTimeZone('Asia/Jakarta')))->modify('first day of next month')->format('Y-m');}catch(Throwable $ignored){return '';}
}
function lful_history_category(string $key): string { return 'histori:'.substr(hash('sha256',$key),0,32); }
function lful_history_label(string $table,string $column,array $raw): string {
    if(legacy_source_is_pomg($column,$raw))return 'Komite (POMG)';
    if(preg_match('/^JUMLAH([1-4])$/D',$column,$m))return trim((string)($raw['LAIN_LAIN'.$m[1]]??''))?:$column;
    return ['U_SPP'=>'SPP','U_PANGKAL'=>'Uang Pangkal','U_BANGUNAN'=>'Uang Bangunan','U_SERAGAM'=>'Uang Seragam','U_KEGIATAN'=>'Uang Kegiatan','U_MAKAN'=>'Uang Makan','U_INFAQ'=>'Infaq','U_LAIN'=>'Lain-Lain'][$column]??($table==='bayar_du'&&$column==='jumlah'?'Daftar Ulang':$column);
}
function lful_schema_signature(int $unit,?array $manifest=null): string {
    if(isset($manifest['source_schema_signature'])){legacy_source_manifest_check($manifest,$unit);return $manifest['source_schema_signature'];}
    static $cache=[];if(isset($cache[$unit]))return $cache[$unit];$profiles=json_decode(file_get_contents(__DIR__.'/../scripts/legacy_full_profile.json'),true,512,JSON_THROW_ON_ERROR);
    return $cache[$unit]=hash('sha256',lf_json($profiles[[1=>'SD',2=>'SMP',3=>'SMA'][$unit]]));
}
function lful_prepared(array $records,array $manifest): array {
    $students=[];$savings=[];$examples=[];
    foreach($records as $r){
        if($r['reference_only'])continue;$raw=$r['raw'];$id=lful_identity(legacy_source_identity($raw));if($id==='')continue;
        $students[$id]??=['identity'=>$id,'name'=>'','source_class'=>'','source_count'=>0,'record_count'=>0,'savings_records'=>0,'ordinal'=>null,'source_spp'=>null,'source_fees'=>[]];
        $s=&$students[$id];$s['record_count']++;
        if($r['table']==='siswa'){
            $s['identity_source']=array_intersect_key($raw,array_flip(['no_induk','NO_INDUK','NO_INDUK1']));$s['source_count']++;$s['name']=(string)($raw['NAMA']??'');$s['source_class']=trim((string)($raw['KELAS']??''));$s['ordinal']=$r['student_ordinal'];
            $s['source_spp']=$raw['SPP_PERBULAN']??null;$s['source_fees']=array_intersect_key($raw,array_flip(['POMG','PANGKAL','DAFTAR_ULANG','tot_du','potong_du']));
        }
        if(in_array($r['table'],['tabungan','transaksi_m','transaksi_k'],true)){$s['savings_records']++;$savings[$id][]=$r;}
        if($r['table']==='bayar'&&count($examples)<3)$examples[]=array_intersect_key($raw,array_flip(['U_SPP','potong_spp','U_LAIN','JUMLAH1','JUMLAH2','JUMLAH3','JUMLAH4']));unset($s);
    }
    foreach($students as $id=>&$s){
        $s['savings']=!empty($savings[$id])?lf_reconcile($savings[$id],$manifest['source_date']):null;
        if($s['savings']){$r=&$s['savings'];try{$r['journal_balance']=lf_money(lf_cents($r['incoming'])-lf_cents($r['outgoing']));$r['difference']=$r['balance']===null?null:lf_money(lf_cents($r['balance'])-lf_cents($r['journal_balance'],true));}catch(DomainException $e){$r['ready']=false;$r['issues'][]=$e->getMessage();$r['journal_balance']=null;$r['difference']=null;}unset($r);}
    }unset($s);
    return ['students'=>array_values($students),'accounting_examples'=>$examples,'additional_savings'=>lful_additional_savings($records,$manifest['source_date']??null),'schema_signature'=>lful_schema_signature((int)$manifest['unit'],$manifest),'rules_version'=>LFUL_RULES_VERSION];
}
function lful_profile_path(int $unit,?array $manifest=null): string { return legacy_root().'/profile-'.$unit.'-'.lful_schema_signature($unit,$manifest).'.json'; }
function lful_profile_read(int $unit,?array $manifest=null): array {
    $path=lful_profile_path($unit,$manifest);if(!is_file($path))return [];
    try{$p=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);}catch(Throwable $ignored){return [];}
    return is_array($p)&&($p['rules_version']??0)===LFUL_RULES_VERSION&&($p['unit']??0)===$unit?$p:[];
}
function lful_profile_save(mysqli $db,string $id,int $actor): void {
    $d=lful_draft($id);$unit=(int)$d['job_info']['unit'];lf_actor($db,$actor,$unit,true);
    $cfg=$d['settings'];$components=[];
    foreach($cfg['components'] as $key=>$map){
        if(mb_strlen(trim((string)($map['reason']??'')))<10)continue;
        if(($map['category']??'')==='savings_reference'&&($map['confirmed']??false)!==true)continue;
        if(str_starts_with($map['category']??'','biaya_lain:')){$m=lf_one($db,'SELECT nama FROM master_biaya_lain WHERE id=?',[(int)substr($map['category'],11)]);if(!$m)continue;$map=['master_name'=>$m['nama']];}
        elseif(str_starts_with($map['category']??'','biaya_baru:'))continue;
        else $map=['category'=>$map['category']??''];$components[$key]=$map;
    }
    $classRules=[];$classCandidates=[];$cat=lful_catalog_read($id);foreach($cat['students'] as $source){$s=$cfg['students'][$source['identity']]??[];if(($s['provenance']['type']??'')!=='group'||!in_array($s['kind']??'',['active','alumni'],true))continue;$cl=lf_one($db,'SELECT * FROM master_kelas WHERE id=?',[(int)($s['class_id']??0)]);$rule=['kind'=>$s['kind'],'class_label'=>$cl?class_label($cl):''];$classCandidates[$source['source_class']][lf_json($rule)]=$rule;}foreach($classCandidates as $sourceClass=>$rules)if(count($rules)===1)$classRules[$sourceClass]=array_values($rules)[0];
    $p=['rules_version'=>LFUL_RULES_VERSION,'unit'=>$unit,'components'=>$components,'class_rules'=>$classRules,'accounting'=>array_intersect_key($cfg,array_flip(['other_policy','discount_policy','du_policy'])),'actor'=>$actor,'saved_at'=>time()];
    $path=lful_profile_path($unit,$cat['manifest']);$tmp=$path.'.'.bin2hex(random_bytes(8)).'.tmp';file_put_contents($tmp,lf_json($p),LOCK_EX);if(!rename($tmp,$path))throw new DomainException('Profil belum dapat disimpan.');
}
function lful_masters_digest(mysqli $db): string {
    $rows=[];foreach(['tahun_ajaran','master_kelas','master_spp_tahun','master_spp_tarif','master_biaya_lain'] as $t)$rows[$t]=lf_rows($db,"SELECT * FROM $t ORDER BY id");return hash('sha256',lf_json($rows));
}
function lful_suggestions(mysqli $db,string $id): array {
    $d=lful_draft($id);$unit=(int)$d['job_info']['unit'];unit_set_context($db,$unit);if(PHP_SAPI==='cli')$_SESSION['active_unit_id']=$unit;$cat=lful_catalog_read($id);$cfg=$d['settings'];$profile=lful_profile_read($unit,$cat['manifest']);$classes=lf_rows($db,'SELECT * FROM master_kelas WHERE is_active=1 AND is_placeholder=0 AND tingkat>0');$masterNames=lf_rows($db,'SELECT id,nama FROM master_biaya_lain');
    $groups=[];$students=[];$stats=['ready'=>0,'review'=>0,'blocked'=>0];$issuesBy=[];
    foreach($d['simulation']['issues']??[] as $issue)$issuesBy[(string)$issue['identity']][]=$issue['message'];
    foreach($cat['students'] as $s){
        $key=(string)$s['identity'];$old=$cfg['students'][$key]??[];$issues=$issuesBy[$key]??[];$valid=!legacy_source_identity_issues(['NO_INDUK'=>$key]+($s['identity_source']??[]))&&$s['source_count']===1&&preg_match('/^[0-9]{1,10}$/D',$key)&&trim($s['name'])!=='';
        if(($old['kind']??'')!=='non_student'){if(!$valid)$issues[]='Identitas sumber perlu diperiksa.';if($s['savings']&&!$s['savings']['ready'])$issues=array_merge($issues,$s['savings']['issues']);}
        $matching=array_values(array_filter($classes,fn($cl)=>mb_strtoupper(preg_replace('/\s+/u','',class_label($cl)))===mb_strtoupper(preg_replace('/\s+/u','',$s['source_class']))));
        $rule=$profile['class_rules'][$s['source_class']]??[];foreach($classes as $cl)if(($rule['class_label']??'')===class_label($cl))$matching=[$cl];
        $sourceKomite=null;try{$sourceKomite=legacy_source_komite($s['source_fees']);}catch(DomainException $e){$issues[]='POMG (Komite): nominal sumber perlu diperiksa.';}
        $classId=count($matching)===1?(int)$matching[0]['id']:0;$status=$issues?'blocked':(($old['kind']??'')&&($old['obligations_attested']??false)&&(!$s['savings']||!isset($cfg['workflow_version'])||($old['savings_attested']??false))?'ready':'review');$stats[$status]++;
        if(($old['kind']??'')==='non_student'&&!$issues){$stats[$status]--;$status=$s['record_count']===$s['source_count']||(($old['non_student_settled']??false)&&mb_strlen(trim((string)($old['non_student_resolution']??'')))>=10)?'ready':'review';$stats[$status]++;}
        $suggestion=['nis'=>$key,'name'=>$s['name'],'class_id'=>$classId,'kind'=>$rule['kind']??'','komite'=>$sourceKomite??'0.00','psb'=>'0.00','du'=>'0.00','discount'=>'0.00','coverage'=>[]];
        if($cfg['cutoff']){try{$suggestion['start_period']=(new DateTimeImmutable($cfg['cutoff'],new DateTimeZone('Asia/Jakarta')))->modify('first day of next month')->format('Y-m');}catch(Throwable $ignored){}}
        $students[$key]=$s+['suggested'=>$suggestion,'current'=>$old,'status'=>$status,'issues'=>array_values(array_unique($issues))];
        $group=$s['source_class'];$groups[$group]??=['source_class'=>$group,'count'=>0,'identities'=>[],'examples'=>[],'class_id'=>$classId,'kind'=>$rule['kind']??'','rates'=>[],'komite_source_count'=>0];$g=&$groups[$group];$g['count']++;if($sourceKomite!==null)$g['komite_source_count']++;$g['identities'][]=$key;if(count($g['examples'])<3)$g['examples'][]=['identity'=>$key,'name'=>$s['name']];$v=(string)($s['source_spp']??'Tidak tercatat');$g['rates'][$v]=($g['rates'][$v]??0)+1;unset($g);
    }
    $components=[];foreach($cat['components'] as $c){$key=$c['key'];$saved=$profile['components'][$key]??[];$target=$c['suggestion']?:lful_history_category($key);$origin='Usulan berdasarkan kolom sumber';
        if(isset($saved['master_name'])){$target='';$origin='Master dalam profil tidak tersedia; pilih pasangan kembali';foreach($masterNames as $m)if($m['nama']===$saved['master_name']){$target='biaya_lain:'.$m['id'];$origin='Profil unit yang pernah disahkan';}}
        elseif(isset($saved['category'])){$target=$saved['category'];$origin='Profil unit yang pernah disahkan';}
        if($c['column']==='U_SORGA'){$target='komite';$origin='U_SORGA adalah Komite/POMG pada semua unit, pengesahan 24 Oktober 2026';}
        if(!empty($c['is_pomg'])){$target='komite';$origin='POMG adalah Komite, sesuai pengesahan yayasan';}
        if($c['savings'])$target='savings_reference';$components[$key]=$c+['suggested_category'=>$target,'origin'=>$origin];
    }
    return ['version'=>(int)$d['version'],'groups'=>array_values($groups),'students'=>$students,'components'=>$components,'counts'=>$stats,'accounting_suggestions'=>$profile['accounting']??[],'masters_digest'=>lful_masters_digest($db),'source_fingerprint'=>$cat['manifest']['fingerprint']];
}
/** A preview binds the exact identities, settings and current target masters. */
function lful_group_preview(mysqli $db,string $id,int $version,array $input): array {
    $d=lful_draft($id);if((int)$d['version']!==$version)throw new DomainException('Peninjauan berubah. Muat ulang.');
    if(!in_array($d['job_info']['state'],['ready','failed'],true)||lf_one($db,'SELECT id FROM legacy_full_batches WHERE id=?',[$id]))throw new DomainException('Pekerjaan tidak dapat diubah.');
    $suggest=lful_suggestions($db,$id);$type=$input['type']??'students';if(!in_array($type,['students','components','obligations','savings'],true))throw new DomainException('Jenis kelompok tidak tersedia.');
    $ids=$input['identities']??[];if(!is_array($ids)||!$ids||count($ids)>5000||array_filter($ids,fn($v)=>!is_string($v)))throw new DomainException('Pilih data yang akan diperiksa.');$ids=array_values(array_unique($ids));sort($ids,SORT_STRING);
    $settings=$input['settings']??[];if(!is_array($settings))throw new DomainException('Pengaturan kelompok tidak valid.');$evidence=trim((string)($input['evidence']??''));if(mb_strlen($evidence)<10||mb_strlen($evidence)>1000)throw new DomainException('Isi bukti pengesahan kelompok minimal 10 karakter.');
    if($type==='obligations'&&($settings['confirmed']??false)!==true)throw new DomainException('Sahkan pemeriksaan seluruh kewajiban kelompok.');
    if($type==='students'&&!in_array($settings['kind']??'',['active','alumni'],true))throw new DomainException('Pilih status siswa aktif atau alumni.');
    $replace=($input['replace']??false)===true;$patch=[];$skipped=[];$samples=[];$exceptions=[];
    foreach($ids as $identity){
        if($type==='components'){
            $c=$suggest['components'][$identity]??null;if(!$c)throw new DomainException('Komponen tidak ditemukan.');$old=$d['settings']['components'][$identity]??[];
            if($old&&!$replace){$skipped[]=$identity;continue;}$category=$settings['category']??$c['suggested_category'];
            if(str_starts_with($category,'histori:')&&$category!==lful_history_category($identity))throw new DomainException('Kategori histori tidak sesuai komponen.');
            if($category===''||($c['savings']&&(($settings['confirmed']??false)!==true))){$exceptions[]=$identity;continue;}
            $patch['components'][$identity]=['category'=>$category,'reason'=>$evidence,'confirmed'=>($settings['confirmed']??false)===true,'provenance'=>['type'=>'group','source'=>$suggest['source_fingerprint']]];
            $samples[]=['identity'=>$identity,'name'=>$c['name'],'before'=>$old,'after'=>$patch['components'][$identity],'display'=>str_starts_with($category,'histori:')?'Histori Saja':($category==='savings_reference'?'Tabungan Â· referensi jurnal':$category)];continue;
        }
        $s=$suggest['students'][$identity]??null;if(!$s)throw new DomainException('Identitas kelompok tidak ditemukan.');$old=$s['current'];
        if($type==='students'){
            if($s['source_count']!==1){$exceptions[]=$identity;continue;}
            if($old&&!$replace){$skipped[]=$identity;continue;}
            $values=array_intersect_key($settings,array_flip(['kind','class_id','start_period','komite','psb','du','discount']));
            if(($settings['komite_from_source']??false)===true){
                try{$sourceKomite=legacy_source_komite($s['source_fees']);}catch(DomainException $e){$sourceKomite=null;}
                if($sourceKomite===null){$exceptions[]=$identity;continue;}
                $values['komite']=$sourceKomite;
            }
            if(($values['kind']??'')==='active'){
                $cl=lf_one($db,'SELECT * FROM master_kelas WHERE id=? AND is_active=1 AND is_placeholder=0',[(int)($values['class_id']??0)]);if(!$cl)throw new DomainException('Pilih kelas tujuan.');
                $rate=spp_current_effective_rate($db,(string)$cl['tingkat'],(float)($values['discount']??0),lf_one($db,'SELECT label FROM tahun_ajaran WHERE id=?',[(int)$d['settings']['year_id']])['label']??'');
                try{$source=lf_cents($s['source_spp']);}catch(DomainException $e){$source=null;}
                if($source===null||$source!==(int)round(($rate['net']??-1)*100)){$exceptions[]=$identity;continue;}
            }
            $next=array_replace($s['suggested'],$old,$values,['reason'=>$evidence,'provenance'=>['type'=>'group','source'=>$suggest['source_fingerprint']]]);
        }elseif($type==='savings'){
            if(empty($old['kind']))throw new DomainException('Sahkan status siswa sebelum saldo: '.$identity);
            if(!$s['savings']||!$s['savings']['ready'])throw new DomainException('Saldo kelompok belum cocok: '.$identity);
            $next=$old;$next['savings_attested']=true;$next['savings_evidence']=$evidence;
        }else{
            if(!$old)throw new DomainException('Sahkan status siswa terlebih dahulu: '.$identity);
            if(($old['provenance']['type']??'')==='individual'&&!$replace){$skipped[]=$identity;continue;}
            $next=$old;$coverage=$settings['coverage']??[];if(!is_array($coverage))throw new DomainException('Rincian kewajiban tidak valid.');$existing=$old['coverage']??[];
            foreach($coverage as $c){if(!is_array($c))throw new DomainException('Rincian kewajiban tidak valid.');$c['evidence']=$evidence;$found=false;
                foreach($existing as &$previous)if(($previous['category']??'')===($c['category']??'')&&($previous['period']??'')===($c['period']??'')){$found=true;if($replace)$previous=$c;else $skipped[]=$identity.'|'.$c['category'].'|'.$c['period'];}unset($previous);if(!$found)$existing[]=$c;
            }
            $next['coverage']=$existing;$next['obligations_attested']=true;$next['obligations_evidence']=$evidence;
        }
        $display=match($type){'savings'=>'Saldo sumber '.$s['savings']['balance'].' Â· jurnal '.$s['savings']['journal_balance'].' Â· selisih '.$s['savings']['difference'],'obligations'=>count($next['coverage']).' rincian periode; seluruh sisa kewajiban disahkan',default=>($next['kind']==='alumni'?'Alumni Â· tanpa tagihan baru':'Siswa aktif Â· mulai '.$next['start_period'].' Â· potongan '.$next['discount'])};
        $patch['students'][$identity]=$next;$samples[]=['identity'=>$identity,'name'=>$s['name'],'before'=>$old,'after'=>$next,'display'=>$display];
    }
    $payload=['type'=>$type,'identities'=>$ids,'settings'=>$settings,'evidence'=>$evidence,'replace'=>$replace];
    $digest=hash('sha256',lf_json([$id,$version,$suggest['source_fingerprint'],$suggest['masters_digest'],$payload,$patch]));
    return ['digest'=>$digest,'version'=>$version,'input'=>$payload,'patch'=>$patch,'samples'=>$samples,'count'=>count($samples),'skipped'=>$skipped,'exceptions'=>$exceptions];
}
function lful_group_accept(mysqli $db,int $actor,int $unit,string $id,int $version,array $input,string $digest): array {
    lf_actor($db,$actor,$unit,true);$preview=lful_group_preview($db,$id,$version,$input);
    if(!hash_equals($preview['digest'],$digest))throw new DomainException('Pratinjau berubah. Periksa kelompok kembali.');
    if(!$preview['count'])throw new DomainException('Tidak ada data yang dapat diterapkan. Periksa pengecualian.');
    // Add audit metadata after validating the deterministic preview.
    foreach(['students','components'] as $section)if(isset($preview['patch'][$section]))foreach($preview['patch'][$section] as &$decision){$decision['review_audit']=['actor'=>$actor,'at'=>gmdate('c'),'version'=>$version,'preview'=>$digest,'type'=>$input['type']??'students'];}unset($decision);
    return lful_save($db,$actor,$unit,$id,$version,$preview['patch']+['workflow_version'=>LFUL_RULES_VERSION]);
}
function lful_preflight(mysqli $db): array {
    $unit=unit_active_id();try{$health=legacy_readiness($db);$heartFile=legacy_root().'/heartbeat.json';$heart=is_file($heartFile)?json_decode(file_get_contents($heartFile),true):[];}catch(Throwable $ignored){$health=['ready'=>false,'full_worker'=>false];$heart=[];}$checks=[];
    $add=static function($label,$ok,$help,$href='')use(&$checks){$checks[]=['label'=>$label,'ready'=>(bool)$ok,'help'=>$ok?'Siap':$help,'href'=>$href];};
    $add('Skema migrasi',legacy_full_ready($db),'Administrator perlu memasang skema melalui CLI.');
    $add('Worker importer',$health['full_worker']&&($heart['workflow_version']??0)>=LFUL_RULES_VERSION&&time()-($heart['time']??0)<=15,'Mulai ulang worker Import Legacy dengan source terbaru.');$add('SQL Server',!empty($heart['sql_ready']),'Siapkan atau mulai ulang layanan importer.');
    $empty=false;if($unit&&legacy_full_ready($db))try{lful_empty($db,$unit);$empty=true;}catch(DomainException $ignored){}
    $add('Unit tujuan',$empty,$unit?'Unit berisi data. Gunakan server bersih untuk Migrasi Penuh.':'Pilih SD, SMP, atau SMA di sidebar.');
    $years=lf_rows($db,"SELECT id FROM tahun_ajaran WHERE status<>'closed'");$add('Tahun ajaran',(bool)$years,'Siapkan tahun ajaran tujuan.','master_spp.php');$add('Kelas/rombel',(bool)lf_one($db,'SELECT id FROM master_kelas WHERE is_active=1 AND is_placeholder=0 AND tingkat>0'),'Lengkapi kelas/rombel tujuan.','master_kelas.php');
    $rates=false;foreach($years as $y){$m=lf_one($db,"SELECT id FROM master_spp_tahun WHERE tahun_ajaran_id=? AND status<>'closed'",[$y['id']]);if($m&&!in_array(0.0,spp_master_rates($db,(int)$m['id']),true))$rates=true;}$add('Tarif SPP',$rates,'Lengkapi tarif seluruh tingkat.','master_spp.php');
    $backup=false;try{require_once __DIR__.'/database_backup.php';$c=db_backup_config();db_backup_assert_application($c,$db);db_backup_store();$backup=db_backup_dispatch_tools($c)!==null;}catch(Throwable $ignored){}
    $add('Backup pengaman',$backup,'Pasang layanan backup dan lakukan uji pemulihan.','backup_restore.php');$add('Penyimpanan importer',$health['ready'],'Periksa worker, penyimpanan privat, dan ruang disk.');return $checks;
}
function lful_queue_simulation(mysqli $db,int $actor,int $unit,string $id,int $version): void {
    lf_actor($db,$actor,$unit,true);$d=lful_draft($id);if((int)$d['job_info']['unit']!==$unit||(int)$d['version']!==$version)throw new DomainException('Peninjauan atau unit berubah. Muat ulang.');
    if(in_array($d['job_info']['state'],['simulate_queued','simulating'],true))return;
    $operational=($d['settings']['mode']??'')==='operational';
    if(!in_array($d['job_info']['state'],$operational?['ready','failed','imported']:['ready','failed'],true)||(!$operational&&lf_one($db,'SELECT id FROM legacy_full_batches WHERE id=?',[$id])))throw new DomainException('Pekerjaan tidak dapat disimulasikan.');
    $q=lful_store();$q->exec('BEGIN IMMEDIATE');try{$s=$q->prepare('UPDATE full_drafts SET simulation=NULL,confirmed=NULL WHERE job=? AND version=?');$s->execute([$id,$version]);if($s->rowCount()!==1)throw new DomainException('Peninjauan berubah. Muat ulang.');$s=$q->prepare("UPDATE jobs SET state='simulate_queued',actor=?,cancel=0,stage='Menunggu Simulasi',message='Simulasi masuk antrean.',updated=? WHERE id=? AND state IN ('ready','failed','imported')");$s->execute([$actor,time(),$id]);if($s->rowCount()!==1)throw new DomainException('Pekerjaan berubah. Muat ulang.');$q->exec('COMMIT');}catch(Throwable $e){$q->exec('ROLLBACK');throw $e;}
}
