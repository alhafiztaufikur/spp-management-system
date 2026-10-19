<?php
require_once __DIR__.'/database_access.php';
/** Lock contention is a queue condition, not a failed migration. */
class LegacyApplicationBusy extends RuntimeException {}
function lful_wait_access(array $paths,callable $waiting,int $timeoutMs=30000) {
    $deadline=hrtime(true)+$timeoutMs*1000000;$nextNotice=0;
    do{
        $lock=database_access_lock($paths,LOCK_EX|LOCK_NB);if($lock)return $lock;
        $now=hrtime(true);if($now>=$nextNotice){$waiting();$nextNotice=$now+5000000000;}
        if($now>=$deadline)throw new LegacyApplicationBusy('Menunggu permintaan aplikasi selesai. Migrasi akan dilanjutkan otomatis.');
        usleep(100000);
    }while(true);
}
function lful_application_access(array $job,array $paths) {
    $lock=lful_wait_access($paths,static function()use($job){
        if(!empty(legacy_job($job['id'])['cancel']))throw new DomainException('Migrasi dibatalkan sebelum penerapan.');
        legacy_update($job['id'],['stage'=>'Menunggu Akses Aplikasi','message'=>'Menunggu halaman yang masih berjalan selesai. Worker melanjutkan otomatis; tidak perlu klik ulang.']);
    });
    try{legacy_update($job['id'],['stage'=>'Finalisasi Migrasi','message'=>'Akses aplikasi sudah siap. Worker memeriksa hasil, menyiapkan backup, dan menerapkan migrasi.']);return $lock;}catch(Throwable $e){database_access_release($lock);throw $e;}
}
