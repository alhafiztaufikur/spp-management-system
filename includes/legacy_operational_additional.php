<?php
function lop_extra_identity(string $key): string {return '@tambahan:'.$key;}
function lop_main_bank_fingerprint(string $identity,array $rec): string {
    return hash('sha256',lf_json([$identity,$rec['balance'],$rec['incoming'],$rec['outgoing'],$rec['accounts'],$rec['journals']]));
}
/** Select one primary ledger explicitly; an extra source is evidence, never a second deposit. */
function lop_replace_main_banks(array $records,array $cfg,?string $snapshot,?array &$errors=null): array {
    $ledgers=lful_additional_savings($records,$snapshot);$sourceOwners=[];$banks=[];$used=[];
    foreach($records as $r){$identity=lful_identity(legacy_source_identity($r['raw']));if($r['table']==='siswa')$sourceOwners[$identity]=($sourceOwners[$identity]??0)+1;if(in_array($r['table'],['tabungan','transaksi_m','transaksi_k'],true))$banks[$identity][]=$r;}
    foreach($cfg['additional_savings']??[] as $key=>$decision){
        if(($decision['reason']??'')!=='replace_main')continue;$ledger=$ledgers[$key]??null;$target=$decision['target_identity']??'';
        try{
        if(!$ledger||!is_string($target)||($sourceOwners[$target]??0)!==1||empty($banks[$target]))throw new DomainException('Pilih rekening utama milik identitas sumber yang unik.');
        if(isset($used[$target]))throw new DomainException('Satu rekening utama tidak boleh digantikan oleh dua keputusan tabungan tambahan.');$used[$target]=true;
        $rawBank=lf_reconcile($banks[$target],$snapshot);
        if(($decision['confirmed']??false)!==true||!hash_equals($ledger['fingerprint'],(string)($decision['fingerprint']??''))||!hash_equals(lop_main_bank_fingerprint($target,$rawBank),(string)($decision['main_fingerprint']??'')))throw new DomainException('Sumber atau rekening utama berubah. Periksa konfirmasi saldo ulang.');
        if($ledger['identity']==='Tanpa identitas siswa')throw new DomainException('Lengkapi pemilik catatan tanpa identitas sebelum menggunakannya sebagai bukti.');
        $fix=['amount'=>lf_money(lf_cents($decision['amount']??null)),'evidence'=>$decision['evidence']??'','confirmed'=>true,'additional_ledger'=>$key];
        $checked=lop_fixed_bank(lop_effective_bank_rows($banks[$target],$cfg),$snapshot,$fix);
        if(!$checked['ready'])throw new DomainException(implode(' ',$checked['issues']));
        $cfg['op_savings'][$target]=$fix;
        }catch(DomainException $e){if($errors===null)throw $e;$errors[]=lop_pending($ledger['identity']??'',(string)$key,'additional_savings','additional_savings_target',$e->getMessage());}
    }return $cfg;
}
function lop_extra_source(array $job): array {
    $manifest=lf_extract_manifest($job);$cache=legacy_root().'/'.$job['id'].'/additional-input-v'.LOP_VERSION.'.json';
    if(is_file($cache)){$data=json_decode(file_get_contents($cache),true,512,JSON_THROW_ON_ERROR);if(($data['hash']??'')===$manifest['rows_sha256'])return $data['rows'];}
    $out=[];$occurrences=[];$f=fopen(legacy_root().'/'.$job['id'].'/finance.jsonl','rb');
    try{while(($line=fgets($f))!==false){$r=json_decode($line,true,512,JSON_THROW_ON_ERROR);if(!legacy_source_additional_savings($r))continue;
        $hash=hash('sha256',lf_canonical($r['raw']));$occ=($occurrences[$r['table']][$hash]??0)+1;$occurrences[$r['table']][$hash]=$occ;
        $r['row_hash']=$hash;$r['occurrence']=$occ;$r['row_key']=hash('sha256',implode('|',[$job['unit'],strtolower($job['hash']),$r['table'],$hash,$occ]));$out[]=$r;
    }}finally{fclose($f);}
    $tmp=$cache.'.'.bin2hex(random_bytes(5)).'.partial';file_put_contents($tmp,lf_json(['hash'=>$manifest['rows_sha256'],'rows'=>$out]),LOCK_EX);rename($tmp,$cache);return $out;
}
/** Extra ledgers remain evidence for one confirmed primary balance, never extra cash. */
function lop_review_extra_banks(array $records,array $cfg,?string $snapshot,array $owners,array &$pending): void {
    foreach(lful_additional_savings($records,$snapshot) as $key=>$ledger){
        $decision=$cfg['additional_savings'][$key]??[];
        if(in_array($decision['reason']??'',['duplicate','retired_zero'],true)&&!lful_additional_savings_issues([$key=>$ledger],[$key=>$decision]))continue;
        if(($decision['reason']??'')==='replace_main'){
            $target=$decision['target_identity']??'';
            if(!empty($owners[$target]['savings'])&&($cfg['op_savings'][$target]['additional_ledger']??'')===$key)continue;
        }
        $pending[]=lop_pending($ledger['identity'],$key,'additional_savings','additional_savings_review','Konfirmasi rekening utama dan jumlah sebenarnya pada tabungan tambahan.',['ledger'=>$key,'balance'=>$ledger['reconciliation']['balance']]);
    }
}
