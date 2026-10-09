<?php
if(PHP_SAPI!=='cli'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1'||!preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME')))exit(1);
ob_start();require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/reports.php';require_once __DIR__.'/http_form_scope.php';require_once __DIR__.'/excel_test_helpers.php';
$base=rtrim((string)getenv('SPP_TEST_BASE_URL'),'/');if(!preg_match('#^http://(?:localhost|127\.0\.0\.1):[0-9]+$#D',$base))throw new RuntimeException('Use loopback');spp_test_assert_http_clone($base,DB_NAME);
function global_http_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function global_http(string $path,string $session,?array $post=null):array{
 global $base;$headers=['Cookie: PHPSESSID='.$session];if($post!==null)$headers[]='Content-Type: application/x-www-form-urlencoded';
 $body=file_get_contents($base.'/'.$path,false,stream_context_create(['http'=>['method'=>$post===null?'GET':'POST','header'=>implode("\r\n",$headers),'content'=>$post===null?'':http_build_query($post),'ignore_errors'=>true,'follow_location'=>0,'timeout'=>60]]));$all=$http_response_header??[];preg_match('/^HTTP\/\S+\s+(\d+)/',$all[0]??'',$m);return [(int)($m[1]??0),(string)$body,$all];
}
function global_http_fingerprint(mysqli $db):array{$result=[];foreach($db->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetch_all() as $table){$rows=$db->query('SELECT * FROM `'.$table[0].'`')->fetch_all(MYSQLI_ASSOC);$json=array_map(static fn($row)=>json_encode($row,JSON_THROW_ON_ERROR),$rows);sort($json);$result[$table[0]]=hash('sha256',implode("\n",$json));}return $result;}
$sessions=[];$requests=0;$initial=global_http_fingerprint($koneksi);unit_set_context($koneksi,0);
$studentIds=[];foreach([1,2,3] as $unit){unit_set_context($koneksi,$unit);$studentIds[$unit]=(int)$koneksi->query('SELECT s.id FROM siswa s JOIN bayar b ON b.NO_INDUK=s.NO_INDUK AND b.unit_id=s.unit_id ORDER BY b.id LIMIT 1')->fetch_row()[0];}
try{
 foreach(['super_admin','admin','kasir','bendahara'] as $role){
  unit_set_context($koneksi,0);$s=$koneksi->prepare("SELECT id FROM admin WHERE role=? AND is_active=1 AND (?='super_admin' OR unit_id=1) ORDER BY id LIMIT 1");$s->bind_param('ss',$role,$role);$s->execute();$actor=(int)$s->get_result()->fetch_row()[0];$s->close();global_http_assert($actor>0,'Role fixture missing');
  $session='globalreport'.bin2hex(random_bytes(12));$sessions[]=$session;session_id($session);session_start();$_SESSION=['admin_id'=>$actor,'admin_role'=>$role,'active_unit_id'=>1];session_write_close();
  foreach(['1','2','3','all'] as $choice){
   [$status,$html]=global_http('laporan/global.php?unit='.$choice,$session);$requests++;global_http_assert($status===200&&substr_count($html,'data-report-unit=')===4,'Catalogue scope '.$role.' '.$choice);
   global_http_assert(substr_count($html,'aria-current="true"')===1,'Selected button state');
   foreach(global_report_templates() as $template){
    $query=['template'=>$template,'unit'=>$choice,'tanggal_awal'=>'2000-01-01','tanggal_akhir'=>'2035-12-31','tahun_ajaran'=>'2026/2027','bulan_awal'=>'07','bulan_akhir'=>'06','kategori'=>$template==='penerimaan'?'semua':'spp','siswa_status'=>'all'];
    [$status,$html]=global_http('laporan/template.php?'.http_build_query($query),$session);$requests++;
    global_http_assert($status===200&&!preg_match('/Fatal error|Warning:|Gagal memuat laporan/',$html),'Template '.$role.' '.$choice.' '.$template);
    global_http_assert(str_contains($html,'name="unit" value="'.$choice.'"'),'Filter scope lost');
    foreach(in_array('--access-only',$argv,true)?[]:(in_array('--excel-only',$argv,true)?['excel']:['preview','print','excel','pdf']) as $format){
     $export=$query+['format'=>$format];
     // Binary export coverage uses a billed student; whole-report totals are reconciled by model tests.
     $export['student_id']=$studentIds[$choice==='all'?2:(int)$choice];
     if(in_array($format,['pdf','excel'],true))$export['download']='1';
     [$status,$body,$headers]=global_http('laporan/export_global.php?'.http_build_query($export),$session);$requests++;
     global_http_assert($status===200,'Export '.$role.' '.$choice.' '.$template.' '.$format);
     if($format==='pdf')global_http_assert(str_starts_with($body,'%PDF-'),'Invalid PDF');
     elseif($format==='excel'){
      global_http_assert(str_starts_with($body,"PK\x03\x04"),'Invalid XLSX');$book=test_excel_read($body);
      try{foreach($book->getAllSheets() as $sheet)global_http_assert($sheet->getCell('B1')->getValue()===unit_school_name($choice==='all'?0:(int)$choice),'Excel school identity differs from selected scope');}
      finally{$book->disconnectWorksheets();}
     }
     else global_http_assert(!preg_match('/Fatal error|Warning:/',$body),'Preview warning');
    }
   }
  }
  foreach(['unit=4','unit=0','unit=-1','unit=bogus','unit[]=all'] as $bad)foreach(['global.php','template.php?template=status&','export_global.php?template=status&format=pdf&'] as $path){$separator=str_contains($path,'?')?'':'?';global_http_assert(global_http('laporan/'.$path.$separator.$bad,$session)[0]===400,'Invalid unit accepted');$requests++;}
  // Report reads do not persist unit 0/foreign-unit context into the account session.
  session_id($session);session_start();global_http_assert($_SESSION['active_unit_id']===1,'Operational session changed');$csrf=$_SESSION['csrf_unit_switch']??'';session_write_close();
  [$status,$dashboard]=global_http('dashboard.php?unit=all',$session);$requests++;
  if($role!=='super_admin')global_http_assert(!str_contains($dashboard,'data-palette="super"'),'Dashboard privilege broadened');
  foreach(['siswa/daftar.php','pembayaran/proses.php','master_spp.php'] as $path){$status=global_http($path,$session,['aksi'=>'update','unit_id'=>2,'csrf_token'=>'invalid','id'=>$studentIds[2]])[0];$requests++;global_http_assert(in_array($status,[302,303,403,422],true),'Foreign mutation not rejected');}
  if($role!=='super_admin'){
   [$status,$letter]=global_http('laporan/template.php?template=tunggakan-siswa&unit=all',$session);$requests++;
   global_http_assert($status===200&&!str_contains($letter,'data-report-unit='),'Principal letter privilege broadened');
  }
  fwrite(STDOUT,"PASS: $role four scopes, nine templates, ".(in_array('--access-only',$argv,true)?'read access':(in_array('--excel-only',$argv,true)?'Excel readback':'four export formats')).", input guards and operational isolation\n");
 }
 foreach(['laporan/global.php?unit=all','laporan/template.php?template=status&unit=2','laporan/export_global.php?template=status&unit=all&format=excel'] as $path){global_http_assert(global_http($path,'')[0]===302,'Anonymous report access');$requests++;}
 unit_set_context($koneksi,0);$inactive=(int)$koneksi->query("SELECT id FROM admin WHERE role='kasir' AND unit_id=1 AND is_active=1 ORDER BY id LIMIT 1")->fetch_row()[0];
 $session='globalreport'.bin2hex(random_bytes(12));$sessions[]=$session;session_id($session);session_start();$_SESSION=['admin_id'=>$inactive,'admin_role'=>'kasir','active_unit_id'=>1];session_write_close();
 $koneksi->query('UPDATE admin SET is_active=0 WHERE id='.$inactive);
 try{global_http_assert(global_http('laporan/global.php?unit=all',$session)[0]===302,'Inactive account could read reports');$requests++;}
 finally{$koneksi->query('UPDATE admin SET is_active=1 WHERE id='.$inactive);}
 global_http_assert(global_http_fingerprint($koneksi)===$initial,'Report suite changed a database record');
 echo "PASS: $requests requests; database fingerprints unchanged\n";
}finally{foreach($sessions as $session){session_id($session);session_start();$_SESSION=[];session_destroy();session_write_close();}}
ob_end_flush();
