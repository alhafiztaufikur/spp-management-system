<?php
require_once __DIR__.'/legacy_operational_read.php';
require_once __DIR__.'/history_performance.php';
/** Source-owner class is a labelled import snapshot, not a transaction-date placement. */
function lop_report_class(mysqli $db,array $journal): array {
    static $maps=[];$unit=(int)$journal['unit_id'];$key=$db->thread_id.':'.$unit;
    if(!isset($maps[$key])){
        $maps[$key]=[];
        foreach(hp_rows($db,'SELECT * FROM master_kelas WHERE unit_id=?',[$unit]) as $c)$maps[$key][mb_strtoupper(class_label($c))][]=$c;
    }
    $label=trim((string)($journal['source_class']??''));
    $unknown=['kelas_label'=>$label!==''?$label:'Tidak tercatat','master_kelas_id'=>null,'tingkat'=>null,'kode_rombel'=>null,'is_placeholder'=>0,'class_basis'=>$label!==''?'source_label':'unknown','rombel_jenis'=>'legacy'];
    if($label==='')return $unknown;
    $academic=preg_match('/^(1[0-2]|[1-9])\s*([A-Z])$/Di',$label,$m)===1;
    [$min,$max]=unit_level_bounds($unit);
    if($academic&&(int)$m[1]>=$min&&(int)$m[1]<=$max){
        $canonical=(int)$m[1].strtoupper($m[2]);$matches=$maps[$key][$canonical]??[];
        $matches=array_values(array_filter($matches,static fn($c)=>class_kind($c)==='reguler'&&(int)$c['tingkat']===(int)$m[1]));
        $class=count($matches)===1?$matches[0]:null;
        return array_replace($unknown,['kelas_label'=>$canonical,'master_kelas_id'=>$class['id']??null,'tingkat'=>(int)$m[1],'kode_rombel'=>strtoupper($m[2]),'rombel_jenis'=>'reguler','class_basis'=>'source_academic_snapshot']);
    }
    // Explicit source-group aliases may identify custom/PSB/out/alumni classes.
    $id=(int)($journal['group_class_id']??0);
    if($id&&mb_strtoupper(trim((string)($journal['group_code']??'')))===mb_strtoupper($label)){
        foreach($maps[$key] as $classes)foreach($classes as $c)if((int)$c['id']===$id){
            if(class_kind($c)==='reguler'&&(int)$c['tingkat']>0)return $unknown;
            return array_replace($unknown,['master_kelas_id'=>$id,'tingkat'=>$c['tingkat'],'rombel_jenis'=>class_kind($c),'class_basis'=>'source_group_alias']);
        }
    }
    if(strtoupper($label)==='PSB')return array_replace($unknown,['kelas_label'=>'PSB','tingkat'=>0,'rombel_jenis'=>'psb','class_basis'=>'explicit_psb']);
    return $unknown;
}
function lop_savings_report_rows(mysqli $db,string $start,string $end,array $f): array {
    if(!lop_ready($db))return [];$timeOverrides=journal_time_overrides($db);$rows=[];$undated=!empty($f['include_undated'])?report_undated_journal_periods($db,'legacy_op_journals'):[];$selection='';if(($f['owner_id']??0)>0)$selection.=' AND j.owner_id='.(int)$f['owner_id'];if(($f['student_id']??0)>0)$selection.=' AND o.student_id='.(int)$f['student_id'];
    foreach(hp_rows($db,"SELECT j.*,CASE WHEN j.origin='new' THEN (SELECT MIN(a.actor_id) FROM legacy_op_audit a WHERE a.unit_id=j.unit_id AND a.entity_type='bank_new' AND a.entity_id=j.id) ELSE NULL END native_actor,o.name,o.student_id,o.source_identity,o.source_class,s.NO_INDUK,s.NO_induk_diknas,g.master_kelas_id group_class_id,g.code group_code,g.kind group_kind,g.label group_label FROM legacy_op_journals j JOIN legacy_op_owners o ON o.id=j.owner_id AND o.unit_id=j.unit_id LEFT JOIN siswa s ON s.id=o.student_id AND s.unit_id=o.unit_id LEFT JOIN legacy_op_groups g ON g.id=o.group_id AND g.unit_id=o.unit_id JOIN legacy_full_batches b ON b.id=j.batch_id AND b.unit_id=j.unit_id WHERE b.state='completed' AND j.cancelled=0 $selection AND ((j.source_date>=? AND j.source_date<?)".(!empty($f['include_undated'])?' OR j.source_date IS NULL':'').") ORDER BY j.source_date,j.id",[$start,$end]) as $j){
        if(empty($j['source_date'])&&!report_undated_matches($f,$undated[(int)$j['id']]??''))continue;
        if(($f['owner_id']??0)>0&&(int)$j['owner_id']!==(int)$f['owner_id'])continue;
        if(($f['student_id']??0)>0&&(int)$j['student_id']!==(int)$f['student_id'])continue;

        $operator=$j['origin']==='adjustment'?'':($j['origin']==='legacy'?lful_operator_key((int)$j['unit_id'],$j['source_operator']):(string)$j['source_operator']);
        $operatorKey=$j['origin']==='legacy'?$operator:(string)($j['native_actor']??$operator);if(($f['operator']??'')!==''&&$f['operator']!==$operatorKey)continue;if(isset($f['_multi']['operator'])&&!filter_is_all($f['_multi']['operator'])&&!in_array($operatorKey,$f['_multi']['operator'],true))continue;
        $class=lop_report_class($db,$j);
        $rows[]=$class+['id'=>-(1000000000+(int)$j['id']),'unit_id'=>$j['unit_id'],'NO_INDUK'=>$j['NO_INDUK']??'rekening:'.$j['owner_id'],'NAMA'=>$j['name'],'NO_induk_diknas'=>$j['NO_induk_diknas']??'','tanggal'=>$j['source_date'],'time_precision'=>journal_time_precision($db,-1000000000-(int)$j['id'],$j['origin'],$j['source_date'],$timeOverrides),'masuk'=>$j['origin']==='adjustment'?'0.00':$j['incoming'],'keluar'=>$j['origin']==='adjustment'?'0.00':$j['outgoing'],'user_id'=>$operator,'keterangan'=>$j['origin']==='adjustment'?'Penyesuaian Saldo Migrasi':($j['origin']==='legacy'?'Legacy · '.$j['note']:$j['note']),'jenis'=>$j['origin']==='adjustment'?'Penyesuaian Saldo Migrasi':(lf_cents($j['incoming'])>0?'Masuk':'Keluar'),'urutan_mutasi'=>lf_cents($j['incoming'])>0?0:1,'delta_opening'=>$j['origin']==='adjustment'?(float)$j['incoming']-(float)$j['outgoing']:0,'legacy_origin'=>$j['origin'],'owner_type'=>$j['student_id']?'student':'account','legacy_owner_id'=>$j['owner_id']];
    }return $rows;
}
function lop_savings_report_openings(mysqli $db,array $identities,string $before): array {
    if(!lop_ready($db)||!$identities)return [];$out=[];$marks=implode(',',array_fill(0,count($identities),'?'));
    foreach(hp_rows($db,"SELECT COALESCE(s.NO_INDUK,CONCAT('rekening:',o.id)) identity_key,SUM(j.incoming-j.outgoing) amount FROM legacy_op_journals j JOIN legacy_op_owners o ON o.id=j.owner_id AND o.unit_id=j.unit_id LEFT JOIN siswa s ON s.id=o.student_id AND s.unit_id=o.unit_id JOIN legacy_full_batches b ON b.id=j.batch_id AND b.unit_id=j.unit_id WHERE b.state='completed' AND j.cancelled=0 AND j.source_date<? AND COALESCE(s.NO_INDUK,CONCAT('rekening:',o.id)) IN ($marks) GROUP BY identity_key",array_merge([$before],$identities)) as $r)if(in_array($r['identity_key'],$identities,true))$out[$r['identity_key']]=(float)$r['amount'];return $out;
}
