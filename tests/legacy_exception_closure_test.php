<?php
/** Pure invariants: accepted unknowns remain unknown history, never fabricated tariffs or cash. */
require_once __DIR__.'/../includes/legacy_full.php';
function closure_assert(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
$key=hash('sha256','fee');$row=hash('sha256','row');$source=hash('sha256','source');
$item=['column'=>'JUMLAH1','category'=>'histori:'.str_repeat('a',32),'label'=>'Biaya Legacy tanpa kode (belum teridentifikasi)','amount'=>'17000.00','migration'=>['status'=>'unresolved','rule'=>'unidentified_fee','detail_key'=>$key,'source_key'=>$source,'source_row_key'=>$row,'priority'=>'priority','evidence'=>['candidate_names'=>[]]]];
$decision=['source_key'=>$source,'row_key'=>$row,'column'=>'JUMLAH1','category'=>$item['category'],'amount'=>'17000.00','priority'=>'priority','reason'=>'Keputusan klien menerima histori tanpa klasifikasi tambahan.','decision_id'=>'fixture-client-decision','actor_id'=>1,'closed_at'=>'2026-10-27T12:00:00+07:00'];
$closures=['fees'=>[$key=>$decision],'owners'=>['TEST'=>['identity'=>'TEST']]];
$closed=lec_item($item,$closures);closure_assert($closed['amount']===$item['amount']&&$closed['category']===$item['category'],'Penutupan mengubah nominal atau jenis histori');
closure_assert($closed['migration']['evidence']===$item['migration']['evidence']&&$closed['migration']['original_resolution']['label']===$item['label'],'Bukti/label sumber hilang');
closure_assert($closed['migration']['status']==='closed'&&lec_item($closed,$closures)===$closed,'Penutupan tidak idempoten');
$summary=lfet_summary([['items'=>[$closed]]]);closure_assert($summary['unresolved']===0&&$summary['closed']===1&&$summary['amount_cents']===1700000,'Ringkasan masih menganggap histori ditutup sebagai masalah');
$bad=$item;$bad['amount']='18000.00';try{lec_item($bad,$closures);throw new RuntimeException('Perubahan nominal tidak ditolak');}catch(DomainException $expected){}
$bad=$item;$bad['migration']['source_key']=hash('sha256','other-unit');try{lec_item($bad,$closures);throw new RuntimeException('Sumber berbeda tidak ditolak');}catch(DomainException $expected){}
$plan=['owners'=>['TEST'=>['group'=>'1'],'VALID'=>['group'=>'8A']],'groups'=>['1'=>['identities'=>['TEST']],'8A'=>['identities'=>['VALID']]],'pending'=>[['identity'=>'TEST','row'=>'test-row','code'=>'owner_review','section'=>'owner','message'=>'uji'],['identity'=>'VALID','row'=>'valid-row','code'=>'savings_review','section'=>'savings','message'=>'saldo']],'summary'=>['pending'=>2,'fee_unresolved'=>1,'fee_unresolved_priority'=>1,'fee_unresolved_historical'=>0,'fee_trace'=>['unresolved'=>1,'closed'=>0]]];
$preview=lec_preview($plan,$closures);closure_assert(!isset($preview['owners']['TEST'])&&isset($preview['owners']['VALID'])&&$preview['pending']===[ $plan['pending'][1] ],'Penutupan identitas uji mengubah pemilik/catatan valid');
closure_assert($preview['summary']['fee_unresolved']===0&&$preview['summary']['fee_closed']===1&&$preview['summary']['pending']===1,'Ringkasan cache belum mengikuti keputusan klien');
closure_assert(lec_preview($preview,$closures)===$preview,'Cache menghitung ulang penutupan dua kali');
echo "PASS: cash/source preservation, source and amount guards, closed counts, selective owner exclusion, cached preview and replay.\n";
