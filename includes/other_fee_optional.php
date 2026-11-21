<?php
/** Optional choices never reference or settle a legacy bill. */
function other_fee_optional_choices(mysqli $db):array {
    $rows=$db->query("SELECT id,nama,nominal,jenis_pembayaran FROM master_biaya_lain WHERE is_active=1
        AND ((jenis_pembayaran='berulang' AND nominal>0) OR jenis_pembayaran='input_manual') ORDER BY nama")->fetch_all(MYSQLI_ASSOC);
    if(function_exists('lf_reserved_master'))$rows=array_values(array_filter($rows,static fn($r)=>!lf_reserved_master($db,(int)$r['id'])));
    foreach($rows as &$r){$r['master_id']=(int)$r['id'];$r['nominal']=$r['jenis_pembayaran']==='input_manual'?null:(float)$r['nominal'];$r['id']='master:'.$r['id'];$r['paid']=0;$r['optional']=true;}unset($r);return $rows;
}

function other_fee_manual_amount(mixed $raw): float {
    $value = is_scalar($raw) ? trim((string)$raw) : '';
    if (preg_match('/^(?:[0-9]+|[0-9]{1,3}(?:\.[0-9]{3})+)(?:,[0-9]{1,2})?$/D', $value)) {
        $value = str_replace(['.',','],['','.'],$value);
    }
    if (!preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D', $value)
        || strlen(ltrim(explode('.', $value, 2)[0], '0')) > 13 || (float)$value <= 0) {
        throw new DomainException('Isi nominal Input Manual lebih dari Rp 0 dengan maksimal dua desimal.');
    }
    return (float)$value;
}

function other_fee_existing_note(?array $detail, string $choice): string {
    if (!$detail) return '';
    $bill = (int)($detail['tagihan_biaya_lain_id'] ?? 0);
    $master = (int)($detail['master_biaya_lain_id'] ?? 0);
    if ($choice === 'master:'.$master && $master > 0
        || ($bill > 0 ? $choice === (string)$bill : $choice === '')) {
        return (string)($detail['keterangan'] ?? '');
    }
    return '';
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
        $_POST['_other_fee_modes']=$payload['_other_fee_modes']??[];
        $payload['_other_fee_modes']=[];
        foreach (($payload['biaya_lain_tagihan_id']??[]) as $i=>$choice) {
            if(!is_scalar($choice))throw new RuntimeException('Pilihan Biaya Lain tidak valid.');
            if(!str_starts_with((string)$choice,'master:'))continue;
            $raw=$payload['biaya_lain_nominal'][$i]??0;
            if(!is_scalar($raw))throw new RuntimeException('Nominal Biaya Lain tidak valid.');
            $amount=(float)str_replace(['.',','],['','.'],(string)$raw);
            $line=other_fee_optional_line($db,(string)$choice,$amount,'',$old[(int)($payload['biaya_lain_detail_id'][$i]??0)]??null,$seen,$raw);
            $payload['_other_fee_modes'][$choice]=$line['jenis_pembayaran'];
            if($line['tarif_referensi']!==null)$payload['_other_fee_tariffs'][$choice]=$line['tarif_referensi'];
        }
    } finally {$_POST=$posted;}
}
function other_fee_optional_line(mysqli $db,string $choice,float $amount,string $note,?array $old,array &$seen,mixed $raw=null):array {
    if(!preg_match('/^master:([1-9][0-9]*)$/D',$choice,$m))throw new RuntimeException('Pilihan Biaya Lain tidak valid.');$id=(int)$m[1];
    if(isset($seen[$choice]))throw new RuntimeException('Master yang sama hanya boleh satu baris per transaksi.');$seen[$choice]=true;
    $purchase=$old&&!empty($old['tagihan_biaya_lain_id'])?hp_one($db,"SELECT origin,provenance FROM tagihan_biaya_lain WHERE id=?",[$old['tagihan_biaya_lain_id']]):null;
    $same=$old&&($old['tagihan_biaya_lain_id']===null||($purchase['origin']??'')==='purchase')&&(int)$old['master_biaya_lain_id']===$id;
    $s=$db->prepare('SELECT nama,nominal,is_active,jenis_pembayaran FROM master_biaya_lain WHERE id=? FOR UPDATE');$s->bind_param('i',$id);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();
    require_once __DIR__.'/legacy_finance.php';if(lf_reserved_master($db,$id))throw new RuntimeException('Gunakan tagihan Tunggakan Legacy yang sudah disahkan.');
    $trusted=$GLOBALS['authorizationRequest']??null;
    if(!$r||((!(int)$r['is_active']||!in_array($r['jenis_pembayaran'],['berulang','input_manual'],true))&&!$same))throw new RuntimeException('Master Biaya Lain berubah atau tidak aktif. Muat ulang formulir.');
    $mode = $same ? other_fee_purchase_mode($purchase) : $r['jenis_pembayaran'];
    $expectedMode = $_POST['_other_fee_modes'][$choice] ?? null;
    if (($expectedMode !== null && $expectedMode !== $mode)
        || ($mode === 'input_manual' && !$same && $trusted && $expectedMode === null)) {
        throw new RuntimeException('Jenis pembayaran '.$r['nama'].' berubah. Muat ulang formulir atau ajukan kembali perubahan.');
    }
    $name=$same?$old['nama_biaya_snapshot']:($trusted?($_POST['_other_fee_names'][$choice]??$r['nama']):$r['nama']);
    if ($mode === 'input_manual') {
        if (isset($_POST['_other_fee_tariffs'][$choice])) {
            throw new RuntimeException('Jenis pembayaran '.$r['nama'].' berubah. Muat ulang formulir.');
        }
        return ['purchase_eligible'=>true,'bill_id'=>null,'master_id'=>$id,'nama'=>$name,
            'nominal'=>other_fee_manual_amount($raw ?? $amount),'keterangan'=>$note,
            'tarif_referensi'=>null,'jenis_pembayaran'=>$mode];
    }
    if(!is_finite($amount)||$amount<=0)throw new RuntimeException('Nominal Biaya Lain harus berupa angka positif.');
    $unchanged=$same && abs($amount-(float)$old['nominal_snapshot'])<.001;
    $expected=$_POST['_other_fee_tariffs'][$choice]??null;
    $tariff = (float)($same && $r['jenis_pembayaran'] !== 'berulang'
        ? ($old['tarif_referensi_snapshot'] ?? 0) : $r['nominal']);
    if (!$unchanged && $expected!==null && (!is_scalar($expected)||abs((float)$expected-$tariff)>.001)) {
        throw new RuntimeException('Tarif '.$r['nama'].' berubah. Muat ulang formulir atau ajukan kembali perubahan.');
    }
    if (!$unchanged && abs($amount-$tariff)>.001) {
        $message=$r['nama'].' harus dibayar tepat Rp '.number_format($tariff,0,',','.').', tetapi diisi Rp '.number_format($amount,0,',','.').'.';
        if(class_exists('SppPaymentException')) throw new SppPaymentException(['code'=>'recurring_amount','severity'=>'error','title'=>'Biaya Berulang harus lunas','message'=>$message,'target'=>'biaya-lain-list']);
        throw new RuntimeException($message);
    }
    $unquotedHistory=$old&&$old['tagihan_biaya_lain_id']===null&&($old['tarif_referensi_snapshot']??null)===null;
    return ['purchase_eligible'=>!$old||($old['tarif_referensi_snapshot']??null)!==null||($purchase['origin']??'')==='purchase','bill_id'=>null,'master_id'=>$id,'nama'=>$name,'nominal'=>$amount,'keterangan'=>$note,
        'tarif_referensi'=>$unquotedHistory?null:($unchanged?($old['tarif_referensi_snapshot']??null):$tariff),'jenis_pembayaran'=>$mode];
}
