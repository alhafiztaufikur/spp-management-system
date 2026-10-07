<?php
if(PHP_SAPI!=='cli'||!preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME')))exit(1);
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/parent_letter_drafts.php';
$_SESSION=['admin_id'=>41,'admin_role'=>'super_admin','active_unit_id'=>0];unit_set_context($koneksi,0);
$accounts=$koneksi->query('SELECT id,nama,username,role,unit_id,is_active FROM admin ORDER BY id')->fetch_all(MYSQLI_ASSOC);
$students=parent_letter_collect($koneksi,['mode'=>'all'])['students'];$keys=[];
foreach([1,2,3] as $unit)$keys[$unit]=array_map('unit_student_key',array_slice(array_values(array_filter($students,static fn($s)=>(int)$s['unit_id']===$unit)),0,4));
echo json_encode(['accounts'=>$accounts,'keys'=>$keys],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
