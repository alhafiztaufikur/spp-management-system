<?php

require_once __DIR__ . '/daftar_ulang.php';
require_once __DIR__ . '/kelas.php';
require_once __DIR__ . '/tagihan_tahunan.php';

function report_e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function report_money($value): string { return 'Rp ' . number_format((float)$value, 0, ',', '.'); }
function report_months(): array { return ['01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April','05'=>'Mei','06'=>'Juni','07'=>'Juli','08'=>'Agustus','09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember']; }
function report_month_code($value): string {
    $names = array_flip(report_months());
    if (isset($names[$value])) return $names[$value];
    $month = (int)$value;
    return $month >= 1 && $month <= 12 ? str_pad((string)$month, 2, '0', STR_PAD_LEFT) : date('m');
}
function report_date_label(string $date):string{$time=strtotime($date);if(!$time)return $date;return date('d',$time).' '.(report_months()[date('m',$time)]??date('m',$time)).' '.date('Y',$time);}
function report_date_range_label(string $start,string $end):string{return $start===$end?report_date_label($start):report_date_label($start).' – '.report_date_label($end);}
function report_sql_month_expr(string $column): string {
    return "CASE LOWER($column) WHEN 'januari' THEN 1 WHEN 'februari' THEN 2 WHEN 'maret' THEN 3 WHEN 'april' THEN 4 WHEN 'mei' THEN 5 WHEN 'juni' THEN 6 WHEN 'juli' THEN 7 WHEN 'agustus' THEN 8 WHEN 'september' THEN 9 WHEN 'oktober' THEN 10 WHEN 'november' THEN 11 WHEN 'desember' THEN 12 ELSE CAST($column AS UNSIGNED) END";
}
function report_registry(): array {
    return [
        'status' => ['label'=>'Laporan Status Pembayaran','description'=>'Membantu admin melihat siapa yang sudah lunas, masih cicilan, atau belum membayar pada kewajiban yang dipilih.','icon'=>'OK','orientation'=>'portrait'],
        'penerimaan' => ['label'=>'Rekap Penerimaan Harian','description'=>'Menampilkan nominal pembayaran yang diterima kasir per siswa dan per komponen pada tanggal yang dipilih.','icon'=>'RP','orientation'=>'landscape'],
        'spp-tahunan' => ['label'=>'Rekap SPP Tahun Ajaran per Kelas','description'=>'Memudahkan pengecekan SPP Juli sampai Juni untuk melihat bulan yang sudah lunas dan yang masih tertunggak.','icon'=>'SPP','orientation'=>'landscape'],
        'per-item' => ['label'=>'Rekap Pembayaran per Item','description'=>'Menampilkan kondisi satu jenis pembayaran agar admin cepat mengecek tagihan, pembayaran, dan sisa per siswa.','icon'=>'ITEM','orientation'=>'landscape'],
        'tabungan-siswa' => ['label'=>'Rekap Transaksi Tabungan Siswa','description'=>'Daftar transaksi tabungan masuk dan keluar sesuai tanggal, siswa, rombel, dan kasir yang dipilih.','icon'=>'TAB','orientation'=>'portrait'],
        'saldo-tabungan' => ['label'=>'Rekap Saldo Tabungan','description'=>'Menampilkan saldo tabungan terkini setiap siswa agar admin dapat mengecek saldo tanpa membuka riwayat transaksi.','icon'=>'SAL','orientation'=>'portrait'],
        'riwayat-tagihan' => ['label'=>'Riwayat Tagihan Siswa','description'=>'Menampilkan seluruh tagihan SPP, biaya tahunan, daftar ulang, dan biaya lain beserta status pembayarannya.','icon'=>'TAG','orientation'=>'landscape'],
        'setoran' => ['label'=>'Rekap Setoran Kas Harian','description'=>'Ringkasan penerimaan pembayaran dan mutasi tabungan untuk membantu pengecekan kas harian kasir.','icon'=>'KAS','orientation'=>'portrait'],
    ];
}
function report_date_value($value, string $fallback): string {
    $value = trim((string)$value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : $fallback;
}
function report_filters(mysqli $db, array $source): array {
    $today = date('Y-m-d');
    $start = report_date_value($source['tanggal_awal'] ?? '', $today);
    $end = report_date_value($source['tanggal_akhir'] ?? '', $start);
    if ($start > $end) [$start,$end]=[$end,$start];
    $allowedStatus = ['','tidak_ditagihkan','belum_bayar','cicilan','lunas','rekonsiliasi','ada_pembayaran','tunggakan','dibatalkan'];
    $allowedMethods = ['','Tunai','VA','Qris'];
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
    $operator = report_operator_filter_value($db, $requestedOperator);
    return [
        'tanggal_awal'=>$start,'tanggal_akhir'=>$end,
        'tahun_ajaran'=>$academicYear,
        'bulan_awal'=>report_month_code($source['bulan_awal'] ?? date('m')),
        'bulan_akhir'=>report_month_code($source['bulan_akhir'] ?? ($source['bulan_awal'] ?? date('m'))),
        'tahun'=>(int)($source['tahun'] ?? date('Y')),
        'tahun_awal'=>(int)($source['tahun_awal'] ?? ($source['tahun'] ?? date('Y'))),
        'tahun_akhir'=>(int)($source['tahun_akhir'] ?? ($source['tahun_awal'] ?? ($source['tahun'] ?? date('Y')))),
        'tahun_tagihan'=>preg_match('/^\d{4}\/\d{4}$/', (string)($source['tahun_tagihan'] ?? '')) ? (string)$source['tahun_tagihan'] : '',
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
function report_years(mysqli $db): array { return $db->query('SELECT label,status FROM tahun_ajaran ORDER BY label DESC')->fetch_all(MYSQLI_ASSOC); }
function report_class_filter_value($value): string {
    $value = trim((string)$value);
    if ($value === '' || $value === '0') return '';
    if (preg_match('/^tingkat:([1-6])$/', $value, $match)) return 'tingkat:' . $match[1];
    if (preg_match('/^rombel:(\d+)$/', $value, $match)) return 'rombel:' . (int)$match[1];
    if (ctype_digit($value) && (int)$value > 0) return 'rombel:' . (int)$value;
    return '';
}
function report_class_filter_level(array $filters): int {
    return preg_match('/^tingkat:([1-6])$/', (string)($filters['kelas'] ?? ''), $match) ? (int)$match[1] : 0;
}
function report_class_filter_rombel_id(array $filters): int {
    return preg_match('/^rombel:(\d+)$/', (string)($filters['kelas'] ?? ''), $match) ? (int)$match[1] : 0;
}
function report_class_where(array $filters, string $alias='sta', string $levelColumn='kelas', string $rombelColumn='master_kelas_id'): string {
    $rombelId = report_class_filter_rombel_id($filters);
    if ($rombelId > 0) return " AND {$alias}.{$rombelColumn}=" . $rombelId;
    $level = report_class_filter_level($filters);
    return $level > 0 ? " AND CAST({$alias}.{$levelColumn} AS UNSIGNED)=" . $level : '';
}
function report_class_matches_row(array $filters, array $row, string $rombelKey='master_kelas_id', string $levelKey='tingkat'): bool {
    $rombelId = report_class_filter_rombel_id($filters);
    if ($rombelId > 0) return (int)($row[$rombelKey] ?? 0) === $rombelId;
    $level = report_class_filter_level($filters);
    if ($level <= 0) return true;
    $rowLevel = (string)($row[$levelKey] ?? $row['KELAS'] ?? $row['kelas'] ?? '');
    if (preg_match('/[1-6]/', $rowLevel, $match)) return (int)$match[0] === $level;
    return false;
}
function report_allowed_operator_roles(): array {
    return ($_SESSION['admin_role'] ?? '') === 'admin' ? ['admin', 'kasir'] : ['kasir'];
}
function report_operator_filter_label(): string {
    return ($_SESSION['admin_role'] ?? '') === 'admin' ? 'Semua admin/kasir' : 'Semua kasir';
}
function report_operators(mysqli $db, string $role = ''): array {
    if ($role !== '') {
        $stmt = $db->prepare('SELECT id,username,nama,role FROM admin WHERE role=? ORDER BY nama');
        $stmt->bind_param('s', $role);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
    return $db->query('SELECT id,username,nama,role FROM admin ORDER BY nama')->fetch_all(MYSQLI_ASSOC);
}
function report_operator_options(mysqli $db): array {
    $roles = report_allowed_operator_roles();
    $placeholders = implode(',', array_fill(0, count($roles), '?'));
    $stmt = $db->prepare("SELECT id,username,nama,role FROM admin WHERE role IN ($placeholders) ORDER BY FIELD(role,'admin','kasir'), nama");
    $types = str_repeat('s', count($roles));
    $stmt->bind_param($types, ...$roles);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}
function report_operator_filter_value(mysqli $db, string $operator): string {
    $operator = trim($operator);
    if ($operator === '') return '';
    $roles = report_allowed_operator_roles();
    $placeholders = implode(',', array_fill(0, count($roles), '?'));
    $stmt = $db->prepare("SELECT id FROM admin WHERE role IN ($placeholders) AND (CAST(id AS CHAR)=? OR username=? OR nama=?) LIMIT 1");
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
        'spp'=>'SPP','komite'=>'Komite','daftar_ulang'=>'Daftar Ulang','pangkal'=>'Uang Pangkal',
        'bangunan'=>'Uang Bangunan','seragam'=>'Uang Seragam','kegiatan'=>'Uang Kegiatan',
        'makan'=>'Uang Makan','sorga'=>'Uang Sorga','infaq'=>'Uang Infaq',
    ];
    foreach($db->query('SELECT id,nama FROM master_biaya_lain ORDER BY nama')->fetch_all(MYSQLI_ASSOC) as $row) {
        $items['biaya_lain:'.$row['id']] = 'Biaya Lain '.$row['nama'];
    }
    return $items;
}
function report_status_name(float $bill, float $paid): string {
    if ($bill <= 0 && $paid <= 0) return 'Tidak Ditagihkan';
    if ($bill <= 0 && $paid > 0) return 'Perlu Rekonsiliasi';
    if ($paid <= .001) return 'Belum Bayar';
    if ($paid + .001 < $bill) return 'Cicilan';
    if ($paid > $bill + .001) return 'Perlu Rekonsiliasi';
    return 'Lunas';
}
function report_status_key(string $status): string { return ['Tidak Ditagihkan'=>'tidak_ditagihkan','Belum Bayar'=>'belum_bayar','Cicilan'=>'cicilan','Lunas'=>'lunas','Perlu Rekonsiliasi'=>'rekonsiliasi','Dibatalkan'=>'dibatalkan'][$status] ?? ''; }
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
function report_operator_join(string $userExpression, string $alias = 'op'): string {
    return " LEFT JOIN admin $alias ON CAST($alias.id AS CHAR)=CAST($userExpression AS CHAR) OR $alias.username=CAST($userExpression AS CHAR) OR $alias.nama=CAST($userExpression AS CHAR)";
}
function report_operator_where(string $userExpression, string $alias = 'op'): string {
    return "(CAST($userExpression AS CHAR)=? OR CAST($alias.id AS CHAR)=? OR $alias.username=? OR $alias.nama=?)";
}
function report_operator_params(string $operator): array {
    return [$operator, $operator, $operator, $operator];
}
function report_filter_rows(array $rows, array $filters): array {
    return array_values(array_filter($rows, static function($row) use($filters) {
        $status = (string)($row['_status'] ?? ''); $key=report_status_key($status);
        if ($filters['status']==='ada_pembayaran' && !in_array($key,['cicilan','lunas'],true)) return false;
        if ($filters['status']==='tunggakan' && !in_array($key,['belum_bayar','cicilan'],true)) return false;
        if ($filters['status']!=='' && !in_array($filters['status'],['ada_pembayaran','tunggakan'],true) && $filters['status']!==$key) return false;
        if (!report_row_matches_query($row, $filters['q'])) return false;
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
    return match($filters['siswa_status']){'all'=>'','archived'=>" AND ($placement.status<>'aktif' OR $student.is_active=0)",default=>" AND $placement.status='aktif' AND $student.is_active=1"};
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
      JOIN siswa s ON s.NO_INDUK = sta.no_induk
      LEFT JOIN tagihan_tahunan_siswa t ON t.tahun_ajaran_id = ta.id
        AND t.no_induk = sta.no_induk AND t.komponen = ? AND t.status = 'open'
      LEFT JOIN bayar_tahunan_siswa d ON d.tagihan_tahunan_id = t.id
      WHERE ta.label = ?" . report_student_status_where($f) . report_class_where($f, 'sta') . "
      GROUP BY sta.id, s.NO_induk_diknas, s.NAMA, sta.kelas_rombel_snapshot, t.nominal_tagihan
      ORDER BY sta.kelas_rombel_snapshot, s.NAMA";
    $stmt = $db->prepare($sql);
    $stmt->bind_param('ss', $component, $f['tahun_ajaran']);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function report_status_data(mysqli $db, array $f): array {
    $category=$f['kategori']; $rows=[]; $period=''; $label=report_categories($db)[$category]??'Pembayaran';
    if ($category === 'spp') {
        [$month,$year]=report_period_from_academic($f['tahun_ajaran'],$f['bulan_awal']);
        $sql="SELECT sta.no_induk,s.NO_induk_diknas nis_diknas,s.NAMA nama,sta.kelas_rombel_snapshot kelas,sta.spp_perbulan_snapshot tagihan,COALESCE(SUM(b.U_SPP),0) terbayar
            FROM siswa_tahun_ajaran sta JOIN tahun_ajaran ta ON ta.id=sta.tahun_ajaran_id JOIN siswa s ON s.NO_INDUK=sta.no_induk
            LEFT JOIN bayar_spp_periode bsp ON bsp.no_induk=sta.no_induk AND bsp.tahun=? AND bsp.bulan=? LEFT JOIN bayar b ON b.id=bsp.bayar_id
            WHERE ta.label=?".report_student_status_where($f).report_class_where($f)." GROUP BY sta.id,s.NAMA,s.NO_induk_diknas ORDER BY sta.kelas_rombel_snapshot,s.NAMA";
        $stmt=$db->prepare($sql); $stmt->bind_param('sss',$year,$month,$f['tahun_ajaran']); $stmt->execute(); $data=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
        $period=(report_months()[$month]??$month).' '.$year;
    } elseif (array_key_exists($category, annual_fee_components())) {
        $data = report_annual_fee_status_rows($db, $f, $category);
        $period = $f['tahun_ajaran'];
    } elseif ($category==='daftar_ulang') {
        $sql="SELECT sta.no_induk,s.NO_induk_diknas nis_diknas,s.NAMA nama,sta.kelas_rombel_snapshot kelas,COALESCE(t.nominal_tagihan,0) tagihan,COALESCE(SUM(d.jumlah),0) terbayar
          FROM siswa_tahun_ajaran sta JOIN tahun_ajaran ta ON ta.id=sta.tahun_ajaran_id JOIN siswa s ON s.NO_INDUK=sta.no_induk
          LEFT JOIN tagihan_daftar_ulang t ON t.penempatan_id=sta.id AND t.status='open' LEFT JOIN bayar_du d ON d.tagihan_daftar_ulang_id=t.id
          WHERE ta.label=?".report_student_status_where($f).report_class_where($f,'sta')." GROUP BY sta.id,s.NAMA,s.NO_induk_diknas,sta.kelas_rombel_snapshot,t.nominal_tagihan ORDER BY kelas,s.NAMA";
        $stmt=$db->prepare($sql);$stmt->bind_param('s',$f['tahun_ajaran']);$stmt->execute();$data=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();$period=$f['tahun_ajaran'];
    } elseif (str_starts_with($category,'biaya_lain:')) {
        $masterId=(int)substr($category,11);
        $sql="SELECT s.NO_INDUK no_induk,s.NO_induk_diknas nis_diknas,s.NAMA nama,COALESCE(t.kelas_rombel_snapshot,CASE WHEN mk.is_placeholder=1 THEN CONCAT('Kelas ',mk.tingkat,' (Belum Ditentukan)') ELSE CONCAT(mk.tingkat,UPPER(mk.kode_rombel)) END) kelas,
          COALESCE(t.nominal_tagihan,0) tagihan,COALESCE(SUM(d.nominal_snapshot),0) terbayar
          FROM siswa s LEFT JOIN master_kelas mk ON mk.id=s.master_kelas_id LEFT JOIN tagihan_biaya_lain t ON t.no_induk=s.NO_INDUK AND t.master_biaya_lain_id=? AND t.status='open'
          LEFT JOIN bayar_biaya_lain d ON d.tagihan_biaya_lain_id=t.id WHERE 1=1".($f['siswa_status']==='active'?' AND s.is_active=1':($f['siswa_status']==='archived'?' AND s.is_active=0':'')).report_class_where($f,'s','KELAS')." GROUP BY s.id,t.id ORDER BY kelas,s.NAMA";
        $stmt=$db->prepare($sql);$stmt->bind_param('i',$masterId);$stmt->execute();$data=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();$period='Satu kali per tagihan';
    } else $data = [];
    foreach($data??[] as $item){$bill=(float)$item['tagihan'];$paid=(float)$item['terbayar'];$status=report_status_name($bill,$paid);$rows[]=['nis'=>$item['no_induk'],'nis_diknas'=>$item['nis_diknas']??'','nama'=>$item['nama'],'kelas'=>$item['kelas']??'-','periode'=>$period,'tagihan'=>$bill,'terbayar'=>$paid,'sisa'=>max(0,$bill-$paid),'status'=>$status,'_status'=>$status];}
    $rows=report_filter_rows($rows,$f);
    return ['title'=>$label,'subtitle'=>'Status kewajiban · '.$period,'columns'=>[['nis','NIS'],['nama','Nama Siswa'],['kelas','Kelas'],['periode','Periode'],['tagihan','Tagihan','money'],['terbayar','Terbayar','money'],['sisa','Sisa','money'],['status','Status','status']],'rows'=>$rows];
}

function report_payment_components(mysqli $db, array $f): array {
    $start=$f['tanggal_awal'].' 00:00:00';$end=date('Y-m-d H:i:s',strtotime($f['tanggal_akhir'].' +1 day'));
    $where=['b.TGL_BYR>=?','b.TGL_BYR<?'];$types='ss';$params=[$start,$end];
    $paymentClassWhere = report_class_where($f,'b','KELAS');
    if($paymentClassWhere!=='')$where[]=substr($paymentClassWhere,5);
    $operatorJoin='';
    if($f['operator']!==''){$operatorJoin=report_operator_join('b.user_id','op');$where[]=report_operator_where('b.user_id','op');$types.='ssss';$params=array_merge($params,report_operator_params($f['operator']));}
    if($f['metode']!==''){$where[]='b.sistem_pembayaran=?';$types.='s';$params[]=$f['metode'];}
    $stmt=$db->prepare('SELECT b.*,s.NAMA,s.NO_induk_diknas FROM bayar b LEFT JOIN siswa s ON s.NO_INDUK=b.NO_INDUK'.$operatorJoin.' WHERE '.implode(' AND ',$where).' ORDER BY b.TGL_BYR,b.id');
    $stmt->bind_param($types,...$params);$stmt->execute();$payments=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
    if(!$payments)return [];
    $ids=array_column($payments,'id');$idList=implode(',',array_map('intval',$ids));$extra=[];$du=[];
    foreach($db->query("SELECT bayar_id,master_biaya_lain_id,nama_biaya_snapshot,nominal_snapshot FROM bayar_biaya_lain WHERE bayar_id IN ($idList)")->fetch_all(MYSQLI_ASSOC) as $d)$extra[$d['bayar_id']][]=$d;
    foreach($db->query("SELECT bayar_id,jumlah FROM bayar_du WHERE bayar_id IN ($idList)")->fetch_all(MYSQLI_ASSOC) as $d)$du[$d['bayar_id']]=(float)$d['jumlah'];
    $map=['U_PANGKAL'=>'Uang Pangkal','U_BANGUNAN'=>'Uang Bangunan','U_SERAGAM'=>'Uang Seragam','U_KEGIATAN'=>'Uang Kegiatan','U_SPP'=>'SPP','U_KOMITE'=>'Komite','U_MAKAN'=>'Uang Makan','U_SORGA'=>'Uang Sorga','U_INFAQ'=>'Uang Infaq'];$rows=[];
    foreach($payments as $p){$base=['id'=>(int)$p['id'],'tanggal'=>$p['TGL_BYR'],'nomor'=>'TRX-'.str_pad((string)$p['id'],6,'0',STR_PAD_LEFT),'nis'=>$p['NO_INDUK'],'nis_diknas'=>$p['NO_induk_diknas']??'','nama'=>$p['NAMA']??'-','kelas'=>$p['kelas_rombel_snapshot']?:$p['KELAS'],'metode'=>$p['sistem_pembayaran'],'operator'=>$p['user_id']?:'-'];
      foreach($map as $field=>$name)if((float)$p[$field]>0)$rows[]=array_merge($base,['kategori_key'=>strtolower(substr($field,2)),'komponen'=>$name,'nominal'=>(float)$p[$field]]);
      if(($du[$p['id']]??0)>0)$rows[]=array_merge($base,['kategori_key'=>'daftar_ulang','komponen'=>'Daftar Ulang','nominal'=>$du[$p['id']]]);
      foreach($extra[$p['id']]??[] as $d)$rows[]=array_merge($base,['kategori_key'=>$d['master_biaya_lain_id']?'biaya_lain:'.$d['master_biaya_lain_id']:'biaya_lain','komponen'=>$d['nama_biaya_snapshot'],'nominal'=>(float)$d['nominal_snapshot']]);
      if((float)$p['potong_spp']>0)$rows[]=array_merge($base,['kategori_key'=>'potongan','komponen'=>'Potongan SPP','nominal'=>-(float)$p['potong_spp']]);
    }
    return $rows;
}

function report_receipt_data(mysqli $db,array $f):array{
    $components=report_payment_components($db,$f);$category=$f['kategori'];
    if($category!==''&&$category!=='semua')$components=array_values(array_filter($components,fn($r)=>$r['kategori_key']===$category));
    if($f['q']!=='')$components=array_values(array_filter($components,fn($r)=>report_row_matches_query($r,$f['q'])));
    $allCategories=report_categories($db);
    $wantedCategories=$category!==''&&$category!=='semua'
        ? array_intersect_key($allCategories,[$category=>true])
        : $allCategories;
    $orderedCategories=[];foreach($allCategories as $key=>$label)if(isset($wantedCategories[$key]))$orderedCategories[$key]=$label;
    $columns=[['nis','NIS','nis'],['nama','Nama Siswa'],['kelas','Kelas','kelas']];
    foreach($orderedCategories as $key=>$label)$columns[]=[$key,$label,'money_optional'];
    $columns[]=['total_penerimaan','Total','money_optional'];
    $rowsByStudent=[];
    foreach($components as $component){
        if(!isset($orderedCategories[$component['kategori_key']]))continue;
        $nis=(string)$component['nis'];
        if(!isset($rowsByStudent[$nis])){
            $rowsByStudent[$nis]=['nis'=>$nis,'nis_diknas'=>$component['nis_diknas']??'','nama'=>$component['nama'],'kelas'=>$component['kelas'],'total_penerimaan'=>0];
            foreach($orderedCategories as $key=>$_)$rowsByStudent[$nis][$key]=null;
        }
        $key=$component['kategori_key'];$amount=(float)$component['nominal'];
        $rowsByStudent[$nis][$key]=($rowsByStudent[$nis][$key]??0)+$amount;
        $rowsByStudent[$nis]['total_penerimaan']+=$amount;
    }
    $rows=array_values($rowsByStudent);
    usort($rows,fn($a,$b)=>[$a['kelas'],$a['nama'],$a['nis']]<=>[$b['kelas'],$b['nama'],$b['nis']]);
    return ['title'=>'Rekap Penerimaan Harian','subtitle'=>$f['tanggal_awal'].' s/d '.$f['tanggal_akhir'].' · nominal per siswa dan komponen pembayaran','columns'=>$columns,'rows'=>$rows,'receipt_components'=>$components];
}

function report_academic_months(string $label):array{[$a,$b]=array_map('intval',explode('/',$label));$out=[];for($m=7;$m<=12;$m++)$out[]=[$m,$a];for($m=1;$m<=6;$m++)$out[]=[$m,$b];return $out;}
function report_spp_year_data(mysqli $db,array $f):array{
    $stmt=$db->prepare("SELECT sta.id,sta.no_induk,s.NO_induk_diknas nis_diknas,s.NAMA nama,sta.kelas_rombel_snapshot kelas,sta.spp_perbulan_snapshot tarif FROM siswa_tahun_ajaran sta JOIN tahun_ajaran ta ON ta.id=sta.tahun_ajaran_id JOIN siswa s ON s.NO_INDUK=sta.no_induk WHERE ta.label=?".report_student_status_where($f).report_class_where($f)." ORDER BY sta.kelas_rombel_snapshot,s.NAMA");$stmt->bind_param('s',$f['tahun_ajaran']);$stmt->execute();$students=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
    $paid=[];$stmt=$db->prepare("SELECT bsp.no_induk,bsp.bulan,bsp.tahun,SUM(b.U_SPP) paid FROM bayar_spp_periode bsp JOIN bayar b ON b.id=bsp.bayar_id WHERE CONCAT(IF(CAST(bsp.bulan AS UNSIGNED)>=7,bsp.tahun,CAST(bsp.tahun AS UNSIGNED)-1),'/',IF(CAST(bsp.bulan AS UNSIGNED)>=7,CAST(bsp.tahun AS UNSIGNED)+1,bsp.tahun))=? GROUP BY bsp.no_induk,bsp.bulan,bsp.tahun");$stmt->bind_param('s',$f['tahun_ajaran']);$stmt->execute();foreach($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $p)$paid[$p['no_induk']][str_pad($p['bulan'],2,'0',STR_PAD_LEFT).'-'.$p['tahun']]=(float)$p['paid'];$stmt->close();
    $columns=[['nis','NIS'],['nama','Nama Siswa'],['kelas','Kelas']];$months=report_academic_months($f['tahun_ajaran']);foreach($months as [$m,$y])$columns[]=['m'.sprintf('%02d',$m).'_'.$y,(report_months()[sprintf('%02d',$m)]??$m).' '.$y,'html'];$columns=array_merge($columns,[['total_tagihan','Total Tagihan','money'],['total_bayar','Total Bayar','money'],['tunggakan','Tunggakan','money']]);$rows=[];
    foreach($students as $s){$row=['nis'=>$s['no_induk'],'nis_diknas'=>$s['nis_diknas']??'','nama'=>$s['nama'],'kelas'=>$s['kelas']?:'-'];$totalBill=0;$totalPaid=0;foreach($months as [$m,$y]){$code=sprintf('%02d',$m);$amount=$paid[$s['no_induk']][$code.'-'.$y]??0;$rate=(float)$s['tarif'];$status=report_status_name($rate,$amount);$row['m'.$code.'_'.$y]=['text'=>$status,'sub'=>report_money($amount),'status'=>report_status_key($status)];$totalBill+=$rate;$totalPaid+=$amount;}$row['total_tagihan']=$totalBill;$row['total_bayar']=$totalPaid;$row['tunggakan']=max(0,$totalBill-$totalPaid);$row['_status']=$row['tunggakan']>.001?($totalPaid>0?'Cicilan':'Belum Bayar'):'Lunas';$rows[]=$row;}
    $rows=report_filter_rows($rows,$f);return ['title'=>'Rekap SPP Tahun Ajaran per Kelas','subtitle'=>'Tahun ajaran '.$f['tahun_ajaran'],'columns'=>$columns,'rows'=>$rows];
}

function report_item_is_monthly_category(string $category): bool {
    return $category === 'spp';
}
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
    $category=$f['kategori'];$categories=report_categories($db);$label=$categories[$category]??'SPP';$rows=[];
    if(report_item_is_monthly_category($category)){
        $months=report_month_range((int)$f['bulan_awal'],max(2000,(int)$f['tahun_awal']),(int)$f['bulan_akhir'],max(2000,(int)$f['tahun_akhir']));
        $periodLabel=report_month_range_label($months);
        $stmt=$db->prepare("SELECT s.NO_INDUK nis,s.NO_induk_diknas nis_diknas,s.NAMA nama,s.SPP_PERBULAN tarif,
            mk.kode_rombel,COALESCE(mk.tingkat,s.KELAS) tingkat,mk.is_placeholder,s.master_kelas_id
          FROM siswa s
          LEFT JOIN master_kelas mk ON mk.id=s.master_kelas_id
          WHERE s.is_active=1 AND COALESCE(mk.tingkat, CAST(s.KELAS AS UNSIGNED)) BETWEEN 1 AND 6".report_class_where($f,'s','KELAS')."
          ORDER BY tingkat,mk.kode_rombel,s.NAMA");
        $stmt->execute();$students=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();

        $startKey=min(array_map(static fn($m)=>$m[1]*100+$m[0],$months));
        $endKey=max(array_map(static fn($m)=>$m[1]*100+$m[0],$months));
        $paid=[];
        $stmt=$db->prepare("SELECT bsp.no_induk,bsp.bulan,bsp.tahun,SUM(b.U_SPP) paid
          FROM bayar_spp_periode bsp
          JOIN bayar b ON b.id=bsp.bayar_id
          WHERE (CAST(bsp.tahun AS UNSIGNED)*100+CAST(bsp.bulan AS UNSIGNED)) BETWEEN ? AND ?
          GROUP BY bsp.no_induk,bsp.bulan,bsp.tahun");
        $stmt->bind_param('ii',$startKey,$endKey);$stmt->execute();
        foreach($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $p){
            $paid[$p['no_induk']][sprintf('%04d-%02d',(int)$p['tahun'],(int)$p['bulan'])]=(float)$p['paid'];
        }
        $stmt->close();

        $columns=[['nis','NIS'],['nama','Nama Siswa'],['kelas','Kelas']];
        foreach($months as [$m,$y])$columns[]=['m'.sprintf('%04d_%02d',$y,$m),(report_months()[sprintf('%02d',$m)]??$m).' '.$y,'html'];
        $columns=array_merge($columns,[['total_tagihan','Total Tagihan','money'],['total_bayar','Total Bayar','money'],['tunggakan','Tunggakan','money'],['status','Status','status']]);
        foreach($students as $student){
            $row=['nis'=>$student['nis'],'nis_diknas'=>$student['nis_diknas']??'','nama'=>$student['nama'],'kelas'=>class_label($student)?:'Belum diatur'];
            $totalBill=0.0;$totalPaid=0.0;
            foreach($months as [$m,$y]){
                $code=sprintf('%04d-%02d',$y,$m);$key='m'.sprintf('%04d_%02d',$y,$m);
                $bill=(float)$student['tarif'];$amount=(float)($paid[$student['nis']][$code]??0);
                $status=report_status_name($bill,$amount);
                $row[$key]=['text'=>$status,'sub'=>report_money($amount),'status'=>report_status_key($status)];
                $totalBill+=$bill;$totalPaid+=$amount;
            }
            $row['total_tagihan']=$totalBill;$row['total_bayar']=$totalPaid;$row['tunggakan']=max(0,$totalBill-$totalPaid);
            $row['status']=$row['tunggakan']<=0.001?'Lunas':($totalPaid>0.001?'Cicilan':'Belum Bayar');
            $row['_status']=$row['status'];
            $rows[]=$row;
        }
        $rows=report_filter_rows($rows,$f);
        return ['title'=>'Rekap Pembayaran per Item: '.$label,'subtitle'=>'Periode tagihan: '.$periodLabel,'columns'=>$columns,'rows'=>$rows];
    }

    if (array_key_exists($category, annual_fee_components())) {
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
        $wanted=$category;$paid=[];foreach($components as $component)if($component['kategori_key']===$wanted)$paid[$component['nis']]=($paid[$component['nis']]??0)+(float)$component['nominal'];
        $statusFilters=$f;$statusFilters['status']='';$statusData=report_status_data($db,$statusFilters);foreach($statusData['rows'] as $row){$row['terbayar']=$paid[$row['nis']]??0;$row['sisa']=max(0,(float)$row['tagihan']-$row['terbayar']);$row['status']=report_status_name((float)$row['tagihan'],(float)$row['terbayar']);$row['_status']=$row['status'];$row['periode']=$periodLabel;if(($row['kelas']??'')===''||($row['kelas']??'')==='-')$row['kelas']='Belum diatur';$rows[]=$row;}
    }
    $rows=report_filter_rows($rows,$f);return ['title'=>'Rekap Pembayaran per Item: '.$label,'subtitle'=>$subtitle,'columns'=>[['nis','NIS'],['nama','Nama Siswa'],['kelas','Kelas'],['periode','Periode'],['tagihan','Tagihan','money'],['terbayar','Dibayar','money'],['sisa','Sisa','money'],['status','Status','status']],'rows'=>$rows];
}

function report_savings_transactions(mysqli $db,string $start,string $end,array $f):array{
    $where="x.tanggal>=? AND x.tanggal<?";$types='ss';$params=[$start,$end];$operatorJoin='';if($f['operator']!==''){$operatorJoin=report_operator_join('x.user_id','op');$where.=' AND '.report_operator_where('x.user_id','op');$types.='ssss';$params=array_merge($params,report_operator_params($f['operator']));}
    $sql="SELECT x.*,s.NAMA,s.NO_induk_diknas,COALESCE(sta.master_kelas_id,s.master_kelas_id) master_kelas_id,
      COALESCE(sta.kelas_rombel_snapshot,CASE WHEN mk.is_placeholder=1 THEN CONCAT('Kelas ',COALESCE(mk.tingkat,s.KELAS),' (Belum Ditentukan)') ELSE CONCAT(mk.tingkat,UPPER(mk.kode_rombel)) END) kelas_label,
      mk.tingkat,mk.kode_rombel,mk.is_placeholder FROM (
      SELECT id,NO_INDUK,TANGGAL tanggal,MASUK masuk,0 keluar,user_id,'Masuk' jenis,0 urutan_mutasi FROM transaksi_m
      UNION ALL SELECT id,NO_INDUK,TANGGAL,0,KELUAR,user_id,'Keluar',1 FROM transaksi_k
    ) x JOIN siswa s ON s.NO_INDUK=x.NO_INDUK
      $operatorJoin
      LEFT JOIN tahun_ajaran ta ON DATE(x.tanggal) BETWEEN ta.tanggal_mulai AND ta.tanggal_selesai
      LEFT JOIN siswa_tahun_ajaran sta ON sta.tahun_ajaran_id=ta.id AND sta.no_induk=x.NO_INDUK
      LEFT JOIN master_kelas mk ON mk.id=COALESCE(sta.master_kelas_id,s.master_kelas_id)
      WHERE $where ORDER BY x.tanggal,x.urutan_mutasi,x.id";
    $stmt=$db->prepare($sql);$stmt->bind_param($types,...$params);$stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();return $rows;
}
function report_savings_openings(mysqli $db,array $studentIds,string $before):array{
    $studentIds=array_values(array_unique(array_filter($studentIds)));if(!$studentIds)return [];$placeholders=implode(',',array_fill(0,count($studentIds),'?'));
    $sql="SELECT x.NO_INDUK,SUM(x.delta) saldo FROM (SELECT NO_INDUK,MASUK delta FROM transaksi_m WHERE TANGGAL<? UNION ALL SELECT NO_INDUK,-KELUAR FROM transaksi_k WHERE TANGGAL<?) x WHERE x.NO_INDUK IN ($placeholders) GROUP BY x.NO_INDUK";
    $stmt=$db->prepare($sql);$types='ss'.str_repeat('s',count($studentIds));$params=array_merge([$before,$before],$studentIds);$stmt->bind_param($types,...$params);$stmt->execute();$result=[];foreach($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row)$result[$row['NO_INDUK']]=(float)$row['saldo'];$stmt->close();return $result;
}
function report_savings_last_dates(mysqli $db,array $studentIds,string $before):array{
    $studentIds=array_values(array_unique(array_filter($studentIds)));if(!$studentIds)return [];$placeholders=implode(',',array_fill(0,count($studentIds),'?'));
    $sql="SELECT x.NO_INDUK,MAX(x.tanggal) terakhir FROM (SELECT NO_INDUK,TANGGAL tanggal FROM transaksi_m WHERE TANGGAL<? UNION ALL SELECT NO_INDUK,TANGGAL FROM transaksi_k WHERE TANGGAL<?) x WHERE x.NO_INDUK IN ($placeholders) GROUP BY x.NO_INDUK";
    $stmt=$db->prepare($sql);$types='ss'.str_repeat('s',count($studentIds));$params=array_merge([$before,$before],$studentIds);$stmt->bind_param($types,...$params);$stmt->execute();$result=[];foreach($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row)$result[$row['NO_INDUK']]=$row['terakhir'];$stmt->close();return $result;
}
function report_savings_current_balances(mysqli $db,array $studentIds):array{
    $studentIds=array_values(array_unique(array_filter($studentIds)));if(!$studentIds)return [];$placeholders=implode(',',array_fill(0,count($studentIds),'?'));
    $stmt=$db->prepare("SELECT NO_INDUK,COALESCE(SALDO,0) saldo FROM tabungan WHERE NO_INDUK IN ($placeholders)");$types=str_repeat('s',count($studentIds));$stmt->bind_param($types,...$studentIds);$stmt->execute();$balances=[];foreach($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row)$balances[$row['NO_INDUK']]=(float)$row['saldo'];$stmt->close();return $balances;
}
function report_savings_class_data(mysqli $db,array $f):array{
    $mode=in_array($f['mode'],['harian','bulanan'],true)?$f['mode']:'harian';$year=max(2000,$f['tahun']);$month=(int)$f['bulan_awal'];
    if($mode==='harian'){$periodStart=sprintf('%04d-%02d-01',$year,$month);$periodEnd=date('Y-m-d',strtotime($periodStart.' +1 month'));$parts=[];for($d=1;$d<=(int)date('t',strtotime($periodStart));$d++)$parts[]=sprintf('%04d-%02d-%02d',$year,$month,$d);}else{$periodStart="$year-01-01";$periodEnd=($year+1).'-01-01';$parts=[];for($m=1;$m<=12;$m++)$parts[]=sprintf('%04d-%02d',$year,$m);}
    $transactions=report_savings_transactions($db,$periodStart.' 00:00:00',$periodEnd.' 00:00:00',$f);$studentMap=[];foreach($transactions as $t){if(!report_class_matches_row($f,$t))continue;$studentMap[$t['NO_INDUK']]=['nis'=>$t['NO_INDUK'],'nis_diknas'=>$t['NO_induk_diknas']??'','nama'=>$t['NAMA'],'kelas'=>$t['kelas_label']?:class_label($t)];}
    $sql="SELECT s.NO_INDUK nis,s.NO_induk_diknas nis_diknas,s.NAMA nama,s.master_kelas_id,mk.tingkat,mk.kode_rombel,mk.is_placeholder FROM siswa s LEFT JOIN master_kelas mk ON mk.id=s.master_kelas_id WHERE s.is_active=1".report_class_where($f,'s','KELAS').' ORDER BY s.NAMA';foreach($db->query($sql)->fetch_all(MYSQLI_ASSOC) as $s)$studentMap[$s['nis']]=['nis'=>$s['nis'],'nis_diknas'=>$s['nis_diknas']??'','nama'=>$s['nama'],'kelas'=>class_label($s)];
    $matrix=[];foreach($transactions as $t){if(!report_class_matches_row($f,$t))continue;$key=$mode==='harian'?date('Y-m-d',strtotime($t['tanggal'])):date('Y-m',strtotime($t['tanggal']));$matrix[$t['NO_INDUK']][$key]['in']=($matrix[$t['NO_INDUK']][$key]['in']??0)+(float)$t['masuk'];$matrix[$t['NO_INDUK']][$key]['out']=($matrix[$t['NO_INDUK']][$key]['out']??0)+(float)$t['keluar'];}
    $columns=[['nis','NIS'],['nama','Nama Siswa'],['kelas','Kelas'],['saldo_awal','Saldo Awal','money']];foreach($parts as $part)$columns[]=['p'.$part,$mode==='harian'?date('d',strtotime($part)):report_months()[date('m',strtotime($part.'-01'))],'html'];$columns=array_merge($columns,[['total_masuk','Total Masuk','money'],['total_keluar','Total Keluar','money'],['saldo_akhir','Saldo Akhir','money']]);$rows=[];$openings=report_savings_openings($db,array_keys($studentMap),$periodStart.' 00:00:00');
    foreach($studentMap as $s){if(!report_row_matches_query($s,$f['q']))continue;$opening=$openings[$s['nis']]??0;$row=$s+['saldo_awal'=>$opening];$in=0;$out=0;foreach($parts as $part){$cell=$matrix[$s['nis']][$part]??['in'=>0,'out'=>0];$row['p'.$part]=['text'=>'M '.report_money($cell['in']),'sub'=>'K '.report_money($cell['out']),'status'=>($cell['in']||$cell['out'])?'lunas':''];$in+=$cell['in'];$out+=$cell['out'];}$row['total_masuk']=$in;$row['total_keluar']=$out;$row['saldo_akhir']=$opening+$in-$out;$rows[]=$row;}
    return ['title'=>'Rekap Mutasi Tabungan per Kelas','subtitle'=>($mode==='harian'?(report_months()[sprintf('%02d',$month)]??$month).' ':'Tahun ').$year,'columns'=>$columns,'rows'=>$rows];
}
function report_savings_student_data(mysqli $db,array $f):array{
    $start=$f['tanggal_awal'].' 00:00:00';$end=date('Y-m-d H:i:s',strtotime($f['tanggal_akhir'].' +1 day'));
    $transactions=report_savings_transactions($db,$start,$end,$f);
    $timelineFilters=$f;$timelineFilters['operator']='';$timeline=report_savings_transactions($db,$start,$end,$timelineFilters);
    $studentIds=array_values(array_unique(array_column($transactions,'NO_INDUK')));$running=report_savings_openings($db,$studentIds,$start);$historicalBalances=[];
    $studentMap=array_fill_keys($studentIds,true);
    foreach($timeline as $transaction){$nis=(string)$transaction['NO_INDUK'];if(!isset($studentMap[$nis]))continue;$running[$nis]=($running[$nis]??0)+(float)$transaction['masuk']-(float)$transaction['keluar'];$historicalBalances[$transaction['jenis'].'#'.$transaction['id']]=(float)$running[$nis];}
    $rows=[];
    foreach($transactions as $t){if($f['mutasi']==='masuk'&&$t['jenis']!=='Masuk')continue;if($f['mutasi']==='keluar'&&$t['jenis']!=='Keluar')continue;if(!report_class_matches_row($f,$t))continue;$candidate=['nis'=>$t['NO_INDUK'],'nis_diknas'=>$t['NO_induk_diknas']??'','nama'=>$t['NAMA'],'kelas'=>$t['kelas_label']?:class_label($t)];if(!report_row_matches_query($candidate,$f['q']))continue;$key=$t['jenis'].'#'.$t['id'];$rows[]=$candidate+['tanggal'=>$t['tanggal'],'jenis'=>$t['jenis'],'masuk'=>(float)$t['masuk'],'keluar'=>(float)$t['keluar'],'saldo_saat_ini'=>(float)($historicalBalances[$key]??0),'operator'=>$t['user_id']?:'-'];}
    return ['title'=>'Rekap Transaksi Tabungan Siswa','subtitle'=>$f['tanggal_awal'].' s/d '.$f['tanggal_akhir'],'columns'=>[['tanggal','Tanggal/Waktu'],['nis','NIS'],['nama','Nama Siswa'],['kelas','Kelas'],['jenis','Mutasi'],['masuk','Masuk','money'],['keluar','Keluar','money'],['saldo_saat_ini','Saldo Saat Ini','money'],['operator','Operator']],'rows'=>$rows];
}
function report_savings_student_data_legacy(mysqli $db,array $f):array{
    $start=$f['tanggal_awal'].' 00:00:00';$end=date('Y-m-d H:i:s',strtotime($f['tanggal_akhir'].' +1 day'));$transactions=report_savings_transactions($db,$start,$end,$f);$rows=[];$running=report_savings_openings($db,array_column($transactions,'NO_INDUK'),$start);
    foreach($transactions as $t){if(!report_class_matches_row($f,$t))continue;if($f['q']!==''&&stripos($t['NO_INDUK'].' '.$t['NAMA'],$f['q'])===false)continue;$opening=$running[$t['NO_INDUK']]??0;$running[$t['NO_INDUK']]=$opening+(float)$t['masuk']-(float)$t['keluar'];$rows[]=['tanggal'=>$t['tanggal'],'nis'=>$t['NO_INDUK'],'nama'=>$t['NAMA'],'kelas'=>$t['kelas_label']?:class_label($t),'jenis'=>$t['jenis'],'masuk'=>(float)$t['masuk'],'keluar'=>(float)$t['keluar'],'saldo_awal'=>$opening,'saldo'=>$running[$t['NO_INDUK']],'operator'=>$t['user_id']?:'-'];}
    $mode=in_array($f['mode'],['buku','transaksi'],true)?$f['mode']:'buku';$columns=$mode==='buku'?[['tanggal','Tanggal/Waktu'],['nis','NIS'],['nama','Nama Siswa'],['jenis','Mutasi'],['masuk','Masuk','money'],['keluar','Keluar','money'],['saldo','Saldo Berjalan','money'],['operator','Operator']]:[['tanggal','Tanggal/Waktu'],['nis','NIS'],['nama','Nama Siswa'],['kelas','Kelas'],['jenis','Mutasi'],['masuk','Masuk','money'],['keluar','Keluar','money'],['operator','Operator']];return ['title'=>'Laporan Tabungan Siswa — '.($mode==='buku'?'Buku Saldo':'Daftar Transaksi'),'subtitle'=>$f['tanggal_awal'].' s/d '.$f['tanggal_akhir'],'columns'=>$columns,'rows'=>$rows];
}
function report_savings_balance_data(mysqli $db,array $f):array{
    $studentWhere=$f['siswa_status']==='archived'?' AND s.is_active=0':($f['siswa_status']==='all'?'':' AND s.is_active=1');
    $sql="SELECT s.NO_INDUK nis,s.NO_induk_diknas nis_diknas,s.NAMA nama,s.master_kelas_id,mk.tingkat,mk.kode_rombel,mk.is_placeholder,COALESCE(t.SALDO,0) saldo_saat_ini FROM siswa s LEFT JOIN tabungan t ON t.NO_INDUK=s.NO_INDUK LEFT JOIN master_kelas mk ON mk.id=s.master_kelas_id WHERE 1=1$studentWhere".report_class_where($f,'s','KELAS')." ORDER BY mk.tingkat,mk.kode_rombel,s.NAMA";
    $rows=[];foreach($db->query($sql)->fetch_all(MYSQLI_ASSOC) as $student){$saldo=(float)$student['saldo_saat_ini'];if($f['saldo_status']==='ada_saldo'&&$saldo<=0.001)continue;if($f['saldo_status']==='saldo_nol'&&$saldo>0.001)continue;$row=['nis'=>$student['nis'],'nis_diknas'=>$student['nis_diknas']??'','nama'=>$student['nama'],'kelas'=>class_label($student)?:'Belum diatur','saldo_saat_ini'=>$saldo,'master_kelas_id'=>$student['master_kelas_id']??0,'tingkat'=>$student['tingkat']??''];if(report_row_matches_query($row,$f['q']))$rows[]=$row;}
    return ['title'=>'Rekap Saldo Tabungan','subtitle'=>'Saldo tabungan terkini siswa','columns'=>[['nis','NIS','nis'],['nama','Nama Siswa'],['kelas','Kelas','kelas'],['saldo_saat_ini','Saldo Saat Ini','money']],'rows'=>$rows];
}
function report_billing_categories(mysqli $db):array{
    $categories=['spp'=>'SPP','pangkal'=>'Uang Pangkal','bangunan'=>'Uang Bangunan','seragam'=>'Uang Seragam','kegiatan'=>'Uang Kegiatan','komite'=>'Uang Komite','makan'=>'Uang Makan','sorga'=>'Uang Sorga','infaq'=>'Uang Infaq','daftar_ulang'=>'Daftar Ulang'];
    foreach($db->query('SELECT id,nama FROM master_biaya_lain ORDER BY nama')->fetch_all(MYSQLI_ASSOC) as $item)$categories['biaya_lain:'.(int)$item['id']]=$item['nama'];return $categories;
}
function report_billing_history_data(mysqli $db,array $f):array{
    $rows=[];$studentWhere=$f['siswa_status']==='archived'?' AND s.is_active=0':($f['siswa_status']==='all'?'':' AND s.is_active=1');
    $append=static function(array $row)use(&$rows,$f):void{if($f['tahun_tagihan']!==''&&$row['tahun_ajaran']!==$f['tahun_tagihan'])return;if($f['komponen_tagihan']!==''&&$row['komponen_key']!==$f['komponen_tagihan'])return;if(!report_class_matches_row($f,$row))return;$row['_status']=$row['status'];if(report_filter_rows([$row],$f))$rows[]=$row;};
    $annualSql="SELECT t.no_induk nis,s.NO_induk_diknas nis_diknas,s.NAMA nama,t.kelas_rombel_snapshot kelas,COALESCE(sta.master_kelas_id,s.master_kelas_id) master_kelas_id,COALESCE(mk.tingkat,t.kelas_snapshot) tingkat,t.komponen,t.tahun_ajaran_snapshot tahun_ajaran,t.nominal_tagihan tagihan,t.status bill_status,COALESCE(SUM(d.jumlah),0) terbayar FROM tagihan_tahunan_siswa t JOIN siswa s ON s.NO_INDUK=t.no_induk LEFT JOIN siswa_tahun_ajaran sta ON sta.id=t.penempatan_id LEFT JOIN master_kelas mk ON mk.id=COALESCE(sta.master_kelas_id,s.master_kelas_id) LEFT JOIN bayar_tahunan_siswa d ON d.tagihan_tahunan_id=t.id WHERE 1=1$studentWhere GROUP BY t.id,s.NO_induk_diknas,s.NAMA,sta.master_kelas_id,s.master_kelas_id,mk.tingkat";
    foreach($db->query($annualSql)->fetch_all(MYSQLI_ASSOC) as $item){$bill=(float)$item['tagihan'];$paid=(float)$item['terbayar'];$component=(string)$item['komponen'];$componentConfig=annual_fee_components()[$component]??['label'=>$component];$append(['nis'=>$item['nis'],'nis_diknas'=>$item['nis_diknas']??'','nama'=>$item['nama'],'kelas'=>$item['kelas']?:'Belum diatur','master_kelas_id'=>$item['master_kelas_id']??0,'tingkat'=>$item['tingkat']??'','komponen'=>$componentConfig['label'],'komponen_key'=>$component,'periode'=>$item['tahun_ajaran'],'tahun_ajaran'=>$item['tahun_ajaran'],'tagihan'=>$bill,'terbayar'=>$paid,'sisa'=>max(0,$bill-$paid),'status'=>report_billing_status($bill,$paid,$item['bill_status'])]);}
    $duSql="SELECT t.no_induk nis,s.NO_induk_diknas nis_diknas,s.NAMA nama,sta.kelas_rombel_snapshot kelas,COALESCE(sta.master_kelas_id,s.master_kelas_id) master_kelas_id,COALESCE(mk.tingkat,t.kelas_snapshot) tingkat,t.tahun_ajaran_snapshot tahun_ajaran,t.nominal_tagihan tagihan,t.status bill_status,COALESCE(SUM(d.jumlah),0) terbayar FROM tagihan_daftar_ulang t JOIN siswa s ON s.NO_INDUK=t.no_induk LEFT JOIN siswa_tahun_ajaran sta ON sta.id=t.penempatan_id LEFT JOIN master_kelas mk ON mk.id=COALESCE(sta.master_kelas_id,s.master_kelas_id) LEFT JOIN bayar_du d ON d.tagihan_daftar_ulang_id=t.id WHERE 1=1$studentWhere GROUP BY t.id,s.NO_induk_diknas,s.NAMA,sta.master_kelas_id,s.master_kelas_id,mk.tingkat";
    foreach($db->query($duSql)->fetch_all(MYSQLI_ASSOC) as $item){$bill=(float)$item['tagihan'];$paid=(float)$item['terbayar'];$append(['nis'=>$item['nis'],'nis_diknas'=>$item['nis_diknas']??'','nama'=>$item['nama'],'kelas'=>$item['kelas']?:'Belum diatur','master_kelas_id'=>$item['master_kelas_id']??0,'tingkat'=>$item['tingkat']??'','komponen'=>'Daftar Ulang','komponen_key'=>'daftar_ulang','periode'=>$item['tahun_ajaran'],'tahun_ajaran'=>$item['tahun_ajaran'],'tagihan'=>$bill,'terbayar'=>$paid,'sisa'=>max(0,$bill-$paid),'status'=>report_billing_status($bill,$paid,$item['bill_status'])]);}
    $otherSql="SELECT t.no_induk nis,s.NO_induk_diknas nis_diknas,s.NAMA nama,t.kelas_rombel_snapshot kelas,t.master_kelas_id,mk.tingkat,t.master_biaya_lain_id,t.nama_snapshot,t.nominal_tagihan tagihan,t.status bill_status,t.created_at,COALESCE(SUM(d.nominal_snapshot),0) terbayar FROM tagihan_biaya_lain t JOIN siswa s ON s.NO_INDUK=t.no_induk LEFT JOIN master_kelas mk ON mk.id=t.master_kelas_id LEFT JOIN bayar_biaya_lain d ON d.tagihan_biaya_lain_id=t.id WHERE 1=1$studentWhere GROUP BY t.id,s.NO_induk_diknas,s.NAMA,mk.tingkat";
    foreach($db->query($otherSql)->fetch_all(MYSQLI_ASSOC) as $item){$time=strtotime((string)$item['created_at']);$yearLabel=$time?du_academic_year_label((int)date('m',$time),(int)date('Y',$time)):'';$bill=(float)$item['tagihan'];$paid=(float)$item['terbayar'];$append(['nis'=>$item['nis'],'nis_diknas'=>$item['nis_diknas']??'','nama'=>$item['nama'],'kelas'=>$item['kelas']?:class_label($item),'master_kelas_id'=>$item['master_kelas_id']??0,'tingkat'=>$item['tingkat']??'','komponen'=>$item['nama_snapshot'],'komponen_key'=>'biaya_lain:'.(int)$item['master_biaya_lain_id'],'periode'=>$time?'Diterbitkan '.report_date_label(date('Y-m-d',$time)):'Diterbitkan','tahun_ajaran'=>$yearLabel,'tagihan'=>$bill,'terbayar'=>$paid,'sisa'=>max(0,$bill-$paid),'status'=>report_billing_status($bill,$paid,$item['bill_status'])]);}
    $paid=[];foreach($db->query('SELECT bsp.no_induk,bsp.bulan,bsp.tahun,COALESCE(SUM(b.U_SPP),0) terbayar FROM bayar_spp_periode bsp JOIN bayar b ON b.id=bsp.bayar_id GROUP BY bsp.no_induk,bsp.bulan,bsp.tahun')->fetch_all(MYSQLI_ASSOC) as $item)$paid[$item['no_induk']][sprintf('%04d-%02d',(int)$item['tahun'],(int)$item['bulan'])]=(float)$item['terbayar'];
    $sppSql="SELECT sta.no_induk nis,s.NO_induk_diknas nis_diknas,s.NAMA nama,sta.kelas_rombel_snapshot kelas,sta.master_kelas_id,sta.kelas tingkat,sta.spp_perbulan_snapshot tarif,ta.label tahun_ajaran FROM siswa_tahun_ajaran sta JOIN tahun_ajaran ta ON ta.id=sta.tahun_ajaran_id JOIN siswa s ON s.NO_INDUK=sta.no_induk WHERE sta.kelas<>'PSB'$studentWhere";
    foreach($db->query($sppSql)->fetch_all(MYSQLI_ASSOC) as $student){[$startYear,$endYear]=array_map('intval',explode('/',$student['tahun_ajaran']));for($index=0;$index<12;$index++){$month=(($index+6)%12)+1;$year=$index<6?$startYear:$endYear;$code=sprintf('%04d-%02d',$year,$month);$bill=(float)$student['tarif'];$amount=(float)($paid[$student['nis']][$code]??0);$append(['nis'=>$student['nis'],'nis_diknas'=>$student['nis_diknas']??'','nama'=>$student['nama'],'kelas'=>$student['kelas']?:'Belum diatur','master_kelas_id'=>$student['master_kelas_id']??0,'tingkat'=>$student['tingkat']??'','komponen'=>'SPP','komponen_key'=>'spp','periode'=>(report_months()[sprintf('%02d',$month)]??$month).' '.$year,'tahun_ajaran'=>$student['tahun_ajaran'],'tagihan'=>$bill,'terbayar'=>$amount,'sisa'=>max(0,$bill-$amount),'status'=>report_billing_status($bill,$amount)]);}}
    usort($rows,static fn($a,$b)=>[$a['tahun_ajaran'],$a['kelas'],$a['nama'],$a['komponen'],$a['periode']]<=>[$b['tahun_ajaran'],$b['kelas'],$b['nama'],$b['komponen'],$b['periode']]);
    return ['title'=>'Riwayat Tagihan Siswa','subtitle'=>'Seluruh histori tagihan siswa','columns'=>[['nis','NIS','nis'],['nama','Nama Siswa'],['kelas','Kelas','kelas'],['komponen','Komponen'],['periode','Periode/Tahun Ajaran'],['tagihan','Tagihan','money'],['terbayar','Sudah Dibayar','money'],['sisa','Sisa','money'],['status','Status','status']],'rows'=>$rows];
}
function report_settlement_data(mysqli $db,array $f):array{
    $components=array_values(array_filter(report_payment_components($db,$f), static fn($row)=>($row['kategori_key']??'') !== 'potongan'));
    $payByMethod=['Tunai'=>0,'VA'=>0,'Qris'=>0];
    foreach($components as $row)$payByMethod[$row['metode']]=($payByMethod[$row['metode']]??0)+(float)$row['nominal'];
    $start=$f['tanggal_awal'].' 00:00:00';
    $end=date('Y-m-d H:i:s',strtotime($f['tanggal_akhir'].' +1 day'));
    $savings=report_savings_transactions($db,$start,$end,$f);
    $savingIn=array_sum(array_column($savings,'masuk'));
    $savingOut=array_sum(array_column($savings,'keluar'));
    $cash=($payByMethod['Tunai']??0)+($payByMethod['VA']??0)+($payByMethod['Qris']??0)+$savingIn-$savingOut;
    $rows=[
        ['bagian'=>'Pembayaran Tunai','nominal'=>$payByMethod['Tunai']??0],
        ['bagian'=>'Pembayaran Virtual Account','nominal'=>$payByMethod['VA']??0],
        ['bagian'=>'Pembayaran QRIS','nominal'=>$payByMethod['Qris']??0],
        ['bagian'=>'Tabungan Masuk','nominal'=>$savingIn],
        ['bagian'=>'Tabungan Keluar','nominal'=>-$savingOut],
        ['bagian'=>'Total Bersih','nominal'=>$cash],
    ];
    return ['title'=>'Rekap Setoran Kas Harian','subtitle'=>$f['tanggal_awal'].' s/d '.$f['tanggal_akhir'].' - ringkasan penerimaan kasir','columns'=>[['bagian','Komponen'],['nominal','Nominal','money']],'rows'=>$rows,'details'=>$components,'settlement'=>['cash'=>$cash,'payment_count'=>count(array_unique(array_column($components,'id')))]];
}
function report_build(mysqli $db,string $template,array $filters):array{
    return match($template){'status'=>report_status_data($db,$filters),'penerimaan'=>report_receipt_data($db,$filters),'spp-tahunan'=>report_spp_year_data($db,$filters),'per-item'=>report_item_data($db,$filters),'tabungan-siswa'=>report_savings_student_data($db,$filters),'saldo-tabungan'=>report_savings_balance_data($db,$filters),'riwayat-tagihan'=>report_billing_history_data($db,$filters),'setoran'=>report_settlement_data($db,$filters),default=>throw new InvalidArgumentException('Template laporan tidak dikenali.')};
}
function report_savings_transaction_totals(array $rows): array {
    $masuk=0;$keluar=0;foreach($rows as $row){$masuk+=(float)($row['masuk']??0);$keluar+=(float)($row['keluar']??0);}return ['total_masuk'=>$masuk,'total_keluar'=>$keluar,'selisih'=>$masuk-$keluar];
}
function report_money_totals(array $report, string $template=''): array {
    if($template==='setoran'){
        $payment=0;$savingIn=0;$savingOut=0;
        foreach($report['rows']??[] as $row){
            $label=(string)($row['bagian']??'');$amount=(float)($row['nominal']??0);
            if(in_array($label,['Pembayaran Tunai','Pembayaran Virtual Account','Pembayaran QRIS'],true))$payment+=$amount;
            elseif($label==='Tabungan Masuk')$savingIn+=$amount;
            elseif($label==='Tabungan Keluar')$savingOut+=abs($amount);
            if(in_array($label,['Pendapatan pembayaran tunai','Penerimaan Virtual Account','Penerimaan QRIS'],true))$payment+=$amount;
            elseif($label==='Tabungan masuk tunai')$savingIn+=$amount;
            elseif($label==='Tabungan keluar tunai')$savingOut+=abs($amount);
        }
        return [
            ['label'=>'Total Penerimaan Pembayaran','value'=>$payment],
            ['label'=>'Total Tabungan Masuk','value'=>$savingIn],
            ['label'=>'Total Tabungan Keluar','value'=>$savingOut],
            ['label'=>'Total Bersih','value'=>$payment+$savingIn-$savingOut],
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
