<?php
require_once __DIR__.'/legacy_import.php';
require_once __DIR__.'/legacy_finance.php';
require_once __DIR__.'/legacy_full_schema.php';
require_once __DIR__.'/spp_billing.php';
require_once __DIR__.'/kelas.php';
require_once __DIR__.'/legacy_full_components.php';
require_once __DIR__.'/legacy_full_workflow.php';
require_once __DIR__.'/legacy_operational.php';
require_once __DIR__.'/legacy_application_wait.php';

function lful_require(mysqli $db): void {if(!legacy_full_ready($db))throw new DomainException('Migrasi penuh belum siap. Pasang skema melalui CLI.');}
function lful_identity(mixed $v): string {return mb_strtoupper(trim((string)$v));}
function lful_store(): PDO {
    $q=legacy_queue();$q->exec("CREATE TABLE IF NOT EXISTS full_drafts(job TEXT PRIMARY KEY,version INTEGER NOT NULL DEFAULT 1,settings TEXT NOT NULL,simulation TEXT,confirmed TEXT);");return $q;
}
function lful_draft(string $id): array {
    $job=legacy_job($id);if(($job['category']??'')!=='full')throw new DomainException('Bukan pekerjaan migrasi penuh.');
    $s=lful_store()->prepare('SELECT * FROM full_drafts WHERE job=?');$s->execute([$id]);$d=$s->fetch(PDO::FETCH_ASSOC);
    if(!$d)throw new DomainException('Pemeriksaan sumber belum selesai.');foreach(['settings','simulation','confirmed'] as $k)$d[$k]=$d[$k]===null?null:json_decode($d[$k],true,512,JSON_THROW_ON_ERROR);$d['job_info']=$job;return $d;
}
function lful_empty(mysqli $db,int $unit,bool $lock=false): void {
    foreach(['siswa','bayar','bayar_du','tabungan','transaksi_m','transaksi_k','tagihan_spp','tagihan_komite','tagihan_daftar_ulang','tagihan_biaya_lain','siswa_tahun_ajaran'] as $table){
        if(lf_one($db,"SELECT id FROM $table WHERE unit_id=? LIMIT 1".($lock?' FOR UPDATE':''),[$unit]))throw new DomainException('Migrasi penuh memerlukan unit kosong. Data existing ditemukan pada '.$table.'. Tidak ada data dihapus.');
    }
    if(lf_one($db,'SELECT id FROM legacy_full_batches WHERE unit_id=?',[$unit]))throw new DomainException('Unit ini sudah memiliki migrasi penuh.');
}
function lful_component_key(string $table,string $column,array $raw): string {
    $label=preg_match('/^JUMLAH([1-4])$/D',$column,$m)?trim((string)($raw['LAIN_LAIN'.$m[1]]??'')):'';
    return $table.'.'.$column.($label!==''?':'.substr(hash('sha256',$label),0,16):'');
}
function lful_catalog(array $records,array $config=[]): array {
    return lcs_catalog(lful_component_catalog($records),$config);
}
function lful_stage(mysqli $db,array $job): void {
    lful_require($db);lf_actor($db,(int)$job['actor'],(int)$job['unit'],true);lful_empty($db,(int)$job['unit']);lf_stage($db,$job);
    [$records,$manifest]=lf_extract_read($job,true);
    $q=lful_store();$saved=$q->prepare('SELECT settings FROM full_drafts WHERE job=?');$saved->execute([$job['id']]);$existing=$saved->fetchColumn();
    $ruleFile=legacy_root().'/'.$job['id'].'/migration-rules.json';$rules=is_file($ruleFile)?json_decode(file_get_contents($ruleFile),true,32,JSON_THROW_ON_ERROR):[];
    $newRule=($rules['job']??'')===$job['id']&&(int)($rules['unit']??0)===(int)$job['unit']&&($rules['source_hash']??'')===$job['hash']&&($rules['komite_mapping_version']??0)===LCS_KOMITE_MAPPING_VERSION;
    $settings=$existing!==false?json_decode($existing,true,512,JSON_THROW_ON_ERROR):['komite_mapping_version'=>$newRule?LCS_KOMITE_MAPPING_VERSION:0,'cutoff'=>'','year_id'=>0,'evidence'=>'','accounting_reason'=>'','other_policy'=>'','discount_policy'=>'','du_policy'=>'','notes_accepted'=>false,'components'=>[],'students'=>[],'new_masters'=>[]];
    if($existing===false&&is_file(legacy_root().'/'.$job['id'].'/operational-request.json')){
        $settings=lop_source_setup($records,$manifest,$settings,(int)$job['unit']);
        $settings['year_id']=lop_default_year($db);
    }
    $components=lful_catalog($records,$settings);$settings=lcs_configure($settings,$components);
    $q->prepare('INSERT OR IGNORE INTO full_drafts(job,settings) VALUES(?,?)')->execute([$job['id'],lf_json($settings)]);
    file_put_contents(legacy_root().'/'.$job['id'].'/full_catalog.json',lf_json(lful_prepared($records,$manifest)+['component_catalog_version'=>4,'source_fee_masters'=>lful_source_fee_masters($records),'components'=>array_values($components),'manifest'=>$manifest]));
    if(($settings['mode']??'')==='operational')lop_review_source($job);
    legacy_update($job['id'],['state'=>'ready','stage'=>'Lengkapi Data','message'=>'Sumber diperiksa. Lengkapi pemetaan dan jalankan simulasi sebelum migrasi.']);
}
function lful_catalog_read(string $id): array {
    $d=lful_draft($id);$file=legacy_root().'/'.$id.'/full_catalog.json';if(is_file($file)){
        $catalog=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
        legacy_source_manifest_check($catalog['manifest'],(int)$d['job_info']['unit']);
        $dir=legacy_root().'/'.$id;
        if(!is_file($dir.'/source.dat')||!is_file($dir.'/finance.jsonl')||!hash_equals($d['job_info']['hash'],hash_file('sha256',$dir.'/source.dat'))||!hash_equals((string)$catalog['manifest']['rows_sha256'],hash_file('sha256',$dir.'/finance.jsonl')))throw new DomainException('Sumber berubah atau tidak lengkap. Unggah dan periksa ulang backup.');
        if(($catalog['component_catalog_version']??0)<4||($catalog['rules_version']??0)!==LFUL_RULES_VERSION){[$records,$manifest]=lf_extract_read($d['job_info'],true);$catalog=array_replace($catalog,lful_prepared($records,$manifest),['source_fee_masters'=>lful_source_fee_masters($records),'components'=>array_values(lful_catalog($records)),'component_catalog_version'=>4]);file_put_contents($file,lf_json($catalog),LOCK_EX);}
        if(lma_enabled($d['settings']))$catalog['components']=lma_catalog_components($catalog['components']);
        $catalog['components']=lcs_catalog($catalog['components'],$d['settings']);
        return $catalog;
    }
    $db=$GLOBALS['koneksi']??null;if($db instanceof mysqli){$source=lf_one($db,'SELECT s.manifest FROM legacy_full_batches b JOIN legacy_finance_sources s ON s.id=b.source_id AND s.unit_id=b.unit_id WHERE b.id=?',[$id]);if($source)return ['students'=>[],'components'=>[],'manifest'=>json_decode($source['manifest'],true,512,JSON_THROW_ON_ERROR)];}
    throw new DomainException('Katalog sumber tidak tersedia. Pulihkan penyimpanan importer atau gunakan verifikasi administratif.');
}
function lful_save(mysqli $db,int $actor,int $unit,string $id,int $version,array $patch): array {
    lful_require($db);lf_actor($db,$actor,$unit,true);$d=lful_draft($id);if((int)$d['job_info']['unit']!==$unit)throw new DomainException('Unit tidak sesuai.');
    $opBatch=lop_ready($db)?lf_one($db,'SELECT manifest FROM legacy_full_batches WHERE id=?',[$id]):null;
    $resume=$opBatch&&(json_decode($opBatch['manifest'],true)['mode']??'')==='operational';
    if($resume&&lf_one($db,"SELECT id FROM legacy_full_batches WHERE id=? AND state<>'completed'",[$id]))throw new DomainException('Selesaikan verifikasi hasil sebelumnya sebelum mengubah draf.');
    if(!in_array($d['job_info']['state'],$resume?['ready','failed','imported']:['ready','failed'],true))throw new DomainException('Pekerjaan tidak dapat diubah.');if(!$resume)lful_empty($db,$unit);
    $config=$d['settings'];
    if($resume)foreach(['mode','cutoff','year_id'] as $fixed)if(array_key_exists($fixed,$patch)&&($fixed==='cutoff'?lop_date($patch[$fixed])!==lop_date($config[$fixed]):(string)$patch[$fixed]!==(string)$config[$fixed]))throw new DomainException('Sumber dan waktu peralihan batch yang diterapkan tetap dikunci.');
    foreach(['op_groups','op_status_rules'] as $key)if(isset($patch[$key])&&(!is_array($patch[$key])||count($patch[$key])>5000))throw new DomainException('Kelompok atau aturan status tidak valid.');
    if(array_key_exists('mode',$patch))$config['mode']=$patch['mode'];
    foreach(['op_groups','op_status_rules'] as $k)if(array_key_exists($k,$patch))foreach($patch[$k] as $key=>$value){if(!is_array($value))throw new DomainException('Kelompok atau aturan status tidak valid.');$config[$k][$key]=array_replace($config[$k][$key]??[],$value);}
    if(isset($config['mode'])&&!in_array($config['mode'],['archive','operational'],true))throw new DomainException('Mode tidak valid.');
    if(($config['mode']??'')==='operational')lop_require($db);
    if(($config['mode']??'')==='operational')foreach($config['op_groups']??[] as $key=>$group){if(!is_array($group))throw new DomainException('Kelompok tidak valid.');if(!empty($group['target_class_id'])){$class=class_find($db,(int)$group['target_class_id'],true);if(!$class||$class['is_placeholder']||(int)$class['tingkat']===0)throw new DomainException('Rombel tujuan harus kelas akademik aktif pada unit ini.');$group['level']=(int)$class['tingkat'];$group['rombel']=$class['kode_rombel'];}else{unset($group['level'],$group['rombel']);}$config['op_groups'][$key]=$group;}
    foreach(['cutoff','year_id','evidence','other_policy','discount_policy','du_policy','accounting_reason','notes_accepted','workflow_version','op_bulk_audit'] as $k)if(array_key_exists($k,$patch))$config[$k]=$patch[$k];
    foreach(['op_import_unmatched','op_accept_matching_savings'] as $flag)if(array_key_exists($flag,$patch)){
        if(!is_bool($patch[$flag]))throw new DomainException('Pilihan persetujuan tidak valid.');$config[$flag]=$patch[$flag];
    }
    if(isset($patch['op_bulk_audit'])){
        if(!is_array($patch['op_bulk_audit'])||!in_array($patch['op_bulk_audit']['scope']??'',['all','students','components','savings','payments'],true))throw new DomainException('Pengesahan kelompok tidak valid.');
        $config['op_bulk_audit']=array_replace($patch['op_bulk_audit'],['actor'=>$actor,'accepted_at'=>date(DATE_ATOM),'source_version'=>$version]);
    }
    foreach(['students','components','additional_savings','op_savings','op_journals'] as $section)if(isset($patch[$section])){if(!is_array($patch[$section])||count($patch[$section])>5000)throw new DomainException('Pemetaan tidak valid.');foreach($patch[$section] as $key=>$value){if(!is_array($value))throw new DomainException('Pemetaan tidak valid.');if($section==='components'&&(lma_enabled($config)||lcs_komite_enabled($config))&&isset($value['category'])&&!isset($value['automatic_mapping']))$value['automatic_mapping']=false;$config[$section][(string)$key]=array_replace($config[$section][(string)$key]??[],$value);}}
    if(isset($patch['components'])&&lcs_komite_enabled($config)){$catalog??=lful_catalog_read($id);$config=lcs_configure($config,$catalog['components']);}
    foreach($patch['op_savings']??[] as $fix){if(($fix['confirmed']??false)!==true||mb_strlen(trim((string)($fix['evidence']??'')))<10||mb_strlen((string)($fix['evidence']??''))>1000)throw new DomainException('Sahkan saldo FIX dan isi bukti minimal 10 karakter.');lf_cents($fix['amount']??null);}
    foreach($patch['additional_savings']??[] as $key=>$decision)if(($decision['reason']??'')==='replace_main'){
        $catalog??=lful_catalog_read($id);$ledger=$catalog['additional_savings'][$key]??null;$target=$decision['target_identity']??null;
        if(!is_string($target)||!$ledger||$ledger['identity']==='Tanpa identitas siswa')throw new DomainException('Pilih sumber dan rekening utama yang valid.');
        $targets=array_values(array_filter($catalog['students'],fn($s)=>(string)$s['identity']===$target&&(int)($s['source_count']??0)===1));
        if(count($targets)!==1||($targets[0]['savings']['accounts']??0)!==1||($decision['confirmed']??false)!==true||!is_string($decision['evidence']??null)||mb_strlen(trim($decision['evidence']))<10||mb_strlen($decision['evidence'])>1000||!hash_equals($ledger['fingerprint'],(string)($decision['fingerprint']??''))||!hash_equals(lop_main_bank_fingerprint($target,$targets[0]['savings']),(string)($decision['main_fingerprint']??'')))throw new DomainException('Konfirmasi sumber dan rekening utama berubah.');
        $amount=lf_money(lf_cents($decision['amount']??null));$fix=$config['op_savings'][$target]??[];
        if(($fix['confirmed']??false)!==true||($fix['additional_ledger']??'')!==$key||lf_cents($fix['amount']??null)!==lf_cents($amount))throw new DomainException('Saldo utama dan pengesahan bukti tambahan harus disimpan bersama.');
        foreach($config['additional_savings'] as $otherKey=>$other)if($otherKey!==$key&&($other['reason']??'')==='replace_main'&&($other['target_identity']??'')===$target)throw new DomainException('Rekening utama sudah dipilih pada pengesahan tabungan tambahan lain.');
    }
    if($resume){
        foreach($patch['students']??[] as $identity=>$value)foreach(['name','nis','testing_confirmed'] as $field)if(array_key_exists($field,$value)&&$value[$field]!==($d['settings']['students'][$identity][$field]??null)&&lf_one($db,"SELECT id FROM legacy_op_owners WHERE batch_id=? AND source_identity=? AND state='ready'",[$id,(string)$identity]))throw new DomainException('Identitas yang sudah digunakan tidak ditulis ulang dari draf. Ubah nama siswa melalui Data Siswa; nomor induk tetap menjadi referensi sumber.');
        foreach($patch['op_savings']??[] as $identity=>$value)if($value!==($d['settings']['op_savings'][$identity]??null)&&lf_one($db,'SELECT a.id FROM legacy_op_accounts a JOIN legacy_op_owners o ON o.id=a.owner_id AND o.unit_id=a.unit_id WHERE o.batch_id=? AND o.source_identity=?',[$id,(string)$identity]))throw new DomainException('Rekening sudah digunakan. Koreksi melalui halaman rekening agar transaksi lanjutan diperiksa.');
        foreach($patch['additional_savings']??[] as $key=>$value)if($value!==($d['settings']['additional_savings'][$key]??null)&&lf_one($db,'SELECT a.id FROM legacy_op_accounts a JOIN legacy_op_owners o ON o.id=a.owner_id AND o.unit_id=a.unit_id WHERE o.batch_id=? AND o.source_identity IN (?,?,?)',[$id,lop_extra_identity((string)$key),(string)($value['target_identity']??''),(string)($d['settings']['additional_savings'][$key]['target_identity']??'')]))throw new DomainException('Rekening sudah digunakan. Gunakan Koreksi Rekening.');
        foreach($patch['op_journals']??[] as $key=>$value)if($value!==($d['settings']['op_journals'][$key]??null)&&lf_one($db,'SELECT id FROM legacy_op_journals WHERE batch_id=? AND row_key=?',[$id,$key]))throw new DomainException('Jurnal sudah digunakan. Gunakan Koreksi Transaksi.');
        foreach($config['op_groups']??[] as $code=>$group)foreach(['kind','target_class_id','graduation_year'] as $field)if(($field==='target_class_id'?(int)($group[$field]??0)!==(int)($d['settings']['op_groups'][$code][$field]??0):($group[$field]??null)!==($d['settings']['op_groups'][$code][$field]??null))&&lf_one($db,"SELECT o.id FROM legacy_op_owners o JOIN legacy_op_groups g ON g.id=o.group_id AND g.unit_id=o.unit_id WHERE o.batch_id=? AND o.state='ready' AND g.code=? LIMIT 1",[$id,(string)$code]))throw new DomainException('Jenis kelompok yang sudah digunakan tetap dikunci. Tinjau kelompok yang masih tertahan.');
        foreach($config['op_groups']??[] as $code=>$group)foreach(['confirmed','obligations_attested','savings_attested'] as $field)if(!empty($d['settings']['op_groups'][$code][$field])&&empty($group[$field])&&lf_one($db,"SELECT o.id FROM legacy_op_owners o JOIN legacy_op_groups g ON g.id=o.group_id AND g.unit_id=o.unit_id WHERE o.batch_id=? AND o.state='ready' AND g.code=? LIMIT 1",[$id,(string)$code]))throw new DomainException('Pengesahan kelompok yang sudah digunakan tidak dapat dicabut melalui draf.');
    }
    if(array_key_exists('new_masters',$patch)){
        if(!is_array($patch['new_masters'])||count($patch['new_masters'])>5000)throw new DomainException('Usulan master tidak valid.');
        foreach($patch['new_masters'] as $key=>$value)if(!preg_match('/^m_[a-f0-9]{16}$/D',(string)$key)||!is_array($value))throw new DomainException('Usulan master tidak valid.');
        $config['new_masters']=$patch['new_masters'];
    }
    $json=lf_json($config);if(strlen($json)>5000000)throw new DomainException('Peninjauan terlalu besar.');
    $s=lful_store()->prepare('UPDATE full_drafts SET settings=?,version=version+1,simulation=NULL,confirmed=NULL WHERE job=? AND version=?');$s->execute([$json,$id,$version]);if($s->rowCount()!==1)throw new DomainException('Peninjauan berubah. Muat ulang.');
    legacy_update($id,['state'=>'ready','stage'=>'Lengkapi Data','message'=>'Pemetaan disimpan. Jalankan simulasi ulang.']);return lful_draft($id);
}
function lful_date(mixed $value): ?string {
    if($value===null||$value==='')return null;if(!is_string($value)||!preg_match('/^(\d{4}-\d{2}-\d{2})(?:[T ](\d{2}:\d{2}:\d{2})(?:\.\d+)?)?$/D',$value,$m))throw new DomainException('Tanggal sumber tidak valid.');
    $v=$m[1].' '.($m[2]??'00:00:00');$dt=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$v);if(!$dt||$dt->format('Y-m-d H:i:s')!==$v)throw new DomainException('Tanggal sumber tidak valid.');return $v;
}
function lful_period(string $v): bool {return preg_match('/^[12][0-9]{3}-(0[1-9]|1[0-2])$/D',$v)===1||preg_match('/^([12][0-9]{3})\/([12][0-9]{3})$/D',$v,$m)===1&&(int)$m[2]===(int)$m[1]+1;}
function lful_projection_fingerprint(array $payments,array $journals,array $resolved=[]): string {
    $logical=array_flip($resolved);
    $hashes=[];foreach($payments as $p){$items=[];foreach($p['items'] as $i){$entry=array_map('strval',[$i['column'],$logical[$i['category']]??$i['category'],$i['label'],$i['amount']]);if(($i['migration']['version']??0)===LMA_VERSION)$entry[]=lf_canonical($i['migration']);$items[]=$entry;}sort($items);$hashes[]=hash('sha256',lf_json(['payment',array_map(static fn($v)=>(string)($v??''),[$p['identity'],$p['row_key'],$p['source_table'],$p['date'],$p['operator'],$p['period'],$p['class'],$p['total']]),$items]));}
    foreach($journals as $j)$hashes[]=hash('sha256',lf_json(['journal',array_map(static fn($v)=>(string)($v??''),[$j['identity'],$j['row_key'],$j['date'],$j['operator'],$j['incoming'],$j['outgoing']])]));sort($hashes,SORT_STRING);return hash('sha256',implode("\n",$hashes));
}
/** Pure projection builder: raw archive never becomes an editable contemporary receipt. */
function lful_payment(array $r,array $config,array $masterNames): ?array {
    $raw=$r['raw'];$table=$r['table'];if(!in_array($table,['bayar','bayar_du'],true))return null;
    if(!in_array($config['du_policy'],['reference','separate'],true)||!in_array($config['other_policy'],['total_details','source_details','independent','reference'],true)||!in_array($config['discount_policy'],['information','subtract'],true)||mb_strlen(trim((string)$config['accounting_reason']))<10)throw new DomainException('Sahkan hubungan total/rincian, potongan, dan pembayaran DU beserta bukti pemeriksaannya.');
    if($table==='bayar_du'&&$config['du_policy']==='reference')return null;
    $values=[];foreach(legacy_source_money_columns($r) as $c)if($c!=='potong_spp')$values[$c]=lf_cents(legacy_source_money_value($r,$c)??'0');
    if($table==='bayar'){
        $details=0;foreach(range(1,4) as $i)$details+=$values['JUMLAH'.$i]??0;
        if(in_array($config['other_policy'],['total_details','source_details'],true)){$total=$values['U_LAIN']??0;
            if($config['other_policy']==='source_details'&&$total===0)$values['U_LAIN']=0;
            else{if($details>$total)throw new DomainException('Rincian Biaya Lain melebihi U_LAIN.');$values['U_LAIN']=$total-$details;}}
        elseif($config['other_policy']==='reference')$values['U_LAIN']=0;
        $discount=lf_cents($raw['potong_spp']??'0');if($config['discount_policy']==='subtract'){
            if($discount>($values['U_SPP']??0))throw new DomainException('Potongan sumber melebihi SPP.');$values['U_SPP']-=$discount;
        }
    }
    $items=[];$total=0;foreach($values as $c=>$amount){if($amount===0)continue;$key=lful_component_key($table,$c,$raw);$map=$config['components'][$key]??null;
        if(!$map)throw new DomainException('Komponen belum dipetakan: '.$key.'. Tambahkan master biaya bila belum tersedia.');
        $category=(string)($map['category']??'');
        if(lful_savings_column($c)){
            if($category!=='savings_reference'||($map['confirmed']??false)!==true||mb_strlen(trim((string)($map['reason']??'')))<10)throw new DomainException('Sahkan hubungan kolom tabungan dengan jurnal; tidak boleh menjadi penerimaan biaya: '.$key);
            continue;
        }
        $label=$category===lful_history_category($key)?($map['source_name']??$config['_component_names'][$key]??lful_history_label($table,$c,$raw)):lful_category_label($category,$masterNames,$config['new_masters']??[]);
        if(!empty($config['_component_conflicts'][$key])){
            $expected=$config['_component_conflicts'][$key];$accepted=$map['accepted_source_names']??[];
            if(!is_array($accepted))$accepted=[];sort($expected,SORT_STRING);sort($accepted,SORT_STRING);
            if(empty($map['confirmed'])||(!in_array($map['source_name']??'',$expected,true)&&$expected!==$accepted))throw new DomainException('Kode biaya memiliki beberapa nama. Sahkan pemetaan komponen ini.');
        }
        if(!$label||mb_strlen(trim((string)($map['reason']??'')))<10)throw new DomainException('Pasangan master tidak tersedia atau alasan pemetaan belum diisi: '.$key);
        $total+=$amount;if($total>999999999999999)throw new DomainException('Total pembayaran melampaui batas.');
        $items[]=['column'=>$c,'category'=>$category,'label'=>$label,'amount'=>lf_money($amount)];
    }
    return ['row_key'=>$r['row_key'],'source_table'=>$table,'date'=>lful_date($raw['TGL_BYR']??null),'operator'=>$raw['user_id']??null,'period'=>trim((string)($raw['BULAN']??'').' '.(string)($raw['TAHUN']??$raw['th_ajaran']??'')),'class'=>(string)($raw['KELAS']??$raw['kelas']??'Tidak tercatat'),'total'=>lf_money($total),'items'=>$items];
}
/** Recompute from verified extraction and current masters, never trust posted totals. */
require_once __DIR__.'/legacy_ui.php';
function lful_simulate(mysqli $db,string $id,bool $persist=true): array {
    if((lful_draft($id)['settings']['mode']??'')==='operational')return lop_simulate($db,$id,$persist);
    lful_require($db);$d=lful_draft($id);$job=$d['job_info'];$unit=(int)$job['unit'];unit_set_context($db,$unit);$config=$d['settings'];
    // CLI has no browser session; billing helpers must use the job's unit bounds.
    if(PHP_SAPI==='cli')$_SESSION['active_unit_id']=$unit;
    [$records,$manifest]=lf_extract_read($job,true);$issues=[];$notes=[];$add=static function(string $identity,string $message,string $code='student_invalid')use(&$issues){$issues[]=legacy_ui_issue($identity,$message,$code);};
    $components=lful_catalog($records,$config);$config=lcs_configure($config,$components);
    foreach($components as $key=>$component){$config['_component_names'][$key]=$component['name'];if(!empty($component['ambiguous']))$config['_component_conflicts'][$key]=$component['source_names'];}
    $issues=array_merge($issues,lful_additional_savings_issues(lful_additional_savings($records,$manifest['source_date']??null),$config['additional_savings']??[]));
    if(($config['du_policy']??'')==='reference'&&array_filter($records,static fn($r)=>$r['table']==='bayar_du'))$notes[]=['identity'=>'Unit','message'=>'Tabel bayar_du disimpan sebagai referensi karena sudah tercakup pembayaran utama, sesuai bukti hubungan komponen. Tidak ditambahkan ke total histori lagi.'];
    if(($config['other_policy']??'')==='reference'&&array_filter($records,static fn($r)=>$r['table']==='bayar'&&isset($r['raw']['U_LAIN'])&&(string)$r['raw']['U_LAIN']!=='0'))$notes[]=['identity'=>'Unit','message'=>'U_LAIN disimpan sebagai referensi sesuai bukti hubungan komponen; hanya rincian yang dihitung dalam histori.'];
    try{lful_empty($db,$unit);}catch(DomainException $e){$add('Unit',$e->getMessage(),'unit_not_empty');}
    $cutoff=null;try{$cutoff=lful_date($config['cutoff']);if(!$cutoff||$cutoff>date('Y-m-d H:i:s')||$cutoff<lful_date($manifest['source_date']))throw new DomainException('Waktu peralihan harus setelah snapshot sumber dan tidak di masa depan.');}catch(DomainException $e){$add('Unit',$e->getMessage(),'transition_invalid');}
    $year=lf_one($db,"SELECT * FROM tahun_ajaran WHERE id=? AND status<>'closed'",[(int)$config['year_id']]);if(!$year)$add('Unit','Pilih tahun ajaran unit yang terbuka.','year_missing');
    $sppMaster=$year?lf_one($db,"SELECT id,status FROM master_spp_tahun WHERE tahun_ajaran_id=? AND status<>'closed'",[$year['id']]):null;
    if(!$sppMaster)$add('Unit','Siapkan master penerbitan SPP pada tahun tujuan.','spp_missing');
    elseif(in_array(0.0,spp_master_rates($db,(int)$sppMaster['id']),true))$add('Unit','Lengkapi tarif SPP seluruh tingkat sebelum migrasi.','spp_missing');
    if(mb_strlen(trim((string)$config['evidence']))<10||mb_strlen((string)$config['evidence'])>1000)$add('Unit','Isi referensi bukti peralihan minimal 10 karakter.','transition_evidence');
    $masters=[];foreach(lf_rows($db,'SELECT id,nama FROM master_biaya_lain') as $m)$masters[(int)$m['id']]=$m['nama'];
    $proposals=[];try{$proposals=lful_proposals($db,$config);}catch(DomainException $e){$add('Komponen Legacy',$e->getMessage(),'components_invalid');}
    $groups=[];$diknasCounts=[];foreach($records as $r){if($r['reference_only'])continue;$nis=lful_identity(legacy_source_identity($r['raw']));if($nis===''){$add('Baris '.$r['row_key'],'Identitas sumber kosong pada '.$r['table'].'.');continue;}$groups[$nis][]=$r;if($r['table']==='siswa'){$dk=trim((string)($r['raw']['NO_induk_diknas']??''));if($dk!=='')$diknasCounts[$dk]=($diknasCounts[$dk]??0)+1;}}
    $students=[];$payments=[];$journals=[];$usedNis=[];$total=0;$balanceTotal=0;$debtTotal=0;$coverageCount=0;$referenceCount=0;
    foreach($groups as $nis=>$rows){$nis=(string)$nis;$identities=array_values(array_filter($rows,static fn($r)=>$r['table']==='siswa'));$s=$config['students'][$nis]??[];$kind=$s['kind']??'';
        $identityIssues=[];foreach($identities as $identityRow)$identityIssues=array_merge($identityIssues,legacy_source_identity_issues($identityRow['raw']));if($identityIssues){$add($nis,implode(' ',array_unique($identityIssues)),'source_identity');continue;}
        if(!in_array($kind,['active','alumni','non_student'],true)){$add($nis,'Pilih status siswa aktif, alumni, atau bukan siswa.','student_status');continue;}
        if(mb_strlen(trim((string)($s['reason']??'')))<10){$add($nis,'Isi alasan/bukti klasifikasi siswa.','student_evidence');continue;}
        if($kind==='non_student'){
            $hasFinance=(bool)array_filter($rows,static fn($r)=>$r['table']!=='siswa');
            if($hasFinance&&(($s['non_student_settled']??false)!==true||mb_strlen(trim((string)($s['non_student_resolution']??'')))<10)){$add($nis,'Rekening bukan siswa memiliki data keuangan. Catat bukti penyelesaian saldo/kewajiban di luar modul siswa sebelum melanjutkan.');continue;}
            $referenceCount+=count($rows);$notes[]=['identity'=>$nis,'message'=>'Bukan siswa; data tetap menjadi referensi sumber, tidak menjadi saldo atau transaksi siswa.'];continue;
        }
        if(count($identities)!==1){$add($nis,'Identitas sumber tidak tunggal/tidak ditemukan. Lengkapi sumber sebelum migrasi.','source_identity');continue;}
        $raw=$identities[0]['raw'];$target=trim((string)($s['nis']??$nis));$name=trim((string)($s['name']??$raw['NAMA']??''));
        if(!preg_match('/^[0-9]{1,10}$/D',$target)||isset($usedNis[$target])||$name===''||mb_strlen($name)>100||preg_match('/[\x00-\x1f\x7f]/u',$name)){$add($nis,'NIS tujuan/nama tidak valid atau NIS ganda.','source_identity');continue;}$usedNis[$target]=true;
        $class=lf_one($db,'SELECT * FROM master_kelas WHERE id=? AND is_active=1',[(int)($s['class_id']??0)]);
        if($kind==='active'&&(!$class||$class['is_placeholder']||(int)$class['tingkat']===0)){$add($nis,'Pilih kelas/rombel yang sudah disahkan.','class_missing');continue;}
        if($kind==='alumni')$class=null;
        $diagnostic='student_invalid';try{
            foreach(['komite','psb','du','discount'] as $k)$s[$k]=lf_money(lf_cents($s[$k]??null));
            $start=(string)($s['start_period']??'');if($kind==='active'&&(!preg_match('/^[12][0-9]{3}-(0[1-9]|1[0-2])$/D',$start)||($cutoff&&$start<=substr($cutoff,0,7))))throw new DomainException('Mulai penagihan baru harus sesudah bulan peralihan; sisa bulan peralihan dimasukkan sebagai Tunggakan Legacy.');
            if($kind==='active'&&$year && ($start<substr($year['tanggal_mulai'],0,7)||$start>substr($year['tanggal_selesai'],0,7)))throw new DomainException('Mulai penagihan berada di luar tahun ajaran tujuan.');
            $diagnostic='obligations_invalid';if(($s['obligations_attested']??false)!==true||mb_strlen(trim((string)($s['obligations_evidence']??'')))<10)throw new DomainException('Sahkan seluruh sisa kewajiban, termasuk bila tidak ada tunggakan.');
            $coverage=$s['coverage']??[];$seen=[];if(!is_array($coverage)||count($coverage)>1000)throw new DomainException('Cakupan periode tidak valid.');
            foreach($coverage as &$c){$category=(string)($c['category']??'');if(!lful_category_label($category,$masters,$proposals))throw new DomainException('Komponen atau master cakupan belum tersedia.');
                if(!lful_period((string)($c['period']??''))||!in_array($c['disposition']??'',['settled','legacy_debt','not_applicable'],true)||mb_strlen(trim((string)($c['evidence']??'')))<10)throw new DomainException('Periode/status/bukti cakupan belum lengkap.');
                if(in_array($category,['spp','komite'],true)&&strlen($c['period'])!==7)throw new DomainException('SPP/Komite memerlukan periode bulanan.');
                if($c['disposition']==='legacy_debt'&&strlen($c['period'])===7&&$cutoff&&$c['period']>substr($cutoff,0,7))throw new DomainException('Tunggakan awal bulanan tidak boleh melampaui bulan peralihan.');
                $key=$category.'|'.$c['period'];if(isset($seen[$key]))throw new DomainException('Cakupan kewajiban ganda.');$seen[$key]=true;
                $c['amount']=($c['disposition']==='legacy_debt')?lf_money(lf_cents($c['amount']??null)):'0.00';if($c['disposition']==='legacy_debt'&&lf_cents($c['amount'])<=0)throw new DomainException('Sisa tunggakan harus lebih dari nol.');
                $debtTotal+=lf_cents($c['amount']);$coverageCount++;
            }unset($c);$s['coverage']=$coverage;
            if($kind==='active'&&$cutoff){$startYear=(int)substr($cutoff,0,4)-((int)substr($cutoff,5,2)<7?1:0);$cutoffYear=$startYear.'/'.($startYear+1);if(!isset($seen['daftar_ulang|'.$cutoffYear]))throw new DomainException('Lengkapi status Daftar Ulang tahun peralihan '.$cutoffYear.' pada cakupan periode (sudah dibayar, tunggakan, atau tidak ditagihkan).');}
            $diagnostic='savings_invalid';$savingsRows=array_values(array_filter($rows,static fn($r)=>in_array($r['table'],['tabungan','transaksi_m','transaksi_k'],true)));$savings=$savingsRows?lf_reconcile($savingsRows,$manifest['source_date']):null;
            if($savings&&!$savings['ready'])throw new DomainException('Tabungan ditahan: '.implode(' ',$savings['issues']));
            foreach($rows as $r)if($r['table']==='bayar')foreach(legacy_source_money_columns($r) as $column)if(lful_savings_column($column)&&lf_cents(legacy_source_money_value($r,$column)??'0')>0){
                if(!$savings||!$savings['ready']||!array_filter($savingsRows,static fn($r)=>$r['table']==='transaksi_m'))throw new DomainException('Kolom tabungan pada pembayaran memerlukan rekening dan jurnal masuk yang lengkap serta cocok.');
            }
            if(isset($config['workflow_version'])&&$savings&&($s['savings_attested']??false)!==true)throw new DomainException('Sahkan saldo tabungan yang sudah cocok.');
            $diagnostic='spp_missing';$rate=$year&&$class?spp_current_effective_rate($db,(string)$class['tingkat'],(float)$s['discount'],$year['label']):null;
            if($kind==='active'&&(!$rate||$rate['year']==='Belum disiapkan'))throw new DomainException('Lengkapi tarif SPP pada master tahun/kelas tujuan.');
            $dk=trim((string)($raw['NO_induk_diknas']??''));$s['diknas']=$dk!==''&&preg_match('/^[0-9]{1,10}$/D',$dk)&&($diknasCounts[$dk]??0)===1?$dk:null;
            if($dk!==''&&$s['diknas']===null)$notes[]=['identity'=>$nis,'message'=>'NIS Diknas belum memenuhi format/keunikan tujuan; nilai asli tetap tersedia pada sumber.'];
            $s+=['kind'=>$kind];$s['nis']=$target;$s['name']=$name;$s['class']=$class;$s['ordinal']=$identities[0]['student_ordinal'];$s['raw']=$raw;$s['source_key']=$identities[0]['row_key'];$s['savings']=$savings;$s['spp']=$rate?lf_money((int)round($rate['net']*100)):'0.00';$students[$nis]=$s;
            if($savings)$balanceTotal+=lf_cents($savings['balance']);
            $diagnostic='payment_invalid';foreach($rows as $r){if(in_array($r['table'],['bayar','bayar_du'],true)){
                $p=lful_payment($r,$config,$masters);if(!$p)continue;if($p['date']&&($p['date']>lful_date($manifest['source_date'])||($cutoff&&$p['date']>$cutoff)))throw new DomainException('Tanggal pembayaran melampaui snapshot/peralihan.');
                $p['identity']=$nis;$payments[]=$p;$total+=lf_cents($p['total']);if(!$p['date'])$notes[]=['identity'=>$nis,'message'=>'Tanggal tidak tercatat: '.$r['table'].' '.$r['row_key']];
            }elseif(in_array($r['table'],['transaksi_m','transaksi_k'],true)){$raw=$r['raw'];$journals[]=['identity'=>$nis,'row_key'=>$r['row_key'],'date'=>lful_date($raw['TANGGAL']),'operator'=>$raw['user_id']??null,'incoming'=>lf_money(lf_cents($raw['MASUK']??'0')),'outgoing'=>lf_money(lf_cents($raw['KELUAR']??'0'))];}}
        }catch(DomainException $e){$add($nis,$e->getMessage(),$diagnostic);}
    }
    if($notes&&$config['notes_accepted']!==true)$add('Unit','Tinjau dan setujui catatan histori sebelum melanjutkan.','history_notes');
    if(!$students)$add('Unit','Tidak ada siswa yang siap dimigrasikan.','no_students');
    $mastersDigest=hash('sha256',lf_json([lf_rows($db,'SELECT * FROM tahun_ajaran ORDER BY id'),lf_rows($db,'SELECT * FROM master_kelas ORDER BY id'),lf_rows($db,'SELECT * FROM master_spp_tahun ORDER BY id'),lf_rows($db,'SELECT * FROM master_spp_tarif ORDER BY id'),lf_rows($db,'SELECT * FROM master_biaya_lain ORDER BY id')]));
    $summary=['students'=>count($students),'payments'=>count($payments),'journals'=>count($journals),'coverage'=>$coverageCount,'payment_total'=>lf_money($total),'savings_total'=>lf_money($balanceTotal),'debt_total'=>lf_money($debtTotal),'undated'=>count(array_filter($payments,static fn($p)=>$p['date']===null)),'reference_rows'=>$referenceCount,'new_masters'=>count($proposals),'active'=>count(array_filter($students,fn($s)=>$s['kind']==='active')),'alumni'=>count(array_filter($students,fn($s)=>$s['kind']==='alumni'))];
    $digest=hash('sha256',lf_json([$manifest['fingerprint'],$d['version'],$config,$mastersDigest,$summary,$issues,$notes,LFUL_RULES_VERSION,LEGACY_SOURCE_READER_VERSION,lful_schema_signature($unit,$manifest)]));
    $result=['ready'=>!$issues,'version'=>(int)$d['version'],'digest'=>$digest,'summary'=>$summary,'issues'=>$issues,'notes'=>$notes,'source_fingerprint'=>$manifest['fingerprint'],'projection_fingerprint'=>lful_projection_fingerprint($payments,$journals),'masters_digest'=>$mastersDigest,'checked_at'=>time()];
    if($persist)lful_store()->prepare('UPDATE full_drafts SET simulation=?,confirmed=NULL WHERE job=? AND version=?')->execute([lf_json($result),$id,$d['version']]);
    return $result+['students'=>$students,'payments'=>$payments,'journals'=>$journals,'manifest'=>$manifest,'year'=>$year,'cutoff'=>$cutoff,'new_masters'=>$proposals];
}

