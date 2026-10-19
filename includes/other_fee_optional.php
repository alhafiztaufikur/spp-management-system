<?php
/** Optional choices never reference or settle a legacy bill. */
function other_fee_optional_choices(mysqli $db):array {
    $rows=$db->query("SELECT id,nama,nominal FROM master_biaya_lain WHERE is_active=1 AND nominal>0 AND jenis_pembayaran='berulang' ORDER BY nama")->fetch_all(MYSQLI_ASSOC);
    if(function_exists('lf_reserved_master'))$rows=array_values(array_filter($rows,static fn($r)=>!lf_reserved_master($db,(int)$r['id'])));
    foreach($rows as &$r){$r['master_id']=(int)$r['id'];$r['nominal']=(float)$r['nominal'];$r['id']='master:'.$r['id'];$r['paid']=0;$r['optional']=true;}unset($r);return $rows;
}
function other_fee_proposal_names(mysqli $db,array $payload):array {
    $names=[];foreach(($payload['biaya_lain_tagihan_id']??[]) as $choice)if(is_string($choice)&&preg_match('/^master:([1-9][0-9]*)$/D',$choice,$m)){
        $id=(int)$m[1];$s=$db->prepare('SELECT nama FROM master_biaya_lain WHERE id=?');$s->bind_param('i',$id);$s->execute();$r=$s->get_result()->fetch_row();$s->close();if(!$r)throw new RuntimeException('Master Biaya Lain tidak ditemukan.');$names[$choice]=$r[0];
    }return $names;
}
/** Validate before queuing and freeze the quote; approval revalidates the same quote. */
function other_fee_validate_proposal(mysqli $db,int $paymentId,array &$payload): void {
    $q=$db->prepare('SELECT * FROM bayar_biaya_lain WHERE bayar_id=?');$q->bind_param('i',$paymentId);$q->execute();$old=array_column($q->get_result()->fetch_all(MYSQLI_ASSOC),null,'id');$q->close();
    $seen=[];$posted=$_POST;
    try {
        $_POST['_other_fee_tariffs']=$payload['_other_fee_tariffs']??[];
        foreach (($payload['biaya_lain_tagihan_id']??[]) as $i=>$choice) {
            if(!is_scalar($choice))throw new RuntimeException('Pilihan Biaya Lain tidak valid.');
            if(!str_starts_with((string)$choice,'master:'))continue;
            $raw=$payload['biaya_lain_nominal'][$i]??0;
            if(!is_scalar($raw))throw new RuntimeException('Nominal Biaya Lain tidak valid.');
            $amount=(float)str_replace(['.',','],['','.'],(string)$raw);
            $line=other_fee_optional_line($db,(string)$choice,$amount,'',$old[(int)($payload['biaya_lain_detail_id'][$i]??0)]??null,$seen);
            if($line['tarif_referensi']!==null)$payload['_other_fee_tariffs'][$choice]=$line['tarif_referensi'];
        }
    } finally {$_POST=$posted;}
}
function other_fee_optional_line(mysqli $db,string $choice,float $amount,string $note,?array $old,array &$seen):array {
    if(!preg_match('/^master:([1-9][0-9]*)$/D',$choice,$m))throw new RuntimeException('Pilihan Biaya Lain tidak valid.');$id=(int)$m[1];
    if(!is_finite($amount)||$amount<=0)throw new RuntimeException('Nominal Biaya Lain harus berupa angka positif.');
    if(isset($seen[$choice]))throw new RuntimeException('Master yang sama hanya boleh satu baris per transaksi.');$seen[$choice]=true;
    $same=$old&&$old['tagihan_biaya_lain_id']===null&&(int)$old['master_biaya_lain_id']===$id;
    $s=$db->prepare('SELECT nama,nominal,is_active,jenis_pembayaran FROM master_biaya_lain WHERE id=? FOR UPDATE');$s->bind_param('i',$id);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();
    require_once __DIR__.'/legacy_finance.php';if(lf_reserved_master($db,$id))throw new RuntimeException('Gunakan tagihan Tunggakan Legacy yang sudah disahkan.');
    if(!$r||((!(int)$r['is_active']||$r['jenis_pembayaran']!=='berulang')&&!$same))throw new RuntimeException('Master Biaya Lain tidak berulang, berubah, atau tidak aktif. Muat ulang formulir dan pilih tagihan yang diterbitkan.');
    $trusted=$GLOBALS['authorizationRequest']??null;
    $unchanged=$same && abs($amount-(float)$old['nominal_snapshot'])<.001;
    $expected=$_POST['_other_fee_tariffs'][$choice]??null;
    if (!$unchanged && $expected!==null && (!is_scalar($expected)||abs((float)$expected-(float)$r['nominal'])>.001)) {
        throw new RuntimeException('Tarif '.$r['nama'].' berubah. Muat ulang formulir atau ajukan kembali perubahan.');
    }
    if (!$unchanged && abs($amount-(float)$r['nominal'])>.001) {
        $message=$r['nama'].' harus dibayar tepat Rp '.number_format((float)$r['nominal'],0,',','.').', tetapi diisi Rp '.number_format($amount,0,',','.').'.';
        if(class_exists('SppPaymentException')) throw new SppPaymentException(['code'=>'recurring_amount','severity'=>'error','title'=>'Biaya Berulang harus lunas','message'=>$message,'target'=>'biaya-lain-list']);
        throw new RuntimeException($message);
    }
    $name=$same?$old['nama_biaya_snapshot']:($trusted?($_POST['_other_fee_names'][$choice]??$r['nama']):$r['nama']);
    return ['bill_id'=>null,'master_id'=>$id,'nama'=>$name,'nominal'=>$amount,'keterangan'=>$note,
        'tarif_referensi'=>$unchanged?($old['tarif_referensi_snapshot']??null):(float)$r['nominal']];
}
