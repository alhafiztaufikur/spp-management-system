<?php
if(PHP_SAPI!=='cli'||!preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME')))exit('Gunakan salinan database uji.');
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/parent_letter_drafts.php';require_once __DIR__.'/../includes/pdf.php';
function rich_assert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function rich_reject(callable $task):void{$failed=false;try{$task();}catch(InvalidArgumentException|RuntimeException $e){$failed=true;}rich_assert($failed,'Permintaan tidak sah diterima.');}
$_SESSION=['admin_id'=>41,'admin_role'=>'super_admin','active_unit_id'=>0];unit_set_context($koneksi,0);
$students=parent_letter_collect($koneksi,['mode'=>'all'])['students'];
$selected=[];foreach([1,2,3] as $unit){$selected[]=array_values(array_filter($students,static fn($s)=>(int)$s['unit_id']===$unit))[0];}
$draft=parent_letter_draft_create(['students'=>$selected,'today'=>report_letter_today(),'unit'=>0]);$keys=array_map('unit_student_key',$selected);
$initial=parent_letter_draft_read($draft);$expiry=$initial['expires'];
$rich=['format'=>'rich_text','html'=>'<p onclick="bad()"><b>Pengingat &amp; kegiatan</b> <i>sekolah</i> <u>besok</u></p><ul><li>Bawa buku</li></ul><script>BAD_SCRIPT</script><img src="x" onerror="bad()"><a href="javascript:bad()">Tetap sopan</a>'];
$normalized=parent_letter_message_normalize($rich);
rich_assert(str_contains($normalized['html'],'<strong>')&&str_contains($normalized['html'],'<em>')&&str_contains($normalized['html'],'<u>')&&str_contains($normalized['html'],'<ul>'),'Format hilang.');
rich_assert(!preg_match('/onclick|script|img|href|BAD_SCRIPT/',$normalized['html']),'Konten aktif tersimpan.');
parent_letter_draft_update($draft,[$keys[1]=>'Pesan khusus yang dilindungi.']);
$applied=parent_letter_draft_apply($draft,$keys[0],$rich,[$keys[1],$keys[2],$keys[2]]);
rich_assert($applied['updated']===1&&$applied['skipped']===1,'Penerapan/duplikasi target salah.');
$saved=parent_letter_draft_read($draft);
rich_assert($saved['messages'][$keys[1]]==='Pesan khusus yang dilindungi.'&&$saved['messages'][$keys[2]]===$normalized,'Pesan lama atau format salinan berubah.');
rich_assert($saved['expires']===$expiry,'Masa berlaku draf diperpanjang.');
$before=$saved;rich_reject(fn()=>parent_letter_draft_apply($draft,$keys[0],$rich,[$keys[1],'99|foreign'],true));rich_assert(parent_letter_draft_read($draft)===$before,'Permintaan gagal memperbarui sebagian target.');
rich_reject(fn()=>parent_letter_draft_update($draft,[$keys[1]=>'Perubahan parsial','99|foreign'=>'Tidak sah']));rich_assert(parent_letter_draft_read($draft)===$before,'Simpan gagal memperbarui sebagian penerima.');
$replaced=parent_letter_draft_apply($draft,$keys[0],$rich,[$keys[1]],true);rich_assert($replaced['updated']===1,'Ganti pesan gagal.');
parent_letter_draft_update($draft,[$keys[1]=>'Pesan lama siswa kedua.',$keys[2]=>'Pesan lama siswa ketiga.']);
$allApplied=parent_letter_draft_apply($draft,$keys[0],$rich,[$keys[1],$keys[2]],true);
rich_assert($allApplied['updated']===2&&$allApplied['skipped']===0,'Penerapan semua melewati pesan lama.');
$allSaved=parent_letter_draft_read($draft);
foreach($keys as $key)rich_assert($allSaved['messages'][$key]===$normalized,'Pesan/format berbeda setelah penerapan semua.');
$allHtml=report_parent_letters_html($selected,$allSaved['today'],$allSaved['messages']);
rich_assert(substr_count($allHtml,'Pengingat &amp; kegiatan')===3,'Pratinjau surat tidak memakai pesan baru untuk setiap siswa.');
parent_letter_draft_update($draft,[$keys[1]=>'Khusus siswa kedua.']);rich_assert(parent_letter_draft_read($draft)['messages'][$keys[2]]===$normalized,'Salinan masih terhubung ke sumber.');
rich_reject(fn()=>parent_letter_draft_apply($draft,$keys[0],'',[$keys[2]]));rich_reject(fn()=>parent_letter_draft_apply($draft,$keys[0],$rich,[]));
rich_reject(fn()=>parent_letter_message_normalize(['format'=>'rich_text','html'=>'<p>'.str_repeat('a',2001).'</p>']));
rich_assert(mb_strlen(parent_letter_message_text(parent_letter_message_normalize(['format'=>'rich_text','html'=>'<p>'.str_repeat('😀',2000).'</p>'])))===2000,'Batas Unicode salah.');
$html=report_parent_letters_html($selected,$saved['today'],$saved['messages']);rich_assert(strpos($html,'Jumlah tunggakan')<strpos($html,'Pengingat')&&strpos($html,'Pengingat')<strpos($html,'Mohon Bapak/Ibu'),'Letak pesan salah.');
rich_assert(str_contains(parent_letter_message_html('<b>Teks lama</b>'),'&lt;b&gt;'),'Draf teks lama ditafsirkan sebagai HTML.');
rich_assert(parent_letter_message_html(['format'=>'rich_text','html'=>'<p><br></p>'])==='','Format kosong tidak dianggap kosong.');
$principal=report_principal_letter_html([],$saved['today']);
parent_letter_draft_update($draft,[$keys[0]=>['format'=>'rich_text','html'=>str_repeat('<p>Pengingat biaya pendidikan.</p>',55).'<p>AKHIR_FORMAT</p>']]);
$long=parent_letter_draft_read($draft);require_pdf_library();$pdf=new \Dompdf\Dompdf();$pdf->loadHtml(report_parent_letters_html([$selected[0]],$long['today'],$long['messages']),'UTF-8');$pdf->setPaper('A4','portrait');$pdf->render();rich_assert($pdf->getCanvas()->get_page_count()>1,'Pesan berformat panjang terpotong.');
if($out=getenv('SPP_UI_OUTPUT'))file_put_contents($out.'/rich-long.pdf',$pdf->output());
rich_assert($principal===report_principal_letter_html([],$saved['today']),'Surat kepala sekolah berubah.');
$_SESSION['active_unit_id']=1;rich_reject(fn()=>parent_letter_draft_read($draft));$_SESSION['active_unit_id']=0;
$_SESSION['admin_id']=999;rich_reject(fn()=>parent_letter_draft_read($draft));$_SESSION['admin_id']=41;
$_SESSION['parent_letter_drafts'][$draft]['expires']=time()-1;rich_reject(fn()=>parent_letter_draft_read($draft));
echo "OK: format aman, Unicode, kompatibilitas lama, salinan mandiri, penerapan atomik, pesan terlindungi, unit/pemilik/masa berlaku dan PDF panjang.\n";
