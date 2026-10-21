<?php
require_once __DIR__.'/../includes/reports.php';
$rows=[];$categories=['psb','spp','komite','daftar_ulang','biaya_lain:1','biaya_lain:2','unknown'];
$names=['Adi','adi','Ádi','Siswa dengan nama panjang','Budi'];$classes=['2','02','2A','2B','10A'];
for($i=0;$i<1400;$i++)$rows[]=['tingkat'=>$i%4,'kelas'=>$classes[$i%5],'nama'=>$names[$i%5],'nis'=>['0012','12','1234','9900000000'][$i%4],
    'tahun_ajaran'=>['2025/2026','2026/2027',''][$i%3],'komponen_key'=>$categories[$i%7],'komponen'=>['SPP','Spp','Komite','Biaya lain'][$i%4],
    'periode_code'=>sprintf('2026-%02d',($i%12)+1),'periode'=>['Juli','Agustus','2026/2027'][$i%3],'source_index'=>$i];
$expected=$rows;
usort($expected,static function($a,$b){
    $student=[(int)($a['tingkat']??0),(string)($a['kelas']??''),mb_strtolower((string)($a['nama']??'')),(string)($a['nis']??'')]
        <=>[(int)($b['tingkat']??0),(string)($b['kelas']??''),mb_strtolower((string)($b['nama']??'')),(string)($b['nis']??'')];
    return $student!==0?$student:report_billing_history_compare_items($a,$b);
});
report_billing_history_sort_rows($rows);
if($rows!==$expected)throw new RuntimeException('Billing order, numeric-string comparisons or stable ties changed.');
echo "PASS 1,400 billing rows retain the exact previous sort order, including stable ties.\n";
