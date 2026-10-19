<?php
/** Bounded per-owner metadata. No journal list is loaded by the target directory. */
function lop_bank_index(array $job,array $manifest): array {
    $path=legacy_root().'/'.$job['id'].'/operational-bank-index.json';
    if(is_file($path)){$cache=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);if(($cache['rules']??0)===LOP_VERSION&&($cache['sha']??'')===$manifest['rows_sha256'])return $cache['owners'];}
    $owners=[];$f=fopen(legacy_root().'/'.$job['id'].'/finance.jsonl','rb');
    try{while(($line=fgets($f))!==false){$r=json_decode($line,true,512,JSON_THROW_ON_ERROR);if(!in_array($r['table'],['tabungan','transaksi_m','transaksi_k','tb_total','tbg'],true))continue;$identity=lful_identity(legacy_source_identity($r['raw']));if($identity==='')continue;
        $owners[$identity]??=['accounts'=>0,'additional_accounts'=>0,'source_balance'=>null,'journal_balance'=>null,'in'=>0,'out'=>0,'journals'=>0,'invalid'=>false,'invalid_balance'=>false];$o=&$owners[$identity];
        try{if($r['table']==='tabungan'){$o['accounts']++;$o['source_balance']=lf_money(lf_cents($r['raw']['SALDO']??null));}
            elseif(in_array($r['table'],['tb_total','tbg'],true))$o['additional_accounts']++;
            else{$incoming=lf_cents($r['raw']['MASUK']??($r['table']==='transaksi_k'?'0':null));$outgoing=lf_cents($r['raw']['KELUAR']??($r['table']==='transaksi_m'?'0':null));$o['in']+=$incoming;$o['out']+=$outgoing;$o['journals']++;$date=lful_date($r['raw']['TANGGAL']??null);if(!$date||($manifest['source_date']&&substr($date,0,10)>substr($manifest['source_date'],0,10))||($r['table']==='transaksi_m'?($incoming<=0||$outgoing!==0):($outgoing<=0||$incoming!==0)))$o['invalid']=true;}}
        catch(DomainException $e){$o['invalid']=true;if($r['table']==='tabungan')$o['invalid_balance']=true;}unset($o);
    }}finally{fclose($f);}
    foreach($owners as &$o){if($o['journals']&&!$o['invalid'])$o['journal_balance']=lf_money($o['in']-$o['out']);if($o['accounts']!==1||$o['invalid_balance'])$o['source_balance']=null;$o['status']=$o['source_balance']!==null&&$o['source_balance']===$o['journal_balance']?'Cocok':'Perlu diperiksa';unset($o['in'],$o['out'],$o['invalid'],$o['invalid_balance']);}unset($o);
    $tmp=$path.'.'.bin2hex(random_bytes(5)).'.partial';file_put_contents($tmp,lf_json(['rules'=>LOP_VERSION,'sha'=>$manifest['rows_sha256'],'owners'=>$owners]),LOCK_EX);if(!rename($tmp,$path))@unlink($tmp);return $owners;
}
