<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/date_format.php';
$cases=[
 ['2024-02-29',false,'29/02/2024'],['2023-02-29',false,'—'],['2024-04-31',false,'—'],
 ['2026-10-05 01:02:03',true,'05/10/2026 01:02:03 WIB'],['2026-10-05',true,'05/10/2026'],
 ['2026-10-04T18:02:03Z',true,'05/10/2026 01:02:03 WIB'],['2026-10-05T01:02:03+07:00',true,'05/10/2026 01:02:03 WIB'],
 ['',false,'—'],['0000-00-00',false,'—'],['01/02/2026',false,'—'],['2026-10-05 24:00:00',true,'—']
];
foreach($cases as [$input,$withTime,$expected])if(spp_date_label($input,$withTime)!==$expected)throw new RuntimeException('Date presentation mismatch: '.$input);
echo "PASS: strict calendar/ISO parsing, DD/MM/YYYY, datetime/WIB, leap year and day rollover\n";

