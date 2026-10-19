<?php
/** Additional ledgers are evidence, never extra deposits or an unverified opening balance. */
function lful_additional_savings(array $records,?string $snapshotDate=null): array {
    $extra=[];$main=[];
    foreach($records as $r){$raw=$r['raw'];$identity=mb_strtoupper(trim(legacy_source_identity($raw)));
        if(legacy_source_additional_savings($r)){$key=$identity===''?'Tanpa identitas siswa':$identity;$extra[$key][]=$r;}
        elseif(in_array($r['table'],['tabungan','transaksi_m','transaksi_k'],true))$main[$identity][]=$r;
    }
    $digest=static function(array $rows): string {$hashes=[];foreach($rows as $r){$raw=$r['raw'];unset($raw['id']);$hashes[]=hash('sha256',lf_json([$r['table'],lf_canonical($raw)]));}sort($hashes,SORT_STRING);return hash('sha256',implode("\n",$hashes));};
    $out=[];
    foreach($extra as $identity=>$rows){$converted=[];$keys=[];$tables=[];$hasAnonymous=false;$historicalCount=0;
        foreach($rows as $r){$date=legacy_source_record_date($r);if($identity==='Tanpa identitas siswa'&&$date&&$date<'2020-01-01 00:00:00')++$historicalCount;$keys[]=$r['row_key'];$tables[$r['table']]=($tables[$r['table']]??0)+1;
            if($r['table']==='tbg'){$hasAnonymous=true;continue;}
            $r['table']=['tb_total'=>'tabungan','tb_masuk'=>'transaksi_m','tb_keluar'=>'transaksi_k'][$r['table']];$converted[]=$r;
        }
        sort($keys,SORT_STRING);$fingerprint=hash('sha256',implode("\n",$keys));$rec=lf_reconcile($converted,$snapshotDate);
        $same=!$hasAnonymous&&$converted&&isset($main[$identity])&&hash_equals($digest($main[$identity]),$digest($converted));
        $retired=!$hasAnonymous&&$rec['ready']&&$rec['balance']==='0.00';
        $out['ledger_'.substr(hash('sha256',(string)$identity),0,24)]=['identity'=>(string)$identity,'count'=>count($rows),'tables'=>$tables,'fingerprint'=>$fingerprint,'reconciliation'=>$rec,'duplicate_proven'=>(bool)$same,'retired_zero_proven'=>$retired,'requires_review'=>true,'historical_count'=>$historicalCount,'unresolved_count'=>count($rows)-$historicalCount];
    }
    return $out;
}
function lful_additional_savings_issues(array $groups,array $decisions): array {
    $issues=[];
    foreach($groups as $key=>$g){$d=$decisions[$key]??[];
        $valid=($d['confirmed']??false)===true&&hash_equals($g['fingerprint'],(string)($d['fingerprint']??''))&&mb_strlen(trim((string)($d['evidence']??'')))>=10&&mb_strlen((string)$d['evidence'])<=1000;
        $valid=$valid&&((($d['reason']??'')==='duplicate'&&$g['duplicate_proven'])||(($d['reason']??'')==='retired_zero'&&$g['retired_zero_proven']));
        if(!$valid)$issues[]=legacy_ui_issue($g['identity'],'Tabungan tambahan belum terbukti sebagai salinan rekening utama atau rekening selesai bersaldo nol. Periksa kepemilikan dan jurnal; saldo tidak dijumlahkan otomatis.','additional_savings_review');
    }
    return $issues;
}
