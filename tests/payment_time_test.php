<?php
if(PHP_SAPI!=='cli')exit;
require_once __DIR__.'/../includes/payment_time.php';
$n=0;
function ptt(bool $ok,string $label): void {global $n;if(!$ok)throw new RuntimeException($label);$n++;}
foreach(['12:00:00 AM'=>'00:00:00','12:00:00 PM'=>'12:00:00','2:14:42 PM   '=>'14:14:42',' 01:14:42   pm   '=>'13:14:42','8:06:26 AM'=>'08:06:26','23:59:59'=>'23:59:59'] as $clock=>$expected){$r=payment_time_archive(['TGL_BYR'=>'2026-10-01','jam'=>$clock]);ptt($r['value']==='2026-10-01 '.$expected&&$r['precision']==='datetime','Source clock '.$clock);}
foreach([null,'','null','24:00:00','0:12:30 PM','12:60:00 AM','1:01:01 PM extra',1234] as $clock){$r=payment_time_archive(['TGL_BYR'=>'2026-10-01','jam'=>$clock]);ptt($r['precision']==='date'&&$r['value']==='2026-10-01 00:00:00','Invalid/missing clock');}
ptt(payment_time_archive(['TGL_BYR'=>'2026-02-30','jam'=>'1:00:00 PM'])['precision']==='none','Strict calendar');
ptt(payment_time_archive(['jam'=>'1:00:00 PM'])['precision']==='none','Clock never creates a date');
$r=payment_time_archive(['TGL_BYR'=>'2026-10-01 08:30:00','jam'=>'1:00:00 PM']);ptt($r['value']==='2026-10-01 08:30:00'&&$r['conflict'],'Embedded nonzero time wins conflict');
ptt(payment_time_archive(['TGL_BYR'=>'2026-10-01T00:00:00.0000000','jam'=>'1:00:00 PM'])['value']==='2026-10-01 13:00:00','SQL Server midnight padding');
ptt(payment_time_archive(['TGL_BYR'=>'2026-10-01T00:00:00.0000000','jam'=>null])['precision']==='date','SQL Server missing clock');
$e=['original'=>'2026-10-01 00:00:00','archive_at'=>'2026-10-01 13:00:00'];
ptt(payment_time_resolve('2026-10-01 00:00:00',$e)['payment_datetime']===$e['archive_at'],'Recover a proven source clock');
ptt(payment_time_resolve('2026-10-02 00:00:00',$e)['payment_datetime']==='2026-10-02 00:00:00','Do not reuse the clock after a calendar correction');
ptt(payment_time_resolve('2026-10-01 00:00:00',$e+['override_precision'=>'datetime'])['payment_datetime']==='2026-10-01 00:00:00','Explicit corrected midnight wins');
ptt(payment_time_resolve('2026-10-01 00:00:00',$e+['override_precision'=>'date'])['time_precision']==='date','Explicit unknown clock wins');
ptt(payment_time_native_update('2026-10-02','2026-10-01 08:12:34')==='2026-10-02 08:12:34','Native calendar edit preserves time');
ptt(payment_time_native_update('2026-10-02 00:00:00','2026-10-01 08:12:34')==='2026-10-02 00:00:00','Native explicit midnight');
ptt(spp_date_label('2026-10-01 00:00:00',true,'date')==='01/10/2026 · Jam tidak tercatat','Date-only display');
ptt(spp_date_label('2026-10-01 00:00:00',true,'datetime')==='01/10/2026 00:00:00 WIB','Real midnight display');
$stored=['source_date'=>'2026-10-01 00:00:00'];$display=['TGL_BYR'=>'2026-10-01 13:42:34','time_precision'=>'datetime'];
ptt(payment_time_edit_input(['date_day'=>'2026-10-01','date_clock'=>'13:42:34'],$stored,$display)===['date'=>'2026-10-01 00:00:00'],'Amount-only edit never writes the recovered clock');
ptt(payment_time_edit_input(['date_day'=>'2026-10-01','date_clock'=>'00:00'],$stored,$display)===['date'=>'2026-10-01 00:00:00','time_precision'=>'datetime'],'Explicit midnight edit');
ptt(payment_time_edit_input(['date_day'=>'2026-10-01','date_clock'=>''],$stored,$display)===['date'=>'2026-10-01','time_precision'=>'date'],'Explicit clock removal');
ptt(payment_time_edit_input(['date_day'=>'','date_clock'=>''],$stored,$display)===['date'=>null,'time_precision'=>'none'],'Explicit unknown date');
ptt(payment_time_edit_input(['date_day'=>'2026-10-01','date_clock'=>''],$stored,['TGL_BYR'=>$stored['source_date'],'time_precision'=>'date'])===['date'=>$stored['source_date']],'Unknown clock stays unknown on ordinary edits');
require_once __DIR__.'/../includes/authorization_history.php';
$before=['payment'=>['TGL_BYR'=>'2026-10-01 13:42:34','source_date'=>'2026-10-01 00:00:00','time_precision'=>'datetime'],'details'=>[]];
$input=['legacy_input'=>['date'=>'2026-10-01 00:00:00','period'=>'10 2026','note'=>'Catatan baru','items'=>[]]];
$after=authorization_proposed_snapshot($before,$input);ptt($after['payment']['TGL_BYR']===$before['payment']['TGL_BYR']&&$after['payment']['time_precision']==='datetime','Pending Legacy note proposal preserves the displayed archive clock');
$input['legacy_input']['time_precision']='date';$after=authorization_proposed_snapshot($before,$input);ptt($after['payment']['time_precision']==='date','Pending proposal explicitly removes a clock');
$input['legacy_input']['time_precision']='none';$input['legacy_input']['date']=null;$after=authorization_proposed_snapshot($before,$input);ptt($after['payment']['TGL_BYR']===null&&$after['payment']['time_precision']==='none','Pending proposal explicitly removes a date');
$after=authorization_proposed_snapshot(['payment'=>['TGL_BYR'=>'2026-10-01 08:12:34'],'details'=>[]],['tanggal_bayar'=>'2026-10-02']);ptt($after['payment']['TGL_BYR']==='2026-10-02 08:12:34','Pending native proposal preserves the original clock');
echo "PASS $n payment time cases\n";
