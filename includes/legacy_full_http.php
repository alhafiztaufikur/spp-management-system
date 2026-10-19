<?php
require_once __DIR__.'/legacy_full.php';
function lful_http(mysqli $db,string $action,array $input,bool $write): array {
    $actor=(int)($_SESSION['admin_id']??0);$unit=unit_active_id();lf_actor($db,$actor,$unit,$write);lful_require($db);
    if(!is_string($input['id']??null))throw new DomainException('ID tidak valid.');$id=$input['id'];$d=lful_draft($id);
    if($unit!==0&&(int)$d['job_info']['unit']!==$unit)throw new DomainException('Unit pekerjaan tidak sesuai.',403);
    if(!$write&&$action==='full_status'){
        unset($d['confirmed']);$out=['draft'=>$d,'catalog'=>lful_catalog_read($id),'batch'=>lf_one($db,'SELECT id,unit_id,state,cutoff,completed_at FROM legacy_full_batches WHERE id=?',[$id])];if(($d['settings']['mode']??'')==='operational'){$out['mode']='operational';$out['automatic_checks']=['rules_version'=>LOP_VERSION,'payments'=>$d['simulation']['summary']['automatic_payments']??null,'accepted_history'=>$d['simulation']['summary']['accepted_history']??null,'unidentified_records'=>$d['simulation']['summary']['unidentified_records']??null,'references'=>$d['simulation']['summary']['separate_references']??null,'resolved_balances'=>$d['simulation']['summary']['source_balance_fixes']??null];$out['review_task_count']=$d['simulation']['summary']['review_tasks']??null;$out['pending_count']=$out['batch']?(int)lf_one($db,'SELECT COUNT(*) n FROM legacy_op_pending WHERE batch_id=? AND resolved=0',[$id])['n']:($d['simulation']['summary']['pending']??null);$groups=[];foreach($out['catalog']['students']??[] as $student){$g=lop_group((string)$student['source_class'],(int)$d['job_info']['unit']);if($g['graduation_year'])$groups[$g['code']]=['source_code'=>$g['code'],'graduation_year'=>$g['graduation_year'],'suggested_class'=>'Alumni'.$g['graduation_year']];}$out['operational_review']=['rules_version'=>LOP_VERSION,'alumni_suggestions'=>array_values($groups),'banks'=>lop_bank_index($d['job_info'],$out['catalog']['manifest'])];}return $out;
    }
    if(!$write&&$action==='full_suggestions')return lful_suggestions($db,$id);
    if(!$write)throw new DomainException('Metode tidak diizinkan.',400);
    if(!is_string($input['csrf_token']??null)||!hash_equals($_SESSION['csrf_legacy_import']??'',$input['csrf_token']))throw new DomainException('Token CSRF tidak valid.',403);
    $version=filter_var($input['version']??null,FILTER_VALIDATE_INT);if(!$version||(int)$d['version']!==$version)throw new DomainException('Peninjauan berubah. Muat ulang.');
    if($action==='full_save'){$patch=$input['patch']??null;if(is_string($patch))$patch=json_decode($patch,true,64,JSON_THROW_ON_ERROR);if(!is_array($patch))throw new DomainException('Pemetaan tidak valid.');$d=lful_save($db,$actor,$unit,$id,$version,$patch);unset($d['confirmed']);return ['draft'=>$d];}
    if(in_array($action,['full_bulk_preview','full_bulk_accept'],true)){
        if(($d['settings']['mode']??'')!=='operational')throw new DomainException('Persetujuan massal untuk migrasi operasional.');
        $scope=$input['scope']??'all';if(!is_string($scope))throw new DomainException('Pilihan persetujuan tidak valid.');
        $catalog=lful_catalog_read($id);[$records]=lop_review_source($d['job_info']);
        $proposal=lop_bulk_patch($records,$catalog,$d['settings'],$unit,$scope,lf_rows($db,'SELECT id,nama FROM master_biaya_lain ORDER BY id'));
        $digest=hash('sha256',lf_json([$id,$actor,$unit,$version,$scope,$catalog['manifest']['rows_sha256'],lful_masters_digest($db),$proposal]));
        if($action==='full_bulk_preview')return ['scope'=>$scope,'counts'=>$proposal['counts'],'digest'=>$digest,'new_masters'=>array_values($proposal['patch']['new_masters']??[]),'replacements'=>$proposal['replacements']];
        if(($input['confirmation']??'')!=='SETUJUI SEMUA'||!is_string($input['preview_digest']??null)||!hash_equals($digest,$input['preview_digest']))throw new DomainException('Ringkasan persetujuan berubah. Periksa ulang.');
        $saved=lful_save($db,$actor,$unit,$id,$version,$proposal['patch']);
        lful_queue_simulation($db,$actor,$unit,$id,(int)$saved['version']);
        return ['accepted'=>true,'queued'=>true,'counts'=>$proposal['counts']];
    }
    if(in_array($action,['full_group_preview','full_group_accept'],true)){
        $group=$input['group']??null;if(is_string($group))$group=json_decode($group,true,64,JSON_THROW_ON_ERROR);if(!is_array($group))throw new DomainException('Kelompok tidak valid.');
        if($action==='full_group_preview')return lful_group_preview($db,$id,$version,$group);
        $saved=lful_group_accept($db,$actor,$unit,$id,$version,$group,(string)($input['preview_digest']??''));unset($saved['confirmed']);return ['draft'=>$saved];
    }
    if($action==='full_profile_save'){lful_profile_save($db,$id,$actor);return ['saved'=>true];}
    if($action==='full_simulate'){
        if((string)($input['async']??'0')==='1'){lful_queue_simulation($db,$actor,$unit,$id,$version);return ['queued'=>true,'job'=>legacy_public(legacy_job($id))];}
        if(!in_array($d['job_info']['state'],['ready','failed'],true)||(($d['settings']['mode']??'')!=='operational'&&lf_one($db,'SELECT id FROM legacy_full_batches WHERE id=?',[$id])))throw new DomainException('Migrasi sudah diproses; simulasi tidak dapat diubah.');$s=lful_simulate($db,$id);return array_intersect_key($s,array_flip(['ready','digest','version','summary','issues','notes','mode']));
    }
    if($action==='full_confirm'){
        $c=lful_confirm($db,$actor,$unit,$id,$version,(string)($input['digest']??''));$_SESSION['legacy_full_tokens'][$id]=$c['token'];return ['confirmation_token'=>$c['token'],'expires'=>$c['expires']];
    }
    if($action==='full_apply'){
        if(($input['confirmation']??'')!=='MIGRASI PENUH'||!is_string($input['confirmation_token']??null)||!hash_equals($_SESSION['legacy_full_tokens'][$id]??'',$input['confirmation_token']))throw new DomainException('Konfirmasi final tidak sesuai.');
        lful_enqueue_apply($db,$actor,$unit,$id,$version,$input['confirmation_token']);return ['job'=>legacy_public(legacy_job($id))];
    }
    if($action==='full_retry'){
        if(($input['confirmation']??'')!=='VERIFIKASI ULANG')throw new DomainException('Konfirmasi verifikasi ulang diperlukan.');
        $b=lf_one($db,"SELECT id FROM legacy_full_batches WHERE id=? AND state IN ('blocked','verifying')",[$id]);if(!$b)throw new DomainException('Tidak ada migrasi yang menunggu verifikasi.');
        legacy_queue()->prepare("UPDATE jobs SET state='apply_queued',actor=?,updated=? WHERE id=? AND state='failed'")->execute([$actor,time(),$id]);return ['job'=>legacy_public(legacy_job($id))];
    }
    throw new DomainException('Tindakan tidak tersedia.',400);
}
