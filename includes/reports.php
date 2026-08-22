<?php

require_once __DIR__ . '/daftar_ulang.php';
require_once __DIR__ . '/kelas.php';

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
        'status' => ['label'=>'Laporan Status Pembayaran','description'=>'Lihat siswa yang belum bayar, mencicil, atau sudah lunas per kategori.','icon'=>'✓','orientation'=>'portrait'],
        'penerimaan' => ['label'=>'Rekap Penerimaan Harian','description'=>'Rincian uang yang diterima berdasarkan tanggal transaksi aktual.','icon'=>'↗','orientation'=>'landscape'],
        'spp-tahunan' => ['label'=>'Rekap SPP Tahun Ajaran per Kelas','description'=>'Matriks SPP Juli–Juni untuk menemukan tunggakan per rombel.','icon'=>'▦','orientation'=>'landscape'],
        'per-item' => ['label'=>'Rekap Pembayaran per Item','description'=>'Rekap satu item pembayaran per siswa dan periode.','icon'=>'≡','orientation'=>'landscape'],
        'tabungan-kelas' => ['label'=>'Rekap Mutasi Tabungan per Kelas','description'=>'Mutasi masuk dan keluar per hari atau per bulan untuk satu rombel.','icon'=>'⇄','orientation'=>'landscape'],
        'tabungan-siswa' => ['label'=>'Rekap Transaksi Tabungan Siswa','description'=>'Daftar mutasi tabungan masuk dan keluar per siswa.','icon'=>'$','orientation'=>'portrait'],
        'setoran' => ['label'=>'Rekap Setoran Kas Harian','description'=>'Pisahkan pendapatan, non-tunai, dan kas fisik yang diserahkan.','icon'=>'▣','orientation'=>'portrait'],
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
    $allowedStatus = ['','tidak_ditagihkan','belum_bayar','cicilan','lunas','rekonsiliasi','ada_pembayaran','tunggakan'];
    $allowedMethods = ['','Tunai','VA','Qris'];
    $allowedModes = ['harian','bulanan','buku','transaksi'];
    $allowedStudentStatus = ['active','archived','all'];
    $requestedStudentStatus = (string)($source['siswa_status'] ?? 'active');
    $requestedPerPage = (int)($source['per_page'] ?? 25);
    $perPage = in_array($requestedPerPage, [25,50,100], true) ? $requestedPerPage : 25;
    $currentYear = du_current_academic_year();
    $academicYear = trim((string)($source['tahun_ajaran'] ?? $currentYear));
    if (!preg_match('/^\d{4}\/\d{4}$/', $academicYear)) $academicYear = $currentYear;
    return [
        'tanggal_awal'=>$start,'tanggal_akhir'=>$end,
        'tahun_ajaran'=>$academicYear,
        'bulan_awal'=>report_month_code($source['bulan_awal'] ?? date('m')),
        'bulan_akhir'=>report_month_code($source['bulan_akhir'] ?? ($source['bulan_awal'] ?? date('m'))),
        'tahun'=>(int)($source['tahun'] ?? date('Y')),
        'kelas'=>(int)($source['kelas'] ?? 0),
        'kategori'=>trim((string)($source['kategori'] ?? 'spp')),
        'status'=>in_array((string)($source['status'] ?? ''),$allowedStatus,true)?(string)($source['status']??''):'',
        'siswa_status'=>in_array($requestedStudentStatus,$allowedStudentStatus,true)?$requestedStudentStatus:'active',
        'operator'=>trim((string)($source['operator'] ?? '')),
        'metode'=>in_array((string)($source['metode'] ?? ''),$allowedMethods,true)?(string)($source['metode']??''):'',
        'q'=>mb_substr(trim((string)($source['q'] ?? '')),0,100),
        'mode'=>in_array((string)($source['mode'] ?? 'harian'),$allowedModes,true)?(string)($source['mode']??'harian'):'harian',
        'page'=>max(1,(int)($source['page']??1)),'per_page'=>$perPage,
    ];
}
function report_classes(mysqli $db): array { return class_all($db, true); }
function report_years(mysqli $db): array { return $db->query('SELECT label,status FROM tahun_ajaran ORDER BY label DESC')->fetch_all(MYSQLI_ASSOC); }
function report_operators(mysqli $db): array { return $db->query('SELECT id,username,nama,role FROM admin ORDER BY nama')->fetch_all(MYSQLI_ASSOC); }
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
          $sql="SELECT sta.no_induk,s.NO_induk_diknas nis_diknas,s.NAMA nama,sta.kelas_rombel_snapshot kelas,sta.$rate tagihan,COALESCE(SUM(b.U_SPP),0) terbayar
            FROM siswa_tahun_ajaran sta JOIN tahun_ajaran ta ON ta.id=sta.tahun_ajaran_id JOIN siswa s ON s.NO_INDUK=sta.no_induk
            LEFT JOIN bayar_spp_periode bsp ON bsp.no_induk=sta.no_induk AND bsp.tahun=? AND bsp.bulan=? LEFT JOIN bayar b ON b.id=bsp.bayar_id
            WHERE ta.label=?".report_student_status_where($f).report_class_where($f)." GROUP BY sta.id,s.NAMA,s.NO_induk_diknas ORDER BY sta.kelas_rombel_snapshot,s.NAMA";
        } else {
          $monthExpr=report_sql_month_expr('b.BULAN');
          $sql="SELECT sta.no_induk,s.NO_induk_diknas nis_diknas,s.NAMA nama,sta.kelas_rombel_snapshot kelas,sta.$rate tagihan,COALESCE(SUM(b.U_KOMITE),0) terbayar
            FROM siswa_tahun_ajaran sta JOIN tahun_ajaran ta ON ta.id=sta.tahun_ajaran_id JOIN siswa s ON s.NO_INDUK=sta.no_induk
            LEFT JOIN bayar b ON b.NO_INDUK=sta.no_induk AND b.TAHUN=? AND $monthExpr=CAST(? AS UNSIGNED)
            WHERE ta.label=?".report_student_status_where($f).report_class_where($f)." GROUP BY sta.id,s.NAMA,s.NO_induk_diknas ORDER BY sta.kelas_rombel_snapshot,s.NAMA";
        }
        $stmt=$db->prepare($sql); $stmt->bind_param('sss',$year,$month,$f['tahun_ajaran']); $stmt->execute(); $data=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
        $period=(report_months()[$month]??$month).' '.$year;
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
          LEFT JOIN bayar_biaya_lain d ON d.tagihan_biaya_lain_id=t.id WHERE 1=1".($f['siswa_status']==='active'?' AND s.is_active=1':($f['siswa_status']==='archived'?' AND s.is_active=0':'')).($f['kelas']>0?' AND s.master_kelas_id='.(int)$f['kelas']:'')." GROUP BY s.id,t.id ORDER BY kelas,s.NAMA";
        $stmt=$db->prepare($sql);$stmt->bind_param('i',$masterId);$stmt->execute();$data=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();$period='Satu kali per tagihan';
    } else {
        $map=['pangkal'=>['PANGKAL','PANGKAL_BAYAR'],'bangunan'=>['BANGUNAN','BANGUNAN_BAYAR'],'seragam'=>['SERAGAM','SERAGAM_BAYAR'],'kegiatan'=>['KEGIATAN','KEGIATAN_BAYAR'],'makan'=>['MAKAN','U_MAKAN'],'sorga'=>['SORGA','U_SORGA'],'infaq'=>['INFAQ','U_INFAQ']];
        [$billField,$paidField]=$map[$category]??['PANGKAL','PANGKAL_BAYAR'];
        if (str_ends_with($paidField,'_BAYAR')) $paid="s.$paidField"; else $paid="COALESCE(SUM(b.$paidField),0)";
        $sql="SELECT s.NO_INDUK no_induk,s.NO_induk_diknas nis_diknas,s.NAMA nama,".($f['kelas']>0?'mk2.kode_rombel':'mk.kode_rombel').",COALESCE(mk.tingkat,s.KELAS) tingkat,mk.is_placeholder,s.$billField tagihan,$paid terbayar
          FROM siswa s LEFT JOIN master_kelas mk ON mk.id=s.master_kelas_id LEFT JOIN master_kelas mk2 ON mk2.id=s.master_kelas_id LEFT JOIN bayar b ON b.NO_INDUK=s.NO_INDUK
          WHERE 1=1".($f['siswa_status']==='active'?' AND s.is_active=1':($f['siswa_status']==='archived'?' AND s.is_active=0':'')).($f['kelas']>0?' AND s.master_kelas_id='.(int)$f['kelas']:'')." GROUP BY s.id ORDER BY COALESCE(mk.tingkat,s.KELAS),mk.kode_rombel,s.NAMA";
        $data=$db->query($sql)->fetch_all(MYSQLI_ASSOC); foreach($data as &$item)$item['kelas']=class_label($item);unset($item);$period='Satu kali selama terdaftar';
    }
    foreach($data??[] as $item){$bill=(float)$item['tagihan'];$paid=(float)$item['terbayar'];$status=report_status_name($bill,$paid);$rows[]=['nis'=>$item['no_induk'],'nis_diknas'=>$item['nis_diknas']??'','nama'=>$item['nama'],'kelas'=>$item['kelas']??'-','periode'=>$period,'tagihan'=>$bill,'terbayar'=>$paid,'sisa'=>max(0,$bill-$paid),'status'=>$status,'_status'=>$status];}
    $rows=report_filter_rows($rows,$f);
    return ['title'=>$label,'subtitle'=>'Status kewajiban · '.$period,'columns'=>[['nis','NIS'],['nama','Nama Siswa'],['kelas','Kelas'],['periode','Periode'],['tagihan','Tagihan','money'],['terbayar','Terbayar','money'],['sisa','Sisa','money'],['status','Status','status']],'rows'=>$rows];
}