function lful_confirm(mysqli $db,int $actor,int $unit,string $id,int $version,string $digest): array {
    lf_actor($db,$actor,$unit,true);$d=lful_draft($id);if((int)$d['job_info']['unit']!==$unit||(int)$d['version']!==$version)throw new DomainException('Versi/unit berubah.');
    if($d['job_info']['state']!=='ready')throw new DomainException('Pekerjaan sudah diproses atau belum siap.');
    if(($d['settings']['mode']??'')==='operational'){
        require_once __DIR__.'/legacy_migration_confirmation.php';$sim=lop_confirmation_snapshot($db,$d,$digest);
    }else $sim=lful_simulate($db,$id,false);
    if(!$sim['ready']||!hash_equals($sim['digest'],$digest)||!hash_equals((string)($d['simulation']['digest']??''),$digest))throw new DomainException('Simulasi berubah atau masih memiliki masalah. Jalankan ulang.');
    $c=['token'=>bin2hex(random_bytes(24)),'actor'=>$actor,'unit'=>$unit,'version'=>$version,'digest'=>$digest,'expires'=>time()+300];
    lful_store()->prepare('UPDATE full_drafts SET confirmed=? WHERE job=? AND version=?')->execute([lf_json($c),$id,$version]);return $c;
}
function lful_enqueue_apply(mysqli $db,int $actor,int $unit,string $id,int $version,string $token): void {
    lf_actor($db,$actor,$unit,true);$q=lful_store();$q->exec('BEGIN IMMEDIATE');try{$d=lful_draft($id);$c=$d['confirmed'];
        if(!$c||$c['actor']!==$actor||$c['unit']!==$unit||$c['version']!==$version||!hash_equals($c['token'],$token))throw new DomainException('Konfirmasi tidak sesuai.');
        if(in_array($d['job_info']['state'],['apply_queued','applying','imported'],true)){$q->exec('COMMIT');return;}
        if($c['expires']<time()||(int)$d['version']!==$version||$d['job_info']['state']!=='ready')throw new DomainException('Konfirmasi kedaluwarsa atau berubah.');
        $q->prepare("UPDATE jobs SET state='apply_queued',actor=?,stage='Menunggu Migrasi',updated=? WHERE id=? AND state='ready' AND cancel=0")->execute([$actor,time(),$id]);$q->exec('COMMIT');
    }catch(Throwable $e){$q->exec('ROLLBACK');throw $e;}
}
/** A real restore-verified backup, under the same dispatcher lock as daily/manual backups. */
function lful_backup(mysqli $db,int $actor,string $id,string $phase): string {
    if(PHP_SAPI!=='cli')throw new LogicException('Backup berjalan melalui worker.');
    require_once __DIR__.'/database_backup.php';require_once __DIR__.'/../scripts/database_backup_worker.php';
    $c=db_backup_config();db_backup_assert_application($c,$db);$queue=db_backup_store();
    $h=fopen($c['storage_root'].'/runner.lock','c');if(!$h||!flock($h,LOCK_EX|LOCK_NB))throw new DomainException('Backup lain berjalan. Coba lagi setelah selesai.');
    $saved=$GLOBALS['koneksi']??null;
    try{$GLOBALS['koneksi']=$db;$request=substr(hash('sha256',$id.'|'.$phase.'|'.bin2hex(random_bytes(12))),0,32);$j=db_backup_enqueue($actor,$request);$j=db_backup_find($queue,$j['id']);
        if($j['state']!=='queued')throw new DomainException('Antrean backup belum siap.');db_backup_run_job($c,$queue,$j);$GLOBALS['koneksi']=$db;
        $j=db_backup_find($queue,$j['id']);if($j['state']!=='verified')throw new DomainException('Pemulihan backup belum terverifikasi.');db_backup_archive($queue,$j['id']);return $j['id'];
    }finally{$GLOBALS['koneksi']=$saved;flock($h,LOCK_UN);fclose($h);}
}
function lful_test_point(mysqli $db,string $stage): void {
    if(getenv('SPP_FULL_TEST_FAIL')!==$stage)return;
    require_once __DIR__.'/../tests/http_form_scope.php';spp_test_assert_database_target($db);
    throw new RuntimeException('Isolated full migration interruption: '.$stage);
}
function lful_check_result(mysqli $db,string $id): array {
    if(lop_ready($db)){$op=lf_one($db,'SELECT manifest FROM legacy_full_batches WHERE id=?',[$id]);if($op&&(json_decode($op['manifest'],true)['mode']??'')==='operational')return lop_check_result($db,$id);}
    $b=lf_one($db,'SELECT * FROM legacy_full_batches WHERE id=?',[$id]);if(!$b)throw new DomainException('Batch tidak ditemukan.');$m=json_decode($b['manifest'],true,512,JSON_THROW_ON_ERROR);$summary=$m['summary'];
    foreach(['students'=>'legacy_full_students','payments'=>'legacy_full_payments','journals'=>'legacy_full_journals','coverage'=>'legacy_full_coverage'] as $key=>$table)if((int)lf_one($db,"SELECT COUNT(*) n FROM $table WHERE batch_id=?",[$id])['n']!==$summary[$key])throw new DomainException('Jumlah hasil tidak cocok: '.$key);
    foreach(['payment_total'=>['legacy_full_payments','total'],'savings_total'=>['legacy_savings_openings','amount']] as $key=>[$table,$col]){
        $sql=$table==='legacy_savings_openings'?'SELECT COALESCE(SUM(o.amount),0) total FROM legacy_savings_openings o JOIN legacy_full_students s ON s.student_id=o.student_id AND s.unit_id=o.unit_id WHERE s.batch_id=?':"SELECT COALESCE(SUM($col),0) total FROM $table WHERE batch_id=?";
        if(lf_cents((string)lf_one($db,$sql,[$id])['total'])!==lf_cents($summary[$key]))throw new DomainException('Total hasil berbeda: '.$key);
    }
    $source=lf_source_fingerprint($db,(int)$b['unit_id'],(int)$b['source_id']);if(!hash_equals($source['fingerprint'],$m['source_fingerprint']))throw new DomainException('Fingerprint arsip berubah.');
    $payments=[];$items=[];foreach(lf_rows($db,'SELECT i.* FROM legacy_full_items i JOIN legacy_full_payments p ON p.id=i.payment_id AND p.unit_id=i.unit_id WHERE p.batch_id=?',[$id]) as $i)$items[$i['payment_id']][]=['column'=>$i['source_column'],'category'=>$i['category'],'label'=>$i['label'],'amount'=>$i['amount']];
    foreach(lf_rows($db,'SELECT p.*,s.source_identity FROM legacy_full_payments p JOIN legacy_full_students s ON s.student_id=p.student_id AND s.unit_id=p.unit_id WHERE p.batch_id=?',[$id]) as $p)$payments[]=['identity'=>$p['source_identity'],'row_key'=>$p['row_key'],'source_table'=>$p['source_table'],'date'=>$p['source_date'],'operator'=>$p['source_operator'],'period'=>$p['source_period'],'class'=>$p['class_snapshot'],'total'=>$p['total'],'items'=>$items[$p['id']]??[]];
    $journals=[];foreach(lf_rows($db,'SELECT j.*,s.source_identity FROM legacy_full_journals j JOIN legacy_full_students s ON s.student_id=j.student_id AND s.unit_id=j.unit_id WHERE j.batch_id=?',[$id]) as $j)$journals[]=['identity'=>$j['source_identity'],'row_key'=>$j['row_key'],'date'=>$j['source_date'],'operator'=>$j['source_operator'],'incoming'=>$j['incoming'],'outgoing'=>$j['outgoing']];
    if(!hash_equals((string)$m['projection_fingerprint'],lful_projection_fingerprint($payments,$journals,$m['resolved_masters']??[])))throw new DomainException('Fingerprint histori hasil berbeda.');
    foreach($m['resolved_masters']??[] as $logical=>$actual){
        $p=$m['mapping']['new_masters'][substr($logical,11)]??null;$master=lf_one($db,'SELECT id,nama FROM master_biaya_lain WHERE id=? AND unit_id=?',[(int)substr($actual,11),(int)$b['unit_id']]);
        if(!$p||!$master)throw new DomainException('Master hasil migrasi tidak ditemukan pada unit tujuan.');
    }
    $debt=lf_one($db,'SELECT COALESCE(SUM(d.amount),0) total FROM legacy_debts d JOIN legacy_full_students s ON s.review_id=d.review_id AND s.unit_id=d.unit_id WHERE s.batch_id=?',[$id]);if(lf_cents((string)$debt['total'])!==lf_cents($summary['debt_total']))throw new DomainException('Total tunggakan berbeda.');
    $bad=lf_one($db,'SELECT s.id FROM legacy_full_students s JOIN legacy_savings_openings o ON o.student_id=s.student_id AND o.unit_id=s.unit_id JOIN siswa st ON st.id=s.student_id AND st.unit_id=s.unit_id LEFT JOIN tabungan t ON t.NO_INDUK=st.NO_INDUK AND t.unit_id=s.unit_id WHERE s.batch_id=? AND (o.cancelled_at IS NOT NULL OR t.id IS NULL OR ROUND(t.SALDO,2)<>o.amount+COALESCE((SELECT SUM(CAST(m.MASUK AS DECIMAL(15,2))) FROM transaksi_m m WHERE m.unit_id=s.unit_id AND m.NO_INDUK=st.NO_INDUK),0)-COALESCE((SELECT SUM(CAST(k.KELUAR AS DECIMAL(15,2))) FROM transaksi_k k WHERE k.unit_id=s.unit_id AND k.NO_INDUK=st.NO_INDUK),0)) LIMIT 1',[$id]);if($bad)throw new DomainException('Saldo aktif berbeda dari saldo peralihan dan transaksi berikutnya.');
    return $b;
}
/** One InnoDB transaction, called only while the worker owns exclusive access. */
function lful_apply_transaction(mysqli $db,array $job,array $sim,string $beforeBackup): void {
    if(PHP_SAPI!=='cli')throw new LogicException('Penerapan melalui worker.');$id=$job['id'];$unit=(int)$job['unit'];$actor=(int)$job['actor'];$d=lful_draft($id);$config=$d['settings'];
    require_once __DIR__.'/database_backup.php';db_backup_assert_application(db_backup_config(),$db);db_backup_archive(db_backup_store(),$beforeBackup);
    $db->begin_transaction();$db->query('SET @legacy_full_apply=1');try{
        lf_actor($db,$actor,$unit,true,true);lful_empty($db,$unit,true);
        if(!$d['confirmed']||$d['confirmed']['expires']<time()||$d['confirmed']['version']!==(int)$d['version']||!hash_equals($d['confirmed']['digest'],$sim['digest']))throw new DomainException('Konfirmasi berubah atau kedaluwarsa.');
        // Lock the master rows before reading their values; simultaneous repricing cannot pass an old digest.
        foreach(['tahun_ajaran','master_kelas','master_spp_tahun','master_spp_tarif','master_biaya_lain'] as $table)lf_rows($db,"SELECT id FROM $table FOR UPDATE");
        $fresh=lful_simulate($db,$id,false);if(!$fresh['ready']||!hash_equals($sim['digest'],$fresh['digest']))throw new DomainException('Sumber/master berubah sebelum penerapan.');
        $sim=$fresh;unset($fresh);
        $resolved=lful_create_proposals($db,$unit,$sim['new_masters']??[]);$sim=lful_resolve_projection($sim,$resolved);
        lful_test_point($db,'after_masters');
        lf_publish($db,$job,true);$source=lf_one($db,'SELECT id FROM legacy_finance_sources WHERE backup_hash=?',[$job['hash']]);
        $manifest=array_intersect_key($sim,array_flip(['summary','digest','source_fingerprint','projection_fingerprint','masters_digest','notes']));
        $manifest['mapping']=$config;
        $manifest['resolved_masters']=$resolved;
        lf_query($db,'INSERT INTO legacy_full_batches(id,unit_id,source_id,version,digest,cutoff,year_id,manifest,before_backup,actor_id) VALUES(?,?,?,?,?,?,?,?,?,?)',[$id,$unit,$source['id'],$d['version'],$sim['digest'],$sim['cutoff'],$config['year_id'],lf_json($manifest),$beforeBackup,$actor]);
        $targets=[];foreach($sim['students'] as $identity=>$s){
            $active=$s['kind']==='active'?1:0;$level=$active?(string)$s['class']['tingkat']:'0';$classId=$active?(int)$s['class']['id']:null;
            // PSB/DU source prices are retained in settings; only reviewed remaining debt is payable.
            lf_query($db,'INSERT INTO siswa(unit_id,NO_INDUK,NAMA,KELAS,master_kelas_id,is_active,legacy_pending,SPP_PERBULAN,POMG,PSB,DAFTAR_ULANG,tot_du,potongan_spp_nominal,NO_induk_diknas) VALUES(?,?,?,?,?,?,0,?,?,0,0,0,?,?)',[$unit,$s['nis'],$s['name'],$level,$classId,$active,$s['spp'],$s['komite'],$s['discount'],$s['diknas']]);$student=(int)$db->insert_id;$targets[(string)$identity]=$student;
            lf_query($db,'INSERT INTO legacy_student_import(student_id,unit_id,source_hash,source_row,source_name,source_class,raw_data,imported_by,activated_by,activated_at) VALUES(?,?,?,?,?,?,?,?,?,NOW())',[$student,$unit,$job['hash'],$s['ordinal'],$job['name'],$s['raw']['KELAS']??'',lf_json($s['raw']),$actor,$actor]);
            lf_query($db,"UPDATE legacy_finance_maps SET student_id=?,match_status='full',reason=? WHERE source_id=? AND source_nis=?",[$student,$s['reason'],$source['id'],$identity]);
            $review=lf_one($db,'SELECT r.id FROM legacy_finance_reviews r JOIN legacy_finance_maps m ON m.id=r.map_id AND m.unit_id=r.unit_id WHERE m.source_id=? AND m.source_nis=?',[$source['id'],$identity]);if(!$review)throw new DomainException('Pasangan arsip tidak tersedia.');$reviewId=(int)$review['id'];
            $settings=$s;unset($settings['raw'],$settings['class']);lf_query($db,'INSERT INTO legacy_full_students(unit_id,batch_id,source_identity,student_id,review_id,settings) VALUES(?,?,?,?,?,?)',[$unit,$id,$identity,$student,$reviewId,lf_json($settings)]);
            if($active){$startMonth=substr($s['start_period'],5,2);lf_query($db,"INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,komite_snapshot,komite_mulai_bulan,status) VALUES(?,?,?,?,?,?,?,?,'aktif')",[$config['year_id'],$s['nis'],$level,$classId,class_label($s['class']),$s['spp'],$s['komite'],$startMonth]);}
            if($s['savings']){lf_query($db,'INSERT INTO legacy_savings_openings(unit_id,student_id,review_id,amount,effective_date,evidence,applied_by) VALUES(?,?,?,?,?,?,?)',[$unit,$student,$reviewId,$s['savings']['balance'],substr($sim['cutoff'],0,10),$config['evidence'],$actor]);lf_query($db,'INSERT INTO tabungan(unit_id,NO_INDUK,SALDO) VALUES(?,?,?)',[$unit,$s['nis'],$s['savings']['balance']]);}
            foreach($s['coverage'] as $c){lf_query($db,'INSERT INTO legacy_full_coverage(unit_id,batch_id,student_id,category,period,disposition,evidence) VALUES(?,?,?,?,?,?,?)',[$unit,$id,$student,$c['category'],$c['period'],$c['disposition'],$c['evidence']]);
                if($c['disposition']==='legacy_debt'){$category=$c['category'];$kind=['spp'=>'spp','komite'=>'komite','daftar_ulang'=>'du'][$category]??'other';$label=['spp'=>'SPP','komite'=>'Komite','daftar_ulang'=>'Daftar Ulang','psb'=>'Uang PSB'][$category]??null;
                    if(!$label){$master=lf_one($db,'SELECT nama FROM master_biaya_lain WHERE id=?',[(int)substr($category,11)]);$label=$master['nama'];}
                    $debt=['component'=>$label,'period'=>$c['period'],'amount'=>$c['amount'],'kind'=>$kind,'obligation_key'=>hash('sha256',($kind==='other'?'source:'.mb_strtolower(preg_replace('/\s+/u',' ',$label)):'kind:'.$kind).'|'.$c['period']),'source_row_key'=>$s['source_key'],'distinction'=>$c['evidence']];
                    lf_create_debt($db,$unit,$actor,['source_id'=>$source['id'],'source_nis'=>(string)$identity,'id'=>$reviewId],['id'=>$student,'NO_INDUK'=>$s['nis'],'master_kelas_id'=>$classId,'KELAS'=>$s['class']?class_label($s['class']):'LEGACY'],['effective_date'=>substr($sim['cutoff'],0,10),'evidence'=>$c['evidence']],$debt);
                }
            }
            lf_query($db,"UPDATE legacy_finance_reviews SET status='applied',version=version+1,reviewed_by=?,review_data=? WHERE id=?",[$actor,lf_json(['full_batch'=>$id]),$reviewId]);
        }
        foreach($sim['payments'] as $p){$student=$targets[$p['identity']];lf_query($db,'INSERT INTO legacy_full_payments(unit_id,batch_id,student_id,row_key,source_table,source_date,source_operator,source_period,class_snapshot,total) VALUES(?,?,?,?,?,?,?,?,?,?)',[$unit,$id,$student,$p['row_key'],$p['source_table'],$p['date'],$p['operator'],$p['period'],$p['class'],$p['total']]);$payment=(int)$db->insert_id;
            foreach($p['items'] as $i)lf_query($db,'INSERT INTO legacy_full_items(unit_id,payment_id,source_column,category,label,amount) VALUES(?,?,?,?,?,?)',[$unit,$payment,$i['column'],$i['category'],$i['label'],$i['amount']]);
        }
        foreach($sim['journals'] as $j)lf_query($db,'INSERT INTO legacy_full_journals(unit_id,batch_id,student_id,row_key,source_date,source_operator,incoming,outgoing) VALUES(?,?,?,?,?,?,?,?)',[$unit,$id,$targets[$j['identity']],$j['row_key'],$j['date'],$j['operator'],$j['incoming'],$j['outgoing']]);
        $active=[];foreach($sim['students'] as $s)if($s['kind']==='active')$active[]=$s['nis'];
        if($active){$master=lf_one($db,'SELECT id FROM master_spp_tahun WHERE tahun_ajaran_id=? FOR UPDATE',[$config['year_id']]);spp_publish_students($db,(int)$master['id'],$active);}
        lful_check_result($db,$id);lf_audit($db,$unit,$actor,null,'full_apply',['batch'=>$id,'digest'=>$sim['digest'],'summary'=>$sim['summary']]);lful_test_point($db,'before_commit');$db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}finally{$db->query('SET @legacy_full_apply=0');}
}
/** The caller must release its shared database-access lock first. */
function lful_run(mysqli $db,array $job): void {
    if((lful_draft($job['id'])['settings']['mode']??'')==='operational'){lop_run($db,$job);return;}
    $id=$job['id'];$unit=(int)$job['unit'];$actor=(int)$job['actor'];lful_require($db);lf_actor($db,$actor,$unit,true);
    if(PHP_SAPI==='cli')$_SESSION=array_replace($_SESSION??[],['admin_id'=>$actor,'admin_role'=>'super_admin','active_unit_id'=>$unit]);
    $paths=database_access_paths(DB_NAME,(int)$GLOBALS['dbPort']);$access=lful_application_access($job,$paths);
    try{
        $prior=lf_one($db,'SELECT * FROM legacy_full_batches WHERE id=?',[$id]);
        if($prior&&$prior['state']==='completed'){legacy_update($id,['state'=>'imported','stage'=>'Migrasi Selesai','message'=>'Unit sudah selesai dimigrasikan; tidak digandakan.']);return;}
        if(!$prior){$d=lful_draft($id);$c=$d['confirmed'];if(!$c||$c['expires']<time()||$c['actor']!==$actor||$c['version']!==(int)$d['version']||$c['unit']!==$unit)throw new DomainException('Konfirmasi migrasi tidak sesuai atau kedaluwarsa.');
            $sim=lful_simulate($db,$id,false);if(!$sim['ready']||!hash_equals($sim['digest'],$c['digest']))throw new DomainException('Simulasi tidak lagi cocok. Tinjau kembali sebelum migrasi.');
            legacy_update($id,['stage'=>'Backup Pengaman','message'=>'Menguji pemulihan database tujuan sebelum migrasi.']);$backup=lful_backup($db,$actor,$id,'before');
            legacy_update($id,['stage'=>'Migrasi','message'=>'Menerapkan seluruh data unit dalam satu transaksi.']);lful_apply_transaction($db,$job,$sim,$backup);lful_test_point($db,'after_commit');
        }
        legacy_update($id,['stage'=>'Verifikasi Hasil','message'=>'Mencocokkan hasil dan menguji pemulihan backup setelah migrasi.']);lful_check_result($db,$id);$after=lful_backup($db,$actor,$id,'after');
        $db->begin_transaction();try{lf_actor($db,$actor,$unit,true,true);lful_check_result($db,$id);lf_query($db,"UPDATE legacy_full_batches SET state='completed',after_backup=?,completed_at=NOW() WHERE id=?",[$after,$id]);lf_audit($db,$unit,$actor,null,'full_completed',['batch'=>$id,'after_backup'=>$after]);$db->commit();}catch(Throwable $e){$db->rollback();throw $e;}
        legacy_update($id,['state'=>'imported','stage'=>'Migrasi Selesai','message'=>'Hasil dan pemulihan backup terverifikasi. Unit siap digunakan di SistemSPP.']);
    }catch(Throwable $e){$prior=lf_one($db,'SELECT state FROM legacy_full_batches WHERE id=?',[$id]);if($prior&&$prior['state']!=='completed')lf_query($db,"UPDATE legacy_full_batches SET state='blocked' WHERE id=?",[$id]);throw $e;}
    finally{database_access_release($access);}
}
