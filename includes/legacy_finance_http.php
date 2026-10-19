<?php
require_once __DIR__.'/legacy_finance.php';
require_once __DIR__.'/legacy_ui.php';
function lf_student_search(mysqli $db,array $input): array {
    lf_actor($db,(int)($_SESSION['admin_id']??0),unit_active_id());
    foreach(['q','page'] as $key)if(isset($input[$key])&&!is_string($input[$key]))throw new DomainException('Pencarian siswa tidak valid.',400);
    $q=trim($input['q']??'');$page=$input['page']??'1';
    if(mb_strlen($q)>100||!preg_match('/^[0-9]{1,5}$/D',$page)||(int)$page<1)throw new DomainException('Pencarian siswa tidak valid.',400);
    $unit=unit_active_id();if(!$unit)return ['students'=>[],'total'=>0,'page'=>(int)$page,'code'=>'select_unit'];
    $where='unit_id=?';$args=[$unit];if($q!==''){$where.=' AND (NAMA LIKE ? OR NO_INDUK LIKE ? OR KELAS LIKE ?)';array_push($args,'%'.$q.'%','%'.$q.'%','%'.$q.'%');}
    $total=(int)lf_one($db,'SELECT COUNT(*) n FROM siswa WHERE '.$where,$args)['n'];
    $students=lf_rows($db,'SELECT id,NAMA name,NO_INDUK nis,KELAS class,is_active,legacy_pending FROM siswa WHERE '.$where.' ORDER BY NAMA,id LIMIT 30 OFFSET '.(((int)$page-1)*30),$args);
    return ['students'=>$students,'total'=>$total,'page'=>(int)$page];
}
function lf_reviews_search(mysqli $db,array $input): array {
    foreach(['q','source','status','review_page'] as $key)if(isset($input[$key])&&!is_string($input[$key]))throw new DomainException('Filter peninjauan tidak valid.',400);
    $q=trim($input['q']??'');$status=$input['status']??'';$source=$input['source']??'';$page=$input['review_page']??'1';
    if(mb_strlen($q)>100||!in_array($status,['','ready','held','applied'],true)||($source!==''&&!ctype_digit($source))||!preg_match('/^[0-9]{1,5}$/D',$page)||(int)$page<1)throw new DomainException('Filter peninjauan tidak valid.',400);
    $where='s.published=1';$args=[];if($q!==''){$where.=' AND (m.source_nis LIKE ? OR m.source_name LIKE ? OR st.NAMA LIKE ?)';array_push($args,'%'.$q.'%','%'.$q.'%','%'.$q.'%');}if($source!==''){$where.=' AND m.source_id=?';$args[]=$source;}if($status!==''){$where.=' AND r.status=?';$args[]=$status;}
    $join=' FROM legacy_finance_reviews r JOIN legacy_finance_maps m ON m.id=r.map_id AND m.unit_id=r.unit_id JOIN legacy_finance_sources s ON s.id=m.source_id AND s.unit_id=m.unit_id LEFT JOIN siswa st ON st.id=m.student_id AND st.unit_id=m.unit_id WHERE '.$where;
    $total=(int)lf_one($db,'SELECT COUNT(*) n'.$join,$args)['n'];
    return ['total'=>$total,'page'=>(int)$page,'rows'=>lf_rows($db,'SELECT r.id,r.status,r.version,r.savings_result,m.source_nis,m.source_name,m.match_status,m.unit_id,st.NAMA target_name'.$join.' ORDER BY r.id LIMIT 30 OFFSET '.(((int)$page-1)*30),$args)];
}
function lf_http_bootstrap(mysqli $db): void {
    header('Cache-Control: private, no-store');
    if(!isset($_SESSION['admin_id'])||($_SESSION['admin_role']??'')!=='super_admin')throw new DomainException('Hanya Super Admin yang dapat mengakses arsip.',403);
    lf_actor($db,(int)$_SESSION['admin_id'],unit_active_id());
    if(empty($_SESSION['csrf_legacy_finance']))$_SESSION['csrf_legacy_finance']=bin2hex(random_bytes(32));
}
function lf_http_csrf(): void {
    if(!is_string($_POST['csrf_token']??null)||!hash_equals($_SESSION['csrf_legacy_finance']??'',$_POST['csrf_token']))throw new DomainException('Token CSRF tidak valid.',403);
    if(unit_active_id()===0)throw new DomainException('Pilih satu unit untuk melakukan perubahan.');
}
function lf_confirmation(array $review,string $action='apply'): string {
    $token=bin2hex(random_bytes(24));
    $_SESSION['legacy_finance_confirmations']=array_filter($_SESSION['legacy_finance_confirmations']??[],static fn($c)=>$c['expires']>=time());
    if(count($_SESSION['legacy_finance_confirmations'])>=20)array_shift($_SESSION['legacy_finance_confirmations']);
    $_SESSION['legacy_finance_confirmations'][$token]=['review_id'=>(int)$review['id'],'version'=>(int)$review['version'],'unit'=>unit_active_id(),'actor'=>(int)$_SESSION['admin_id'],'action'=>$action,'digest'=>hash('sha256',lf_json($review['review_data'])),'expires'=>time()+300];
    return $token;
}
function lf_check_confirmation(array $review,string $token,string $action='apply'): void {
    $c=$_SESSION['legacy_finance_confirmations'][$token]??null;
    if(!$c||$c['expires']<time()||$c['review_id']!==(int)$review['id']||$c['version']!==(int)$review['version']||$c['unit']!==unit_active_id()||$c['actor']!==(int)$_SESSION['admin_id']||$c['action']!==$action||!hash_equals($c['digest'],hash('sha256',lf_json($review['review_data']))))throw new DomainException('Konfirmasi kedaluwarsa atau berubah. Periksa ulang peninjauan.');
}
function lf_http_mutate(mysqli $db,string $action,array $input): array {
    lf_http_csrf();$actor=(int)$_SESSION['admin_id'];$unit=unit_active_id();$id=filter_var($input['id']??null,FILTER_VALIDATE_INT);$version=filter_var($input['version']??null,FILTER_VALIDATE_INT);
    if(!$id||!$version)throw new DomainException('Identitas atau versi peninjauan tidak valid.');
    if($action==='map'){
        if(($input['confirmation']??'')!=='COCOKKAN SISWA')throw new DomainException('Konfirmasi pencocokan diperlukan.');
        lf_map($db,$actor,$unit,$id,$version,(int)($input['student_id']??0),(string)($input['reason']??''));
        return ['review'=>lf_review_load($db,$id)];
    }
    if($action==='save'){
        $proposal=$input['proposal']??null;if(is_string($proposal))$proposal=json_decode($proposal,true,32,JSON_THROW_ON_ERROR);
        if(!is_array($proposal))throw new DomainException('Rincian peninjauan tidak valid.');
        $r=lf_save_review($db,$actor,$unit,$id,$version,$proposal);return ['review'=>$r,'confirmation_token'=>lf_confirmation($r)];
    }
    $r=lf_review_load($db,$id);
    // Safe retry uses the original confirmed version after a lost successful response.
    if($action==='apply'&&$r['status']==='applied'&&(int)$r['version']===$version+1){
        $old=$r;$old['version']=$version;lf_check_confirmation($old,(string)($input['confirmation_token']??''));
        return ['review'=>lf_apply($db,$actor,$unit,$id,$version,(string)($input['confirmation']??''))];
    }
    lf_version($r,$version);
    if($action==='confirm')return ['review'=>$r,'confirmation_token'=>lf_confirmation($r)];
    if($action==='apply'){
        lf_check_confirmation($r,(string)($input['confirmation_token']??''));
        return ['review'=>lf_apply($db,$actor,$unit,$id,$version,(string)($input['confirmation']??''))];
    }
    if($action==='cancel_confirm')return ['review'=>$r,'confirmation_token'=>lf_confirmation($r,'cancel')];
    if($action==='cancel_debts_confirm')return ['review'=>$r,'confirmation_token'=>lf_confirmation($r,'cancel_debts')];
    if($action==='cancel_debts'){
        lf_check_confirmation($r,(string)($input['confirmation_token']??''),'cancel_debts');
        lf_cancel_debts($db,$actor,$unit,$id,$version,(string)($input['reason']??''),(string)($input['confirmation']??''));return ['review'=>lf_review_load($db,$id)];
    }
    if($action==='cancel_opening'){
        lf_check_confirmation($r,(string)($input['confirmation_token']??''),'cancel');
        lf_cancel_opening($db,$actor,$unit,$id,$version,(string)($input['reason']??''),(string)($input['confirmation']??''));return ['review'=>lf_review_load($db,$id)];
    }
    throw new DomainException('Tindakan tidak tersedia.');
}
function lf_archive_search(mysqli $db,array $input,bool $csv=false): array {
    foreach(['q','table','source','page','id'] as $key)if(isset($input[$key])&&!is_string($input[$key]))throw new DomainException('Filter tidak valid.');
    $q=trim($input['q']??'');$table=$input['table']??'';$source=$input['source']??'';$page=$input['page']??'1';
    if(mb_strlen($q)>100||($table!==''&&!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D',$table))||($source!==''&&!ctype_digit($source))||!ctype_digit($page)||(int)$page<1||(int)$page>100000)throw new DomainException('Filter tidak valid.');
    $where='s.published=1';$args=[];
    if($q!==''){$where.=' AND (a.source_nis LIKE ? OR a.source_operator LIKE ? OR CAST(a.raw_data AS CHAR) LIKE ?)';array_push($args,'%'.$q.'%','%'.$q.'%','%'.$q.'%');}
    if($table!==''){$where.=' AND a.source_table=?';$args[]=$table;}if($source!==''){$where.=' AND a.source_id=?';$args[]=$source;}
    if(isset($input['id'])){if(!ctype_digit($input['id']))throw new DomainException('ID tidak valid.');$where.=' AND a.id=?';$args[]=$input['id'];}
    $join=' FROM legacy_finance_rows a JOIN legacy_finance_sources s ON s.id=a.source_id AND s.unit_id=a.unit_id WHERE '.$where;
    $count=(int)lf_one($db,'SELECT COUNT(*) n'.$join,$args)['n'];$offset=((int)$page-1)*50;
    if($csv)return ['total'=>$count,'result'=>lf_query($db,'SELECT a.*,s.backup_hash,s.source_date backup_date'.$join.' ORDER BY a.id',$args)];
    return ['total'=>$count,'page'=>(int)$page,'rows'=>lf_rows($db,'SELECT a.*,s.backup_hash,s.source_date backup_date'.$join.' ORDER BY a.id'.($csv?'':' LIMIT 50 OFFSET '.$offset),$args)];
}
function lf_csv_cell(mixed $v): string { $v=(string)($v??'');return preg_match('/^[\s]*[=+@-]/u',$v)?"'".$v:$v; }
