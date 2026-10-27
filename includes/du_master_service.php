<?php
require_once __DIR__.'/legacy_du.php';
require_once __DIR__.'/legacy_du_reconstruction.php';

/** Empty is unchanged. A nonempty amount must be a positive Indonesian money value. */
function du_master_rate_cents(mixed $value,int $grade): ?int {
    if(!is_string($value)&&!is_int($value))throw new DomainException('Nominal kelas '.$grade.' tidak valid.');
    $value=trim((string)$value);if($value==='')return null;
    if(!preg_match('/^(?:[0-9]+|[0-9]{1,3}(?:\.[0-9]{3})+)(?:,[0-9]{1,2})?$/D',$value))throw new DomainException('Isi nominal rupiah yang valid untuk kelas '.$grade.'.');
    $cents=lf_cents(str_replace(',','.',str_replace('.','',$value)));
    if(du_psb_initial_grade(unit_active_id(),$grade)){if($cents===0)return null;throw new DomainException('DU kelas '.$grade.' tercakup PSB; tarif terpisah hanya untuk kelas lanjutan.');}
    if($cents<=0)throw new DomainException('Nominal kelas '.$grade.' harus lebih dari Rp0; kosongkan jika belum ditetapkan.');
    return $cents;
}

/** Caller owns transaction: validation, rates, publication and evidence commit together. */
function du_master_save_and_complete(mysqli $db,int $yearId,string $label,mixed $amounts,int $actor): array {
    $year=hp_one($db,'SELECT * FROM tahun_ajaran WHERE id=? AND label=? FOR UPDATE',[$yearId,$label]);
    if(!$year||$year['status']==='closed')throw new DomainException('Tahun ajaran tertutup atau tidak tersedia; tarif dan tagihan tidak diubah.');
    if($label!==du_current_academic_year())throw new DomainException('Penerbitan DU ini hanya untuk tahun ajaran berjalan. Histori tahun lain tetap tersimpan.');
    if(!is_array($amounts))throw new DomainException('Formulir tarif tidak valid.');
    [$first,$last]=unit_level_bounds();$validated=[];
    foreach($amounts as $grade=>$value){if(!ctype_digit((string)$grade)||(int)$grade<$first||(int)$grade>$last)throw new DomainException('Kelas tarif tidak sesuai unit aktif.');$cents=du_master_rate_cents($value,(int)$grade);if($cents!==null)$validated[(int)$grade]=$cents;}
    $saved=0;$aligned=0;
    foreach($validated as $grade=>$cents){
        $master=hp_one($db,'SELECT * FROM Daftar_ulang WHERE tahun_ajaran_id=? AND kelas=? FOR UPDATE',[$yearId,(string)$grade]);
        if($master&&lf_cents($master['Jumlah'])===$cents)continue;
        $rateAligned=0;
        if(!$master){lf_query($db,'INSERT INTO Daftar_ulang(tahun_ajaran_id,th_ajaran,kelas,Jumlah) VALUES(?,?,?,?)',[$yearId,$label,(string)$grade,lf_money($cents)]);$id=(int)$db->insert_id;}
        else{$id=(int)$master['id'];$rateAligned=du_sync_open_bills_for_master_rate($db,$id,$label,(float)$master['Jumlah'],$cents/100);$aligned+=$rateAligned;lf_query($db,'UPDATE Daftar_ulang SET Jumlah=? WHERE id=?',[lf_money($cents),$id]);}
        du_write_audit($db,$yearId,$id,$master?'ubah_tarif':'buat_tarif',$master?['jumlah'=>$master['Jumlah']]:null,['kelas'=>(string)$grade,'jumlah'=>$cents/100,'basis'=>'system','policy'=>'new_bill_full_rate_unless_individual_discount_proven'],$rateAligned);
        lb_mark_du_master($db,$label,[$grade]);$saved++;
    }
    $coverage=du_psb_reconcile($db,$label,$actor);
    $tracking=ldu_reconstruct_payments($db,$actor);
    lb_cache($db,true);$created=du_publish_year_from_active_students($db,$yearId,$label);
    $result=['rates_saved'=>$saved,'created'=>$created,'existing'=>max(0,(int)($GLOBALS['du_publish_result']['existing']??0)-$aligned),'unknown'=>(int)($GLOBALS['du_publish_result']['unknown']??0),'unpaid_bills_aligned'=>$aligned,'payments_reclassified'=>$tracking['receipts'],'covered_psb'=>(int)($GLOBALS['du_publish_result']['covered_psb']??$coverage['covered_psb']),'conflicts'=>(int)($GLOBALS['du_publish_result']['conflicts']??$coverage['conflicts'])];
    if($saved||$created||$aligned||$coverage['changes'])du_write_audit($db,$yearId,null,'simpan_lengkapi',null,$result,$created);
    if(lop_ready($db))lb_refresh_manifests($db,lb_sync_owners($db,array_column(lb_cache($db)['students'],'id')));
    return $result;
}
