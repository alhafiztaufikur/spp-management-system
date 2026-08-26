<?php

require_once __DIR__ . '/daftar_ulang.php';
require_once __DIR__ . '/kelas.php';

function report_e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function report_input_scalar(array $source, string $key, $default = '') {
    $value = $source[$key] ?? $default;
    return is_scalar($value) ? $value : $default;
}
function report_money($value): string { return 'Rp ' . number_format((float)$value, 0, ',', '.'); }
function report_spreadsheet_text($value): string {
    $text = str_replace(["\0", "\r", "\n", "\t"], ' ', (string)$value);
    return preg_match('/^[\p{Z}\s]*[=+\-@]/u', $text) ? "'" . $text : $text;
}
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
        'status' => ['label'=>'Laporan Status Pembayaran','description'=>'Lihat siswa yang belum bayar, mencicil, atau sudah lunas per kategori.','icon'=>'✓','orientation'=>'portrait'],
        'penerimaan' => ['label'=>'Rekap Penerimaan Harian','description'=>'Rincian uang yang diterima berdasarkan tanggal transaksi aktual.','icon'=>'↗','orientation'=>'landscape'],
        'spp-tahunan' => ['label'=>'Rekap SPP Tahun Ajaran per Kelas','description'=>'Matriks SPP Juli–Juni untuk menemukan tunggakan per rombel.','icon'=>'▦','orientation'=>'landscape'],
        'per-item' => ['label'=>'Rekap Pembayaran per Item','description'=>'Rekap satu item pembayaran per siswa dan periode.','icon'=>'≡','orientation'=>'landscape'],
        'tabungan-kelas' => ['label'=>'Rekap Mutasi Tabungan per Kelas','description'=>'Mutasi masuk dan keluar per hari atau per bulan untuk satu rombel.','icon'=>'⇄','orientation'=>'landscape'],
        'tabungan-siswa' => ['label'=>'Laporan Tabungan Siswa','description'=>'Buku saldo dan daftar transaksi tabungan siswa.','icon'=>'$','orientation'=>'portrait'],
        'setoran' => ['label'=>'Rekap Setoran Kas Harian','description'=>'Pisahkan pendapatan, non-tunai, dan kas fisik yang diserahkan.','icon'=>'▣','orientation'=>'portrait'],
    ];
}
function report_date_value($value, string $fallback): string {
    $value = trim((string)$value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return $fallback;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : $fallback;
}
function report_filters(mysqli $db, array $source): array {
    $today = date('Y-m-d');
    $start = report_date_value(report_input_scalar($source, 'tanggal_awal'), $today);
    $end = report_date_value(report_input_scalar($source, 'tanggal_akhir'), $start);
    if ($start > $end) [$start,$end]=[$end,$start];
    $allowedStatus = ['','tidak_ditagihkan','belum_bayar','cicilan','lunas','rekonsiliasi','ada_pembayaran','tunggakan'];
    $allowedMethods = ['','Tunai','VA','Qris'];
    $allowedModes = ['harian','bulanan','buku','transaksi'];
    $allowedStudentStatus = ['active','archived','all'];
    $requestedStudentStatus = (string)report_input_scalar($source, 'siswa_status', 'active');
    $requestedPerPage = (int)report_input_scalar($source, 'per_page', 25);
    $perPage = in_array($requestedPerPage, [25,50,100], true) ? $requestedPerPage : 25;
    $currentYear = du_current_academic_year();
    $academicYear = trim((string)report_input_scalar($source, 'tahun_ajaran', $currentYear));
    if (!preg_match('/^\d{4}\/\d{4}$/', $academicYear)) $academicYear = $currentYear;
    $calendarYear = (int)report_input_scalar($source, 'tahun', date('Y'));
    if ($calendarYear < 2000 || $calendarYear > 2100) $calendarYear = (int)date('Y');
    return [
        'tanggal_awal'=>$start,'tanggal_akhir'=>$end,
        'tahun_ajaran'=>$academicYear,
        'bulan_awal'=>report_month_code(report_input_scalar($source, 'bulan_awal', date('m'))),
        'bulan_akhir'=>report_month_code(report_input_scalar($source, 'bulan_akhir', report_input_scalar($source, 'bulan_awal', date('m')))),
        'tahun'=>$calendarYear,
        'kelas'=>(int)report_input_scalar($source, 'kelas', 0),
        'kategori'=>trim((string)report_input_scalar($source, 'kategori', 'spp')),
        'status'=>in_array((string)report_input_scalar($source, 'status'),$allowedStatus,true)?(string)report_input_scalar($source, 'status'):'',
        'siswa_status'=>in_array($requestedStudentStatus,$allowedStudentStatus,true)?$requestedStudentStatus:'active',
        'operator'=>trim((string)report_input_scalar($source, 'operator')),
        'metode'=>in_array((string)report_input_scalar($source, 'metode'),$allowedMethods,true)?(string)report_input_scalar($source, 'metode'):'',
        'q'=>mb_substr(trim((string)report_input_scalar($source, 'q')),0,100),
        'mode'=>in_array((string)report_input_scalar($source, 'mode', 'harian'),$allowedModes,true)?(string)report_input_scalar($source, 'mode', 'harian'):'harian',
        'page'=>max(1,(int)report_input_scalar($source, 'page', 1)),'per_page'=>$perPage,
    ];
}
function report_classes(mysqli $db): array { return class_all($db, true); }
function report_years(mysqli $db): array { return $db->query('SELECT label,status FROM tahun_ajaran ORDER BY label DESC')->fetch_all(MYSQLI_ASSOC); }
function report_operators(mysqli $db): array { return $db->query('SELECT id,username,nama,role FROM admin ORDER BY nama')->fetch_all(MYSQLI_ASSOC); }
function report_template_defaults(string $template,array $source,array $filters,array $classes):array{
    if(!isset($source['kategori'])&&in_array($template,['penerimaan','setoran'],true))$filters['kategori']='semua';
    if($template==='tabungan-siswa'&&!isset($source['mode']))$filters['mode']='buku';
    $missingRombel=false;
    if(!isset($source['kelas'])&&in_array($template,['spp-tahunan','per-item','tabungan-kelas'],true)){
        $realClasses=array_values(array_filter($classes,fn($class)=>(int)$class['is_placeholder']===0));
        if($realClasses)$filters['kelas']=(int)$realClasses[0]['id'];else $missingRombel=true;
    }
    return ['filters'=>$filters,'missing_rombel'=>$missingRombel];
}
function report_categories(mysqli $db): array {
    $items = [
        'spp'=>'SPP','komite'=>'Komite','daftar_ulang'=>'Daftar Ulang','pangkal'=>'Uang Pangkal',
        'bangunan'=>'Uang Bangunan','seragam'=>'Uang Seragam','kegiatan'=>'Uang Kegiatan',
        'makan'=>'Uang Makan','sorga'=>'Uang Sorga','infaq'=>'Uang Infaq',
    ];
    foreach($db->query('SELECT id,nama FROM master_biaya_lain ORDER BY nama')->fetch_all(MYSQLI_ASSOC) as $row) $items['biaya_lain:'.$row['id']] = 'Biaya Lain — '.$row['nama'];
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
function report_status_key(string $status): string { return ['Tidak Ditagihkan'=>'tidak_ditagihkan','Belum Bayar'=>'belum_bayar','Cicilan'=>'cicilan','Lunas'=>'lunas','Perlu Rekonsiliasi'=>'rekonsiliasi'][$status] ?? ''; }
function report_filter_rows(array $rows, array $filters): array {
    return array_values(array_filter($rows, static function($row) use($filters) {
        $status = (string)($row['_status'] ?? ''); $key=report_status_key($status);
        if ($filters['status']==='ada_pembayaran' && !in_array($key,['cicilan','lunas'],true)) return false;
        if ($filters['status']==='tunggakan' && !in_array($key,['belum_bayar','cicilan'],true)) return false;
        if ($filters['status']!=='' && !in_array($filters['status'],['ada_pembayaran','tunggakan'],true) && $filters['status']!==$key) return false;
        if ($filters['q']!=='' && stripos(($row['nis']??'').' '.($row['nama']??''),$filters['q'])===false) return false;
        return true;
    }));
}
function report_period_from_academic(string $academicYear, string $month): array {
    [$start,$end]=array_map('intval',explode('/',$academicYear)); $m=(int)$month;
    return [$month, (string)($m>=7?$start:$end)];
}
function report_class_where(array $filters, string $alias='sta'): string { return $filters['kelas']>0 ? " AND {$alias}.master_kelas_id=".(int)$filters['kelas'] : ''; }
function report_student_status_where(array $filters,string $placement='sta',string $student='s'):string{
    return match($filters['siswa_status']){'all'=>'','archived'=>" AND ($placement.status<>'aktif' OR $student.is_active=0)",default=>" AND $placement.status='aktif' AND $student.is_active=1"};
}
function report_paginate(array $rows, array $filters, bool $export): array {
    $total=count($rows); $per=$export?max(1,$total):$filters['per_page']; $pages=max(1,(int)ceil($total/$per)); $page=$export?1:min($filters['page'],$pages);
    return ['rows'=>$export?$rows:array_slice($rows,($page-1)*$per,$per),'total'=>$total,'page'=>$page,'pages'=>$pages,'per_page'=>$per];
}

function report_status_data(mysqli $db, array $f): array {
    $category=$f['kategori']; $rows=[]; $label=report_categories($db)[$category]??'Pembayaran';
    if (in_array($category,['spp','komite'],true)) {
        [$month,$year]=report_period_from_academic($f['tahun_ajaran'],$f['bulan_awal']);
        $field=$category==='spp'?'U_SPP':'U_KOMITE'; $rate=$category==='spp'?'spp_perbulan_snapshot':'komite_snapshot';
        if($category==='spp'){
          $sql="SELECT sta.no_induk,s.NAMA nama,sta.kelas_rombel_snapshot kelas,sta.$rate tagihan,COALESCE(SUM(b.U_SPP),0) terbayar
            FROM siswa_tahun_ajaran sta JOIN tahun_ajaran ta ON ta.id=sta.tahun_ajaran_id JOIN siswa s ON s.NO_INDUK=sta.no_induk
            LEFT JOIN bayar_spp_periode bsp ON bsp.no_induk=sta.no_induk AND bsp.tahun=? AND bsp.bulan=? LEFT JOIN bayar b ON b.id=bsp.bayar_id
            WHERE ta.label=?".report_student_status_where($f).report_class_where($f)." GROUP BY sta.id,s.NAMA ORDER BY sta.kelas_rombel_snapshot,s.NAMA";
        } else {
          $monthExpr=report_sql_month_expr('b.BULAN');
          $sql="SELECT sta.no_induk,s.NAMA nama,sta.kelas_rombel_snapshot kelas,sta.$rate tagihan,COALESCE(SUM(b.U_KOMITE),0) terbayar
            FROM siswa_tahun_ajaran sta JOIN tahun_ajaran ta ON ta.id=sta.tahun_ajaran_id JOIN siswa s ON s.NO_INDUK=sta.no_induk
            LEFT JOIN bayar b ON b.NO_INDUK=sta.no_induk AND b.TAHUN=? AND $monthExpr=CAST(? AS UNSIGNED)
            WHERE ta.label=?".report_student_status_where($f).report_class_where($f)." GROUP BY sta.id,s.NAMA ORDER BY sta.kelas_rombel_snapshot,s.NAMA";
        }
        $stmt=$db->prepare($sql); $stmt->bind_param('sss',$year,$month,$f['tahun_ajaran']); $stmt->execute(); $data=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
        $period=(report_months()[$month]??$month).' '.$year;
    } elseif ($category==='daftar_ulang') {
        $sql="SELECT sta.no_induk,s.NAMA nama,sta.kelas_rombel_snapshot kelas,COALESCE(t.nominal_tagihan,0) tagihan,COALESCE(SUM(d.jumlah),0) terbayar
          FROM siswa_tahun_ajaran sta JOIN tahun_ajaran ta ON ta.id=sta.tahun_ajaran_id JOIN siswa s ON s.NO_INDUK=sta.no_induk
          LEFT JOIN tagihan_daftar_ulang t ON t.penempatan_id=sta.id AND t.status='open' LEFT JOIN bayar_du d ON d.tagihan_daftar_ulang_id=t.id
          WHERE ta.label=?".report_student_status_where($f).report_class_where($f,'sta')." GROUP BY sta.id,s.NAMA,sta.kelas_rombel_snapshot,t.nominal_tagihan ORDER BY kelas,s.NAMA";
        $stmt=$db->prepare($sql);$stmt->bind_param('s',$f['tahun_ajaran']);$stmt->execute();$data=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();$period=$f['tahun_ajaran'];
    } elseif (str_starts_with($category,'biaya_lain:')) {
        $masterId=(int)substr($category,11);
        $sql="SELECT s.NO_INDUK no_induk,s.NAMA nama,COALESCE(t.kelas_rombel_snapshot,CASE WHEN mk.is_placeholder=1 THEN CONCAT('Kelas ',mk.tingkat,' (Belum Ditentukan)') ELSE CONCAT(mk.tingkat,UPPER(mk.kode_rombel)) END) kelas,
          COALESCE(t.nominal_tagihan,0) tagihan,COALESCE(SUM(d.nominal_snapshot),0) terbayar
          FROM siswa s LEFT JOIN master_kelas mk ON mk.id=s.master_kelas_id LEFT JOIN tagihan_biaya_lain t ON t.no_induk=s.NO_INDUK AND t.master_biaya_lain_id=? AND t.status='open'
          LEFT JOIN bayar_biaya_lain d ON d.tagihan_biaya_lain_id=t.id WHERE 1=1".($f['siswa_status']==='active'?' AND s.is_active=1':($f['siswa_status']==='archived'?' AND s.is_active=0':'')).($f['kelas']>0?' AND s.master_kelas_id='.(int)$f['kelas']:'')." GROUP BY s.id,t.id ORDER BY kelas,s.NAMA";
        $stmt=$db->prepare($sql);$stmt->bind_param('i',$masterId);$stmt->execute();$data=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();$period='Satu kali per tagihan';
    } else {
        $map=['pangkal'=>['PANGKAL','U_PANGKAL'],'bangunan'=>['BANGUNAN','U_BANGUNAN'],'seragam'=>['SERAGAM','U_SERAGAM'],'kegiatan'=>['KEGIATAN','U_KEGIATAN'],'makan'=>['MAKAN','U_MAKAN'],'sorga'=>['SORGA','U_SORGA'],'infaq'=>['INFAQ','U_INFAQ']];
        [$billField,$paidField]=$map[$category]??['PANGKAL','U_PANGKAL'];
        $bill=$category==='pangkal'?"CASE WHEN s.tot_pangkal>0 THEN s.tot_pangkal ELSE GREATEST(s.PANGKAL-s.potong_pangkal,0) END":"s.$billField";$paid="COALESCE(SUM(b.$paidField),0)";
        $sql="SELECT s.NO_INDUK no_induk,s.NAMA nama,".($f['kelas']>0?'mk2.kode_rombel':'mk.kode_rombel').",COALESCE(mk.tingkat,s.KELAS) tingkat,mk.is_placeholder,$bill tagihan,$paid terbayar
          FROM siswa s LEFT JOIN master_kelas mk ON mk.id=s.master_kelas_id LEFT JOIN master_kelas mk2 ON mk2.id=s.master_kelas_id LEFT JOIN bayar b ON b.NO_INDUK=s.NO_INDUK
          WHERE 1=1".($f['siswa_status']==='active'?' AND s.is_active=1':($f['siswa_status']==='archived'?' AND s.is_active=0':'')).($f['kelas']>0?' AND s.master_kelas_id='.(int)$f['kelas']:'')." GROUP BY s.id ORDER BY COALESCE(mk.tingkat,s.KELAS),mk.kode_rombel,s.NAMA";
        $data=$db->query($sql)->fetch_all(MYSQLI_ASSOC); foreach($data as &$item)$item['kelas']=class_label($item);unset($item);$period='Satu kali selama terdaftar';
    }
    foreach($data??[] as $item){$bill=(float)$item['tagihan'];$paid=(float)$item['terbayar'];$status=report_status_name($bill,$paid);$rows[]=['nis'=>$item['no_induk'],'nama'=>$item['nama'],'kelas'=>$item['kelas']??'-','periode'=>$period,'tagihan'=>$bill,'terbayar'=>$paid,'sisa'=>max(0,$bill-$paid),'status'=>$status,'_status'=>$status];}
    $rows=report_filter_rows($rows,$f);
    return ['title'=>$label,'subtitle'=>'Status kewajiban · '.$period,'columns'=>[['nis','NIS'],['nama','Nama Siswa'],['kelas','Kelas'],['periode','Periode'],['tagihan','Tagihan','money'],['terbayar','Terbayar','money'],['sisa','Sisa','money'],['status','Status','status']],'rows'=>$rows];
}

function report_payment_components(mysqli $db, array $f): array {
    $start=$f['tanggal_awal'].' 00:00:00';$end=date('Y-m-d H:i:s',strtotime($f['tanggal_akhir'].' +1 day'));
    $where=['b.TGL_BYR>=?','b.TGL_BYR<?'];$types='ss';$params=[$start,$end];
    if($f['kelas']>0){$where[]='b.master_kelas_id=?';$types.='i';$params[]=$f['kelas'];}
    if($f['operator']!==''){$where[]='CAST(b.user_id AS CHAR)=?';$types.='s';$params[]=$f['operator'];}
    if($f['metode']!==''){$where[]='b.sistem_pembayaran=?';$types.='s';$params[]=$f['metode'];}
    $stmt=$db->prepare("SELECT b.*,s.NAMA,COALESCE(op.nama,NULLIF(b.user_id,''),'-') operator_name FROM bayar b LEFT JOIN siswa s ON s.NO_INDUK=b.NO_INDUK LEFT JOIN admin op ON op.id=CAST(b.user_id AS UNSIGNED) WHERE ".implode(' AND ',$where).' ORDER BY b.TGL_BYR,b.id');
    $stmt->bind_param($types,...$params);$stmt->execute();$payments=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
    if(!$payments)return [];
    $ids=array_column($payments,'id');$idList=implode(',',array_map('intval',$ids));$extra=[];$du=[];
    foreach($db->query("SELECT bayar_id,master_biaya_lain_id,nama_biaya_snapshot,nominal_snapshot FROM bayar_biaya_lain WHERE bayar_id IN ($idList)")->fetch_all(MYSQLI_ASSOC) as $d)$extra[$d['bayar_id']][]=$d;
    foreach($db->query("SELECT bayar_id,jumlah FROM bayar_du WHERE bayar_id IN ($idList)")->fetch_all(MYSQLI_ASSOC) as $d)$du[$d['bayar_id']]=(float)$d['jumlah'];
    $map=['U_PANGKAL'=>'Uang Pangkal','U_BANGUNAN'=>'Uang Bangunan','U_SERAGAM'=>'Uang Seragam','U_KEGIATAN'=>'Uang Kegiatan','U_SPP'=>'SPP','U_KOMITE'=>'Komite','U_MAKAN'=>'Uang Makan','U_SORGA'=>'Uang Sorga','U_INFAQ'=>'Uang Infaq'];$rows=[];
    foreach($payments as $p){$base=['id'=>(int)$p['id'],'tanggal'=>$p['TGL_BYR'],'nomor'=>'TRX-'.str_pad((string)$p['id'],6,'0',STR_PAD_LEFT),'nis'=>$p['NO_INDUK'],'nama'=>$p['NAMA']??'-','kelas'=>$p['kelas_rombel_snapshot']?:$p['KELAS'],'metode'=>$p['sistem_pembayaran'],'operator'=>$p['operator_name']?:'-'];
      foreach($map as $field=>$name)if((float)$p[$field]>0)$rows[]=array_merge($base,['kategori_key'=>strtolower(substr($field,2)),'komponen'=>$name,'nominal'=>(float)$p[$field]]);
      if(($du[$p['id']]??0)>0)$rows[]=array_merge($base,['kategori_key'=>'daftar_ulang','komponen'=>'Daftar Ulang','nominal'=>$du[$p['id']]]);
      foreach($extra[$p['id']]??[] as $d)$rows[]=array_merge($base,['kategori_key'=>$d['master_biaya_lain_id']?'biaya_lain:'.$d['master_biaya_lain_id']:'biaya_lain','komponen'=>$d['nama_biaya_snapshot'],'nominal'=>(float)$d['nominal_snapshot']]);
      if((float)$p['potong_spp']>0)$rows[]=array_merge($base,['kategori_key'=>'potongan','komponen'=>'Potongan SPP','nominal'=>-(float)$p['potong_spp']]);
    }
    return $rows;
}

function report_receipt_data(mysqli $db,array $f):array{
    $rows=report_payment_components($db,$f);$category=$f['kategori'];if($category!==''&&$category!=='semua'){$rows=array_values(array_filter($rows,fn($r)=>$r['kategori_key']===$category));}
    if($f['q']!=='')$rows=array_values(array_filter($rows,fn($r)=>stripos($r['nis'].' '.$r['nama'],$f['q'])!==false));
    return ['title'=>'Rekap Penerimaan Harian','subtitle'=>$f['tanggal_awal'].' s/d '.$f['tanggal_akhir'],'columns'=>[['tanggal','Tanggal/Waktu'],['nomor','No. Transaksi'],['nis','NIS'],['nama','Nama Siswa'],['kelas','Kelas'],['komponen','Komponen'],['nominal','Nominal','money'],['metode','Metode'],['operator','Operator']],'rows'=>$rows];
}

function report_academic_months(string $label):array{[$a,$b]=array_map('intval',explode('/',$label));$out=[];for($m=7;$m<=12;$m++)$out[]=[$m,$a];for($m=1;$m<=6;$m++)$out[]=[$m,$b];return $out;}
function report_spp_year_data(mysqli $db,array $f):array{
    $stmt=$db->prepare("SELECT sta.id,sta.no_induk,s.NAMA nama,sta.kelas_rombel_snapshot kelas,sta.spp_perbulan_snapshot tarif FROM siswa_tahun_ajaran sta JOIN tahun_ajaran ta ON ta.id=sta.tahun_ajaran_id JOIN siswa s ON s.NO_INDUK=sta.no_induk WHERE ta.label=?".report_student_status_where($f).report_class_where($f)." ORDER BY sta.kelas_rombel_snapshot,s.NAMA");$stmt->bind_param('s',$f['tahun_ajaran']);$stmt->execute();$students=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
    $paid=[];$stmt=$db->prepare("SELECT bsp.no_induk,bsp.bulan,bsp.tahun,SUM(b.U_SPP) paid FROM bayar_spp_periode bsp JOIN bayar b ON b.id=bsp.bayar_id WHERE CONCAT(IF(CAST(bsp.bulan AS UNSIGNED)>=7,bsp.tahun,CAST(bsp.tahun AS UNSIGNED)-1),'/',IF(CAST(bsp.bulan AS UNSIGNED)>=7,CAST(bsp.tahun AS UNSIGNED)+1,bsp.tahun))=? GROUP BY bsp.no_induk,bsp.bulan,bsp.tahun");$stmt->bind_param('s',$f['tahun_ajaran']);$stmt->execute();foreach($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $p)$paid[$p['no_induk']][str_pad($p['bulan'],2,'0',STR_PAD_LEFT).'-'.$p['tahun']]=(float)$p['paid'];$stmt->close();
    $columns=[['nis','NIS'],['nama','Nama Siswa'],['kelas','Kelas']];$months=report_academic_months($f['tahun_ajaran']);foreach($months as [$m,$y])$columns[]=['m'.sprintf('%02d',$m).'_'.$y,(report_months()[sprintf('%02d',$m)]??$m).' '.$y,'html'];$columns=array_merge($columns,[['total_tagihan','Total Tagihan','money'],['total_bayar','Total Bayar','money'],['tunggakan','Tunggakan','money']]);$rows=[];
    foreach($students as $s){$row=['nis'=>$s['no_induk'],'nama'=>$s['nama'],'kelas'=>$s['kelas']?:'-'];$totalBill=0;$totalPaid=0;$arrears=0;$reconciliation=false;foreach($months as [$m,$y]){$code=sprintf('%02d',$m);$amount=$paid[$s['no_induk']][$code.'-'.$y]??0;$rate=(float)$s['tarif'];$status=report_status_name($rate,$amount);$row['m'.$code.'_'.$y]=['text'=>$status,'sub'=>report_money($amount),'status'=>report_status_key($status)];$totalBill+=$rate;$totalPaid+=$amount;$arrears+=max(0,$rate-$amount);if($status==='Perlu Rekonsiliasi')$reconciliation=true;}$row['total_tagihan']=$totalBill;$row['total_bayar']=$totalPaid;$row['tunggakan']=$arrears;$row['_status']=$reconciliation?'Perlu Rekonsiliasi':($arrears>.001?($totalPaid>0?'Cicilan':'Belum Bayar'):'Lunas');$rows[]=$row;}
    $rows=report_filter_rows($rows,$f);return ['title'=>'Rekap SPP Tahun Ajaran per Kelas','subtitle'=>'Tahun ajaran '.$f['tahun_ajaran'],'columns'=>$columns,'rows'=>$rows];
}

function report_periodic_item_data(mysqli $db,array $f,string $category,string $label,int $startMonth,int $endMonth,int $year):array{
    $months=range($startMonth,$endMonth);$academicByMonth=[];$academicLabels=[];
    foreach($months as $month){$academic=$month>=7?$year.'/'.($year+1):($year-1).'/'.$year;$academicByMonth[$month]=$academic;$academicLabels[$academic]=true;}
    $labels=array_keys($academicLabels);$placeholders=implode(',',array_fill(0,count($labels),'?'));
    $sql="SELECT sta.no_induk,s.NAMA nama,sta.master_kelas_id,sta.kelas_rombel_snapshot kelas,sta.spp_perbulan_snapshot spp_rate,sta.komite_snapshot komite_rate,ta.label
      FROM siswa_tahun_ajaran sta JOIN tahun_ajaran ta ON ta.id=sta.tahun_ajaran_id JOIN siswa s ON s.NO_INDUK=sta.no_induk
      WHERE ta.label IN ($placeholders) AND sta.status='aktif' AND s.is_active=1".($f['kelas']>0?' AND sta.master_kelas_id='.(int)$f['kelas']:'')." ORDER BY sta.kelas_rombel_snapshot,s.NAMA,sta.no_induk";
    $stmt=$db->prepare($sql);$types=str_repeat('s',count($labels));$stmt->bind_param($types,...$labels);$stmt->execute();$placements=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
    $studentRows=[];$billByMonth=[];
    foreach($placements as $placement){$nis=$placement['no_induk'];$studentRows[$nis]??=['nis'=>$nis,'nama'=>$placement['nama'],'classes'=>[]];$studentRows[$nis]['classes'][$placement['kelas']?:'-']=true;foreach($months as $month)if($academicByMonth[$month]===$placement['label'])$billByMonth[$nis][$month]=(float)$placement[$category==='spp'?'spp_rate':'komite_rate'];}
    $paidByMonth=[];$yearText=(string)$year;
    if($category==='spp'){
        $stmt=$db->prepare("SELECT bsp.no_induk,CAST(bsp.bulan AS UNSIGNED) bulan,SUM(b.U_SPP) paid FROM bayar_spp_periode bsp JOIN bayar b ON b.id=bsp.bayar_id WHERE bsp.tahun=? AND CAST(bsp.bulan AS UNSIGNED) BETWEEN ? AND ? GROUP BY bsp.no_induk,CAST(bsp.bulan AS UNSIGNED)");
    }else{
        $monthExpr=report_sql_month_expr('b.BULAN');$stmt=$db->prepare("SELECT b.NO_INDUK no_induk,$monthExpr bulan,SUM(b.U_KOMITE) paid FROM bayar b WHERE b.TAHUN=? AND $monthExpr BETWEEN ? AND ? GROUP BY b.NO_INDUK,$monthExpr");
    }
    $stmt->bind_param('sii',$yearText,$startMonth,$endMonth);$stmt->execute();foreach($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $paid)$paidByMonth[$paid['no_induk']][(int)$paid['bulan']]=(float)$paid['paid'];$stmt->close();
    $rows=[];
    foreach($studentRows as $nis=>$student){$bill=0;$paid=0;$arrears=0;$reconciliation=false;foreach($months as $month){if(!array_key_exists($month,$billByMonth[$nis]??[]))continue;$monthBill=$billByMonth[$nis][$month];$monthPaid=$paidByMonth[$nis][$month]??0;$bill+=$monthBill;$paid+=$monthPaid;$arrears+=max(0,$monthBill-$monthPaid);if($monthPaid>$monthBill+.001)$reconciliation=true;}$status=$reconciliation?'Perlu Rekonsiliasi':($bill<=.001&&$paid<=.001?'Tidak Ditagihkan':($paid<=.001?'Belum Bayar':($arrears>.001?'Cicilan':'Lunas')));$rows[]=['nis'=>$nis,'nama'=>$student['nama'],'kelas'=>implode(' / ',array_keys($student['classes'])),'periode'=>(report_months()[sprintf('%02d',$startMonth)]??$startMonth).($startMonth!==$endMonth?' - '.(report_months()[sprintf('%02d',$endMonth)]??$endMonth):'').' '.$year,'tagihan'=>$bill,'terbayar'=>$paid,'sisa'=>$arrears,'status'=>$status,'_status'=>$status];}
    $rows=report_filter_rows($rows,$f);
    return ['title'=>'Rekap Pembayaran per Item - '.$label,'subtitle'=>'Periode transaksi/tagihan terpilih','columns'=>[['nis','NIS'],['nama','Nama Siswa'],['kelas','Kelas'],['periode','Periode'],['tagihan','Tagihan','money'],['terbayar','Dibayar','money'],['sisa','Sisa','money'],['status','Status','status']],'rows'=>$rows];
}

function report_item_data(mysqli $db,array $f):array{
    $category=$f['kategori'];$categories=report_categories($db);$label=$categories[$category]??'SPP';
    $startMonth=min((int)$f['bulan_awal'],(int)$f['bulan_akhir']);$endMonth=max((int)$f['bulan_awal'],(int)$f['bulan_akhir']);$year=max(2000,$f['tahun']);
    if(in_array($category,['spp','komite'],true))return report_periodic_item_data($db,$f,$category,$label,$startMonth,$endMonth,$year);
    $f2=$f;$f2['tanggal_awal']=sprintf('%04d-%02d-01',$year,$startMonth);$f2['tanggal_akhir']=date('Y-m-t',strtotime(sprintf('%04d-%02d-01',$year,$endMonth)));$components=report_payment_components($db,$f2);
    $wanted=$category;$paid=[];foreach($components as $component)if($component['kategori_key']===$wanted)$paid[$component['nis']]=($paid[$component['nis']]??0)+(float)$component['nominal'];
    $statusData=report_status_data($db,$f);$rows=[];foreach($statusData['rows'] as $row){$row['terbayar']=$paid[$row['nis']]??0;$row['sisa']=max(0,(float)$row['tagihan']-$row['terbayar']);$row['status']=report_status_name((float)$row['tagihan'],(float)$row['terbayar']);$row['_status']=$row['status'];$row['periode']=report_months()[sprintf('%02d',$startMonth)].($startMonth!==$endMonth?'–'.report_months()[sprintf('%02d',$endMonth)]:'').' '.$year;$rows[]=$row;}
    $rows=report_filter_rows($rows??[],$f);return ['title'=>'Rekap Pembayaran per Item — '.$label,'subtitle'=>'Periode transaksi/tagihan terpilih','columns'=>[['nis','NIS'],['nama','Nama Siswa'],['kelas','Kelas'],['periode','Periode'],['tagihan','Tagihan','money'],['terbayar','Dibayar','money'],['sisa','Sisa','money'],['status','Status','status']],'rows'=>$rows];
}

function report_savings_transactions(mysqli $db,string $start,string $end,array $f):array{
    $where="x.tanggal>=? AND x.tanggal<?";$types='ss';$params=[$start,$end];if($f['operator']!==''){$where.=' AND CAST(x.user_id AS CHAR)=?';$types.='s';$params[]=$f['operator'];}
    $sql="SELECT x.*,s.NAMA,COALESCE(sta.master_kelas_id,s.master_kelas_id) master_kelas_id,
      COALESCE(sta.kelas_rombel_snapshot,CASE WHEN mk.is_placeholder=1 THEN CONCAT('Kelas ',COALESCE(mk.tingkat,s.KELAS),' (Belum Ditentukan)') ELSE CONCAT(mk.tingkat,UPPER(mk.kode_rombel)) END) kelas_label,
      mk.tingkat,mk.kode_rombel,mk.is_placeholder FROM (
      SELECT id,NO_INDUK,TANGGAL tanggal,MASUK masuk,0 keluar,user_id,'Masuk' jenis,1 sort_order FROM transaksi_m WHERE bayar_id IS NULL
      UNION ALL SELECT id,NO_INDUK,TANGGAL,0,KELUAR,user_id,'Keluar',2 sort_order FROM transaksi_k
    ) x JOIN siswa s ON s.NO_INDUK=x.NO_INDUK
      LEFT JOIN tahun_ajaran ta ON DATE(x.tanggal) BETWEEN ta.tanggal_mulai AND ta.tanggal_selesai
      LEFT JOIN siswa_tahun_ajaran sta ON sta.tahun_ajaran_id=ta.id AND sta.no_induk=x.NO_INDUK
      LEFT JOIN master_kelas mk ON mk.id=COALESCE(sta.master_kelas_id,s.master_kelas_id)
      WHERE $where ORDER BY x.tanggal,x.sort_order,x.id";
    $stmt=$db->prepare($sql);$stmt->bind_param($types,...$params);$stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();return $rows;
}
function report_savings_openings(mysqli $db,array $studentIds,string $before):array{
    $studentIds=array_values(array_unique(array_filter($studentIds)));if(!$studentIds)return [];$placeholders=implode(',',array_fill(0,count($studentIds),'?'));
    $sql="SELECT x.NO_INDUK,SUM(x.delta) saldo FROM (SELECT NO_INDUK,MASUK delta FROM transaksi_m WHERE bayar_id IS NULL AND TANGGAL<? UNION ALL SELECT NO_INDUK,-KELUAR FROM transaksi_k WHERE TANGGAL<?) x WHERE x.NO_INDUK IN ($placeholders) GROUP BY x.NO_INDUK";
    $stmt=$db->prepare($sql);$types='ss'.str_repeat('s',count($studentIds));$params=array_merge([$before,$before],$studentIds);$stmt->bind_param($types,...$params);$stmt->execute();$result=[];foreach($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row)$result[$row['NO_INDUK']]=(float)$row['saldo'];$stmt->close();return $result;
}
function report_savings_class_data(mysqli $db,array $f):array{
    $mode=in_array($f['mode'],['harian','bulanan'],true)?$f['mode']:'harian';$year=max(2000,$f['tahun']);$month=(int)$f['bulan_awal'];
    if($mode==='harian'){$periodStart=sprintf('%04d-%02d-01',$year,$month);$periodEnd=date('Y-m-d',strtotime($periodStart.' +1 month'));$parts=[];for($d=1;$d<=(int)date('t',strtotime($periodStart));$d++)$parts[]=sprintf('%04d-%02d-%02d',$year,$month,$d);}else{$periodStart="$year-01-01";$periodEnd=($year+1).'-01-01';$parts=[];for($m=1;$m<=12;$m++)$parts[]=sprintf('%04d-%02d',$year,$m);}
    $transactions=report_savings_transactions($db,$periodStart.' 00:00:00',$periodEnd.' 00:00:00',$f);$studentMap=[];foreach($transactions as $t){if($f['kelas']>0&&(int)$t['master_kelas_id']!==$f['kelas'])continue;$studentMap[$t['NO_INDUK']]=['nis'=>$t['NO_INDUK'],'nama'=>$t['NAMA'],'kelas'=>$t['kelas_label']?:class_label($t)];}
    $sql="SELECT s.NO_INDUK nis,s.NAMA nama,s.master_kelas_id,mk.tingkat,mk.kode_rombel,mk.is_placeholder FROM siswa s LEFT JOIN master_kelas mk ON mk.id=s.master_kelas_id WHERE s.is_active=1".($f['kelas']>0?' AND s.master_kelas_id='.(int)$f['kelas']:'').' ORDER BY s.NAMA';foreach($db->query($sql)->fetch_all(MYSQLI_ASSOC) as $s)$studentMap[$s['nis']]=['nis'=>$s['nis'],'nama'=>$s['nama'],'kelas'=>class_label($s)];
    $matrix=[];foreach($transactions as $t){if($f['kelas']>0&&(int)$t['master_kelas_id']!==$f['kelas'])continue;$key=$mode==='harian'?date('Y-m-d',strtotime($t['tanggal'])):date('Y-m',strtotime($t['tanggal']));$matrix[$t['NO_INDUK']][$key]['in']=($matrix[$t['NO_INDUK']][$key]['in']??0)+(float)$t['masuk'];$matrix[$t['NO_INDUK']][$key]['out']=($matrix[$t['NO_INDUK']][$key]['out']??0)+(float)$t['keluar'];}
    $columns=[['nis','NIS'],['nama','Nama Siswa'],['kelas','Kelas'],['saldo_awal','Saldo Awal','money']];foreach($parts as $part)$columns[]=['p'.$part,$mode==='harian'?date('d',strtotime($part)):report_months()[date('m',strtotime($part.'-01'))],'html'];$columns=array_merge($columns,[['total_masuk','Total Masuk','money'],['total_keluar','Total Keluar','money'],['saldo_akhir','Saldo Akhir','money']]);$rows=[];$openings=report_savings_openings($db,array_keys($studentMap),$periodStart.' 00:00:00');
    foreach($studentMap as $s){if($f['q']!==''&&stripos($s['nis'].' '.$s['nama'],$f['q'])===false)continue;$opening=$openings[$s['nis']]??0;$row=$s+['saldo_awal'=>$opening];$in=0;$out=0;foreach($parts as $part){$cell=$matrix[$s['nis']][$part]??['in'=>0,'out'=>0];$row['p'.$part]=['text'=>'M '.report_money($cell['in']),'sub'=>'K '.report_money($cell['out']),'status'=>($cell['in']||$cell['out'])?'lunas':''];$in+=$cell['in'];$out+=$cell['out'];}$row['total_masuk']=$in;$row['total_keluar']=$out;$row['saldo_akhir']=$opening+$in-$out;$rows[]=$row;}
    return ['title'=>'Rekap Mutasi Tabungan per Kelas','subtitle'=>($mode==='harian'?(report_months()[sprintf('%02d',$month)]??$month).' ':'Tahun ').$year,'columns'=>$columns,'rows'=>$rows];
}
function report_savings_student_data(mysqli $db,array $f):array{
    $start=$f['tanggal_awal'].' 00:00:00';$end=date('Y-m-d H:i:s',strtotime($f['tanggal_akhir'].' +1 day'));$transactions=report_savings_transactions($db,$start,$end,$f);$rows=[];$running=report_savings_openings($db,array_column($transactions,'NO_INDUK'),$start);
    foreach($transactions as $t){if($f['kelas']>0&&(int)$t['master_kelas_id']!==$f['kelas'])continue;if($f['q']!==''&&stripos($t['NO_INDUK'].' '.$t['NAMA'],$f['q'])===false)continue;$opening=$running[$t['NO_INDUK']]??0;$running[$t['NO_INDUK']]=$opening+(float)$t['masuk']-(float)$t['keluar'];$rows[]=['tanggal'=>$t['tanggal'],'nis'=>$t['NO_INDUK'],'nama'=>$t['NAMA'],'kelas'=>$t['kelas_label']?:class_label($t),'jenis'=>$t['jenis'],'masuk'=>(float)$t['masuk'],'keluar'=>(float)$t['keluar'],'saldo_awal'=>$opening,'saldo'=>$running[$t['NO_INDUK']],'operator'=>$t['user_id']?:'-'];}
    $mode=in_array($f['mode'],['buku','transaksi'],true)?$f['mode']:'buku';$columns=$mode==='buku'?[['tanggal','Tanggal/Waktu'],['nis','NIS'],['nama','Nama Siswa'],['jenis','Mutasi'],['masuk','Masuk','money'],['keluar','Keluar','money'],['saldo','Saldo Berjalan','money'],['operator','Operator']]:[['tanggal','Tanggal/Waktu'],['nis','NIS'],['nama','Nama Siswa'],['kelas','Kelas'],['jenis','Mutasi'],['masuk','Masuk','money'],['keluar','Keluar','money'],['operator','Operator']];return ['title'=>'Laporan Tabungan Siswa — '.($mode==='buku'?'Buku Saldo':'Daftar Transaksi'),'subtitle'=>$f['tanggal_awal'].' s/d '.$f['tanggal_akhir'],'columns'=>$columns,'rows'=>$rows];
}
function report_settlement_data(mysqli $db,array $f):array{
    $components=report_payment_components($db,$f);if($f['kategori']!==''&&$f['kategori']!=='semua')$components=array_values(array_filter($components,fn($row)=>$row['kategori_key']===$f['kategori']));$payByMethod=['Tunai'=>0,'VA'=>0,'Qris'=>0];foreach($components as $row)$payByMethod[$row['metode']]=($payByMethod[$row['metode']]??0)+(float)$row['nominal'];
    $start=$f['tanggal_awal'].' 00:00:00';$end=date('Y-m-d H:i:s',strtotime($f['tanggal_akhir'].' +1 day'));$savings=report_savings_transactions($db,$start,$end,$f);$savingIn=array_sum(array_column($savings,'masuk'));$savingOut=array_sum(array_column($savings,'keluar'));$cash=($payByMethod['Tunai']??0)+$savingIn-$savingOut;
    $rows=[['bagian'=>'Pendapatan pembayaran tunai','jenis'=>'Pendapatan sekolah','nominal'=>$payByMethod['Tunai']??0],['bagian'=>'Penerimaan Virtual Account','jenis'=>'Non-tunai','nominal'=>$payByMethod['VA']??0],['bagian'=>'Penerimaan QRIS','jenis'=>'Non-tunai','nominal'=>$payByMethod['Qris']??0],['bagian'=>'Tabungan masuk tunai','jenis'=>'Kewajiban tabungan','nominal'=>$savingIn],['bagian'=>'Tabungan keluar tunai','jenis'=>'Mutasi tabungan','nominal'=>-$savingOut],['bagian'=>'Kas fisik yang diserahkan','jenis'=>$cash<0?'Peringatan: kas negatif':'Tunai bersih','nominal'=>$cash]];
    return ['title'=>'Rekap Setoran Kas Harian','subtitle'=>$f['tanggal_awal'].' s/d '.$f['tanggal_akhir'].' · Data live, belum melalui tutup kas','columns'=>[['bagian','Komponen Setoran'],['jenis','Klasifikasi'],['nominal','Nominal','money']],'rows'=>$rows,'details'=>$components,'settlement'=>['cash'=>$cash,'payment_count'=>count(array_unique(array_column($components,'id')))]];
}
function report_build(mysqli $db,string $template,array $filters):array{
    if(in_array($template,['penerimaan','tabungan-siswa','setoran'],true)){
        $start=new DateTimeImmutable($filters['tanggal_awal']);$end=new DateTimeImmutable($filters['tanggal_akhir']);
        if($start->diff($end)->days>365)throw new LengthException('Rentang laporan dibatasi maksimal 366 hari kalender.');
    }
    return match($template){'status'=>report_status_data($db,$filters),'penerimaan'=>report_receipt_data($db,$filters),'spp-tahunan'=>report_spp_year_data($db,$filters),'per-item'=>report_item_data($db,$filters),'tabungan-kelas'=>report_savings_class_data($db,$filters),'tabungan-siswa'=>report_savings_student_data($db,$filters),'setoran'=>report_settlement_data($db,$filters),default=>throw new InvalidArgumentException('Template laporan tidak dikenali.')};
}
function report_summaries(array $rows):array{
    $moneyKeys=['nominal','terbayar','tagihan','sisa','total_bayar','tunggakan','total_masuk','total_keluar','saldo_akhir'];$summary=['Baris'=>count($rows)];foreach($moneyKeys as $key){$sum=0;$found=false;foreach($rows as $row)if(isset($row[$key])&&is_numeric($row[$key])){$sum+=(float)$row[$key];$found=true;}if($found)$summary[ucwords(str_replace('_',' ',$key))]=report_money($sum);}return array_slice($summary,0,5,true);
}
