<?php
require_once __DIR__.'/legacy_operational_bank_index.php';
/** Read only the identities and selected bank evidence needed by this screen. */
function lop_review_source(array $job,string $bankIdentity=''): array {
    $manifest=lf_extract_manifest($job);$cacheFile=legacy_root().'/'.$job['id'].'/operational-input-cache.json';$cache=is_file($cacheFile)?json_decode(file_get_contents($cacheFile),true,512,JSON_THROW_ON_ERROR):null;
    if(($cache['rules']??0)===LOP_VERSION&&($cache['rows_sha256']??'')===$manifest['rows_sha256']){$records=$cache['records'];$statuses=$cache['statuses'];if($bankIdentity!==''){$s=legacy_queue()->prepare("SELECT raw FROM rows WHERE job=? AND class IN ('tabungan','transaksi_m','transaksi_k','tb_total','tb_masuk','tb_keluar') AND UPPER(TRIM(nis))=? ORDER BY ordinal");$s->execute([$job['id'],$bankIdentity]);foreach($s->fetchAll(PDO::FETCH_COLUMN) as $raw)$records[]=json_decode($raw,true,512,JSON_THROW_ON_ERROR);}return [$records,$manifest,$statuses];}
    $records=[];$statuses=[];$duplicates=[];$studentRecords=[];$file=fopen(legacy_root().'/'.$job['id'].'/finance.jsonl','rb');
    try{while(($line=fgets($file))!==false){$r=json_decode($line,true,512,JSON_THROW_ON_ERROR);if($r['table']==='bayar'&&trim((string)($r['raw']['rstatus']??''))!==''){$status=trim((string)$r['raw']['rstatus']);$statuses[$status]=($statuses[$status]??0)+1;}
        if($r['table']!=='siswa'&&($bankIdentity===''||lful_identity(legacy_source_identity($r['raw']))!==$bankIdentity||!in_array($r['table'],['tabungan','transaksi_m','transaksi_k','tb_total','tb_masuk','tb_keluar'],true)))continue;
        $hash=hash('sha256',lf_canonical($r['raw']));$occ=($duplicates[$r['table']][$hash]??0)+1;$duplicates[$r['table']][$hash]=$occ;$r['row_hash']=$hash;$r['occurrence']=$occ;$r['row_key']=hash('sha256',implode('|',[$job['unit'],strtolower($job['hash']),$r['table'],$hash,$occ]));$r['money_columns']=legacy_source_money_columns($r);$records[]=$r;if($r['table']==='siswa')$studentRecords[]=$r;
    }}finally{fclose($file);}$tmp=$cacheFile.'.'.bin2hex(random_bytes(5)).'.partial';file_put_contents($tmp,lf_json(['rules'=>LOP_VERSION,'rows_sha256'=>$manifest['rows_sha256'],'records'=>$studentRecords,'statuses'=>$statuses]),LOCK_EX);if(!rename($tmp,$cacheFile))@unlink($tmp);return [$records,$manifest,$statuses];
}
function lop_preview_store(string $id,array $plan,int $version,string $fingerprint): void {
    $preview=array_intersect_key($plan,array_flip(['owners','groups','summary','pending','history','automatic','references','resolutions']));foreach($preview['owners'] as &$owner)unset($owner['raw'],$owner['decision'],$owner['group_policy']);unset($owner);
    $data=['version'=>$version,'rules'=>LOP_VERSION,'fingerprint'=>$fingerprint,'preview'=>$preview];$file=legacy_root().'/'.$id.'/operational-preview.json';$tmp=$file.'.'.bin2hex(random_bytes(5)).'.partial';file_put_contents($tmp,lf_json($data),LOCK_EX);if(!rename($tmp,$file)){@unlink($tmp);throw new RuntimeException('Pratinjau belum dapat disimpan.');}
}
function lop_preview_load(array $draft,array $catalog,array $records,array $cfg): array {
    $file=legacy_root().'/'.$draft['job_info']['id'].'/operational-preview.json';if(is_file($file)){$data=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);if(($data['rules']??0)===LOP_VERSION&&($data['version']??0)===(int)$draft['version']&&($data['fingerprint']??'')===$catalog['manifest']['fingerprint'])return lop_preview_banks($data['preview'],$draft,$catalog,$cfg);}
    $preview=lop_prepare($records,$cfg,(int)$draft['job_info']['unit'],$catalog['manifest']['source_date']);if(($draft['simulation']['mode']??'')==='operational'&&($draft['simulation']['rules_version']??0)===LOP_VERSION)$preview['summary']=$draft['simulation']['summary'];return lop_preview_banks($preview,$draft,$catalog,$cfg);
}

function lop_preview_banks(array $preview,array $draft,array $catalog,array $cfg): array {
    $index=lop_bank_index($draft['job_info'],$catalog['manifest']);foreach($preview['owners'] as $key=>&$o){$effective=$o['bank_summary']??[];$o['bank_summary']=$index[$key]??['accounts'=>0,'additional_accounts'=>0,'source_balance'=>null,'journal_balance'=>null,'status'=>'Tidak tercatat'];if(($effective['accounts']??0)>0)$o['bank_summary']=array_replace($o['bank_summary'],$effective);$fix=$cfg['op_savings'][$key]??[];$o['bank_summary']['fixed_balance']=!empty($fix['confirmed'])?($fix['amount']??null):null;}unset($o);return $preview;
}
