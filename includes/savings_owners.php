<?php
require_once __DIR__.'/migrated_savings.php';
require_once __DIR__.'/kelas.php';
/** Accept normal rupiah input while preserving exact cents. */
function so_amount(string $raw): string {
    $raw=trim($raw);
    if(preg_match('/^[1-9][0-9]{0,2}(?:\.[0-9]{3})+(?:,[0-9]{1,2})?$/D',$raw))$raw=str_replace(['.',','],['','.'],$raw);
    elseif(preg_match('/^[0-9]+(?:[.,][0-9]{1,2})?$/D',$raw))$raw=str_replace(',','.',$raw);
    else throw new DomainException('Gunakan nominal rupiah dengan maksimal dua desimal.');
    $cents=lf_cents($raw);if($cents<=0)throw new DomainException('Nominal harus lebih dari nol.');return lf_money($cents);
}
function so_source(mysqli $db): string {
    $parts=["SELECT s.id student_id,0 owner_id,s.unit_id,s.NO_INDUK identity,s.NO_induk_diknas,s.NAMA name,s.master_kelas_id,s.KELAS,COALESCE(t.SALDO,0) balance,'student' owner_type,IF(s.is_active=1 OR t.NO_INDUK IS NOT NULL,'ready','archived') state,s.is_active FROM siswa s LEFT JOIN tabungan t ON t.NO_INDUK=s.NO_INDUK AND t.unit_id=s.unit_id WHERE s.legacy_pending=0"];
    if(lop_ready($db))$parts[]="SELECT 0,o.id,o.unit_id,o.source_identity,'',o.name,g.master_kelas_id,o.source_class,a.balance,'account',IF(o.state='ready' AND a.id IS NOT NULL,'ready','held'),1 FROM legacy_op_owners o LEFT JOIN legacy_op_accounts a ON a.owner_id=o.id AND a.unit_id=o.unit_id LEFT JOIN legacy_op_groups g ON g.id=o.group_id AND g.unit_id=o.unit_id JOIN legacy_full_batches b ON b.id=o.batch_id AND b.unit_id=o.unit_id WHERE o.student_id IS NULL AND b.state='completed'";
    return '(SELECT x.*,mk.jenis rombel_jenis,mk.nama_custom rombel_nama,mk.tingkat,mk.kode_rombel,mk.is_placeholder FROM ('.implode(' UNION ALL ',$parts).') x LEFT JOIN master_kelas mk ON mk.id=x.master_kelas_id AND mk.unit_id=x.unit_id)';
}
function so_page(mysqli $db,array $input): array {
    $source=so_source($db);$q=mb_substr(trim(is_string($input['q']??null)?$input['q']:''),0,100);$where=' WHERE 1=1';$args=[];
    if($q!==''){$where.=' AND (name LIKE ? OR identity LIKE ? OR NO_induk_diknas LIKE ?)';$args=array_fill(0,3,'%'.$q.'%');}
    $type=mh_choice($input,'owner_type',['all','student','account'],'all');if($type!=='all'){$where.=' AND owner_type=?';$args[]=$type;}
    $class=max(0,(int)($input['class_id']??0));if($class){$where.=' AND master_kelas_id=?';$args[]=$class;}
    $total=(int)lf_one($db,"SELECT COUNT(*) n FROM $source x $where",$args)['n'];$pages=max(1,(int)ceil($total/25));$page=min($pages,max(1,(int)($input['page']??1)));$offset=($page-1)*25;
    $rows=lf_rows($db,"SELECT x.* FROM $source x $where ORDER BY name,unit_id,student_id,owner_id LIMIT 25 OFFSET $offset",$args);foreach($rows as &$r)$r['class_label']=class_label($r);unset($r);
    return compact('rows','total','page','pages','offset','q','type','class');
}
function so_resolve(mysqli $db,array $input,bool $write=false): array {
    $owner=filter_var($input['owner_id']??0,FILTER_VALIDATE_INT);$student=filter_var($input['student_id']??0,FILTER_VALIDATE_INT);$nis=$input['nis']??$input['no_induk']??'';
    if($owner===false||$student===false||$owner<0||$student<0||!is_string($nis)||mb_strlen($nis)>255)throw new DomainException('Identitas rekening tidak valid.');$nis=trim($nis);
    $source=so_source($db);$where='';$args=[];
    if($owner){$where='owner_id=?';$args=[$owner];}elseif($student){$where='student_id=?';$args=[$student];}elseif($nis!==''){$where="owner_type='student' AND identity=?";$args=[$nis];}else throw new DomainException('Pilih siswa / pemilik rekening.');
    $rows=lf_rows($db,"SELECT * FROM $source x WHERE $where",$args);if(count($rows)!==1)throw new DomainException('Rekening tidak ditemukan atau identitas tidak unik pada unit ini.');$r=$rows[0];
    if(($student&&(int)$r['student_id']!==$student)||($owner&&(int)$r['owner_id']!==$owner)||($nis!==''&&$nis!==$r['identity']))throw new DomainException('Pilihan identitas rekening bertentangan.');
    if($write&&(unit_active_id()===0||(int)$r['unit_id']!==unit_active_id()))throw new DomainException('Pilih satu unit rekening.');
    if($write&&$r['state']!=='ready')throw new DomainException('Rekening belum siap atau diblokir. Selesaikan pemeriksaan rekening terlebih dahulu.');
    if($r['student_id']){lop_bank_assert_ready($db,$r['identity'],(int)$r['unit_id']);if(lop_ready($db)&&lf_one($db,"SELECT id FROM legacy_op_owners WHERE student_id=? AND state<>'ready'",[$r['student_id']]))throw new DomainException('Rekening belum siap.');}
    $r['class_label']=class_label($r);return $r;
}
