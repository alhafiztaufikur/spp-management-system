<?php
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/../koneksi.php';require __DIR__.'/http_form_scope.php';spp_test_assert_database_target($koneksi);
require __DIR__.'/../includes/workflow_update_schema.php';require __DIR__.'/../includes/rombel_update_schema.php';
require __DIR__.'/../includes/special_classes_schema.php';require_once __DIR__.'/../includes/workflow_readiness.php';
function wpa_assert(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
$before=$koneksi->query('SELECT * FROM admin ORDER BY id')->fetch_all(MYSQLI_ASSOC);
wpa_assert(count($before)>1,'Fresh pre-workflow clone required');
workflow_schema_apply($koneksi);workflow_accounts_preserve_existing($koneksi);rombel_schema_apply($koneksi);special_classes_schema_apply($koneksi);
wpa_assert(workflow_schema_ready($koneksi),'Workflow still blocks HTTP');
$after=$koneksi->query('SELECT * FROM admin ORDER BY id')->fetch_all(MYSQLI_ASSOC);
wpa_assert(count($before)===count($after),'Account count changed');
foreach($before as $i=>$old){$new=$after[$i];foreach($old as $key=>$value){if(in_array($key,['role','unit_id'],true))continue;wpa_assert($value===$new[$key],'Existing account field changed: '.$key);}wpa_assert($new['role']===($old['username']==='superadmin'?'super_admin':'admin')&&$new['unit_id']===null,'Role/unit policy incorrect');}
$once=$after;workflow_schema_apply($koneksi);workflow_accounts_preserve_existing($koneksi);rombel_schema_apply($koneksi);special_classes_schema_apply($koneksi);
wpa_assert($once===$koneksi->query('SELECT * FROM admin ORDER BY id')->fetch_all(MYSQLI_ASSOC),'Retry changed accounts/passwords');
$admin=(int)$koneksi->query("SELECT id FROM admin WHERE role='admin' AND is_active=1 LIMIT 1")->fetch_row()[0];
try{$koneksi->query("UPDATE admin SET role='super_admin' WHERE id=$admin");throw new RuntimeException('Second active Super Admin allowed');}catch(mysqli_sql_exception $e){wpa_assert($e->getCode()===1062,'Unexpected constraint failure');}
echo 'PASS preserved '.count($before).' account IDs/usernames/passwords/status/timestamps, one active Super Admin, schema readiness, and idempotent migration.'.PHP_EOL;
