<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/transaction_authorization.php';
foreach(['edit','hapus'] as $action)foreach([0,'0',10,'',[]] as $amount){
    $blocked=false;try{transaction_authorization_payload(['id'=>42,'uang_pangkal'=>$amount],$action);}catch(RuntimeException $e){$blocked=true;}
    if(!$blocked)throw new RuntimeException('Retired cashier field was normalized into an authorization request');
}
$payload=transaction_authorization_payload(['id'=>42,'uang_psb'=>1000],'edit');
if($payload['aksi']!=='update'||$payload['id']!==42||$payload['uang_psb']!=='1000')throw new RuntimeException('Current PSB payload changed');
echo "PASS: retired Pangkal rejected before cashier authorization creation; current PSB payload retained\n";

