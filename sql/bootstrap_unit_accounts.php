<?php
/** Compatibility entrypoint: the former unit-specific seed now creates global two-role accounts. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$output=$argv[1]??'';
if(!$output)throw new RuntimeException('Usage: php sql/bootstrap_unit_accounts.php ABSOLUTE_PRIVATE_CREDENTIALS_FILE [migration guards]');
$argv[]='--credentials='.$output;
require __DIR__.'/update_workflows_20261017.php';
