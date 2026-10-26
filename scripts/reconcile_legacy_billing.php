<?php
/** Compatibility entry point; the complete pipeline now lives in the shared runner. */
if(PHP_SAPI!=='cli')exit(1);
$options=getopt('',['database:','port:','unit:','actor:','apply','backup:','backup-proof:','output:']);
if(isset($options['backup'])&&!isset($options['backup-proof']))throw new RuntimeException('Gunakan --backup-proof=file.json dengan bukti restore, bukan hanya nama file SQL. Lihat documentation/LEGACY_MIGRATION_RUNBOOK.md.');
$args=[PHP_BINARY,__DIR__.'/legacy_migration_runner.php','--mode='.(isset($options['apply'])?'apply':'preview')];
foreach(['database','port','unit','actor','backup-proof','output'] as $key)if(isset($options[$key]))$args[]='--'.$key.'='.$options[$key];
$process=proc_open($args,[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes);if(!is_resource($process))throw new RuntimeException('Runner tidak tersedia.');exit(proc_close($process));