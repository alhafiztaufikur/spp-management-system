<?php

require_once __DIR__ . '/daftar_ulang.php';
require_once __DIR__ . '/kelas.php';
require_once __DIR__ . '/tagihan_tahunan.php';
require_once __DIR__ . '/tagihan_sekali.php';
require_once __DIR__ . '/spp_billing.php';
require_once __DIR__.'/report_multiple.php';
require_once __DIR__.'/savings_operator.php';
require_once __DIR__.'/global_report_scope.php';
require_once __DIR__.'/legacy_full_read.php';
require_once __DIR__.'/global_report_evidence.php';

/** Read-only queries use the verified unit projection so composite indexes remain usable. */
function report_scoped_query(mysqli $db,string $sql,int $mode=MYSQLI_STORE_RESULT): mysqli_result|bool {return $db->query(hp_sql($db,$sql),$mode);}

function report_e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function report_money($value): string { if($value===null)return '—';$v=round((float)$value,2);return 'Rp '.number_format($v,$v===floor($v)?0:2,',','.'); }
function report_months(): array { return ['01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April','05'=>'Mei','06'=>'Juni','07'=>'Juli','08'=>'Agustus','09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember']; }
function report_month_code($value): string {
    $names = array_flip(report_months());
    if (isset($names[$value])) return $names[$value];
    $month = (int)$value;
    return $month >= 1 && $month <= 12 ? str_pad((string)$month, 2, '0', STR_PAD_LEFT) : date('m');
}
function report_date_label(string $date):string{return spp_date_label($date);}
function report_letter_today():string{return (new DateTimeImmutable('now',new DateTimeZone('Asia/Jakarta')))->format('Y-m-d');}
function report_date_range_label(string $start,string $end):string{return $start===$end?report_date_label($start):report_date_label($start).' – '.report_date_label($end);}
function report_sql_month_expr(string $column): string {
    return "CASE LOWER($column) WHEN 'januari' THEN 1 WHEN 'februari' THEN 2 WHEN 'maret' THEN 3 WHEN 'april' THEN 4 WHEN 'mei' THEN 5 WHEN 'juni' THEN 6 WHEN 'juli' THEN 7 WHEN 'agustus' THEN 8 WHEN 'september' THEN 9 WHEN 'oktober' THEN 10 WHEN 'november' THEN 11 WHEN 'desember' THEN 12 ELSE CAST($column AS UNSIGNED) END";
}
function report_sql_ci(string $expression): string {
    return "CONVERT($expression USING utf8mb4) COLLATE utf8mb4_general_ci";
}
function report_sql_ci_eq(string $left, string $right): string {
    return report_sql_ci($left) . '=' . report_sql_ci($right);
}
function report_registry(): array {
    return [
        'status' => ['label'=>'Laporan Status Pembayaran','description'=>'Membantu admin melihat siapa yang sudah lunas, masih cicilan, atau belum membayar pada kewajiban yang dipilih.','icon'=>'OK','orientation'=>'portrait'],
        'penerimaan' => ['label'=>'Rekap Penerimaan Harian','description'=>'Menampilkan nominal pembayaran yang diterima kasir per siswa dan per komponen pada tanggal yang dipilih.','icon'=>'RP','orientation'=>'landscape'],
        'spp-tahunan' => ['label'=>'Rekap SPP Tahun Ajaran per Kelas','description'=>'Memudahkan pengecekan SPP Juli sampai Juni untuk melihat bulan yang sudah lunas dan yang masih tertunggak.','icon'=>'SPP','orientation'=>'landscape'],
        'per-item' => ['label'=>'Rekap Pembayaran per Item','description'=>'Menampilkan kondisi satu jenis pembayaran agar admin cepat mengecek tagihan, pembayaran, dan sisa per siswa.','icon'=>'ITEM','orientation'=>'landscape'],
        'tabungan-siswa' => ['label'=>'Rekap Transaksi Tabungan Siswa','description'=>'Daftar transaksi tabungan masuk dan keluar sesuai tanggal, siswa, rombel, dan kasir yang dipilih.','icon'=>'TAB','orientation'=>'portrait'],
        'saldo-tabungan' => ['label'=>'Rekap Saldo Tabungan','description'=>'Menampilkan saldo tabungan terkini setiap siswa agar admin dapat mengecek saldo tanpa membuka riwayat transaksi.','icon'=>'SAL','orientation'=>'portrait'],
        'riwayat-tagihan' => ['label'=>'Riwayat Tagihan Siswa','description'=>'Ringkasan nominal tagihan per komponen untuk setiap siswa pada tanggal pembuatan yang dipilih.','icon'=>'TAG','orientation'=>'landscape'],
        'tunggakan-siswa' => ['label'=>'Cetak Surat ke Kepala Sekolah','description'=>'Surat resmi berisi total tunggakan per rombel atau seluruh kelas untuk kepala sekolah.','icon'=>'UTG','orientation'=>'portrait'],
        'setoran' => ['label'=>'Rekap Setoran Kas Harian','description'=>'Ringkasan penerimaan pembayaran sekolah berdasarkan komponen dan metode pembayaran untuk penutupan kas harian.','icon'=>'KAS','orientation'=>'portrait'],
        'kas-tabungan' => ['label'=>'Rekap Kas Tabungan Harian','description'=>'Ringkasan tabungan masuk, tabungan keluar, dan mutasi bersih untuk pengecekan kas tabungan harian.','icon'=>'KT','orientation'=>'portrait'],
    ];
}
function report_date_value($value, string $fallback): string {
    $value = trim((string)$value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : $fallback;
}
function report_billing_date_range(array $source): array {
    $validDate=static function($value):string {
        $value=trim((string)$value);
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        return $date&&$date->format('Y-m-d')===$value?$value:'';
    };
    $start=$validDate($source['tanggal_awal']??'');
    $end=$validDate($source['tanggal_akhir']??'');
    if($start===''&&$end!=='')$start=$end;
    if($end===''&&$start!=='')$end=$start;
    if($start!==''&&$start>$end)[$start,$end]=[$end,$start];
    return [$start,$end];
}
function report_billing_date_matches(string $createdAt,array $filters):bool {
    if(!($filters['filter_tanggal_tagihan']??false)||($filters['tanggal_awal']??'')==='')return true;
    $date=substr($createdAt,0,10);
    return $date!==''&&$date>=$filters['tanggal_awal']&&$date<=$filters['tanggal_akhir'];
}
function report_filters(mysqli $db, array $source): array {
    $multiple=report_multiple_normalize($db,$source);
    $today = date('Y-m-d');
    if(($source['template']??'')==='riwayat-tagihan')[$start,$end]=report_billing_date_range($source);
    else {
        $start = report_date_value($source['tanggal_awal'] ?? '', $today);
        $end = report_date_value($source['tanggal_akhir'] ?? '', $start);
        if ($start > $end) [$start,$end]=[$end,$start];
    }
    $allowedStatus = ['','tidak_ditagihkan','belum_bayar','cicilan','lunas','rekonsiliasi','ada_pembayaran','tunggakan','dibatalkan'];
    $allowedMethods = ['','Tunai','VA','Qris','Legacy'];
    $allowedModes = ['harian','bulanan','buku','transaksi'];
    $allowedStudentStatus = ['active','archived','all'];
    $allowedMutasi = ['','masuk','keluar'];
    $allowedSaldoStatus = ['','ada_saldo','saldo_nol'];
    $requestedStudentStatus = (string)($source['siswa_status'] ?? 'active');
    $requestedPerPage = (int)($source['per_page'] ?? 25);
    $perPage = in_array($requestedPerPage, [25,50,100], true) ? $requestedPerPage : 25;
    $currentYear = du_current_academic_year();
    $academicYear = trim((string)($source['tahun_ajaran'] ?? $currentYear));
    if (!preg_match('/^\d{4}\/\d{4}$/', $academicYear)) $academicYear = $currentYear;
    $requestedOperator = trim((string)($source['operator'] ?? ''));
    $operator = isset($multiple['operator'])?'':(($source['template']??'')==='tabungan-siswa'?(isset(savings_operator_options($db,false)[$requestedOperator])?$requestedOperator:''):report_operator_filter_value($db, $requestedOperator));
    $selectedId=max(0,(int)($source['student_id']??0));$selected=null;
    if($selectedId){$stmt=$db->prepare('SELECT id,unit_id,NO_INDUK FROM siswa WHERE id=?');$stmt->bind_param('i',$selectedId);$stmt->execute();$selected=$stmt->get_result()->fetch_assoc();$stmt->close();}
    return [
        '_template'=>(string)($source['template']??''),'_multi'=>$multiple,'include_undated'=>($source['include_undated']??'')==='1','owner_id'=>max(0,(int)($source['owner_id']??0)),'all_periods'=>($source['all_periods']??'')==='1','student_id'=>$selectedId,'student_unit'=>(int)($selected['unit_id']??0),'student_nis'=>$selected['NO_INDUK']??null,
        'tanggal_awal'=>$start,'tanggal_akhir'=>$end,
        'filter_tanggal_tagihan'=>($source['template']??'')==='riwayat-tagihan',
        'tahun_ajaran'=>$academicYear,
        'bulan_awal'=>report_month_code($source['bulan_awal'] ?? date('m')),
        'bulan_akhir'=>report_month_code($source['bulan_akhir'] ?? ($source['bulan_awal'] ?? date('m'))),
        'tahun'=>(int)($source['tahun'] ?? date('Y')),
        'tahun_awal'=>(int)($source['tahun_awal'] ?? ($source['tahun'] ?? date('Y'))),
        'tahun_akhir'=>(int)($source['tahun_akhir'] ?? ($source['tahun_awal'] ?? ($source['tahun'] ?? date('Y')))),
        'kelas'=>report_class_filter_value($source['kelas'] ?? ''),
        'kategori'=>trim((string)($source['kategori'] ?? 'spp')),
        'komponen_tagihan'=>mb_substr(trim((string)($source['komponen_tagihan'] ?? '')),0,100),
        'status'=>in_array((string)($source['status'] ?? ''),$allowedStatus,true)?(string)($source['status']??''):'',
        'siswa_status'=>in_array($requestedStudentStatus,$allowedStudentStatus,true)?$requestedStudentStatus:'active',
        'operator'=>$operator,
        'metode'=>in_array((string)($source['metode'] ?? ''),$allowedMethods,true)?(string)($source['metode']??''):'',
        'mutasi'=>in_array((string)($source['mutasi'] ?? ''),$allowedMutasi,true)?(string)($source['mutasi']??''):'',
        'saldo_status'=>in_array((string)($source['saldo_status'] ?? ''),$allowedSaldoStatus,true)?(string)($source['saldo_status']??''):'',
        'q'=>mb_substr(trim((string)($source['q'] ?? '')),0,100),
        'mode'=>in_array((string)($source['mode'] ?? 'harian'),$allowedModes,true)?(string)($source['mode']??'harian'):'harian',
        'page'=>max(1,(int)($source['page']??1)),'per_page'=>$perPage,
    ];
}
function report_classes(mysqli $db): array { return class_all($db, true); }
function report_years(mysqli $db): array {
    $years=array_column(report_scoped_query($db,"SELECT label,CASE WHEN COUNT(DISTINCT status)=1 THEN MIN(status) ELSE 'beragam antarunit' END status FROM tahun_ajaran GROUP BY label")->fetch_all(MYSQLI_ASSOC),null,'label');
    foreach(report_legacy_years($db) as $year=>$_)$years[$year]??=['label'=>$year,'status'=>'histori'];
    krsort($years,SORT_STRING);return array_values($years);
}
function report_class_filter_value($value): string {
    $value = trim((string)$value);
    if ($value === '' || $value === '0') return '';
    if (preg_match('/^tingkat:(\d{1,2})$/', $value, $match) && (int)$match[1] >= 1 && (int)$match[1] <= 12) return 'tingkat:' . $match[1];
    if (preg_match('/^rombel:(\d+)$/', $value, $match)) return 'rombel:' . (int)$match[1];
    if (ctype_digit($value) && (int)$value > 0) return 'rombel:' . (int)$value;
    return '';
}
function report_class_filter_level(array $filters): int {
    return preg_match('/^tingkat:(\d{1,2})$/', (string)($filters['kelas'] ?? ''), $match) && (int)$match[1] >= 1 && (int)$match[1] <= 12 ? (int)$match[1] : 0;
}
function report_class_filter_rombel_id(array $filters): int {
    return preg_match('/^rombel:(\d+)$/', (string)($filters['kelas'] ?? ''), $match) ? (int)$match[1] : 0;
}
function report_class_where(array $filters, string $alias='sta', string $levelColumn='kelas', string $rombelColumn='master_kelas_id'): string {
    if(isset($filters['_multi']['kelas'])){
        if(filter_is_all($filters['_multi']['kelas']))return '';
        $clauses=[];foreach($filters['_multi']['kelas'] as $value){$one=$filters;unset($one['_multi']['kelas']);$one['kelas']=$value;$clause=report_class_where($one,$alias,$levelColumn,$rombelColumn);if($clause!=='')$clauses[]=substr($clause,5);}
        return $clauses?' AND ('.implode(' OR ',$clauses).')':'';
    }
    $rombelId = report_class_filter_rombel_id($filters);
    if ($rombelId > 0) return " AND {$alias}.{$rombelColumn}=" . $rombelId;
    $level = report_class_filter_level($filters);
    return $level > 0 ? " AND CAST({$alias}.{$levelColumn} AS UNSIGNED)=" . $level : '';
}
function report_class_matches_row(array $filters, array $row, string $rombelKey='master_kelas_id', string $levelKey='tingkat'): bool {
    if(isset($filters['_multi']['kelas'])){if(filter_is_all($filters['_multi']['kelas']))return true;foreach($filters['_multi']['kelas'] as $value){$one=$filters;unset($one['_multi']['kelas']);$one['kelas']=$value;if(report_class_matches_row($one,$row,$rombelKey,$levelKey))return true;}return false;}
    $rombelId = report_class_filter_rombel_id($filters);
    if ($rombelId > 0) return (int)($row[$rombelKey] ?? 0) === $rombelId;
    $level = report_class_filter_level($filters);
    if ($level <= 0) return true;
    $rowLevel = (string)($row[$levelKey] ?? $row['KELAS'] ?? $row['kelas'] ?? '');
    if (preg_match('/^(1[0-2]|[1-9])(?:[A-Z])?$/Di', trim($rowLevel), $match)) return (int)$match[1] === $level;
    return false;
}
function report_allowed_operator_roles(): array {
    return operational_role() ? ['super_admin','admin','kasir'] : ['kasir'];
}
function report_operator_filter_label(): string {
    return operational_role() ? 'Semua petugas' : 'Semua kasir';
}
function report_operators(mysqli $db, string $role = ''): array {
    $unitWhere = ($GLOBALS['app_unit_id'] ?? 1) === 0 ? '' : " AND (unit_id=" . report_query_unit_id() . " OR role IN ('super_admin','admin','kasir'))";
    if ($role !== '') {
        $stmt = $db->prepare('SELECT id,username,nama,role FROM admin WHERE role=? AND is_active=1' . $unitWhere . ' ORDER BY nama');
        $stmt->bind_param('s', $role);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
    return report_scoped_query($db,'SELECT id,username,nama,role FROM admin WHERE is_active=1' . $unitWhere . ' ORDER BY nama')->fetch_all(MYSQLI_ASSOC);
}
function report_operator_options(mysqli $db): array {
    $roles = report_allowed_operator_roles();
    $placeholders = implode(',', array_fill(0, count($roles), '?'));
    $unitWhere = ($GLOBALS['app_unit_id'] ?? 1) === 0 ? '' : " AND (unit_id=" . report_query_unit_id() . " OR role IN ('super_admin','admin','kasir'))";
    $stmt = $db->prepare("SELECT id,username,nama,role FROM admin WHERE role IN ($placeholders) AND is_active=1 {$unitWhere} ORDER BY FIELD(role,'admin','kasir'), nama");
    $types = str_repeat('s', count($roles));
    $stmt->bind_param($types, ...$roles);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach(lful_operator_options($db) as $id=>$label)$rows[]=['id'=>$id,'username'=>$id,'nama'=>$label,'role'=>'legacy'];
    return $rows;
}
function report_operator_filter_value(mysqli $db, string $operator): string {
    $operator = trim($operator);
    if ($operator === '') return '';
    if(str_starts_with($operator,'legacy:'))return isset(lful_operator_options($db)[$operator])?$operator:'';
    $roles = report_allowed_operator_roles();
    $placeholders = implode(',', array_fill(0, count($roles), '?'));
    $unitWhere = ($GLOBALS['app_unit_id'] ?? 1) === 0 ? '' : " AND (unit_id=" . report_query_unit_id() . " OR role IN ('super_admin','admin','kasir'))";
    $stmt = $db->prepare("SELECT id FROM admin WHERE role IN ($placeholders) AND is_active=1 {$unitWhere} AND (CAST(id AS CHAR)=? OR username=? OR nama=?) LIMIT 1");
    $types = str_repeat('s', count($roles)) . 'sss';
    $params = array_merge($roles, [$operator, $operator, $operator]);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (string)$row['id'] : '';
}
function report_categories(mysqli $db): array {
    $items = [
        'spp'=>'SPP','komite'=>'Komite','daftar_ulang'=>'Daftar Ulang',
        'psb'=>'Uang PSB',
    ];
    foreach(report_scoped_query($db,'SELECT id,nama FROM master_biaya_lain ORDER BY nama')->fetch_all(MYSQLI_ASSOC) as $row) {
        $items['biaya_lain:'.$row['id']] = 'Biaya Lain '.$row['nama'];
    }
    // Only labels are reused within this request. The audit revision invalidates
    // them after a correction; master values and all monetary models stay live.
    static $history=[],$allHistory=[];$scope=(int)($GLOBALS['app_unit_id']??unit_active_id());
    $ready=lop_ready($db);$revision=$ready?(int)hp_one($db,'SELECT COALESCE(MAX(id),0) revision FROM legacy_op_audit')['revision']:0;
    $key=$db->thread_id.':'.$scope.':'.$revision;
    if(!isset($history[$key])){
        $labels=[];$byUnit=[];
        if($scope>0&&isset($allHistory[$db->thread_id])&&$revision<=$allHistory[$db->thread_id]['revision']){
            $labels=$allHistory[$db->thread_id]['units'][$scope]??[];
        }else{
            if(function_exists('lful_read_ready')&&lful_read_ready($db))foreach(hp_rows($db,"SELECT DISTINCT i.unit_id,i.source_column,i.category,i.label FROM legacy_full_items i JOIN legacy_full_payments p ON p.id=i.payment_id AND p.unit_id=i.unit_id JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id WHERE b.state='completed' AND i.category LIKE 'histori:%' ORDER BY i.category") as $r)$labels[$r['category']]=$byUnit[$r['unit_id']][$r['category']]=lcs_component_view($r)['display_label'].' (Legacy)';
            if($ready){
                $cursor=report_scoped_query($db,hp_sql($db,"SELECT p.unit_id,p.items FROM legacy_op_payments p JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id WHERE b.state='completed' AND p.cancelled=0"),MYSQLI_USE_RESULT);
                try{while($row=$cursor->fetch_assoc())foreach(json_decode($row['items'],true) as $item)if(str_starts_with($item['category'],'histori:'))$labels[$item['category']]=$byUnit[$row['unit_id']][$item['category']]=lcs_component_view($item)['display_label'].' (Legacy)';}
                finally{$cursor->free();}
            }
            if($scope===0)$allHistory[$db->thread_id]=['revision'=>$revision,'units'=>$byUnit];
        }
        $history[$key]=$labels;
    }
    return array_replace($items,$history[$key]);
}
function report_status_name(float $bill, float $paid): string {
    if ($bill <= 0 && $paid <= 0) return 'Tidak Ditagihkan';
    if ($bill <= 0 && $paid > 0) return 'Perlu Rekonsiliasi';
    if ($paid <= .001) return 'Belum Bayar';
    if ($paid + .001 < $bill) return 'Cicilan';
    if ($paid > $bill + .001) return 'Perlu Rekonsiliasi';
    return 'Lunas';
}
function report_status_key(string $status): string { if(str_contains($status,'Legacy')){if(str_starts_with($status,'Lunas')||str_starts_with($status,'Sudah dibayar'))return 'lunas';if(str_starts_with($status,'Cicilan'))return 'cicilan';if(str_starts_with($status,'Tunggakan Legacy')&&!str_contains($status,'pemeriksaan'))return 'belum_bayar';if(str_starts_with($status,'Tidak ditagihkan'))return 'tidak_ditagihkan';if(str_starts_with($status,'Dibatalkan'))return 'dibatalkan';return 'rekonsiliasi';}return ['Tidak Ditagihkan'=>'tidak_ditagihkan','Belum Bayar'=>'belum_bayar','Cicilan'=>'cicilan','Lunas'=>'lunas','Tercakup Uang PSB'=>'lunas','Potongan Penuh'=>'lunas','Perlu Rekonsiliasi'=>'rekonsiliasi','Dibatalkan'=>'dibatalkan','Ada Pembayaran'=>'ada_pembayaran','Ada Pembayaran di Luar Periode'=>'ada_pembayaran','Belum Diketahui'=>'rekonsiliasi'][$status] ?? ''; }
function report_row_search_text(array $row): string {
    return implode(' ', array_filter([
        $row['nis'] ?? $row['no_induk'] ?? $row['NO_INDUK'] ?? '',
        $row['diknas'] ?? $row['nis_diknas'] ?? $row['NO_induk_diknas'] ?? '',
        $row['nama'] ?? $row['NAMA'] ?? '',
        $row['kelas'] ?? $row['kelas_label'] ?? '',
    ], static fn($value) => (string)$value !== ''));
}
function report_row_matches_query(array $row, string $query): bool {
    return $query === '' || stripos(report_row_search_text($row), $query) !== false;
}
function report_row_matches_filters(array $row,array $filters):bool {
    if(($filters['student_id']??0)>0 && (string)($row['nis']??$row['no_induk']??$row['NO_INDUK']??'')!==(string)($filters['student_nis']??''))return false;
    return report_row_matches_query($row,(string)($filters['q']??''));
}
function report_operator_join(string $userExpression, string $alias = 'op'): string {
    $user = report_sql_ci("CAST($userExpression AS CHAR)");
    return " LEFT JOIN admin $alias ON " . report_sql_ci("CAST($alias.id AS CHAR)") . "=$user OR " . report_sql_ci("$alias.username") . "=$user OR " . report_sql_ci("$alias.nama") . "=$user";
}
function report_operator_where(string $userExpression, string $alias = 'op'): string {
    return '(' . report_sql_ci("CAST($userExpression AS CHAR)") . '=? OR ' . report_sql_ci("CAST($alias.id AS CHAR)") . '=? OR ' . report_sql_ci("$alias.username") . '=? OR ' . report_sql_ci("$alias.nama") . '=?)';
}
function report_operator_params(string $operator): array {
    return [$operator, $operator, $operator, $operator];
}
function report_row_has_payment(array $row): bool {
    foreach(['terbayar','total_bayar','sudah_dibayar','paid','pembayaran_opsional'] as $field)if(isset($row[$field])&&(float)$row[$field]>.001)return true;
    return false;
}
function report_filter_rows(array $rows, array $filters): array {
    if(($filters['status']??null)===''&&(!isset($filters['_multi']['status'])||filter_is_all($filters['_multi']['status']))
        &&!(($filters['student_id']??0)>0)&&(string)($filters['q']??'')==='')return array_values($rows);
    return array_values(array_filter($rows, static function($row) use($filters) {
        $status = (string)($row['_status'] ?? ''); $key=report_status_key($status);
        $hasPayment=report_row_has_payment($row);
        if(isset($filters['_multi']['status'])&&!filter_is_all($filters['_multi']['status'])){
            $found=false;foreach($filters['_multi']['status'] as $wanted){if($wanted===$key||($wanted==='ada_pembayaran'&&$hasPayment)||($wanted==='tunggakan'&&in_array($key,['belum_bayar','cicilan'],true)))$found=true;}if(!$found)return false;
        }
        if ($filters['status']==='ada_pembayaran' && !$hasPayment) return false;
        if ($filters['status']==='tunggakan' && !in_array($key,['belum_bayar','cicilan'],true)) return false;
        if ($filters['status']!=='' && !in_array($filters['status'],['ada_pembayaran','tunggakan'],true) && $filters['status']!==$key) return false;
        if (!report_row_matches_filters($row,$filters)) return false;
        return true;
    }));
}
function report_billing_status(float $bill, float $paid, string $billStatus='open'): string {
    return $billStatus === 'cancelled' ? 'Dibatalkan' : report_status_name($bill, $paid);
}
function report_period_from_academic(string $academicYear, string $month): array {
    [$start,$end]=array_map('intval',explode('/',$academicYear)); $m=(int)$month;
    return [$month, (string)($m>=7?$start:$end)];
}
function report_student_status_where(array $filters,string $placement='sta',string $student='s'):string{
    return (($filters['student_id']??0)>0?' AND '.$student.'.id='.(int)$filters['student_id']:'')." AND $student.legacy_pending=0".match($filters['siswa_status']){'all'=>'','archived'=>" AND $student.is_active=0",default=>" AND $student.is_active=1"};
}
function report_paginate(array $rows, array $filters, bool $export): array {
    $total=count($rows); $per=$export?max(1,$total):$filters['per_page']; $pages=max(1,(int)ceil($total/$per)); $page=$export?1:min($filters['page'],$pages);
    return ['rows'=>$export?$rows:array_slice($rows,($page-1)*$per,$per),'total'=>$total,'page'=>$page,'pages'=>$pages,'per_page'=>$per];
}

function report_annual_fee_status_rows(mysqli $db, array $f, string $component): array {
    annual_fee_component($component);
    $sql = "SELECT sta.no_induk, s.NO_induk_diknas nis_diknas, s.NAMA nama,
        sta.kelas_rombel_snapshot kelas, COALESCE(t.nominal_tagihan, 0) tagihan,
        COALESCE(SUM(d.jumlah), 0) terbayar
      FROM siswa_tahun_ajaran sta
      JOIN tahun_ajaran ta ON ta.id = sta.tahun_ajaran_id
      JOIN siswa s ON s.NO_INDUK = sta.no_induk AND s.unit_id=sta.unit_id
      LEFT JOIN tagihan_tahunan_siswa t ON t.tahun_ajaran_id = ta.id
        AND t.no_induk = sta.no_induk AND t.unit_id=sta.unit_id AND t.komponen = ? AND t.status = 'open'
      LEFT JOIN bayar_tahunan_siswa d ON d.tagihan_tahunan_id = t.id
      WHERE ta.label = ?" . report_student_status_where($f) . report_class_where($f, 'sta') . "
      GROUP BY sta.id, s.NO_induk_diknas, s.NAMA, sta.kelas_rombel_snapshot, t.nominal_tagihan
      ORDER BY sta.kelas_rombel_snapshot, s.NAMA";
    $stmt = $db->prepare(hp_sql($db,$sql));
    $stmt->bind_param('ss', $component, $f['tahun_ajaran']);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function report_status_data(mysqli $db, array $f): array {
    if(report_item_is_monthly_category($f['kategori']))return report_monthly_status($db,$f);
    if(in_array($f['kategori'],['psb','daftar_ulang'],true))return report_annual_evidence($db,$f);
    if(str_starts_with($f['kategori'],'histori:'))return report_history_component_status($db,$f);
    $category=$f['kategori']; $rows=[]; $period=''; $label=report_categories($db)[$category]??'Pembayaran';
    if (array_key_exists($category, annual_fee_components())) {
        $data = report_annual_fee_status_rows($db, $f, $category);
        $period = $f['tahun_ajaran'];
    } elseif (str_starts_with($category,'biaya_lain:')) {
        $masterId=(int)substr($category,11);
        $sql="SELECT s.id student_id,s.unit_id,s.NO_INDUK no_induk,s.NO_induk_diknas nis_diknas,s.NAMA nama,COALESCE(t.kelas_rombel_snapshot,CASE WHEN mk.tingkat=0 AND UPPER(mk.kode_rombel) LIKE 'ALUMNI%' THEN mk.kode_rombel WHEN mk.is_placeholder=1 THEN CONCAT('Kelas ',mk.tingkat,' (Belum Ditentukan)') ELSE CONCAT(mk.tingkat,UPPER(mk.kode_rombel)) END) kelas,
          t.id bill_id,COALESCE(t.master_kelas_id,s.master_kelas_id) master_kelas_id,COALESCE(t.nominal_tagihan,0) tagihan,t.status bill_status,COALESCE(SUM(d.nominal_snapshot),0) terbayar
          FROM siswa s LEFT JOIN master_kelas mk ON mk.id=s.master_kelas_id LEFT JOIN tagihan_biaya_lain t ON t.no_induk=s.NO_INDUK AND t.unit_id=s.unit_id AND t.master_biaya_lain_id=?
          LEFT JOIN bayar_biaya_lain d ON d.tagihan_biaya_lain_id=t.id WHERE 1=1".($f['siswa_status']==='active'?' AND s.is_active=1':($f['siswa_status']==='archived'?' AND s.is_active=0':''))." GROUP BY s.id,s.NO_INDUK,s.NO_induk_diknas,s.NAMA,s.master_kelas_id,t.id,t.master_kelas_id,t.kelas_rombel_snapshot,t.nominal_tagihan,t.status,mk.tingkat,mk.kode_rombel,mk.is_placeholder ORDER BY kelas,s.NAMA";
        $stmt=$db->prepare(hp_sql($db,$sql));$stmt->bind_param('i',$masterId);$stmt->execute();$data=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();$period='Satu kali per tagihan';
    } else $data = [];
    foreach($data??[] as $item){
        $bill=(float)$item['tagihan'];$paid=(float)$item['terbayar'];$status=report_billing_status($bill,$paid,(string)($item['bill_status']??'open'));
        $row=['nis'=>$item['no_induk'],'student_id'=>(int)($item['student_id']??0),'unit_id'=>(int)($item['unit_id']??report_query_unit_id()),'bill_id'=>(int)($item['bill_id']??0),'master_kelas_id'=>(int)($item['master_kelas_id']??0),'tingkat'=>(string)(int)($item['kelas']??0),'nis_diknas'=>$item['nis_diknas']??'','nama'=>$item['nama'],'kelas'=>$item['kelas']??'-','periode'=>$period,'tagihan'=>$bill,'terbayar'=>$paid,'sisa'=>$status==='Dibatalkan'?0.0:max(0,$bill-$paid),'status'=>$status,'_status'=>$status];
        if(!str_starts_with($category,'biaya_lain:')||report_class_matches_row($f,$row))$rows[]=$row;
    }
    if(str_starts_with($category,'biaya_lain:'))$rows=report_fee_status_rows($db,$f,$rows);
    $rows=report_filter_rows($rows,$f);
    $columns=[['nis','NIS'],['nama','Nama Siswa'],['kelas','Kelas'],['periode','Periode'],['tagihan','Tagihan','money_optional'],['terbayar','Terbayar','money'],['sisa','Sisa','money_optional'],['status','Status','status']];if(str_starts_with($category,'biaya_lain:'))array_splice($columns,6,0,[['pembayaran_opsional','Pembayaran Tanpa Tagihan','money']]);
    return ['title'=>$label,'subtitle'=>'Status kewajiban · '.$period,'columns'=>$columns,'rows'=>$rows];
}

function report_payment_components(mysqli $db, array $f,bool $cashProjection=false): array {
    $start=$f['tanggal_awal'].' 00:00:00';$end=date('Y-m-d H:i:s',strtotime($f['tanggal_akhir'].' +1 day'));
    $where=[!empty($f['include_undated'])?'((b.TGL_BYR>=? AND b.TGL_BYR<?) OR b.TGL_BYR IS NULL)':'(b.TGL_BYR>=? AND b.TGL_BYR<?)'];$types='ss';$params=[$start,$end];
    $paymentClassWhere = report_class_where($f,'b','KELAS');
    if($paymentClassWhere!=='')$where[]=substr($paymentClassWhere,5);
    $operatorJoin='';
    if(isset($f['_multi']['operator'])&&!filter_is_all($f['_multi']['operator']))$where[]=substr(report_multi_operator_sql($f,'b.user_id'),5);
    if($f['operator']!==''){$operatorJoin=report_operator_join('b.user_id','op');$where[]=report_operator_where('b.user_id','op');$types.='ssss';$params=array_merge($params,report_operator_params($f['operator']));}
    if(isset($f['_multi']['metode'])){$where[]=substr(filter_sql_values($f['_multi']['metode'],'b.sistem_pembayaran')?:' AND 1=1',5);}
    if($f['metode']!==''){$where[]='b.sistem_pembayaran=?';$types.='s';$params[]=$f['metode'];}
    $stmt=$db->prepare('SELECT b.*,s.id student_id,s.is_active student_active,s.NAMA,s.NO_induk_diknas FROM bayar b LEFT JOIN siswa s ON s.NO_INDUK=b.NO_INDUK AND s.unit_id=b.unit_id'.$operatorJoin.' WHERE '.implode(' AND ',$where).' ORDER BY b.TGL_BYR,b.id');
    $stmt->bind_param($types,...$params);$stmt->execute();$payments=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
    if(!$payments)return lful_report_components($db,$f,$cashProjection);
    $ids=array_column($payments,'id');$idList=implode(',',array_map('intval',$ids));$extra=[];$du=[];
    foreach(report_scoped_query($db,"SELECT bayar_id,master_biaya_lain_id,tagihan_biaya_lain_id,nama_biaya_snapshot,nominal_snapshot FROM bayar_biaya_lain WHERE bayar_id IN ($idList)")->fetch_all(MYSQLI_ASSOC) as $d)$extra[$d['bayar_id']][]=$d;
    foreach(report_scoped_query($db,"SELECT bayar_id,jumlah FROM bayar_du WHERE bayar_id IN ($idList)")->fetch_all(MYSQLI_ASSOC) as $d)$du[$d['bayar_id']]=(float)$d['jumlah'];
    $map=['U_PSB'=>'Uang PSB','U_SPP'=>'SPP','U_KOMITE'=>'Komite'];$rows=[];
    foreach($payments as $p){$nativePeriod=trim((string)($p['th_ajaran']??''))?:trim((string)($p['BULAN']??'')).' '.trim((string)($p['TAHUN']??''));if(empty($p['TGL_BYR'])&&!report_undated_matches($f,$nativePeriod))continue;$base=['id'=>(int)$p['id'],'tanggal'=>$p['TGL_BYR'],'nomor'=>'TRX-'.str_pad((string)$p['id'],6,'0',STR_PAD_LEFT),'nis'=>$p['NO_INDUK'],'nis_diknas'=>$p['NO_induk_diknas']??'','nama'=>$p['NAMA']??'-','kelas'=>$p['kelas_rombel_snapshot']?:$p['KELAS'],'metode'=>$p['sistem_pembayaran'],'operator'=>$p['user_id']?:'-','source_period'=>$nativePeriod,'student_id'=>(int)($p['student_id']??0),'student_active'=>$p['student_active']];
      foreach($map as $field=>$name)if((float)$p[$field]>0)$rows[]=array_merge($base,['kategori_key'=>strtolower(substr($field,2)),'komponen'=>$name,'nominal'=>(float)$p[$field]]);
      if(($du[$p['id']]??0)>0)$rows[]=array_merge($base,['kategori_key'=>'daftar_ulang','komponen'=>'Daftar Ulang','nominal'=>$du[$p['id']]]);
      foreach($extra[$p['id']]??[] as $d)$rows[]=array_merge($base,['kategori_key'=>$d['master_biaya_lain_id']?'biaya_lain:'.$d['master_biaya_lain_id']:'biaya_lain','bill_id'=>(int)($d['tagihan_biaya_lain_id']??0),'optional_fee'=>$d['tagihan_biaya_lain_id']===null,'komponen'=>$d['nama_biaya_snapshot'],'nominal'=>(float)$d['nominal_snapshot']]);
      if((float)$p['potong_spp']>0)$rows[]=array_merge($base,['kategori_key'=>'potongan','komponen'=>'Potongan SPP','nominal'=>-(float)$p['potong_spp']]);
    }
    return array_values(array_filter(array_merge($rows,lful_report_components($db,$f,$cashProjection)),static fn($r)=>report_component_matches($r,$f)));
}

function report_receipt_data(mysqli $db,array $f):array{
    $components=report_payment_components($db,$f,true);$category=$f['kategori'];
    $selected=$f['_multi']['kategori']??[$category];$all=filter_is_all($selected)||in_array('semua',$selected,true)||$selected===[''];
    if(!$all)$components=array_values(array_filter($components,static fn($r)=>in_array($r['kategori_key'],$selected,true)||in_array($r['source_category']??$r['kategori_key'],$selected,true)));
    if($f['q']!==''||($f['student_id']??0)>0)$components=array_values(array_filter($components,fn($r)=>report_row_matches_filters($r,$f)));
    $allCategories=report_categories($db);
    $allCategories['potongan']='Potongan SPP';
    $displaySelected=array_map(static fn($key)=>$key===lcs_sorga_history_key()?'komite':$key,$selected);
    $wantedCategories=array_intersect_key($allCategories,array_fill_keys($all?array_column($components,'kategori_key'):$displaySelected,true));
    $orderedCategories=[];foreach($allCategories as $key=>$label)if(isset($wantedCategories[$key]))$orderedCategories[$key]=$label;
    $columns=[['nis','NIS','nis'],['nama','Nama Siswa'],['kelas','Kelas','kelas']];
    foreach($orderedCategories as $key=>$label)$columns[]=[$key,$label,'money_optional'];
    $columns[]=['total_penerimaan','Total','money_optional'];
    $rowsByStudent=[];
    foreach($components as $component){
        if(!isset($orderedCategories[$component['kategori_key']]))continue;
        $nis=(string)$component['nis'];
        // A date range may cross promotion or a rombel transfer. Keep the
        // transaction's class snapshot instead of assigning all cash to the
        // first class encountered for this student.
        $groupKey=$nis."\0".(string)$component['kelas'];
        if(!isset($rowsByStudent[$groupKey])){
            $rowsByStudent[$groupKey]=['nis'=>$nis,'nis_diknas'=>$component['nis_diknas']??'','nama'=>$component['nama'],'kelas'=>$component['kelas'],'total_penerimaan'=>0];
            foreach($orderedCategories as $key=>$_)$rowsByStudent[$groupKey][$key]=null;
        }
        $key=$component['kategori_key'];$amount=(float)$component['nominal'];
        $rowsByStudent[$groupKey][$key]=($rowsByStudent[$groupKey][$key]??0)+$amount;
        $rowsByStudent[$groupKey]['total_penerimaan']+=$amount;
    }
    $rows=array_values($rowsByStudent);
    usort($rows,fn($a,$b)=>[$a['kelas'],$a['nama'],$a['nis']]<=>[$b['kelas'],$b['nama'],$b['nis']]);
    return ['title'=>'Rekap Penerimaan Harian','subtitle'=>report_date_range_label($f['tanggal_awal'],$f['tanggal_akhir']).' · nominal per siswa dan komponen pembayaran','columns'=>$columns,'rows'=>$rows,'receipt_components'=>$components];
}

function report_academic_months(string $label):array{[$a,$b]=array_map('intval',explode('/',$label));$out=[];for($m=7;$m<=12;$m++)$out[]=[$m,$a];for($m=1;$m<=6;$m++)$out[]=[$m,$b];return $out;}
function report_spp_year_data(mysqli $db,array $f):array{
    return report_monthly_data($db,$f,true);
}

function report_item_is_monthly_category(string $category): bool {
    return $category === 'spp' || $category === 'komite';
}
function report_item_is_annual_category(string $category): bool {
    return $category === 'daftar_ulang' || array_key_exists($category, annual_fee_components());
}
function report_item_is_one_time_category(string $category): bool {return array_key_exists($category,one_time_fee_components());}
function report_item_month_period_label(int $startMonth, int $endMonth, int $year): string {
    $months = report_months();
    $start = $months[sprintf('%02d', $startMonth)] ?? (string)$startMonth;
    $end = $months[sprintf('%02d', $endMonth)] ?? (string)$endMonth;
    return ($startMonth === $endMonth ? $start : $start . ' s.d. ' . $end) . ' ' . $year;
}
function report_month_range(int $startMonth, int $startYear, int $endMonth, int $endYear): array {
    $startKey = ($startYear * 12) + max(1, min(12, $startMonth));
    $endKey = ($endYear * 12) + max(1, min(12, $endMonth));
    if ($startKey > $endKey) [$startKey, $endKey] = [$endKey, $startKey];
    $endKey = min($endKey, $startKey + 35);
    $months = [];
    for ($key = $startKey; $key <= $endKey; $key++) {
        $year = intdiv($key - 1, 12);
        $month = (($key - 1) % 12) + 1;
        $months[] = [$month, $year];
    }
    return $months;
}
function report_month_range_label(array $months): string {
    if (!$months) return '';
    $names = report_months();
    [$firstMonth, $firstYear] = $months[0];
    [$lastMonth, $lastYear] = $months[count($months) - 1];
    $first = ($names[sprintf('%02d', $firstMonth)] ?? $firstMonth) . ' ' . $firstYear;
    $last = ($names[sprintf('%02d', $lastMonth)] ?? $lastMonth) . ' ' . $lastYear;
    return $first === $last ? $first : $first . ' s.d. ' . $last;
}
function report_item_date_period_label(array $f): string {
    return $f['tanggal_awal'] === $f['tanggal_akhir']
        ? report_date_label($f['tanggal_awal'])
        : report_date_label($f['tanggal_awal']) . ' s.d. ' . report_date_label($f['tanggal_akhir']);
}
function report_item_data(mysqli $db,array $f):array{
    if(report_item_is_monthly_category($f['kategori']))return report_monthly_data($db,$f);
    if(str_starts_with($f['kategori'],'histori:'))return report_history_component_status($db,$f,true);
    if(report_item_is_one_time_category($f['kategori'])){$report=report_annual_evidence($db,$f);$report['title']='Rekap Pembayaran per Item: '.report_categories($db)[$f['kategori']];$report['subtitle']='Kewajiban sekali saat masuk';return $report;}
    $category=$f['kategori'];$categories=report_categories($db);$label=$categories[$category]??'SPP';$rows=[];

    if (report_item_is_annual_category($category)) {
        $subtitle = 'Tahun ajaran: ' . $f['tahun_ajaran'];
        $statusFilters = $f;
        $statusFilters['status'] = '';
        $statusData = report_status_data($db, $statusFilters);
        foreach ($statusData['rows'] as $row) {
            $row['periode'] = $f['tahun_ajaran'];
            if (($row['kelas'] ?? '') === '' || ($row['kelas'] ?? '') === '-') $row['kelas'] = 'Belum diatur';
            $rows[] = $row;
        }
    } else {
        $periodLabel=report_item_date_period_label($f);$subtitle='Tanggal transaksi: '.$periodLabel;$components=report_payment_components($db,$f);
        if(str_starts_with($category,'biaya_lain:')){
            $statusFilters=$f;$statusFilters['status']='';unset($statusFilters['_multi']['status']);
            $statusData=report_status_data($db,$statusFilters);
            $rows=report_fee_item_rows($db,$f,$statusData['rows'],$components);
        }else{
        $wanted=$category;$paid=[];$paidByBill=[];$optionalPaid=[];$componentStudents=[];foreach($components as $component)if($component['kategori_key']===$wanted){$componentStudents[$component['nis']]=$component;
            if(!empty($component['bill_id']))$paidByBill[$component['bill_id']]=($paidByBill[$component['bill_id']]??0)+(float)$component['nominal'];
            // Historical receipts have no native bill allocation. An opening bill
            // already represents the remaining debt; these receipts cannot repay it twice.
            if(!empty($component['optional_fee'])||(!empty($component['legacy'])&&str_starts_with($wanted,'biaya_lain:')))$optionalPaid[$component['nis']]=($optionalPaid[$component['nis']]??0)+(float)$component['nominal'];
            else $paid[$component['nis']]=($paid[$component['nis']]??0)+(float)$component['nominal'];
        }
        $statusFilters=$f;$statusFilters['status']='';unset($statusFilters['_multi']['status']);
        $statusData=str_starts_with($category,'histori:')?['rows'=>[]]:report_status_data($db,$statusFilters);
        $seenStudents=array_fill_keys(array_column($statusData['rows'],'nis'),true);
        foreach($componentStudents as $nis=>$component)if(!isset($seenStudents[$nis]))$statusData['rows'][]=['nis'=>(string)$nis,'nis_diknas'=>$component['nis_diknas']??'','nama'=>$component['nama'],'kelas'=>$component['kelas'],'student_id'=>$component['student_id']??0,'tagihan'=>null,'_incomplete'=>true];
        $assignedOptional=[];
        foreach($statusData['rows'] as $row){
            $cancelled=($row['_status']??'')==='Dibatalkan';
            if(!empty($componentStudents[$row['nis']]['legacy'])&&($row['tagihan']??0)<=.001){$row['tagihan']=null;$row['_incomplete']=true;}
            $row['pembayaran_opsional']=isset($assignedOptional[$row['nis']])?0:($optionalPaid[$row['nis']]??0);$assignedOptional[$row['nis']]=true;
            $row['terbayar']=!empty($row['bill_id'])?($paidByBill[$row['bill_id']]??0):($paid[$row['nis']]??0);
            $row['sisa']=$row['tagihan']===null?null:max(0,(float)$row['tagihan']-$row['terbayar']);
            $row['status']=$row['tagihan']===null?($row['terbayar']+$row['pembayaran_opsional']>.001?'Ada Pembayaran':'Belum Diketahui'):((float)$row['tagihan']<=0&&$row['pembayaran_opsional']>.001
                ? 'Ada Pembayaran' : report_status_name((float)$row['tagihan'],(float)$row['terbayar']));
            $row['_status']=$row['status'];$row['periode']=$periodLabel;
            if($cancelled){$row['status']=$row['_status']='Dibatalkan';$row['sisa']=0.0;}
            if(($row['kelas']??'')===''||($row['kelas']??'')==='-')$row['kelas']='Belum diatur';
            $rows[]=$row;
        }
        }
    }
    $rows=report_filter_rows($rows,$f);$columns=[['nis','NIS'],['nama','Nama Siswa'],['kelas','Kelas'],['periode','Periode'],['tagihan','Tagihan','money_optional'],['terbayar',str_starts_with($category,'biaya_lain')?'Dibayar Tagihan':'Dibayar','money']];if(str_starts_with($category,'biaya_lain'))$columns[]=['pembayaran_opsional','Pembayaran Tanpa Tagihan','money'];$columns=array_merge($columns,[['sisa','Sisa','money_optional'],['status','Status','status']]);return ['title'=>'Rekap Pembayaran per Item: '.$label,'subtitle'=>$subtitle,'columns'=>$columns,'rows'=>$rows];
}

function report_savings_transactions(mysqli $db,string $start,string $end,array $f):array{
    require_once __DIR__.'/legacy_operational_reports.php';
    require_once __DIR__.'/legacy_finance.php';
    $fullJournalUnion=lful_read_ready($db)?" UNION ALL SELECT -j.id,j.unit_id,s.NO_INDUK,j.source_date,j.incoming,j.outgoing,CONCAT('legacy:',j.unit_id,':',LOWER(HEX(COALESCE(j.source_operator,'')))),'Histori Legacy',CASE WHEN j.incoming>0 THEN 'Masuk' ELSE 'Keluar' END,CASE WHEN j.incoming>0 THEN 0 ELSE 1 END,0 FROM legacy_full_journals j JOIN siswa s ON s.id=j.student_id AND s.unit_id=j.unit_id JOIN legacy_full_batches b ON b.id=j.batch_id AND b.unit_id=j.unit_id WHERE b.state='completed' ":'';
    $fullOpeningDelta=lful_read_ready($db)?"IF(EXISTS(SELECT 1 FROM legacy_full_students fs WHERE fs.student_id=o.student_id AND fs.unit_id=o.unit_id),0,o.amount)":'o.amount';
    $openingUnion=legacy_finance_ready($db)?" UNION ALL SELECT o.id,o.unit_id,s.NO_INDUK,CAST(o.effective_date AS DATETIME),0,0,'','Saldo awal Legacy, bukan setoran','Saldo Awal',-1,$fullOpeningDelta FROM legacy_savings_openings o JOIN siswa s ON s.id=o.student_id AND s.unit_id=o.unit_id WHERE o.cancelled_at IS NULL ":'';
    $where=!empty($f['include_undated'])?'((x.tanggal>=? AND x.tanggal<?) OR x.tanggal IS NULL)':'(x.tanggal>=? AND x.tanggal<?)';$types='ss';$params=[$start,$end];if(($f['owner_id']??0)>0)$where.=' AND 0=1';if(($f['student_id']??0)>0)$where.=' AND s.id='.(int)$f['student_id'];$operatorJoin='';$useSavingsOperator=($f['_savings_operator']??false);if(!$useSavingsOperator&&$f['operator']!==''&&!str_starts_with($f['operator'],'legacy:')){$operatorJoin=report_operator_join('x.user_id','op');$where.=' AND '.report_operator_where('x.user_id','op');$types.='ssss';$params=array_merge($params,report_operator_params($f['operator']));}
    // Internal school-cash scope only; account ledgers keep every valid mutation.

    $studentJoin = report_sql_ci_eq('s.NO_INDUK', 'x.NO_INDUK').' AND s.unit_id=x.unit_id';
    $placementJoin = report_sql_ci_eq('sta.no_induk', 'x.NO_INDUK').' AND sta.unit_id=x.unit_id';
    $operatorValues=$f['_multi']['operator']??($f['operator']!==''?[$f['operator']]:['*']);
    if(!filter_is_all($operatorValues)){
        $legacySelected=array_values(array_filter($operatorValues,static fn($op)=>str_starts_with((string)$op,'legacy:')));
        $nativeSelected=array_values(array_diff($operatorValues,$legacySelected));$clauses=[];
        if($legacySelected)$clauses[]=substr(filter_sql_values($legacySelected,'x.user_id'),5);
        if($nativeSelected){$nativeFilters=$f;$nativeFilters['_multi']['operator']=$nativeSelected;$clauses[]=substr($useSavingsOperator?savings_operator_where($nativeSelected,savings_operator_owner_sql('x.unit_id','x.jenis','x.id','x.user_id')):report_multi_operator_sql($nativeFilters,'x.user_id'),5);}
        if($clauses)$where.=' AND ('.implode(' OR ',$clauses).')';
    }
    $ownerSelect=$useSavingsOperator?',('.savings_operator_owner_sql('x.unit_id','x.jenis','x.id','x.user_id').') savings_owner_id':'';
    $sql="SELECT x.*$ownerSelect,s.NAMA,s.NO_induk_diknas,CASE WHEN s.KELAS IN ('CUSTOM','OUT') THEN s.master_kelas_id ELSE COALESCE(sta.master_kelas_id,s.master_kelas_id) END master_kelas_id,
      CASE WHEN mk.tingkat=0 AND UPPER(mk.kode_rombel) LIKE 'ALUMNI%' THEN mk.kode_rombel WHEN mk.jenis='custom' THEN mk.nama_custom WHEN mk.jenis='out' THEN 'Out' ELSE COALESCE(sta.kelas_rombel_snapshot,CASE WHEN mk.is_placeholder=1 THEN CONCAT('Kelas ',COALESCE(mk.tingkat,s.KELAS),' (Belum Ditentukan)') ELSE CONCAT(mk.tingkat,UPPER(mk.kode_rombel)) END) END kelas_label,
      mk.jenis AS rombel_jenis,mk.nama_custom AS rombel_nama,mk.tingkat,mk.kode_rombel,mk.is_placeholder FROM (
      SELECT id,unit_id,NO_INDUK,TANGGAL tanggal,MASUK masuk,0 keluar,user_id,keterangan,'Masuk' jenis,0 urutan_mutasi,0 delta_opening FROM transaksi_m
      UNION ALL SELECT id,unit_id,NO_INDUK,TANGGAL,0,KELUAR,user_id,keterangan,'Keluar',1,0 FROM transaksi_k
      $openingUnion
      $fullJournalUnion
    ) x JOIN siswa s ON $studentJoin
      $operatorJoin
      LEFT JOIN tahun_ajaran ta ON ta.unit_id=s.unit_id AND DATE(x.tanggal) BETWEEN ta.tanggal_mulai AND ta.tanggal_selesai
      LEFT JOIN siswa_tahun_ajaran sta ON sta.tahun_ajaran_id=ta.id AND $placementJoin
      LEFT JOIN master_kelas mk ON mk.id=CASE WHEN s.KELAS IN ('CUSTOM','OUT') THEN s.master_kelas_id ELSE COALESCE(sta.master_kelas_id,s.master_kelas_id) END
      WHERE $where ORDER BY x.tanggal,x.urutan_mutasi,x.id";
    $stmt=$db->prepare(hp_sql($db,$sql));$stmt->bind_param($types,...$params);$stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();foreach($rows as &$row)$row['time_precision']=journal_time_precision($db,(int)$row['id'],(int)$row['id']<0?'legacy':'native',$row['tanggal']);unset($row);if(!empty($f['include_undated'])){$proofs=report_undated_journal_periods($db,'legacy_full_journals');$rows=array_values(array_filter($rows,static fn($r)=>!empty($r['tanggal'])||((int)$r['id']<0&&report_undated_matches($f,$proofs[-(int)$r['id']]??''))));}if($useSavingsOperator){$names=savings_operator_options($db,false);foreach($rows as &$r)$r['savings_operator_name']=$names[(string)$r['savings_owner_id']]??'Tidak tercatat';unset($r);}$rows=array_merge($rows,lop_savings_report_rows($db,$start,$end,$f));usort($rows,fn($a,$b)=>[$a['tanggal'],$a['urutan_mutasi'],$a['id']]<=>[$b['tanggal'],$b['urutan_mutasi'],$b['id']]);return $rows;
}
function report_savings_openings(mysqli $db,array $studentIds,string $before):array{
    require_once __DIR__.'/legacy_operational_reports.php';
    require_once __DIR__.'/legacy_finance.php';
    $studentIds=array_values(array_unique(array_filter($studentIds)));if(!$studentIds)return [];$placeholders=implode(',',array_fill(0,count($studentIds),'?'));
    $sql="SELECT x.NO_INDUK,SUM(x.delta) saldo FROM (SELECT unit_id,NO_INDUK,MASUK delta FROM transaksi_m WHERE TANGGAL<? UNION ALL SELECT unit_id,NO_INDUK,-KELUAR FROM transaksi_k WHERE TANGGAL<?) x WHERE x.NO_INDUK IN ($placeholders) GROUP BY x.NO_INDUK";
    $stmt=$db->prepare(hp_sql($db,$sql));$types='ss'.str_repeat('s',count($studentIds));$params=array_merge([$before,$before],$studentIds);$stmt->bind_param($types,...$params);$stmt->execute();$result=[];foreach($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row)$result[$row['NO_INDUK']]=(float)$row['saldo'];$stmt->close();
    if(legacy_finance_ready($db))foreach(lf_rows($db,"SELECT s.NO_INDUK,o.amount FROM legacy_savings_openings o JOIN siswa s ON s.id=o.student_id AND s.unit_id=o.unit_id WHERE o.cancelled_at IS NULL AND o.effective_date<? AND s.NO_INDUK IN ($placeholders)",array_merge([$before],$studentIds)) as $r)$result[$r['NO_INDUK']]=($result[$r['NO_INDUK']]??0)+(float)$r['amount'];
    if(lful_read_ready($db)){
        foreach(lf_rows($db,"SELECT s.NO_INDUK,SUM(j.incoming-j.outgoing) amount FROM legacy_full_journals j JOIN siswa s ON s.id=j.student_id AND s.unit_id=j.unit_id JOIN legacy_full_batches b ON b.id=j.batch_id AND b.unit_id=j.unit_id WHERE b.state='completed' AND j.source_date<? AND s.NO_INDUK IN ($placeholders) GROUP BY s.NO_INDUK",array_merge([$before],$studentIds)) as $r)$result[$r['NO_INDUK']]=($result[$r['NO_INDUK']]??0)+(float)$r['amount'];
        foreach(lf_rows($db,"SELECT s.NO_INDUK,o.amount FROM legacy_savings_openings o JOIN legacy_full_students fs ON fs.student_id=o.student_id AND fs.unit_id=o.unit_id JOIN siswa s ON s.id=fs.student_id AND s.unit_id=fs.unit_id WHERE o.cancelled_at IS NULL AND o.effective_date<? AND s.NO_INDUK IN ($placeholders)",array_merge([$before],$studentIds)) as $r)$result[$r['NO_INDUK']]-=(float)$r['amount'];
    }
    foreach(lop_savings_report_openings($db,$studentIds,$before) as $identity=>$amount)$result[$identity]=($result[$identity]??0)+$amount;return $result;
}
function report_savings_last_dates(mysqli $db,array $studentIds,string $before):array{
    $studentIds=array_values(array_unique(array_filter($studentIds)));if(!$studentIds)return [];$placeholders=implode(',',array_fill(0,count($studentIds),'?'));
    $sql="SELECT x.NO_INDUK,MAX(x.tanggal) terakhir FROM (SELECT unit_id,NO_INDUK,TANGGAL tanggal FROM transaksi_m WHERE TANGGAL<? UNION ALL SELECT unit_id,NO_INDUK,TANGGAL FROM transaksi_k WHERE TANGGAL<?) x WHERE x.NO_INDUK IN ($placeholders) GROUP BY x.NO_INDUK";
    $stmt=$db->prepare(hp_sql($db,$sql));$types='ss'.str_repeat('s',count($studentIds));$params=array_merge([$before,$before],$studentIds);$stmt->bind_param($types,...$params);$stmt->execute();$result=[];foreach($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row)$result[$row['NO_INDUK']]=$row['terakhir'];$stmt->close();return $result;
}
function report_savings_current_balances(mysqli $db,array $studentIds):array{
    $studentIds=array_values(array_unique(array_filter($studentIds)));if(!$studentIds)return [];$placeholders=implode(',',array_fill(0,count($studentIds),'?'));
    $stmt=$db->prepare("SELECT unit_id,NO_INDUK,COALESCE(SALDO,0) saldo FROM tabungan WHERE NO_INDUK IN ($placeholders)");$types=str_repeat('s',count($studentIds));$stmt->bind_param($types,...$studentIds);$stmt->execute();$balances=[];foreach($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row)$balances[$row['NO_INDUK']]=(float)$row['saldo'];$stmt->close();return $balances;
}
function report_savings_class_data(mysqli $db,array $f):array{
    $mode=in_array($f['mode'],['harian','bulanan'],true)?$f['mode']:'harian';$year=max(2000,$f['tahun']);$month=(int)$f['bulan_awal'];
    if($mode==='harian'){$periodStart=sprintf('%04d-%02d-01',$year,$month);$periodEnd=date('Y-m-d',strtotime($periodStart.' +1 month'));$parts=[];for($d=1;$d<=(int)date('t',strtotime($periodStart));$d++)$parts[]=sprintf('%04d-%02d-%02d',$year,$month,$d);}else{$periodStart="$year-01-01";$periodEnd=($year+1).'-01-01';$parts=[];for($m=1;$m<=12;$m++)$parts[]=sprintf('%04d-%02d',$year,$m);}
    $transactions=report_savings_transactions($db,$periodStart.' 00:00:00',$periodEnd.' 00:00:00',$f);$studentMap=[];foreach($transactions as $t){if(!report_class_matches_row($f,$t))continue;$studentMap[$t['NO_INDUK']]=['nis'=>$t['NO_INDUK'],'nis_diknas'=>$t['NO_induk_diknas']??'','nama'=>$t['NAMA'],'kelas'=>trim((string)($t['kelas_label']??''))!==''?$t['kelas_label']:'Tidak tercatat'];}
    $sql="SELECT s.NO_INDUK nis,s.NO_induk_diknas nis_diknas,s.NAMA nama,s.master_kelas_id,mk.jenis AS rombel_jenis,mk.nama_custom AS rombel_nama,mk.tingkat,mk.kode_rombel,mk.is_placeholder FROM siswa s LEFT JOIN master_kelas mk ON mk.id=s.master_kelas_id WHERE s.is_active=1".report_class_where($f,'s','KELAS').' ORDER BY s.NAMA';foreach(report_scoped_query($db,$sql)->fetch_all(MYSQLI_ASSOC) as $s)$studentMap[$s['nis']]=['nis'=>$s['nis'],'nis_diknas'=>$s['nis_diknas']??'','nama'=>$s['nama'],'kelas'=>class_label($s)];
    $matrix=[];foreach($transactions as $t){if(!report_class_matches_row($f,$t))continue;$key=$mode==='harian'?date('Y-m-d',strtotime($t['tanggal'])):date('Y-m',strtotime($t['tanggal']));$matrix[$t['NO_INDUK']][$key]['in']=($matrix[$t['NO_INDUK']][$key]['in']??0)+(float)$t['masuk'];$matrix[$t['NO_INDUK']][$key]['out']=($matrix[$t['NO_INDUK']][$key]['out']??0)+(float)$t['keluar'];}
    $columns=[['nis','NIS'],['nama','Nama Siswa'],['kelas','Kelas'],['saldo_awal','Saldo Awal','money']];foreach($parts as $part)$columns[]=['p'.$part,$mode==='harian'?date('d',strtotime($part)):report_months()[date('m',strtotime($part.'-01'))],'html'];$columns=array_merge($columns,[['saldo_awal_legacy','Saldo Awal Legacy','money'],['total_masuk','Total Masuk','money'],['total_keluar','Total Keluar','money'],['saldo_akhir','Saldo Akhir','money']]);$rows=[];$openings=report_savings_openings($db,array_keys($studentMap),$periodStart.' 00:00:00');
    foreach($studentMap as $s){if(!report_row_matches_filters($s,$f))continue;$opening=$openings[$s['nis']]??0;$row=$s+['saldo_awal'=>$opening];$in=0;$out=0;foreach($parts as $part){$cell=$matrix[$s['nis']][$part]??['in'=>0,'out'=>0];$row['p'.$part]=['text'=>'M '.report_money($cell['in']),'sub'=>'K '.report_money($cell['out']),'status'=>($cell['in']||$cell['out'])?'lunas':''];$in+=$cell['in'];$out+=$cell['out'];}$legacyOpening=0;foreach($transactions as $tx)if($tx['NO_INDUK']===$s['nis'])$legacyOpening+=(float)($tx['delta_opening']??0);$row['saldo_awal_legacy']=$legacyOpening;$row['total_masuk']=$in;$row['total_keluar']=$out;$row['saldo_akhir']=$opening+$legacyOpening+$in-$out;$rows[]=$row;}
    return ['title'=>'Rekap Mutasi Tabungan per Kelas','subtitle'=>($mode==='harian'?(report_months()[sprintf('%02d',$month)]??$month).' ':'Tahun ').$year,'columns'=>$columns,'rows'=>$rows];
}
function report_savings_student_data(mysqli $db,array $f):array{
    // Scope each unit before grouping running balances by its legacy NIS.
    if((int)($GLOBALS['app_unit_id']??unit_active_id())===0){$all=[];try{foreach([1,2,3] as $u){unit_set_context($db,$u);$part=report_savings_student_data($db,$f);foreach($part['rows'] as &$row)$row['unit']=unit_label($u);unset($row);$all=array_merge($all,$part['rows']);}}finally{unit_set_context($db,0);}$part['rows']=$all;array_unshift($part['columns'],['unit','Unit']);return $part;}
    if($f['all_periods']??false){$f['tanggal_awal']='1000-01-01';$f['tanggal_akhir']='9998-12-31';}

    $start=$f['tanggal_awal'].' 00:00:00';$end=date('Y-m-d H:i:s',strtotime($f['tanggal_akhir'].' +1 day'));
    $transactions=report_savings_transactions($db,$start,$end,$f);
    $timelineFilters=$f;$timelineFilters['operator']='';unset($timelineFilters['_multi']['operator']);$timeline=report_savings_transactions($db,$start,$end,$timelineFilters);
    $studentIds=array_values(array_unique(array_column($transactions,'NO_INDUK')));$running=report_savings_openings($db,$studentIds,$start);$historicalBalances=[];
    $studentMap=array_fill_keys($studentIds,true);$unknownDays=[];$dayGroups=[];foreach($timeline as $t){$day=substr((string)$t['tanggal'],0,10);$k=$t['NO_INDUK'].'|'.$day;$dayGroups[$k]['count']=($dayGroups[$k]['count']??0)+1;if(($t['time_precision']??'')==='date')$dayGroups[$k]['day']=true;}foreach($dayGroups as $k=>$g)if($g['count']>1&&!empty($g['day']))$unknownDays[$k]=true;
    $unknownOrder=[];foreach($transactions as $t)if(empty($t['tanggal']))$unknownOrder[$t['NO_INDUK']]=true;
    foreach($timeline as $transaction){$nis=(string)$transaction['NO_INDUK'];if(!isset($studentMap[$nis]))continue;$running[$nis]=($running[$nis]??0)+(float)$transaction['masuk']-(float)$transaction['keluar']+(float)($transaction['delta_opening']??0);$historicalBalances[$transaction['jenis'].'#'.$transaction['id']]=(float)$running[$nis];}
    $legacyOperators=[];foreach($transactions as $t)if(str_starts_with((string)$t['user_id'],'legacy:')){$legacyOperators=lful_operator_options($db);break;}
    $rows=[];
    foreach($transactions as $t){if(isset($f['_multi']['mutasi'])&&!filter_is_all($f['_multi']['mutasi'])&&!in_array(strtolower($t['jenis']),$f['_multi']['mutasi'],true))continue;if($f['mutasi']==='masuk'&&$t['jenis']!=='Masuk')continue;if($f['mutasi']==='keluar'&&$t['jenis']!=='Keluar')continue;if(!report_class_matches_row($f,$t))continue;$candidate=['nis'=>$t['NO_INDUK'],'nis_diknas'=>$t['NO_induk_diknas']??'','nama'=>$t['NAMA'],'kelas'=>trim((string)($t['kelas_label']??''))!==''?$t['kelas_label']:'Tidak tercatat'];if(!report_row_matches_filters($candidate,$f))continue;$key=$t['jenis'].'#'.$t['id'];$rows[]=$candidate+['tanggal'=>$t['tanggal'],'jenis'=>$t['jenis'],'penyesuaian'=>(float)($t['delta_opening']??0),'masuk'=>(float)$t['masuk'],'keluar'=>(float)$t['keluar'],'saldo_saat_ini'=>(isset($unknownOrder[$t['NO_INDUK']])||isset($unknownDays[$t['NO_INDUK'].'|'.substr((string)$t['tanggal'],0,10)]))?null:(float)($historicalBalances[$key]??0),'operator'=>str_starts_with((string)$t['user_id'],'legacy:')?($legacyOperators[$t['user_id']]??'Legacy'):($t['savings_operator_name']??($t['user_id']?:'-')),'keterangan'=>$t['keterangan']??''];}
    return ['notes'=>array_filter($rows,static fn($row)=>$row['saldo_saat_ini']===null)?['Saldo berjalan belum diketahui jika waktu atau urutan transaksi tidak terbukti.']:[],'title'=>'Rekap Transaksi Tabungan','subtitle'=>!empty($f['all_periods'])?'Semua periode':report_date_range_label($f['tanggal_awal'],$f['tanggal_akhir']),'columns'=>[['tanggal','Tanggal/Waktu','datetime'],['nis','NIS'],['nama','Nama Siswa'],['kelas','Kelas'],['jenis','Mutasi'],['masuk','Masuk','money'],['keluar','Keluar','money'],['penyesuaian','Penyesuaian','money'],['saldo_saat_ini','Saldo Berjalan','money'],['operator','Operator'],['keterangan','Keterangan']],'rows'=>$rows];
}
function report_savings_student_data_legacy(mysqli $db,array $f):array{
    $start=$f['tanggal_awal'].' 00:00:00';$end=date('Y-m-d H:i:s',strtotime($f['tanggal_akhir'].' +1 day'));$transactions=report_savings_transactions($db,$start,$end,$f);$rows=[];$running=report_savings_openings($db,array_column($transactions,'NO_INDUK'),$start);
    foreach($transactions as $t){if(!report_class_matches_row($f,$t))continue;if(!report_row_matches_filters($t,$f))continue;$opening=$running[$t['NO_INDUK']]??0;$running[$t['NO_INDUK']]=$opening+(float)$t['masuk']-(float)$t['keluar']+(float)($t['delta_opening']??0);$rows[]=['tanggal'=>$t['tanggal'],'nis'=>$t['NO_INDUK'],'nama'=>$t['NAMA'],'kelas'=>trim((string)($t['kelas_label']??''))!==''?$t['kelas_label']:'Tidak tercatat','jenis'=>$t['jenis'],'penyesuaian'=>(float)($t['delta_opening']??0),'masuk'=>(float)$t['masuk'],'keluar'=>(float)$t['keluar'],'saldo_awal'=>$opening,'saldo'=>$running[$t['NO_INDUK']],'operator'=>$t['user_id']?:'-'];}
    $mode=in_array($f['mode'],['buku','transaksi'],true)?$f['mode']:'buku';$columns=$mode==='buku'?[['tanggal','Tanggal/Waktu','datetime'],['nis','NIS'],['nama','Nama Siswa'],['jenis','Mutasi'],['masuk','Masuk','money'],['keluar','Keluar','money'],['saldo','Saldo Berjalan','money'],['operator','Operator']]:[['tanggal','Tanggal/Waktu','datetime'],['nis','NIS'],['nama','Nama Siswa'],['kelas','Kelas'],['jenis','Mutasi'],['masuk','Masuk','money'],['keluar','Keluar','money'],['operator','Operator']];return ['title'=>'Laporan Tabungan Siswa — '.($mode==='buku'?'Buku Saldo':'Daftar Transaksi'),'subtitle'=>report_date_range_label($f['tanggal_awal'],$f['tanggal_akhir']),'columns'=>$columns,'rows'=>$rows];
}
function report_savings_balance_data(mysqli $db,array $f):array{
    require_once __DIR__.'/migrated_history.php';$studentSource=mh_students_source($db);
    $studentWhere=report_student_status_where($f);
    $sql="SELECT s.id student_id,s.NO_INDUK nis,s.NO_induk_diknas nis_diknas,s.NAMA nama,s.master_kelas_id,mk.jenis AS rombel_jenis,mk.nama_custom AS rombel_nama,mk.tingkat,mk.kode_rombel,mk.is_placeholder,COALESCE(t.SALDO,0) saldo_saat_ini FROM $studentSource s LEFT JOIN tabungan t ON ".report_sql_ci_eq('t.NO_INDUK','s.NO_INDUK')." AND t.unit_id=s.unit_id LEFT JOIN master_kelas mk ON mk.id=s.master_kelas_id WHERE 1=1$studentWhere".report_class_where($f,'s','KELAS')." ORDER BY mk.tingkat,mk.kode_rombel,s.NAMA";
    $held=lop_held_student_accounts($db);$rows=[];foreach(report_scoped_query($db,$sql)->fetch_all(MYSQLI_ASSOC) as $student){if(isset($held[(int)$student['student_id']]))continue;$saldo=(float)$student['saldo_saat_ini'];if($f['saldo_status']==='ada_saldo'&&$saldo<=0.001)continue;if($f['saldo_status']==='saldo_nol'&&$saldo>0.001)continue;$row=['nis'=>$student['nis'],'nis_diknas'=>$student['nis_diknas']??'','nama'=>$student['nama'],'kelas'=>class_label($student)?:'Belum diatur','saldo_saat_ini'=>$saldo,'master_kelas_id'=>$student['master_kelas_id']??0,'tingkat'=>$student['tingkat']??''];if(report_row_matches_filters($row,$f))$rows[]=$row;}
    if(lop_ready($db))foreach(lf_rows($db,"SELECT o.id,o.name,o.source_class,g.master_kelas_id,a.balance FROM legacy_op_owners o LEFT JOIN legacy_op_groups g ON g.id=o.group_id AND g.unit_id=o.unit_id JOIN legacy_op_accounts a ON a.owner_id=o.id AND a.unit_id=o.unit_id JOIN legacy_full_batches b ON b.id=o.batch_id AND b.unit_id=o.unit_id WHERE b.state='completed' AND o.state='ready' AND o.student_id IS NULL") as $owner){$balance=(float)$owner['balance'];if(($f['saldo_status']==='ada_saldo'&&$balance<=0.001)||($f['saldo_status']==='saldo_nol'&&$balance>0.001))continue;$row=['nis'=>'rekening:'.$owner['id'],'nis_diknas'=>'','nama'=>$owner['name'],'kelas'=>$owner['source_class'],'kelas_label'=>$owner['source_class'],'saldo_saat_ini'=>$balance,'master_kelas_id'=>$owner['master_kelas_id'],'tingkat'=>null];if(report_class_matches_row($f,$row)&&report_row_matches_filters($row,$f))$rows[]=$row;}
    return ['title'=>'Rekap Saldo Tabungan','subtitle'=>'Saldo siswa dan rekening khusus yang telah disahkan','columns'=>[['nis','Identitas','nis'],['nama','Nama Pemilik'],['kelas','Kelas / Kelompok','kelas'],['saldo_saat_ini','Saldo Saat Ini','money']],'rows'=>$rows];
}
function report_billing_categories(mysqli $db):array{
    $categories=['spp'=>'SPP','psb'=>'Uang PSB','komite'=>'Uang Komite','daftar_ulang'=>'Daftar Ulang'];
    foreach(report_scoped_query($db,'SELECT id,nama FROM master_biaya_lain ORDER BY nama')->fetch_all(MYSQLI_ASSOC) as $item)$categories['biaya_lain:'.(int)$item['id']]=$item['nama'];return $categories;
}
function report_billing_history_component_order(string $key): int {
    return match($key){
        'daftar_ulang'=>10,

        'psb'=>30,
        'komite'=>60,
        'spp'=>200,
        default=>str_starts_with($key,'biaya_lain:')?100:150,
    };
}
function report_billing_history_compare_items(array $a,array $b): int {
    $yearComparison=(string)($a['tahun_ajaran']??'')<=>(string)($b['tahun_ajaran']??'');
    if($yearComparison!==0)return $yearComparison;
    $componentComparison=report_billing_history_component_order((string)($a['komponen_key']??''))<=>report_billing_history_component_order((string)($b['komponen_key']??''));
    if($componentComparison!==0)return $componentComparison;
    if(in_array(($a['komponen_key']??''),['spp','komite'],true)&&($a['komponen_key']??'')===($b['komponen_key']??'')){
        $periodComparison=(string)($a['periode_code']??'')<=>(string)($b['periode_code']??'');
        if($periodComparison!==0)return $periodComparison;
    }
    return [mb_strtolower((string)($a['komponen']??'')),(string)($a['periode']??'')]<=>[
        mb_strtolower((string)($b['komponen']??'')),(string)($b['periode']??''),
    ];
}
function report_billing_history_sort_rows(array &$rows): void {
    if(count($rows)<2)return;
    // Calculate text keys once, preserving PHP's regular comparisons and stable ties.
    $levels=$classes=$names=$numbers=$years=$orders=$months=$labels=$periods=$positions=[];
    foreach($rows as $position=>$row){
        $levels[]=(int)($row['tingkat']??0);$classes[]=(string)($row['kelas']??'');
        $names[]=mb_strtolower((string)($row['nama']??''));$numbers[]=(string)($row['nis']??'');
        $years[]=(string)($row['tahun_ajaran']??'');$orders[]=report_billing_history_component_order((string)($row['komponen_key']??''));
        $months[]=in_array($row['komponen_key']??'',['spp','komite'],true)?(string)($row['periode_code']??''):'';
        $labels[]=mb_strtolower((string)($row['komponen']??''));$periods[]=(string)($row['periode']??'');$positions[]=$position;
    }
    array_multisort($levels,SORT_ASC,SORT_REGULAR,$classes,SORT_ASC,SORT_REGULAR,$names,SORT_ASC,SORT_REGULAR,$numbers,SORT_ASC,SORT_REGULAR,
        $years,SORT_ASC,SORT_REGULAR,$orders,SORT_ASC,SORT_REGULAR,$months,SORT_ASC,SORT_REGULAR,$labels,SORT_ASC,SORT_REGULAR,$periods,SORT_ASC,SORT_REGULAR,$positions,SORT_ASC,SORT_NUMERIC,$rows);
}

function report_billing_history_group_students(array $rows): array {
    $groups=[];
    foreach($rows as $row){
        $studentNis=(string)($row['nis']??'');$nis=unit_student_key($row);
        if($studentNis==='')continue;
        if(!isset($groups[$nis])){
            $groups[$nis]=[
                'nis'=>$studentNis,'unit_id'=>(int)($row['unit_id']??report_query_unit_id()),
                'nis_diknas'=>(string)($row['nis_diknas']??''),
                'nama'=>(string)($row['nama']??''),
                'kelas'=>(string)($row['kelas']??'Belum diatur'),
                'tingkat'=>(int)($row['tingkat']??0),
                'kelas_list'=>[],
                'total_tagihan'=>0.0,
                'total_terbayar'=>0.0,
                'total_sisa'=>0.0,
                'item_count'=>0,
                'items'=>[],
                'components'=>[],
            ];
        }
        $classLabel=trim((string)($row['kelas']??''));
        if($classLabel!==''&&!in_array($classLabel,$groups[$nis]['kelas_list'],true))$groups[$nis]['kelas_list'][]=$classLabel;
        $groups[$nis]['known_count']=($groups[$nis]['known_count']??0)+($row['tagihan']!==null?1:0);
        $groups[$nis]['total_tagihan']+=(float)($row['tagihan']??0);
        $groups[$nis]['total_terbayar']+=(float)($row['terbayar']??0);
        $groups[$nis]['total_sisa']+=(float)($row['sisa']??0);
        $groups[$nis]['unknown_remaining']=($groups[$nis]['unknown_remaining']??false)||($row['sisa']??null)===null;
        $groups[$nis]['item_count']++;
        $groups[$nis]['items'][]=$row;
    }
    $result=array_values($groups);
    foreach($result as &$group){
        usort($group['items'],'report_billing_history_compare_items');
        $components=[];
        foreach($group['items'] as $item){
            $key=(string)$item['komponen_key'];
            if(!isset($components[$key]))$components[$key]=[
                'komponen_key'=>$key,
                'komponen'=>(string)$item['komponen'],
                'tagihan'=>0.0,
                'terbayar'=>0.0,
                'sisa'=>0.0,
                'item_count'=>0,
            ];
            $components[$key]['known_count']=($components[$key]['known_count']??0)+($item['tagihan']!==null?1:0);
            $components[$key]['tagihan']+=(float)$item['tagihan'];
            $components[$key]['terbayar']+=(float)$item['terbayar'];
            $components[$key]['sisa']+=(float)$item['sisa'];
            $components[$key]['unknown_remaining']=($components[$key]['unknown_remaining']??false)||($item['sisa']??null)===null;
            $components[$key]['legacy_payment_only']=($components[$key]['legacy_payment_only']??false)||!empty($item['legacy_payment_only']);
            $components[$key]['item_count']++;
        }
        foreach($components as &$component){
            if(empty($component['known_count']))$component['tagihan']=null;
            if($component['unknown_remaining']&&$component['sisa']<=.001)$component['sisa']=null;
        }unset($component);
        if(empty($group['known_count']))$group['total_tagihan']=null;
        if($group['unknown_remaining']&&$group['total_sisa']<=.001)$group['total_sisa']=null;
        $group['components']=array_values($components);
        usort($group['components'],static fn($a,$b)=>[
            report_billing_history_component_order($a['komponen_key']),
            mb_strtolower($a['komponen']),
        ]<=>[
            report_billing_history_component_order($b['komponen_key']),
            mb_strtolower($b['komponen']),
        ]);
        $group['kelas']=implode(', ',$group['kelas_list'])?:'Belum diatur';
    }
    unset($group);
    usort($result,static fn($a,$b)=>[
        (int)$a['tingkat'],mb_strtolower((string)$a['kelas']),mb_strtolower((string)$a['nama']),(string)$a['nis'],
    ]<=>[
        (int)$b['tingkat'],mb_strtolower((string)$b['kelas']),mb_strtolower((string)$b['nama']),(string)$b['nis'],
    ]);
    return $result;
}
/** Keep payment-only evidence visible inside the existing component cell. */
function report_billing_component_value(?array $component): mixed {
    if(!$component)return null;
    if(empty($component['legacy_payment_only']))return $component['tagihan'];
    return ['text'=>'Tagihan '.report_money($component['tagihan']),
        'sub'=>'Dibayar '.report_money($component['terbayar'])."\nSisa ".report_money($component['sisa'])];
}
function report_billing_history_component_columns(array $groups): array {
    $columns=[];
    foreach($groups as $student){
        foreach($student['components']??[] as $component){
            $key=(string)$component['komponen_key'];
            if(!isset($columns[$key]))$columns[$key]=[
                'komponen_key'=>$key,
                'komponen'=>(string)$component['komponen'],
            ];
        }
    }
    $result=array_values($columns);
    usort($result,static fn($a,$b)=>[
        report_billing_history_component_order($a['komponen_key']),
        mb_strtolower($a['komponen']),
    ]<=>[
        report_billing_history_component_order($b['komponen_key']),
        mb_strtolower($b['komponen']),
    ]);
    return $result;
}
function report_billing_history_data(mysqli $db,array $f):array{
    $legacyPayments=report_legacy_periods($db)['payments'];
    $rows=[];$existing=[];$studentWhere=report_student_status_where($f);
    $append=static function(array $row)use(&$rows,&$existing,$f):void{
        // Index issued identities before display filters, including cancelled bills.
        $existing[report_billing_identity($row)]=true;
        if(!report_billing_date_matches((string)($row['tanggal_dibuat']??''),$f))return;
        if(isset($f['_multi']['komponen_tagihan'])&&!filter_is_all($f['_multi']['komponen_tagihan'])&&!in_array($row['komponen_key'],$f['_multi']['komponen_tagihan'],true))return;
        if($f['komponen_tagihan']!==''&&$row['komponen_key']!==$f['komponen_tagihan'])return;
        if(!report_class_matches_row($f,$row))return;
        // Keep the issued amount for the audit trail, while a cancelled bill
        // no longer contributes to a student's outstanding obligation.
        if(($row['status']??'')==='Dibatalkan')$row['sisa']=0.0;
        $row['_status']=$row['status'];
        if(report_filter_rows([$row],$f))$rows[]=$row;
    };
    $psbFilters=$f;$psbFilters['kategori']='psb';$psbFilters['status']='';unset($psbFilters['_multi']['status']);
    foreach(report_annual_evidence($db,$psbFilters)['rows'] as $item){
        if(($item['tagihan']??0)<=.001&&$item['terbayar']<=.001)continue;
        $append($item+['komponen'=>'Uang PSB','komponen_key'=>'psb','tahun_ajaran'=>'','legacy_without_academic_year'=>true,'tanggal_perkiraan'=>false]);
    }
    $annualSql="SELECT t.no_induk nis,s.NO_induk_diknas nis_diknas,s.NAMA nama,t.kelas_rombel_snapshot kelas,COALESCE(sta.master_kelas_id,s.master_kelas_id) master_kelas_id,COALESCE(mk.tingkat,t.kelas_snapshot) tingkat,t.komponen,t.tahun_ajaran_snapshot tahun_ajaran,t.created_at tanggal_dibuat,t.nominal_tagihan tagihan,t.status bill_status,COALESCE(d.terbayar,0) terbayar FROM tagihan_tahunan_siswa t JOIN siswa s ON s.NO_INDUK=t.no_induk AND s.unit_id=t.unit_id LEFT JOIN siswa_tahun_ajaran sta ON sta.id=t.penempatan_id LEFT JOIN master_kelas mk ON mk.id=COALESCE(sta.master_kelas_id,s.master_kelas_id) LEFT JOIN (SELECT tagihan_tahunan_id,SUM(jumlah) terbayar FROM bayar_tahunan_siswa GROUP BY tagihan_tahunan_id) d ON d.tagihan_tahunan_id=t.id WHERE 1=1$studentWhere";
    foreach(report_scoped_query($db,hp_sql($db,$annualSql))->fetch_all(MYSQLI_ASSOC) as $item){$bill=(float)$item['tagihan'];$paid=(float)$item['terbayar'];$component=(string)$item['komponen'];$componentConfig=annual_fee_components()[$component]??['label'=>$component];$append(['nis'=>$item['nis'],'nis_diknas'=>$item['nis_diknas']??'','nama'=>$item['nama'],'kelas'=>$item['kelas']?:'Belum diatur','master_kelas_id'=>$item['master_kelas_id']??0,'tingkat'=>$item['tingkat']??'','komponen'=>$componentConfig['label'],'komponen_key'=>$component,'periode'=>$item['tahun_ajaran'],'tahun_ajaran'=>$item['tahun_ajaran'],'tanggal_dibuat'=>$item['tanggal_dibuat'],'tagihan'=>$bill,'terbayar'=>$paid,'sisa'=>max(0,$bill-$paid),'status'=>report_billing_status($bill,$paid,$item['bill_status'])]);}
    $komiteSql="SELECT tk.no_induk nis,s.NO_induk_diknas nis_diknas,s.NAMA nama,tk.kelas_rombel_snapshot kelas,sta.master_kelas_id,sta.kelas tingkat,tk.bulan,tk.tahun,ta.label tahun_ajaran,tk.created_at tanggal_dibuat,tk.nominal_tagihan tagihan,tk.status bill_status,COALESCE(bk.terbayar,0) terbayar FROM tagihan_komite tk JOIN siswa s ON s.NO_INDUK=tk.no_induk AND s.unit_id=tk.unit_id JOIN siswa_tahun_ajaran sta ON sta.id=tk.penempatan_id JOIN tahun_ajaran ta ON ta.id=tk.tahun_ajaran_id LEFT JOIN (SELECT tagihan_komite_id,SUM(nominal) terbayar FROM bayar_komite GROUP BY tagihan_komite_id) bk ON bk.tagihan_komite_id=tk.id WHERE 1=1$studentWhere";
    foreach(report_scoped_query($db,hp_sql($db,$komiteSql))->fetch_all(MYSQLI_ASSOC) as $item){$bill=(float)$item['tagihan'];$paid=(float)$item['terbayar'];$paid+=($legacyPayments[$item['nis']]['komite'][sprintf('%04d-%02d',(int)$item['tahun'],(int)$item['bulan'])]['paid']??0)/100;$append(['nis'=>$item['nis'],'nis_diknas'=>$item['nis_diknas']??'','nama'=>$item['nama'],'kelas'=>$item['kelas']?:'Belum diatur','master_kelas_id'=>$item['master_kelas_id']??0,'tingkat'=>$item['tingkat']??'','komponen'=>'Uang Komite','komponen_key'=>'komite','periode'=>(report_months()[str_pad($item['bulan'],2,'0',STR_PAD_LEFT)]??$item['bulan']).' '.$item['tahun'],'periode_code'=>sprintf('%04d-%02d',(int)$item['tahun'],(int)$item['bulan']),'tahun_ajaran'=>$item['tahun_ajaran'],'tanggal_dibuat'=>$item['tanggal_dibuat'],'tagihan'=>$bill,'terbayar'=>$paid,'sisa'=>max(0,$bill-$paid),'status'=>report_billing_status($bill,$paid,$item['bill_status'])]);}
    $duSql="SELECT t.no_induk nis,s.NO_induk_diknas nis_diknas,s.NAMA nama,COALESCE(NULLIF(sta.kelas_rombel_snapshot,''),CAST(t.kelas_snapshot AS CHAR)) kelas,sta.master_kelas_id,COALESCE(t.kelas_snapshot,sta.kelas) tingkat,t.tahun_ajaran_snapshot tahun_ajaran,t.created_at tanggal_dibuat,t.nominal_tagihan tagihan,t.status bill_status,COALESCE(d.terbayar,0) terbayar FROM tagihan_daftar_ulang t JOIN siswa s ON s.NO_INDUK=t.no_induk AND s.unit_id=t.unit_id LEFT JOIN siswa_tahun_ajaran sta ON sta.id=t.penempatan_id LEFT JOIN master_kelas mk ON mk.id=COALESCE(sta.master_kelas_id,s.master_kelas_id) LEFT JOIN (SELECT tagihan_daftar_ulang_id,SUM(jumlah) terbayar FROM bayar_du GROUP BY tagihan_daftar_ulang_id) d ON d.tagihan_daftar_ulang_id=t.id WHERE 1=1$studentWhere";
    foreach(report_scoped_query($db,hp_sql($db,$duSql))->fetch_all(MYSQLI_ASSOC) as $item){$bill=(float)$item['tagihan'];$paid=(float)$item['terbayar'];$paid+=($legacyPayments[$item['nis']]['daftar_ulang'][$item['tahun_ajaran']]['paid']??0)/100;$append(['nis'=>$item['nis'],'nis_diknas'=>$item['nis_diknas']??'','nama'=>$item['nama'],'kelas'=>$item['kelas']?:'Belum diatur','master_kelas_id'=>$item['master_kelas_id']??0,'tingkat'=>$item['tingkat']??'','komponen'=>'Daftar Ulang','komponen_key'=>'daftar_ulang','periode'=>$item['tahun_ajaran'],'tahun_ajaran'=>$item['tahun_ajaran'],'tanggal_dibuat'=>$item['tanggal_dibuat'],'tagihan'=>$bill,'terbayar'=>$paid,'sisa'=>max(0,$bill-$paid),'status'=>report_billing_status($bill,$paid,$item['bill_status'])]);}
    $otherSql="SELECT t.no_induk nis,s.NO_induk_diknas nis_diknas,s.NAMA nama,t.kelas_rombel_snapshot kelas,t.master_kelas_id,mk.tingkat,t.master_biaya_lain_id,t.nama_snapshot,t.nominal_tagihan tagihan,t.status bill_status,t.created_at,COALESCE(d.terbayar,0) terbayar FROM tagihan_biaya_lain t JOIN siswa s ON s.NO_INDUK=t.no_induk AND s.unit_id=t.unit_id LEFT JOIN master_kelas mk ON mk.id=t.master_kelas_id LEFT JOIN (SELECT tagihan_biaya_lain_id,SUM(nominal_snapshot) terbayar FROM bayar_biaya_lain GROUP BY tagihan_biaya_lain_id) d ON d.tagihan_biaya_lain_id=t.id WHERE 1=1$studentWhere";
    foreach(report_scoped_query($db,hp_sql($db,$otherSql))->fetch_all(MYSQLI_ASSOC) as $item){$time=strtotime((string)$item['created_at']);$yearLabel=$time?du_academic_year_label((int)date('m',$time),(int)date('Y',$time)):'';$bill=(float)$item['tagihan'];$paid=(float)$item['terbayar'];$append(['nis'=>$item['nis'],'nis_diknas'=>$item['nis_diknas']??'','nama'=>$item['nama'],'kelas'=>$item['kelas']?:class_label($item),'master_kelas_id'=>$item['master_kelas_id']??0,'tingkat'=>$item['tingkat']??'','komponen'=>$item['nama_snapshot'],'komponen_key'=>'biaya_lain:'.(int)$item['master_biaya_lain_id'],'periode'=>$time?'Diterbitkan '.report_date_label(date('Y-m-d',$time)):'Diterbitkan','tahun_ajaran'=>$yearLabel,'tanggal_dibuat'=>$item['created_at'],'tagihan'=>$bill,'terbayar'=>$paid,'sisa'=>max(0,$bill-$paid),'status'=>report_billing_status($bill,$paid,$item['bill_status'])]);}
    $sppSql="SELECT ts.no_induk nis,s.NO_induk_diknas nis_diknas,s.NAMA nama,ts.kelas_rombel_snapshot kelas,ts.master_kelas_id,ts.tingkat_snapshot tingkat,ts.bulan,ts.tahun,ts.created_at tanggal_dibuat,ts.nominal_tagihan tagihan,ts.status bill_status,ta.label tahun_ajaran,COALESCE(a.terbayar,0) terbayar FROM tagihan_spp ts JOIN siswa s ON s.NO_INDUK=ts.no_induk AND s.unit_id=ts.unit_id JOIN tahun_ajaran ta ON ta.id=ts.tahun_ajaran_id LEFT JOIN (SELECT a.tagihan_spp_id,SUM(CASE WHEN ab.status='active' THEN a.nominal_dari_bayar ELSE 0 END) terbayar FROM spp_alokasi a LEFT JOIN spp_alokasi_batch ab ON ab.id=a.batch_id GROUP BY a.tagihan_spp_id) a ON a.tagihan_spp_id=ts.id WHERE 1=1$studentWhere";
    foreach(report_scoped_query($db,hp_sql($db,$sppSql))->fetch_all(MYSQLI_ASSOC) as $item){$bill=(float)$item['tagihan'];$amount=(float)$item['terbayar']+($legacyPayments[$item['nis']]['spp'][sprintf('%04d-%02d',(int)$item['tahun'],(int)$item['bulan'])]['paid']??0)/100;$status=$item['bill_status']==='covered_psb'?'Tercakup Uang PSB':($item['bill_status']==='waived'?'Potongan Penuh':report_billing_status($bill,$amount,$item['bill_status']));$append(['nis'=>$item['nis'],'nis_diknas'=>$item['nis_diknas']??'','nama'=>$item['nama'],'kelas'=>$item['kelas']?:'Belum diatur','master_kelas_id'=>$item['master_kelas_id']??0,'tingkat'=>$item['tingkat']??'','komponen'=>'SPP','komponen_key'=>'spp','periode'=>(report_months()[str_pad($item['bulan'],2,'0',STR_PAD_LEFT)]??$item['bulan']).' '.$item['tahun'],'periode_code'=>sprintf('%04d-%02d',(int)$item['tahun'],(int)$item['bulan']),'tahun_ajaran'=>$item['tahun_ajaran'],'tanggal_dibuat'=>$item['tanggal_dibuat'],'tagihan'=>$bill,'terbayar'=>$amount,'sisa'=>max(0,$bill-$amount),'status'=>$status]);}
    $legacyRows=report_billing_legacy_rows($db,$f,$existing);
    $allComponents=$f['komponen_tagihan']===''&&(!isset($f['_multi']['komponen_tagihan'])||filter_is_all($f['_multi']['komponen_tagihan']));
    $allStatuses=$f['status']===''&&(!isset($f['_multi']['status'])||filter_is_all($f['_multi']['status']));
    // Legacy rows already apply class/date/student-state restrictions. Avoid
    // repeating the same all-status/all-component checks for every archive row.
    if($allComponents&&$allStatuses&&!(($f['student_id']??0)>0)&&(string)($f['q']??'')==='')$rows=array_merge($rows,$legacyRows);
    else foreach($legacyRows as $legacyRow)$append($legacyRow);
    report_billing_history_sort_rows($rows);
    $period=($f['tanggal_awal']??'')!==''?report_date_range_label($f['tanggal_awal'],$f['tanggal_akhir']):'Semua tanggal';
    return ['title'=>'Riwayat Tagihan Siswa','subtitle'=>'Tagihan dibuat: '.$period,'columns'=>[['nis','NIS','nis'],['nama','Nama Siswa'],['kelas','Kelas','kelas'],['komponen','Komponen'],['periode','Periode/Tahun Ajaran'],['tagihan','Tagihan','money_optional'],['terbayar','Sudah Dibayar','money'],['sisa','Sisa','money_optional'],['status','Status','status']],'rows'=>$rows];
}
function report_academic_year_start(string $label):?int{
    return preg_match('/^(\d{4})\/\d{4}$/',trim($label),$match)?(int)$match[1]:null;
}
function report_student_debt_groups(mysqli $db,array $filters,string $beforeAcademicYear='',array $targetNis=[],string $asOfDate=''):array{
    if (($GLOBALS['app_unit_id']??1)===0){
        $rows=[];try{foreach([1,2,3] as $owner){unit_set_context($db,$owner);$part=report_student_debt_groups($db,$filters,$beforeAcademicYear,$targetNis,$asOfDate);foreach($part as &$r)$r['unit_id']=$owner;unset($r);$rows=array_merge($rows,$part);}}finally{unit_set_context($db,0);}return $rows;
    }

    $sourceFilters=$filters;
    $sourceFilters['komponen_tagihan']='';unset($sourceFilters['_multi']['komponen_tagihan']);
    $sourceFilters['status']='';unset($sourceFilters['_multi']['status']);
    $currentStudents=[];
    if($asOfDate!==''){
        $sourceFilters['kelas']='';unset($sourceFilters['_multi']['kelas']);
        $result=report_scoped_query($db,'SELECT s.NO_INDUK,s.unit_id,s.NAMA,s.NO_induk_diknas,s.KELAS,s.master_kelas_id,s.is_active,mk.tingkat,mk.kode_rombel,mk.is_placeholder FROM siswa s LEFT JOIN master_kelas mk ON mk.id=s.master_kelas_id WHERE s.legacy_pending=0');
        while($student=$result->fetch_assoc()){
            $student['tingkat']=(int)($student['tingkat']??$student['KELAS']);
            $student['kelas']=$student['master_kelas_id']?class_label($student):(string)$student['KELAS'];
            $currentStudents[$student['NO_INDUK']]=$student;
        }
    }
    $targetMap=[];
    foreach($targetNis as $nis){$nis=trim((string)$nis);if($nis!=='')$targetMap[$nis]=true;}
    if($targetMap){
        $sourceFilters['q']='';
        $sourceFilters['kelas']='';unset($sourceFilters['_multi']['kelas']);
        $sourceFilters['siswa_status']='all';
    }
    $targetStart=report_academic_year_start($beforeAcademicYear);
    $asOfAcademicStart=$asOfDate!==''
        ? report_academic_year_start(du_academic_year_label((int)substr($asOfDate,5,2),(int)substr($asOfDate,0,4)))
        : null;
    $excludedStatuses=['Dibatalkan','Potongan Penuh','Tercakup Uang PSB'];
    $groups=[];
    foreach(report_billing_history_data($db,$sourceFilters)['rows'] as $row){
        $nis=(string)($row['nis']??'');
        if($targetMap&&!isset($targetMap[$nis]))continue;
        if($asOfDate!==''&&in_array((string)($row['komponen_key']??''),['spp','komite'],true)
            &&(string)($row['periode_code']??'')>substr($asOfDate,0,7))continue;
        if($asOfAcademicStart!==null
            &&($rowStart=report_academic_year_start((string)($row['tahun_ajaran']??'')))!==null
            &&$rowStart>$asOfAcademicStart)continue;
        if($asOfDate!==''&&(!isset($currentStudents[$nis])||!report_class_matches_row($filters,$currentStudents[$nis])))continue;
        $remaining=max(0,(float)($row['sisa']??0));
        if($remaining<=.001||in_array((string)($row['status']??''),$excludedStatuses,true))continue;
        if($targetStart!==null&&!($row['legacy_without_academic_year']??false)){
            $rowStart=report_academic_year_start((string)($row['tahun_ajaran']??''));
            if($rowStart!==null&&$rowStart>=$targetStart)continue;
        }
        if(!isset($groups[$nis])){
            $groups[$nis]=[
                'nis'=>$nis,
                'unit_id'=>(int)($currentStudents[$nis]['unit_id']??0),
                'nis_diknas'=>(string)($row['nis_diknas']??''),
                'nama'=>(string)($currentStudents[$nis]['NAMA']??$row['nama']??''),
                'kelas'=>(string)($currentStudents[$nis]['kelas']??$row['kelas']??'Belum diatur'),
                'master_kelas_id'=>(int)($currentStudents[$nis]['master_kelas_id']??$row['master_kelas_id']??0),
                'tingkat'=>(int)($currentStudents[$nis]['tingkat']??$row['tingkat']??0),
                'total_tagihan'=>0.0,
                'sudah_dibayar'=>0.0,
                'total_tunggakan'=>0.0,
                'items'=>[],
            ];
        }
        $groups[$nis]['known_count']=($groups[$nis]['known_count']??0)+($row['tagihan']!==null?1:0);
        $groups[$nis]['total_tagihan']+=(float)($row['tagihan']??0);
        $groups[$nis]['sudah_dibayar']+=(float)($row['terbayar']??0);
        $groups[$nis]['total_tunggakan']+=$remaining;
        $groups[$nis]['items'][]=$row;
    }
    $rows=array_values($groups);
    usort($rows,static fn($a,$b)=>[
        (int)$a['tingkat'],mb_strtolower((string)$a['kelas']),mb_strtolower((string)$a['nama']),(string)$a['nis'],
    ]<=>[
        (int)$b['tingkat'],mb_strtolower((string)$b['kelas']),mb_strtolower((string)$b['nama']),(string)$b['nis'],
    ]);
    return $rows;
}
function report_student_debt_data(mysqli $db,array $filters):array{
    $today=report_letter_today();
    return [
        'title'=>'Surat Laporan Tunggakan ke Kepala Sekolah',
        'subtitle'=>'Tunggakan sampai '.report_date_label($today),
        'as_of_date'=>$today,
        'columns'=>[
            ['nis','NIS','nis'],['nis_diknas','NIS Diknas'],['nama','Nama Siswa'],['kelas','Kelas/Rombel','kelas'],
            ['total_tagihan','Total Tagihan','money'],['sudah_dibayar','Sudah Dibayar','money'],['total_tunggakan','Total Tunggakan','money'],
        ],
        'rows'=>report_student_debt_groups($db,$filters,'',[],$today),
    ];
}
function report_principal_debt_rows(array $students):array{
    $groups=[];
    foreach($students as $student){
        $classId=(int)($student['master_kelas_id']??0);
        $level=(int)($student['tingkat']??0);
        $class=(string)($student['kelas']??'Belum diatur');
        $unitId=(int)($student['unit_id']??0);
        $key=$unitId.'|'.($classId>0?'id:'.$classId:'level:'.$level.'|'.$class);
        if(!isset($groups[$key]))$groups[$key]=[
            'kelas'=>$class,'tingkat'=>$level,'master_kelas_id'=>$classId,'unit_id'=>$unitId,
            'jumlah_siswa'=>0,'total_tunggakan'=>0.0,
        ];
        $groups[$key]['jumlah_siswa']++;
        $groups[$key]['total_tunggakan']+=(float)($student['total_tunggakan']??0);
    }
    $rows=array_values($groups);
    usort($rows,static fn($a,$b)=>[$a['tingkat'],mb_strtolower($a['kelas']),$a['master_kelas_id']]<=>[$b['tingkat'],mb_strtolower($b['kelas']),$b['master_kelas_id']]);
    return $rows;
}
function report_principal_debt_data(mysqli $db,array $filters):array{
    $today=report_letter_today();
    $filters['q']='';
    return [
        'title'=>'Surat Laporan Tunggakan ke Kepala Sekolah',
        'subtitle'=>'Tunggakan sampai '.report_date_label($today),
        'as_of_date'=>$today,
        'columns'=>[['kelas','Kelas/Rombel'],['jumlah_siswa','Siswa Menunggak'],['total_tunggakan','Total Tunggakan','money']],
        'rows'=>report_principal_debt_rows(report_student_debt_groups($db,$filters,'',[],$today)),
    ];
}
function report_principal_scope_label(array $filters,array $classes):string{
    $classFilter=(string)($filters['kelas']??'');
    if(isset($filters['_multi']['kelas'])&&!filter_is_all($filters['_multi']['kelas']))return implode(', ',array_map(static fn($value)=>$GLOBALS['spp_filter_choices']['kelas']['options'][$value]??$value,$filters['_multi']['kelas']));
    if($classFilter==='')return 'Seluruh Kelas/Rombel';
    if(($level=report_class_filter_level($filters))>0)return 'Seluruh Rombel Kelas '.$level;
    $rombelId=report_class_filter_rombel_id($filters);
    foreach($classes as $class)if((int)$class['id']===$rombelId)return 'Rombel '.($class['label']??class_label($class));
    return 'Rombel dipilih';
}
function report_prior_debt_summary(mysqli $db,string $targetAcademicYear,array $studentNis):array{
    $studentNis=array_values(array_unique(array_filter(array_map(static fn($nis)=>trim((string)$nis),$studentNis),static fn($nis)=>$nis!=='')));
    if(!$studentNis){
        return ['has_debt'=>false,'affected_students'=>0,'total_outstanding'=>0.0,'students'=>[]];
    }
    $filters=report_filters($db,[
        'siswa_status'=>'all','per_page'=>100,'tahun_ajaran'=>$targetAcademicYear,
    ]);
    $students=report_student_debt_groups($db,$filters,$targetAcademicYear,$studentNis);
    $total=0.0;
    foreach($students as &$student){
        $student['tunggakan']=(float)$student['total_tunggakan'];
        $total+=$student['tunggakan'];
    }
    unset($student);
    return [
        'has_debt'=>count($students)>0,
        'affected_students'=>count($students),
        'total_outstanding'=>$total,
        'students'=>$students,
    ];
}
function report_active_regular_student_nis(mysqli $db):array{
    $rows=report_scoped_query($db,"SELECT NO_INDUK FROM siswa WHERE is_active=1 AND CAST(KELAS AS UNSIGNED) " . ($GLOBALS['app_unit_id'] === 0 ? 'BETWEEN 1 AND 12' : report_query_level_sql()) . " ORDER BY NO_INDUK")->fetch_all(MYSQLI_ASSOC);
    return array_values(array_filter(array_map(static fn($row)=>(string)($row['NO_INDUK']??''),$rows)));
}
function report_settlement_component_summary(array $components,bool $merge=false): array {
    $order=['psb'=>20,'spp'=>50,'komite'=>60,'daftar_ulang'=>100,'potongan'=>900];
    $summary=[];
    foreach($components as $component){
        $key=(string)($component['kategori_key']??'lainnya');
        $name=(string)($component['komponen']??'Komponen lain');
        $groupKey=$merge?$key:$key.'|'.$name;
        if(!isset($summary[$groupKey])){
            $summary[$groupKey]=[
                'komponen'=>$name,
                'jenis'=>$key==='potongan'?'Penyesuaian':(str_starts_with($key,'biaya_lain')?'Biaya Lain':'Pembayaran'),
                'nominal'=>0.0,
                '_order'=>$order[$key]??(str_starts_with($key,'biaya_lain')?500:700),
            ];
        }
        $summary[$groupKey]['nominal']+=(float)($component['nominal']??0);
    }
    $rows=array_values($summary);
    usort($rows,static fn($a,$b)=>[$a['_order'],$a['komponen']]<=>[$b['_order'],$b['komponen']]);
    foreach($rows as &$row)unset($row['_order']);
    unset($row);
    return $rows;
}
function report_settlement_data(mysqli $db,array $f):array{
    $components=report_payment_components($db,$f,!empty($f['_global_report']));if(empty($f['_global_report']))$components=array_values(array_filter($components,static fn($r)=>empty($r['legacy'])));
    if(!empty($f['_global_report'])){$labels=report_categories($db);foreach($components as &$c){$name=$labels[$c['kategori_key']]??$c['komponen'];$name=preg_replace('/^Biaya Lain /','',$name);$c['komponen']=preg_replace('/ \(Legacy\)$/','',$name);}unset($c);}
    $componentSummary=report_settlement_component_summary($components,!empty($f['_global_report']));
    $payByMethod=['Tunai'=>0,'VA'=>0,'Qris'=>0];
    foreach($components as $row)$payByMethod[$row['metode']]=($payByMethod[$row['metode']]??0)+(float)$row['nominal'];
    $paymentTotal=array_sum(array_column($componentSummary,'nominal'));
    $methodSummary=[
        ['metode'=>'Tunai','nominal'=>$payByMethod['Tunai']??0],
        ['metode'=>'Virtual Account','nominal'=>$payByMethod['VA']??0],
        ['metode'=>'QRIS','nominal'=>$payByMethod['Qris']??0],
    ];
    $unclassified=array_sum($payByMethod)-($payByMethod['Tunai']??0)-($payByMethod['VA']??0)-($payByMethod['Qris']??0);if(abs($unclassified)>.001)$methodSummary[]=['metode'=>'Metode tidak tercatat','nominal'=>$unclassified];
    $cash=array_sum($payByMethod);
    $rows=[
        ['bagian'=>'Pembayaran Tunai','nominal'=>$payByMethod['Tunai']??0],
        ['bagian'=>'Pembayaran Virtual Account','nominal'=>$payByMethod['VA']??0],
        ['bagian'=>'Pembayaran QRIS','nominal'=>$payByMethod['Qris']??0],
        ['bagian'=>'Total Setoran','nominal'=>$cash],
    ];
    if(abs($unclassified)>.001)array_splice($rows,3,0,[['bagian'=>'Metode tidak tercatat','nominal'=>$unclassified]]);
    return [
        'title'=>'Rekap Setoran Kas Harian',
        'subtitle'=>report_date_range_label($f['tanggal_awal'],$f['tanggal_akhir']).' - ringkasan penerimaan pembayaran',
        'columns'=>[['bagian','Arus Kas'],['nominal','Nominal','money']],
        'rows'=>$rows,
        'component_summary'=>$componentSummary,
        'component_rows'=>$componentSummary,
        'component_total'=>$paymentTotal,
        'total_setoran'=>$cash,
        'payment_total'=>$paymentTotal,
        'method_summary'=>$methodSummary,
        'settlement'=>['cash'=>$cash,'payment_count'=>count(array_unique(array_column($components,'id')))]];
}

function report_savings_cash_summary(array $transactions,bool $includeHistory=false):array{
    $totalIn=0.0;$totalOut=0.0;$countIn=0;$countOut=0;
    foreach($transactions as $transaction){
        if(!$includeHistory&&(int)($transaction['id']??0)<0&&($transaction['legacy_origin']??'legacy')!=='new')continue;
        if(($transaction['delta_opening']??0)!=0||in_array($transaction['jenis']??'',['Saldo Awal','Penyesuaian Saldo Migrasi'],true))continue;
        $in=(float)($transaction['masuk']??0);$out=(float)($transaction['keluar']??0);
        $type=mb_strtolower((string)($transaction['jenis']??''));
        $totalIn+=$in;$totalOut+=$out;
        if($type==='masuk'||($type===''&&$in>0))$countIn++;
        if($type==='keluar'||($type===''&&$out>0))$countOut++;
    }
    return [
        'total_masuk'=>$totalIn,
        'total_keluar'=>$totalOut,
        'mutasi_bersih'=>$totalIn-$totalOut,
        'count_masuk'=>$countIn,
        'count_keluar'=>$countOut,
        'count_total'=>$countIn+$countOut,
    ];
}

function report_savings_cash_data(mysqli $db,array $f):array{
    $f['_school_savings_cash']=true;
    $start=$f['tanggal_awal'].' 00:00:00';
    $end=date('Y-m-d H:i:s',strtotime($f['tanggal_akhir'].' +1 day'));
    $cashRows=report_savings_transactions($db,$start,$end,$f);$summary=report_savings_cash_summary($cashRows,!empty($f['_global_report']));$studentSummary=report_savings_cash_summary(array_values(array_filter($cashRows,fn($r)=>($r['owner_type']??'student')==='student')),!empty($f['_global_report']));$accountSummary=report_savings_cash_summary(array_values(array_filter($cashRows,fn($r)=>($r['owner_type']??'student')==='account')),!empty($f['_global_report']));
    return [
        'title'=>'Rekap Kas Tabungan Harian',
        'subtitle'=>report_date_range_label($f['tanggal_awal'],$f['tanggal_akhir']).' - ringkasan mutasi tabungan',
        'columns'=>[['bagian','Arus Tabungan'],['nominal','Nominal','money']],
        'rows'=>[
            ['bagian'=>'Tabungan Masuk','nominal'=>$summary['total_masuk']],
            ['bagian'=>'Tabungan Keluar','nominal'=>$summary['total_keluar']],
            ['bagian'=>'Mutasi Bersih','nominal'=>$summary['mutasi_bersih']],
            ['bagian'=>'Rincian · Setoran Siswa','nominal'=>$studentSummary['total_masuk']],
            ['bagian'=>'Rincian · Penarikan Siswa','nominal'=>$studentSummary['total_keluar']],
            ['bagian'=>'Rincian · Setoran Non-siswa','nominal'=>$accountSummary['total_masuk']],
            ['bagian'=>'Rincian · Penarikan Non-siswa','nominal'=>$accountSummary['total_keluar']],
        ],
        'component_rows'=>[
            ['komponen'=>'Tabungan Masuk','nominal'=>$summary['total_masuk']],
            ['komponen'=>'Tabungan Keluar','nominal'=>$summary['total_keluar']],
        ],
        'component_total'=>$summary['mutasi_bersih'],
        'total_masuk'=>$summary['total_masuk'],
        'total_keluar'=>$summary['total_keluar'],
        'mutasi_bersih'=>$summary['mutasi_bersih'],
        'transaction_summary'=>[
            ['label'=>'Transaksi Masuk','value'=>$summary['count_masuk']],
            ['label'=>'Transaksi Keluar','value'=>$summary['count_keluar']],
            ['label'=>'Total Transaksi','value'=>$summary['count_total']],
        ],
        'settlement'=>[
            'saving_in_count'=>$summary['count_masuk'],
            'saving_out_count'=>$summary['count_keluar'],
            'transaction_count'=>$summary['count_total'],
        ],
    ];
}



/** Combine read reports only after each unit has calculated its own identities and snapshots. */
function report_merge_numbers(array $a,array $b):array {
    foreach($b as $key=>$value){
        if(is_array($value)) $a[$key]=report_merge_numbers($a[$key]??[],$value);
        elseif(is_int($value)||is_float($value)) $a[$key]=($a[$key]??0)+$value;
        elseif(!isset($a[$key])) $a[$key]=$value;
    }
    return $a;
}
function report_merge_summary_rows(array $a,array $b):array {
    $out=[];
    foreach(array_merge($a,$b) as $row){$identity=[];foreach($row as $key=>$value)if(!is_numeric($value))$identity[$key]=$value;$key=json_encode($identity);$out[$key]=report_merge_numbers($out[$key]??[],$row);}
    return array_values($out);
}
function report_build(mysqli $db,string $template,array $filters):array{
    if(in_array($template,global_report_templates(),true))$filters['_global_report']=true;
    if($template==='tabungan-siswa')$filters['_savings_operator']=true;
    $multiple=report_multiple_sections($db,$template,$filters);if($multiple!==null)return $multiple;
    if (($GLOBALS['app_unit_id']??1)===0) {
        $combined=null;
        $categoryLabels=in_array($template,['status','per-item'],true)?report_categories($db):[];
        if(in_array($template,['penerimaan','setoran'],true))payment_time_index($db);
        if(in_array($template,['status','spp-tahunan','per-item','riwayat-tagihan','tunggakan-siswa'],true))lb_cache($db);
        try {
            foreach([1,2,3] as $owner){
                if(($filters['student_unit']??0)>0&&(int)$filters['student_unit']!==$owner)continue;
                unit_set_context($db,$owner);
                $part=report_build($db,$template,$filters);
                foreach($part['rows'] as &$row){$row['unit_id']=$owner;$row['unit']=unit_label($owner);if(isset($row['kelas'])){$row['kelas_asli']=$row['kelas'];$row['kelas']=unit_label($owner).' · '.$row['kelas'];}}unset($row);
                if($combined===null){$combined=$part;array_unshift($combined['columns'],['unit','Unit']);continue;}
                $combined['rows']=array_merge($combined['rows'],$part['rows']);
                $columnKeys=array_column($combined['columns'],0);
                foreach($part['columns'] as $column)if(!in_array($column[0],$columnKeys,true)){$combined['columns'][]=$column;$columnKeys[]=$column[0];}
                if(isset($part['receipt_components']))$combined['receipt_components']=array_merge($combined['receipt_components']??[],$part['receipt_components']);
                foreach(['component_total','total_setoran','payment_total','total_masuk','total_keluar','mutasi_bersih','legacy_undated_count'] as $key)if(isset($part[$key]))$combined[$key]=($combined[$key]??0)+$part[$key];
                foreach(['component_summary','component_rows','method_summary','transaction_summary'] as $key)if(isset($part[$key]))$combined[$key]=report_merge_summary_rows($combined[$key]??[],$part[$key]);
                if(isset($part['settlement']))$combined['settlement']=report_merge_numbers($combined['settlement']??[],$part['settlement']);
            }
        } finally {unit_set_context($db,0);}
        if($template==='penerimaan'){
            $order=array_flip(array_merge(['unit','nis','nama','kelas'],array_keys(report_categories($db)),['potongan','total_penerimaan']));
            usort($combined['columns'],static fn($a,$b)=>($order[$a[0]]??PHP_INT_MAX)<=>($order[$b[0]]??PHP_INT_MAX));
        }
        if(isset($categoryLabels[$filters['kategori']])){if($template==='status')$combined['title']=$categoryLabels[$filters['kategori']];if($template==='per-item')$combined['title']='Rekap Pembayaran per Item: '.$categoryLabels[$filters['kategori']];}
        $combined=report_evidence_notes($combined,$template,$filters);return $combined;
    }

    $report=match($template){'status'=>report_status_data($db,$filters),'penerimaan'=>report_receipt_data($db,$filters),'spp-tahunan'=>report_spp_year_data($db,$filters),'per-item'=>report_item_data($db,$filters),'tabungan-siswa'=>report_savings_student_data($db,$filters),'saldo-tabungan'=>report_savings_balance_data($db,$filters),'riwayat-tagihan'=>report_billing_history_data($db,$filters),'tunggakan-siswa'=>report_principal_debt_data($db,$filters),'setoran'=>report_settlement_data($db,$filters),'kas-tabungan'=>report_savings_cash_data($db,$filters),default=>throw new InvalidArgumentException('Template laporan tidak dikenali.')};
    $report=report_evidence_notes($report,$template,$filters);
    return $report;
}
function report_savings_transaction_totals(array $rows): array {
    $masuk=0;$keluar=0;foreach($rows as $row){$masuk+=(float)($row['masuk']??0);$keluar+=(float)($row['keluar']??0);}return ['total_masuk'=>$masuk,'total_keluar'=>$keluar,'selisih'=>$masuk-$keluar];
}
function report_money_totals(array $report, string $template=''): array {
    if(isset($report['sections'])){
        $totals=[];foreach($report['sections'] as $part)foreach(report_money_totals($part,$template) as $total){
            $key=$total['key']??$total['label'];$key=['total_tagihan'=>'tagihan','total_bayar'=>'terbayar','tunggakan'=>'sisa'][$key]??$key;
            if(!isset($totals[$key]))$totals[$key]=['key'=>$key,'label'=>['tagihan'=>'Total Tagihan','terbayar'=>'Total Dibayar','sisa'=>'Total Sisa'][$key]??$total['label'],'value'=>0];$totals[$key]['value']+=$total['value'];
        }return array_values($totals);
    }
    if($template==='setoran'){
        return [['label'=>'Total Setoran','value'=>(float)($report['total_setoran']??0)]];
    }
    if($template==='kas-tabungan'){
        return [
            ['label'=>'Total Tabungan Masuk','value'=>(float)($report['total_masuk']??0)],
            ['label'=>'Total Tabungan Keluar','value'=>(float)($report['total_keluar']??0)],
            ['label'=>'Mutasi Bersih','value'=>(float)($report['mutasi_bersih']??0)],
        ];
    }
    $totals=[];
    foreach($report['columns']??[] as $column){
        $key=$column[0]??'';$label=$column[1]??$key;$type=$column[2]??'text';
        if(!in_array($type,['money','money_optional'],true)||$key==='')continue;
        if($template==='tabungan-siswa'&&$key==='saldo_saat_ini')continue;
        $sum=0;$found=false;
        foreach($report['rows']??[] as $row){
            if(!array_key_exists($key,$row)||$row[$key]===null||$row[$key]==='')continue;
            if(!is_numeric($row[$key]))continue;
            $sum+=(float)$row[$key];$found=true;
        }
        $totalLabel=strcasecmp((string)$label,'Total')===0?'Total Penerimaan':(preg_match('/^Total\b/i',(string)$label)?(string)$label:'Total '.$label);
        if($found)$totals[]=['label'=>$totalLabel,'value'=>$sum,'key'=>$key];
    }
    if($template==='tabungan-siswa'){
        $masuk=0;$keluar=0;
        foreach($totals as $total){
            if(($total['key']??'')==='masuk')$masuk=(float)$total['value'];
            if(($total['key']??'')==='keluar')$keluar=(float)$total['value'];
        }
        if($masuk!==0.0||$keluar!==0.0)$totals[]=['label'=>'Total Bersih','value'=>$masuk-$keluar,'key'=>'total_bersih'];
    }
    return $totals;
}
function report_summaries(array $rows):array{
    $moneyKeys=['nominal','total_penerimaan','masuk','keluar','terbayar','tagihan','sisa','total_bayar','tunggakan','saldo_awal','total_masuk','total_keluar','saldo_akhir'];$summary=['Baris'=>count($rows)];foreach($moneyKeys as $key){$sum=0;$found=false;foreach($rows as $row)if(isset($row[$key])&&is_numeric($row[$key])){$sum+=(float)$row[$key];$found=true;}if($found)$summary[ucwords(str_replace('_',' ',$key))]=report_money($sum);}return array_slice($summary,0,5,true);
}
