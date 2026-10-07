<?php
require_once __DIR__.'/account_letter_ui.php';

function finance_icon(string $name): string {
    $paths = [
        'chart'=>'<path d="M4 20V11M10 20V4M16 20V8M22 20V2"/>',
        'card'=>'<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h3"/>',
        'in'=>'<path d="M12 21V3m-6 6 6-6 6 6M4 17v4h16v-4"/>',
        'out'=>'<path d="M12 3v18m-6-6 6 6 6-6M4 7V3h16v4"/>',
        'clock'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'calendar'=>'<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M8 2v4M16 2v4M3 10h18"/>',
        'list'=>'<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'pie'=>'<path d="M12 3v9h9M9 3.5a9 9 0 1 0 11.5 11.5"/>',
    ];
    return isset($paths[$name])?'<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$paths[$name].'</svg>':account_letter_icon($name);
}

/** Actual payment counts for the seven days ending on the report's last day. */
function finance_payment_trend(mysqli $db,string $lastDay,string $studentSql,array $studentParams): array {
    $firstDay=date('Y-m-d',strtotime($lastDay.' -6 days'));
    $end=date('Y-m-d',strtotime($lastDay.' +1 day'));
    $stmt=$db->prepare('SELECT DATE(b.TGL_BYR) day,COUNT(*) n FROM bayar b JOIN siswa s ON s.NO_INDUK=b.NO_INDUK AND s.unit_id=b.unit_id WHERE b.TGL_BYR>=? AND b.TGL_BYR<? '.$studentSql.' GROUP BY DATE(b.TGL_BYR)');
    $values=array_merge([$firstDay,$end],$studentParams);
    $stmt->bind_param('ss'.str_repeat('s',count($studentParams)),...$values);
    $stmt->execute();$counts=array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC),'n','day');$stmt->close();
    $result=[];
    for($i=0;$i<7;$i++){ $day=date('Y-m-d',strtotime($firstDay.' +'.$i.' days'));$result[$day]=(int)($counts[$day]??0); }
    return $result;
}
