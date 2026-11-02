<?php
/** Pure regression: a large edit form must carry its own server-rendered scope. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/payment_return.php';
function unit_active_id(): int{return 1;}
function edit_scope_check(bool $ok,string $label): void{if(!$ok)throw new RuntimeException($label);}
$template=file_get_contents(__DIR__.'/../pembayaran/edit.php');
edit_scope_check((bool)preg_match('/id="form-bayar">\s*<input type="hidden" name="_operational_unit" value="<\?= unit_active_id\(\) \?>"/',$template),'Edit scope must be rendered explicitly.');
$form='<html><form method="POST">'.str_repeat('x',(int)ini_get('pcre.backtrack_limit')+100).'</form></html>';
edit_scope_check(preg_replace_callback('/<form\b[^>]*>.*?<\/form>/is',static fn($m)=>$m[0],$form)===null&&preg_last_error()===PREG_BACKTRACK_LIMIT_ERROR,'Large form must reproduce the former injector failure.');
$html='<html><form><input name="_operational_unit" value="1"><input name="csrf_token" value="fresh"><input name="csrf_unit_switch" value="fresh-switch"><input name="request_key" value="fresh-key"><input name="id" value="12"><input name="return_context" value="fresh-context"><input name="uang_psb" value="100"><textarea name="keterangan"></textarea></form></html>';
$restored=payment_edit_restore_markup($html,['_operational_unit'=>'2','csrf_token'=>'stale','csrf_unit_switch'=>'stale-switch','request_key'=>'stale-key','id'=>'99','return_context'=>'foreign','uang_psb'=>'200','keterangan'=>'<unsafe>']);
foreach(['name="_operational_unit" value="1"','name="csrf_token" value="fresh"','name="csrf_unit_switch" value="fresh-switch"','name="request_key" value="fresh-key"','name="id" value="12"','name="return_context" value="fresh-context"','name="uang_psb" value="200"','&lt;unsafe&gt;'] as $expected)edit_scope_check(str_contains($restored,$expected),'Restored markup: '.$expected);
$token=str_repeat('a',48);$_SESSION=['admin_id'=>7,'payment_returns'=>[$token=>['actor'=>7,'scope'=>2,'unit'=>2,'payment'=>12,'expires'=>time()+60]]];
edit_scope_check(payment_return_entry($token,12)===null,'A context from another active unit remains inaccessible.');
edit_scope_check(payment_return_entry_for_unit($token,12,2)!==null,'Original-unit recovery accepts a matching server context.');
foreach([[13,2],[12,1],[12,0]] as [$id,$unit])edit_scope_check(payment_return_entry_for_unit($token,$id,$unit)===null,'Wrong payment/unit cannot recover.');
$_POST=['csrf_token'=>'old','_operational_unit'=>'2','id'=>'12','aksi'=>'update','return_context'=>$token,'uang_psb'=>'200','activity_request_key'=>str_repeat('b',32)];
payment_edit_draft_save(12,$token,2);$draft=$_SESSION['payment_returns'][$token]['draft'];
edit_scope_check($draft['uang_psb']==='200'&&$draft['activity_request_key']===str_repeat('b',32),'Preserve values and edit idempotency key.');
foreach(['csrf_token','_operational_unit','id','aksi','return_context'] as $key)edit_scope_check(!array_key_exists($key,$draft),'Do not save scope/security field '.$key);
$_SESSION['admin_id']=8;edit_scope_check(payment_return_entry_for_unit($token,12,2)===null,'Other actor cannot recover.');
$_SESSION['admin_id']=7;$_SESSION['payment_returns'][$token]['expires']=time()-1;edit_scope_check(payment_return_entry_for_unit($token,12,2)===null,'Expired return context cannot recover.');
echo "PASS: large-form scope, protected draft fields, original-unit/actor/payment/expiry recovery and idempotency key.\n";
