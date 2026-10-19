<?php
/** Web confirmation checks the persisted worker result, never builds the full migration plan.
 * The worker still recomputes and compares the complete digest before backup/application,
 * then checks it again while holding destination/master locks inside the transaction.
 */
function lop_confirmation_snapshot(mysqli $db,array $draft,string $digest): array {
    $sim=$draft['simulation']??[];$context=$sim['confirmation_context']??[];
    if(empty($sim['ready'])||($sim['mode']??'')!=='operational'||($sim['rules_version']??0)!==LOP_VERSION||(int)($sim['version']??0)!==(int)$draft['version']||!hash_equals((string)($sim['digest']??''),$digest)||!$context)throw new DomainException('Hasil pemeriksaan perlu diperbarui. Pilih Periksa Ulang / Simulasi; draf tetap tersimpan.');
    $job=$draft['job_info'];$source=lf_extract_manifest($job);
    if(!hash_equals((string)($context['source_hash']??''),$job['hash'])||!hash_equals((string)($context['rows_sha256']??''),$source['rows_sha256'])||!hash_equals((string)($sim['masters_digest']??''),lful_masters_digest($db)))throw new DomainException('Sumber atau master berubah. Jalankan Periksa Ulang / Simulasi.');
    $batch=lf_one($db,'SELECT id FROM legacy_full_batches WHERE id=?',[$job['id']]);
    if(!$batch)lful_empty($db,(int)$job['unit']);
    if(!hash_equals((string)($context['native_digest']??''),lop_native_digest($db))||!hash_equals((string)($context['working_digest']??''),$batch?lop_working_digest($db,$job['id']):''))throw new DomainException('Data tujuan berubah. Jalankan Periksa Ulang / Simulasi sebelum finalisasi.');
    return $sim;
}