function report_payment_components(mysqli $db, array $f): array {
    $start=$f['tanggal_awal'].' 00:00:00';$end=date('Y-m-d H:i:s',strtotime($f['tanggal_akhir'].' +1 day'));
    $where=['b.TGL_BYR>=?','b.TGL_BYR<?'];$types='ss';$params=[$start,$end];
    if($f['kelas']>0){$where[]='b.master_kelas_id=?';$types.='i';$params[]=$f['kelas'];}
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

function report_item_data(mysqli $db,array $f):array{
    $category=$f['kategori'];$categories=report_categories($db);$label=$categories[$category]??'SPP';
    $startMonth=min((int)$f['bulan_awal'],(int)$f['bulan_akhir']);$endMonth=max((int)$f['bulan_awal'],(int)$f['bulan_akhir']);$year=max(2000,$f['tahun']);
    if(in_array($category,['spp','komite'],true)){
        $field=$category==='spp'?'U_SPP':'U_KOMITE';$rateField=$category==='spp'?'SPP_PERBULAN':'POMG';
        $monthExpr=report_sql_month_expr('b.BULAN');
        $sql="SELECT s.NO_INDUK nis,s.NO_induk_diknas nis_diknas,s.NAMA nama,".($f['kelas']>0?"mk.kode_rombel":"mk.kode_rombel").",COALESCE(mk.tingkat,s.KELAS) tingkat,mk.is_placeholder,
          s.$rateField*? tagihan,COALESCE(SUM(CASE WHEN b.TAHUN=? AND $monthExpr BETWEEN ? AND ? THEN b.$field ELSE 0 END),0) terbayar
          FROM siswa s LEFT JOIN master_kelas mk ON mk.id=s.master_kelas_id LEFT JOIN bayar b ON b.NO_INDUK=s.NO_INDUK WHERE s.is_active=1".($f['kelas']>0?' AND s.master_kelas_id='.(int)$f['kelas']:'')." GROUP BY s.id ORDER BY tingkat,mk.kode_rombel,s.NAMA";
        $count=$endMonth-$startMonth+1;$yearText=(string)$year;$stmt=$db->prepare($sql);$stmt->bind_param('isii',$count,$yearText,$startMonth,$endMonth);$stmt->execute();$data=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
        foreach($data as $item){$bill=(float)$item['tagihan'];$paid=(float)$item['terbayar'];$status=report_status_name($bill,$paid);$rows[]=['nis'=>$item['nis'],'nis_diknas'=>$item['nis_diknas']??'','nama'=>$item['nama'],'kelas'=>class_label($item),'periode'=>(report_months()[sprintf('%02d',$startMonth)]??$startMonth).($startMonth!==$endMonth?'–'.(report_months()[sprintf('%02d',$endMonth)]??$endMonth):'').' '.$year,'tagihan'=>$bill,'terbayar'=>$paid,'sisa'=>max(0,$bill-$paid),'status'=>$status,'_status'=>$status];}
    } else {
        $f2=$f;$f2['tanggal_awal']=sprintf('%04d-%02d-01',$year,$startMonth);$f2['tanggal_akhir']=date('Y-m-t',strtotime(sprintf('%04d-%02d-01',$year,$endMonth)));$components=report_payment_components($db,$f2);
        $wanted=$category;$paid=[];foreach($components as $component)if($component['kategori_key']===$wanted)$paid[$component['nis']]=($paid[$component['nis']]??0)+(float)$component['nominal'];
        $statusData=report_status_data($db,$f);$rows=[];foreach($statusData['rows'] as $row){$row['terbayar']=$paid[$row['nis']]??0;$row['sisa']=max(0,(float)$row['tagihan']-$row['terbayar']);$row['status']=report_status_name((float)$row['tagihan'],(float)$row['terbayar']);$row['_status']=$row['status'];$row['periode']=report_months()[sprintf('%02d',$startMonth)].($startMonth!==$endMonth?'–'.report_months()[sprintf('%02d',$endMonth)]:'').' '.$year;$rows[]=$row;}
    }
    $rows=report_filter_rows($rows??[],$f);return ['title'=>'Rekap Pembayaran per Item — '.$label,'subtitle'=>'Periode transaksi/tagihan terpilih','columns'=>[['nis','NIS'],['nama','Nama Siswa'],['kelas','Kelas'],['periode','Periode'],['tagihan','Tagihan','money'],['terbayar','Dibayar','money'],['sisa','Sisa','money'],['status','Status','status']],'rows'=>$rows];
}

function report_savings_transactions(mysqli $db,string $start,string $end,array $f):array{
    $where="x.tanggal>=? AND x.tanggal<?";$types='ss';$params=[$start,$end];$operatorJoin='';if($f['operator']!==''){$operatorJoin=report_operator_join('x.user_id','op');$where.=' AND '.report_operator_where('x.user_id','op');$types.='ssss';$params=array_merge($params,report_operator_params($f['operator']));}
    $sql="SELECT x.*,s.NAMA,s.NO_induk_diknas,COALESCE(sta.master_kelas_id,s.master_kelas_id) master_kelas_id,
      COALESCE(sta.kelas_rombel_snapshot,CASE WHEN mk.is_placeholder=1 THEN CONCAT('Kelas ',COALESCE(mk.tingkat,s.KELAS),' (Belum Ditentukan)') ELSE CONCAT(mk.tingkat,UPPER(mk.kode_rombel)) END) kelas_label,
      mk.tingkat,mk.kode_rombel,mk.is_placeholder FROM (
      SELECT id,NO_INDUK,TANGGAL tanggal,MASUK masuk,0 keluar,user_id,'Masuk' jenis FROM transaksi_m
      UNION ALL SELECT id,NO_INDUK,TANGGAL,0,KELUAR,user_id,'Keluar' FROM transaksi_k
    ) x JOIN siswa s ON s.NO_INDUK=x.NO_INDUK
      $operatorJoin
      LEFT JOIN tahun_ajaran ta ON DATE(x.tanggal) BETWEEN ta.tanggal_mulai AND ta.tanggal_selesai
      LEFT JOIN siswa_tahun_ajaran sta ON sta.tahun_ajaran_id=ta.id AND sta.no_induk=x.NO_INDUK
      LEFT JOIN master_kelas mk ON mk.id=COALESCE(sta.master_kelas_id,s.master_kelas_id)
      WHERE $where ORDER BY x.tanggal,x.id";
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
function report_savings_class_data(mysqli $db,array $f):array{
    $mode=in_array($f['mode'],['harian','bulanan'],true)?$f['mode']:'harian';$year=max(2000,$f['tahun']);$month=(int)$f['bulan_awal'];
    if($mode==='harian'){$periodStart=sprintf('%04d-%02d-01',$year,$month);$periodEnd=date('Y-m-d',strtotime($periodStart.' +1 month'));$parts=[];for($d=1;$d<=(int)date('t',strtotime($periodStart));$d++)$parts[]=sprintf('%04d-%02d-%02d',$year,$month,$d);}else{$periodStart="$year-01-01";$periodEnd=($year+1).'-01-01';$parts=[];for($m=1;$m<=12;$m++)$parts[]=sprintf('%04d-%02d',$year,$m);}
    $transactions=report_savings_transactions($db,$periodStart.' 00:00:00',$periodEnd.' 00:00:00',$f);$studentMap=[];foreach($transactions as $t){if($f['kelas']>0&&(int)$t['master_kelas_id']!==$f['kelas'])continue;$studentMap[$t['NO_INDUK']]=['nis'=>$t['NO_INDUK'],'nis_diknas'=>$t['NO_induk_diknas']??'','nama'=>$t['NAMA'],'kelas'=>$t['kelas_label']?:class_label($t)];}
    $sql="SELECT s.NO_INDUK nis,s.NO_induk_diknas nis_diknas,s.NAMA nama,s.master_kelas_id,mk.tingkat,mk.kode_rombel,mk.is_placeholder FROM siswa s LEFT JOIN master_kelas mk ON mk.id=s.master_kelas_id WHERE s.is_active=1".($f['kelas']>0?' AND s.master_kelas_id='.(int)$f['kelas']:'').' ORDER BY s.NAMA';foreach($db->query($sql)->fetch_all(MYSQLI_ASSOC) as $s)$studentMap[$s['nis']]=['nis'=>$s['nis'],'nis_diknas'=>$s['nis_diknas']??'','nama'=>$s['nama'],'kelas'=>class_label($s)];
    $matrix=[];foreach($transactions as $t){if($f['kelas']>0&&(int)$t['master_kelas_id']!==$f['kelas'])continue;$key=$mode==='harian'?date('Y-m-d',strtotime($t['tanggal'])):date('Y-m',strtotime($t['tanggal']));$matrix[$t['NO_INDUK']][$key]['in']=($matrix[$t['NO_INDUK']][$key]['in']??0)+(float)$t['masuk'];$matrix[$t['NO_INDUK']][$key]['out']=($matrix[$t['NO_INDUK']][$key]['out']??0)+(float)$t['keluar'];}
    $columns=[['nis','NIS'],['nama','Nama Siswa'],['kelas','Kelas'],['saldo_awal','Saldo Awal','money']];foreach($parts as $part)$columns[]=['p'.$part,$mode==='harian'?date('d',strtotime($part)):report_months()[date('m',strtotime($part.'-01'))],'html'];$columns=array_merge($columns,[['total_masuk','Total Masuk','money'],['total_keluar','Total Keluar','money'],['saldo_akhir','Saldo Akhir','money']]);$rows=[];$openings=report_savings_openings($db,array_keys($studentMap),$periodStart.' 00:00:00');
    foreach($studentMap as $s){if(!report_row_matches_query($s,$f['q']))continue;$opening=$openings[$s['nis']]??0;$row=$s+['saldo_awal'=>$opening];$in=0;$out=0;foreach($parts as $part){$cell=$matrix[$s['nis']][$part]??['in'=>0,'out'=>0];$row['p'.$part]=['text'=>'M '.report_money($cell['in']),'sub'=>'K '.report_money($cell['out']),'status'=>($cell['in']||$cell['out'])?'lunas':''];$in+=$cell['in'];$out+=$cell['out'];}$row['total_masuk']=$in;$row['total_keluar']=$out;$row['saldo_akhir']=$opening+$in-$out;$rows[]=$row;}
    return ['title'=>'Rekap Mutasi Tabungan per Kelas','subtitle'=>($mode==='harian'?(report_months()[sprintf('%02d',$month)]??$month).' ':'Tahun ').$year,'columns'=>$columns,'rows'=>$rows];
}
function report_savings_student_data(mysqli $db,array $f):array{
    $start=$f['tanggal_awal'].' 00:00:00';$end=date('Y-m-d H:i:s',strtotime($f['tanggal_akhir'].' +1 day'));$transactions=report_savings_transactions($db,$start,$end,$f);$rows=[];
    foreach($transactions as $t){if($f['kelas']>0&&(int)$t['master_kelas_id']!==$f['kelas'])continue;$candidate=['nis'=>$t['NO_INDUK'],'nis_diknas'=>$t['NO_induk_diknas']??'','nama'=>$t['NAMA'],'kelas'=>$t['kelas_label']?:class_label($t)];if(!report_row_matches_query($candidate,$f['q']))continue;$rows[]=$candidate+['tanggal'=>$t['tanggal'],'jenis'=>$t['jenis'],'masuk'=>(float)$t['masuk'],'keluar'=>(float)$t['keluar'],'operator'=>$t['user_id']?:'-'];}
    return ['title'=>'Rekap Transaksi Tabungan Siswa','subtitle'=>$f['tanggal_awal'].' s/d '.$f['tanggal_akhir'],'columns'=>[['tanggal','Tanggal/Waktu'],['nis','NIS'],['nama','Nama Siswa'],['kelas','Kelas'],['jenis','Mutasi'],['masuk','Masuk','money'],['keluar','Keluar','money'],['operator','Operator']],'rows'=>$rows];
}
function report_savings_student_data_legacy(mysqli $db,array $f):array{
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
    return match($template){'status'=>report_status_data($db,$filters),'penerimaan'=>report_receipt_data($db,$filters),'spp-tahunan'=>report_spp_year_data($db,$filters),'per-item'=>report_item_data($db,$filters),'tabungan-kelas'=>report_savings_class_data($db,$filters),'tabungan-siswa'=>report_savings_student_data($db,$filters),'setoran'=>report_settlement_data($db,$filters),default=>throw new InvalidArgumentException('Template laporan tidak dikenali.')};
}
function report_savings_transaction_totals(array $rows): array {
    $masuk=0;$keluar=0;foreach($rows as $row){$masuk+=(float)($row['masuk']??0);$keluar+=(float)($row['keluar']??0);}return ['total_masuk'=>$masuk,'total_keluar'=>$keluar,'selisih'=>$masuk-$keluar];
}
function report_money_totals(array $report, string $template=''): array {
    if($template==='setoran'){
        $payment=0;$savingIn=0;$savingOut=0;
        foreach($report['rows']??[] as $row){
            $label=(string)($row['bagian']??'');$amount=(float)($row['nominal']??0);
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
        if($masuk!==0.0||$keluar!==0.0)$totals[]=['label'=>'Selisih Bersih','value'=>$masuk-$keluar,'key'=>'selisih_bersih'];
    }
    return $totals;
}
function report_summaries(array $rows):array{
    $moneyKeys=['nominal','total_penerimaan','masuk','keluar','terbayar','tagihan','sisa','total_bayar','tunggakan','saldo_awal','total_masuk','total_keluar','saldo_akhir'];$summary=['Baris'=>count($rows)];foreach($moneyKeys as $key){$sum=0;$found=false;foreach($rows as $row)if(isset($row[$key])&&is_numeric($row[$key])){$sum+=(float)$row[$key];$found=true;}if($found)$summary[ucwords(str_replace('_',' ',$key))]=report_money($sum);}return array_slice($summary,0,5,true);
}
