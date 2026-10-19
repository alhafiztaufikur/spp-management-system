<?php
/** Presentation only. These labels and links never authorize an import. */
function legacy_ui_e(mixed $value): string { return htmlspecialchars((string)($value??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function legacy_ui_sidebar(): void { include __DIR__.'/sidebar.php'; }
function legacy_ui_topbar(string $title,string $breadcrumb='Pengaturan / Import Legacy'): void {
    echo '<div class="topbar"><button type="button" class="sidebar-toggle" id="btn-sidebar-toggle" onclick="toggleSidebar()" aria-label="Buka navigasi"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18"/></svg></button><div class="topbar-title"><h2>'.legacy_ui_e($title).'</h2><span class="breadcrumb">'.legacy_ui_e($breadcrumb).'</span></div><div class="clock-badge" id="liveClock">--:--:--</div></div>';
}
function legacy_ui_state(string $state): string {
    return ['queued'=>'Menunggu pemeriksaan','processing'=>'Sedang diperiksa','ready'=>'Siap ditinjau','simulate_queued'=>'Menunggu pemeriksaan','simulating'=>'Sedang diperiksa','apply_queued'=>'Menunggu penerapan','applying'=>'Sedang diterapkan','imported'=>'Selesai','failed'=>'Perlu diperbaiki','cancelled'=>'Dibatalkan','accepted'=>'Siap diimpor','held'=>'Perlu diperbaiki','non_student'=>'Bukan siswa','already_imported'=>'Sudah diimpor','archived'=>'Arsip tersimpan','archive'=>'Siap diarsipkan','reference'=>'Referensi sumber'][$state]??'Belum diperiksa';
}
function legacy_ui_icon(string $name): string {
    $paths=['upload'=>'M12 16V3m-5 5 5-5 5 5M4 15v6h16v-6','next'=>'m9 5 7 7-7 7','back'=>'m15 5-7 7 7 7','save'=>'M5 3h12l4 4v14H3V3h2m2 0v6h10V3M7 21v-8h10v8','check'=>'m5 12 4 4L19 6','file'=>'M14 2H5v20h14V7l-5-5m0 0v6h5M8 13h8M8 17h6','users'=>'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m18 0v-2a4 4 0 0 0-3-3.87M9 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8m8 0a4 4 0 0 1 0 8','clock'=>'M12 7v5l3 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0'];
    return '<svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="'.($paths[$name]??$paths['file']).'"/></svg>';
}
function legacy_ui_url(string $path,array $query): string {return $path.'?'.http_build_query(array_filter($query,static fn($v)=>$v!==null&&$v!==''));}
function legacy_ui_steps(array $steps,string $active,string $path,array $context=[]): void {
    echo '<nav aria-label="Tahapan impor"><ol class="legacy-steps">';$n=0;
    foreach($steps as $key=>$label){$target=$context;if($key==='upload')unset($target['id']);echo '<li><a href="'.legacy_ui_e(legacy_ui_url($path,$target+['step'=>$key])).'"'.($active===$key?' aria-current="step"':'').'><span>'.++$n.'</span>'.legacy_ui_e($label).'</a></li>';}
    echo '</ol></nav>';
}
function legacy_ui_time(mixed $timestamp): string {return is_numeric($timestamp)?(new DateTimeImmutable('@'.(int)$timestamp))->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('d/m/Y H:i').' WIB':'Belum tercatat';}
function legacy_ui_money(mixed $value): string {if($value===null||!is_numeric($value))return 'Belum tercatat';return 'Rp '.number_format((float)$value,fmod((float)$value,1.0)===0.0?0:2,',','.');}
function legacy_ui_amounts(string $json): void {
    $labels=['U_SPP'=>'SPP','potong_spp'=>'Potongan SPP','U_PANGKAL'=>'Pangkal','U_BANGUNAN'=>'Bangunan','U_SERAGAM'=>'Seragam','U_KEGIATAN'=>'Kegiatan','U_MAKAN'=>'Makan','U_INFAQ'=>'Infaq','U_LAIN'=>'Lain-Lain','jumlah'=>'Daftar Ulang','SALDO'=>'Saldo','MASUK'=>'Masuk','KELUAR'=>'Keluar'];
    foreach(json_decode($json,true)??[] as $column=>$amount){$label=$labels[$column]??str_replace('_',' ',$column);echo '<div><small>'.legacy_ui_e($label).'</small><br>'.legacy_ui_e(legacy_ui_money($amount)).'</div>';}
}
function legacy_ui_jobs(int $unit,?string $category=null): array {
    if(($_SESSION['admin_role']??'')!=='super_admin')$category='students';
    $sql='SELECT * FROM jobs WHERE (?=0 OR unit=?)';$args=[$unit,$unit];if($category!==null){$sql.=' AND category=?';$args[]=$category;}
    $stmt=legacy_queue()->prepare($sql.' ORDER BY created DESC LIMIT 20');$stmt->execute($args);return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function legacy_ui_job_state(array $job): string {return ($job['state']??'')==='imported'&&str_starts_with((string)($job['stage']??''),'Siap digunakan')?(string)$job['stage']:legacy_ui_state((string)($job['state']??''));}
function legacy_ui_job_url(array $job): string {if(($job['category']??'students')==='full'){$operational=is_file(legacy_root().'/'.$job['id'].'/operational-request.json');try{$operational=(lful_draft($job['id'])['settings']['mode']??'')==='operational';}catch(Throwable $ignored){}return ($operational?'legacy_operational_review.php':'legacy_full.php').'?id='.$job['id'];}return 'legacy_import.php?id='.$job['id'];}
function legacy_ui_recent(int $unit,?string $category=null): void {
    echo '<section class="dbt-card"><h2>Pekerjaan terakhir</h2>';try{$jobs=legacy_ui_jobs($unit,$category);}catch(Throwable $e){$jobs=[];}
    if(!$jobs)echo '<p>Belum ada pekerjaan.</p>';else {echo '<div class="dbt-table-scroll"><table class="dbt-table"><thead><tr><th>File & unit</th><th>Waktu</th><th>Status</th><th>Tindakan</th></tr></thead><tbody>';foreach($jobs as $job)echo '<tr><td>'.legacy_ui_e($job['name']).'<br><small>'.legacy_ui_e(unit_label((int)$job['unit'])).'</small></td><td>'.legacy_ui_e(legacy_ui_time($job['created'])).'</td><td>'.legacy_ui_e(legacy_ui_job_state($job)).'</td><td><a class="dbt-button dbt-button-outline" href="'.legacy_ui_e(legacy_ui_job_url($job)).'">'.($job['state']==='imported'?'Lihat Hasil':'Lanjutkan Pekerjaan').'</a></td></tr>';echo '</tbody></table></div>';}
    echo '</section>';
}
/** Explicit diagnostic codes: never derive actions by parsing an error message. */
function legacy_ui_problem(string $code): array {
    return ['source_schema_unsupported'=>['step'=>'upload','label'=>'Periksa struktur backup dan profil pembaca'],'additional_savings_review'=>['section'=>'savings','label'=>'Periksa tabungan tambahan'],'unit_not_empty'=>['section'=>'students','step'=>'upload','label'=>'Pilih mode impor yang sesuai','href'=>'backup_restore.php?tab=legacy'], 'source_identity'=>['section'=>'students','label'=>'Periksa identitas siswa'],'student_status'=>['section'=>'students','label'=>'Periksa status & kelas'],'student_evidence'=>['section'=>'students','label'=>'Lengkapi bukti siswa'],'class_missing'=>['section'=>'students','label'=>'Lengkapi Master Kelas','href'=>'master_kelas.php'],'spp_missing'=>['section'=>'students','label'=>'Lengkapi Master SPP','href'=>'master_spp.php'],'year_missing'=>['step'=>'review','label'=>'Pilih tahun ajaran'],'transition_invalid'=>['step'=>'review','label'=>'Periksa waktu peralihan'],'transition_evidence'=>['step'=>'review','label'=>'Lengkapi bukti peralihan'],'history_notes'=>['step'=>'review','label'=>'Periksa catatan histori'],'components_invalid'=>['section'=>'components','label'=>'Periksa komponen biaya'],'savings_invalid'=>['section'=>'savings','label'=>'Periksa saldo & jurnal'],'obligations_invalid'=>['section'=>'obligations','label'=>'Periksa sisa kewajiban'],'student_invalid'=>['section'=>'students','label'=>'Periksa rincian siswa'],'payment_invalid'=>['section'=>'components','label'=>'Periksa aturan pembayaran'],'no_students'=>['section'=>'students','label'=>'Sahkan status siswa'],'request_failed'=>['label'=>'Periksa isian lalu coba kembali']][$code]??['section'=>'students','label'=>'Periksa rincian'];
}
function legacy_ui_issue(string $identity,string $message,string $code): array {return ['identity'=>$identity,'message'=>$message,'code'=>$code,'action'=>legacy_ui_problem($code)];}
function legacy_ui_change(array $before,array $after,array $categories=[],array $classes=[]): void {
    $labels=['nis'=>'NIS','name'=>'Nama siswa','kind'=>'Status','class_id'=>'Kelas tujuan','start_period'=>'Mulai penagihan','komite'=>'Komite per bulan','discount'=>'Potongan SPP','category'=>'Komponen tujuan','savings_attested'=>'Pengesahan saldo','obligations_attested'=>'Pemeriksaan kewajiban','reason'=>'Bukti','obligations_evidence'=>'Bukti kewajiban','savings_evidence'=>'Bukti saldo'];
    $display=static function(string $key,mixed $v)use($categories,$classes):string {if($v===null||$v==='')return 'Belum diisi';if(is_bool($v))return $v?'Sudah disahkan':'Belum disahkan';if($key==='kind')return ['active'=>'Siswa aktif','alumni'=>'Alumni','non_student'=>'Bukan siswa'][$v]??(string)$v;if(in_array($key,['komite','discount'],true))return legacy_ui_money($v);if($key==='category')return str_starts_with((string)$v,'histori:')?'Histori Saja':($categories[$v]??($v==='savings_reference'?'Referensi jurnal tabungan':'Periksa komponen'));if($key==='class_id'){foreach($classes as $cl)if((int)$cl['id']===(int)$v)return class_label($cl);return 'Belum dipilih';}return is_scalar($v)?(string)$v:'Rincian diperiksa';};
    echo '<dl class="legacy-changes">';foreach($labels as $key=>$label){if(!array_key_exists($key,$after)||($before[$key]??null)===$after[$key])continue;echo '<div><dt>'.legacy_ui_e($label).'</dt><dd><span>'.legacy_ui_e($display($key,$before[$key]??null)).'</span> <span aria-label="menjadi">→</span> <strong>'.legacy_ui_e($display($key,$after[$key])).'</strong></dd></div>';}
    if(isset($after['coverage'])&&($before['coverage']??[])!==$after['coverage']){echo '<div><dt>Cakupan kewajiban</dt><dd>';foreach($after['coverage'] as $c)echo '<p>'.legacy_ui_e(($categories[$c['category']]??'Komponen').' · '.$c['period'].' · '.(['settled'=>'Sudah dibayar','legacy_debt'=>'Tunggakan','not_applicable'=>'Tidak ditagihkan'][$c['disposition']]??'Belum diperiksa').' · '.legacy_ui_money($c['amount']??0)).'</p>';echo '</dd></div>';}
    echo '</dl>';
}
