<?php
/** Source-bound automatic checks. No original amounts, identities or dates are rewritten. */
function lop_unowned_history(array $record): ?array {
    if(trim((string)legacy_source_identity($record['raw']))!==''||!in_array($record['table'],['bayar','bayar_du','tabungan','transaksi_m','transaksi_k','tb_total','tb_masuk','tb_keluar','tbg'],true))return null;
    $date=legacy_source_record_date($record);if(!$date||$date>='2020-01-01 00:00:00')return null;
    $amounts=[];$empty=true;foreach(legacy_source_money_columns($record) as $column){$value=legacy_source_money_value($record,$column);$amounts[$column]=$value;if($value!==null&&$value!=='')$empty=false;}
    return ['row_key'=>$record['row_key'],'source_table'=>$record['table'],'date'=>$date,'amounts'=>$amounts,'kind'=>$empty?'empty_reference':'history_unowned','reason'=>'Identitas tidak tercatat; tanggal sumber sebelum 1 Januari 2020. Tidak memengaruhi rekening aktif.'];
}
function lop_payment_auto_check(array $record,array $config): ?array {
    if($record['table']!=='bayar'||!in_array($config['other_policy']??'',['total_details','source_details'],true))return null;
    $raw=$record['raw'];try{
        $total=lf_cents($raw['U_LAIN']??'0');$details=0;foreach(range(1,4) as $n)$details+=lf_cents($raw['JUMLAH'.$n]??'0');
    }catch(DomainException $e){return null;}
    if($total!==0||$details<=0)return null;
    return ['row_key'=>$record['row_key'],'code'=>'other_zero_uses_details','source_total'=>lf_money($total),'detail_total'=>lf_money($details),'rule'=>'U_LAIN kosong/nol: rincian pembayaran dihitung sekali.'];
}
function lop_problem_action(array $problem): array {
    return match($problem['code']){
        'identity_missing'=>['title'=>'Identitas belum tercatat','label'=>'Periksa identitas','section'=>'people'],
        'identity_ambiguous','identity_invalid','nis_duplicate'=>['title'=>'Identitas perlu dicocokkan','label'=>'Periksa identitas','section'=>'people'],
        'owner_review'=>['title'=>'Kelompok pemilik belum disahkan','label'=>'Sahkan kelompok','section'=>'groups'],
        'payment_review'=>['title'=>'Pembayaran perlu dilengkapi','label'=>'Periksa pembayaran','section'=>'components'],
        'fee_transaction_held'=>['title'=>'Transaksi diarsipkan otomatis','label'=>'Lihat sumber','section'=>'components'],
        'monthly_evidence'=>['title'=>'Bukti tagihan perlu dilengkapi','label'=>'Buka peninjauan','section'=>'people'],
        'savings_review'=>['title'=>'Saldo tabungan perlu dikonfirmasi','label'=>'Isi Jumlah Sebenarnya','section'=>'savings'],
        'additional_savings_review','additional_savings_target'=>['title'=>'Rekening tambahan perlu dikonfirmasi','label'=>'Tinjau rekening tambahan','section'=>'savings'],
        default=>['title'=>'Data perlu dilengkapi','label'=>'Buka peninjauan','section'=>'people'],
    };
}
