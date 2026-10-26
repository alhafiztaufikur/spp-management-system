<?php
require_once __DIR__.'/../includes/spp_student_tariff.php';
$checks=0;
foreach(['0'=>'0.00','0,00'=>'0.00','120.000'=>'120000.00','120.000,50'=>'120000.50','120000.50'=>'120000.50','1,5'=>'1.50','9.999.999.999.999,99'=>'9999999999999.99','0001'=>'1.00'] as $raw=>$expected){
    if(sct_amount((string)$raw)!==$expected)throw new RuntimeException('Wrong amount: '.$raw);$checks++;
}
foreach([null,[],true,0,'',' ','-1','1,234','1.23.456','NaN','1e6','10.000.000.000.000','1,2,3','1.00,50','1000xyz'] as $raw){
    try{sct_amount($raw);throw new RuntimeException('Invalid amount accepted: '.json_encode($raw));}catch(DomainException $error){$checks++;}
}
echo "PASS $checks final student tariff amount cases.\n";
