<?php
require_once __DIR__.'/report_letters.php';

function parent_letter_collect(mysqli $db,array $query): array
{
    $mode=(string)($query['mode']??'single');
    if(!in_array($mode,['single','selected','class','all'],true))throw new InvalidArgumentException('Pilihan cetak tidak dikenal.');
    $filters=report_filters($db,$query);$filters['status']='';
    $filters['q']=$mode==='class'?$filters['q']:'';$filters['kelas']=$mode==='class'?$filters['kelas']:'';
    if($mode==='class'&&$filters['kelas']==='')throw new InvalidArgumentException('Pilih kelas atau rombel terlebih dahulu.');
    $today=report_letter_today();$students=report_student_debt_groups($db,$filters,'',[],$today);
    if(in_array($mode,['single','selected'],true)){
        $raw=(string)($query['students']??$query['student_key']??$query['nis']??'');
        if(strlen($raw)>30000)throw new InvalidArgumentException('Pilihan siswa terlalu banyak.');
        $requested=array_values(array_filter(array_map('trim',explode(',',$raw))));
        if($mode==='single')$requested=array_slice($requested,0,1);
        $lookup=[];foreach(array_slice($requested,0,1000) as $key){
            if(str_contains($key,'|')){$lookup[$key]=true;continue;}
            $matches=array_values(array_filter($students,static fn($s)=>$s['nis']===$key));
            if(count($matches)>1)throw new InvalidArgumentException('NIS terdapat di beberapa unit. Pilih siswa melalui daftar surat.');
            if($matches)$lookup[unit_student_key($matches[0])]=true;
        }
        $students=array_values(array_filter($students,static fn($s)=>isset($lookup[unit_student_key($s)])));
    }
    if(!$students)throw new RuntimeException('Tidak ada tunggakan terbuka untuk pilihan ini. Perbarui daftar surat.');
    return ['students'=>$students,'today'=>$today,'unit'=>unit_active_id()];
}

function parent_letter_draft_create(array $data): string
{
    foreach($_SESSION['parent_letter_drafts']??[] as $key=>$draft)if(($draft['expires']??0)<time())unset($_SESSION['parent_letter_drafts'][$key]);
    if(count($_SESSION['parent_letter_drafts']??[])>=20)throw new RuntimeException('Terlalu banyak draf terbuka. Tunggu draf lama kedaluwarsa atau masuk ulang.');
    $token=bin2hex(random_bytes(24));
    $_SESSION['parent_letter_drafts'][$token]=$data+['owner'=>(int)$_SESSION['admin_id'],'expires'=>time()+7200,'messages'=>[]];
    return $token;
}

function parent_letter_draft_read(string $token): array
{
    $draft=$_SESSION['parent_letter_drafts'][$token]??null;
    if(!$draft || $draft['expires']<time() || $draft['owner']!==(int)$_SESSION['admin_id'])throw new RuntimeException('Draf surat sudah kedaluwarsa atau tidak ditemukan. Susun ulang surat dari daftar siswa.');
    if($draft['unit']!==unit_active_id())throw new RuntimeException('Unit aktif berbeda dari unit draf. Kembali ke unit saat draf dibuat.');
    return $draft;
}

function parent_letter_draft_update(string $token,array $messages): array
{
    $draft=parent_letter_draft_read($token);$allowed=[];foreach($draft['students'] as $student)$allowed[unit_student_key($student)]=true;
    foreach($messages as $key=>$message){
        if(!isset($allowed[$key]))throw new InvalidArgumentException('Penerima tidak termasuk dalam draf surat.');
        if(!is_string($message)||!mb_check_encoding($message,'UTF-8')||mb_strlen($message,'UTF-8')>2000)throw new InvalidArgumentException('Pesan setiap siswa maksimal 2.000 karakter.');
        $message=str_replace(["\r\n","\r"],"\n",$message);
        $draft['messages'][$key]=trim($message);
    }
    $_SESSION['parent_letter_drafts'][$token]=$draft;return $draft;
}
