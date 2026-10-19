<?php
if(PHP_SAPI!=='cli')exit(1);ob_start();
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/legacy_activation.php';require_once __DIR__.'/../includes/legacy_full.php';require_once __DIR__.'/legacy_finance_support.php';
$base=rtrim((string)getenv('SPP_TEST_BASE_URL'),'/');spp_test_assert_http_clone($base,DB_NAME);
function ar_request(string $path,string $sid,array $post=null): array {
    global $base;$options=['method'=>$post===null?'GET':'POST','header'=>'Cookie: PHPSESSID='.$sid,'ignore_errors'=>true,'follow_location'=>0,'timeout'=>45];
    if($post!==null){$options['header'].="\r\nContent-Type: application/x-www-form-urlencoded";$options['content']=http_build_query($post);}
    $html=file_get_contents($base.'/'.$path,false,stream_context_create(['http'=>$options]));preg_match('/HTTP\/\S+ (\d+)/',$http_response_header[0],$m);return [(int)$m[1],$html];
}
function ar_session(array $actor,int $unit): string {
    $sid='activationreg'.bin2hex(random_bytes(10));session_id($sid);session_start();$_SESSION=['admin_id'=>$actor['id'],'admin_role'=>$actor['role'],'active_unit_id'=>$unit];session_write_close();return $sid;
}
function ar_form(string $html): array {
    $form=spp_test_form_scope($html,'confirmed');preg_match_all('/name="(id|csrf_token)" value="([^"]*)"/',$form,$matches,PREG_SET_ORDER);$out=[];foreach($matches as $m)$out[$m[1]]=$m[2];return [$form,$out];
}
$suffix=(string)random_int(1000000,8999999);$browser=[];
unit_set_context($koneksi,0);$before=[];foreach(['bayar','tabungan','transaksi_m','transaksi_k','tagihan_spp','tagihan_komite','tagihan_daftar_ulang','tagihan_tahunan_siswa'] as $t)$before[$t]=(int)$koneksi->query('SELECT COUNT(*) FROM '.$t)->fetch_row()[0];
$superActor=lf_one($koneksi,"SELECT id,role FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1");
foreach([1=>1,2=>7,3=>10] as $unit=>$grade){
    unit_set_context($koneksi,$unit);$_SESSION['active_unit_id']=$unit;
    $class=lf_one($koneksi,'SELECT id FROM master_kelas WHERE tingkat=? AND is_active=1 AND is_placeholder=0 LIMIT 1',[$grade]);
    $year=lf_one($koneksi,"SELECT t.id,t.label FROM tahun_ajaran t JOIN master_spp_tahun m ON m.tahun_ajaran_id=t.id AND m.unit_id=t.unit_id WHERE t.status IN ('draft','published') AND m.status<>'closed' ORDER BY t.label='2026/2027' DESC,t.label DESC LIMIT 1");
    lft_assert($class&&$year,'QA class/year missing');$rate=spp_current_effective_rate($koneksi,(string)$grade,0,$year['label']);
    foreach(['super_admin','admin','kasir'] as $ri=>$role){
        if($role==='super_admin')$actor=$superActor;
        else{lf_query($koneksi,'INSERT INTO admin(username,password,nama,role,unit_id,is_active) VALUES(?,?,?,?,?,1)',['activation_'.$suffix.'_'.$unit.'_'.$role,password_hash(bin2hex(random_bytes(16)),PASSWORD_DEFAULT),'QA Activation',$role,$unit]);$actor=['id'=>(int)$koneksi->insert_id,'role'=>$role];}
        lft_assert((bool)$actor,'QA actor missing');
        $nis='0'.$suffix.$ri;$name='Activation Regression '.$role.' '.$unit;
        lf_query($koneksi,"INSERT INTO siswa(NO_INDUK,NAMA,KELAS,is_active,legacy_pending) VALUES(?,?,'LEGACY',0,1)",[$nis,$name]);$student=(int)$koneksi->insert_id;lft_assert($student>3,'Fixture must distinguish student from sidebar unit ID');
        $raw=['no_induk'=>$nis,'NAMA'=>$name,'POMG'=>'15000.0000','SPP_PERBULAN'=>'530000'];
        lf_query($koneksi,"INSERT INTO legacy_student_import(student_id,unit_id,source_hash,source_row,source_name,source_class,raw_data,imported_by) VALUES(?,?,?,1,'SD171026','SOURCE',?,?)",[$student,$unit,hash('sha256',$nis.$unit),lf_json($raw),$actor['id']]);
        $sid=ar_session($actor,$unit);[$status,$html]=ar_request('siswa/aktivasi_legacy.php?id='.$student,$sid);lft_assert($status===200,'GET activation failed');[$form,$hidden]=ar_form($html);
        lft_assert((int)$hidden['id']===$student,'Sidebar replaced the student ID');lft_assert(str_contains($form,'name="komite"')&&preg_match('/name="komite"[^>]*value="15000.00"/',$form),'POMG not proposed as Komite');
        $post=$hidden+['class_id'=>$class['id'],'year_id'=>$year['id'],'spp'=>$rate['net'],'komite'=>'15000','psb'=>'100000','du'=>'40000','confirmed'=>'1'];
        lft_assert(ar_request('siswa/aktivasi_legacy.php?id='.$student,$sid,array_replace($post,['csrf_token'=>'bad']))[0]===403,'CSRF accepted');
        [$status,$bad]=ar_request('siswa/aktivasi_legacy.php?id='.$student,$sid,array_replace($post,['class_id'=>0]));lft_assert($status===409&&str_contains($bad,$name),'Validation lost the student');
        [$badForm,$badId]=ar_form($bad);lft_assert((int)$badId['id']===$student&&preg_match('/name="komite"[^>]*value="15000"/',$badForm),'Validation discarded input');
        lft_assert(ar_request('siswa/aktivasi_legacy.php?id='.$student,$sid,array_replace($post,['id'=>3]))[0]===409,'Stale mismatched form accepted');
        [$status,$preview]=ar_request('siswa/aktivasi_legacy.php?id='.$student,$sid,array_replace($post,['action'=>'preview','spp'=>0,'confirmed'=>'']));lft_assert($status===200,'No-JS tariff preview failed');
        [$previewForm,$previewId]=ar_form($preview);lft_assert(preg_match('/name="spp"[^>]*value="'.preg_quote((string)$rate['net'],'/').'"/',$previewForm),'Server preview did not resolve master rate');
        lft_assert((int)lf_one($koneksi,'SELECT legacy_pending FROM siswa WHERE id=?',[$student])['legacy_pending']===1,'Preview activated student');
        if($unit===1&&$ri===0)$browser=['student'=>$student,'session'=>$sid,'class'=>$class['id'],'year'=>$year['id']];
        lft_assert(ar_request('siswa/aktivasi_legacy.php?id='.$student,$sid,$post)[0]===302,'Activation failed');
        $saved=lf_one($koneksi,'SELECT legacy_pending,is_active,POMG FROM siswa WHERE id=?',[$student]);lft_assert(!$saved['legacy_pending']&&(int)$saved['is_active']===1&&(float)$saved['POMG']===15000.0,'Wrong student or Komite activated');
        lft_assert((int)lf_one($koneksi,'SELECT COUNT(*) n FROM siswa_tahun_ajaran WHERE no_induk=?',[$nis])['n']===1,'Missing/duplicate placement');
        lft_assert(ar_request('siswa/aktivasi_legacy.php?id='.$student,$sid,$post)[0]===409,'Activation replay accepted');
        if($unit===1){$other=ar_session($actor,2);if($role==='super_admin')lft_assert(ar_request('siswa/aktivasi_legacy.php?id='.$student,$other)[0]===404,'Cross-unit lookup accepted');}
    }
    echo "PASS: unit $unit / Super Admin, Admin, Kasir activation, source Komite, retained values, server preview, stale form and replay.\n";
}
unit_set_context($koneksi,0);foreach($before as $t=>$n)lft_assert((int)$koneksi->query('SELECT COUNT(*) FROM '.$t)->fetch_row()[0]===$n,'Activation created financial records: '.$t);
lft_assert(ar_request('siswa/aktivasi_legacy.php?id='.$browser['student'],'')[0]===302,'Anonymous access accepted');
$sa=lf_one($koneksi,"SELECT id,role FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1");lft_assert(ar_request('siswa/aktivasi_legacy.php?id='.$browser['student'],ar_session($sa,0))[0]===409,'All Unit mutation opened');
$b=lf_one($koneksi,"SELECT id,role FROM admin WHERE role='bendahara' AND unit_id=1 AND is_active=1 LIMIT 1");if($b)lft_assert(ar_request('siswa/aktivasi_legacy.php?id='.$browser['student'],ar_session($b,1))[0]===302,'Bendahara activation opened');
echo "PASS: unchanged bills, payments and savings; anonymous, cross-unit and read-only access guards.\n";
