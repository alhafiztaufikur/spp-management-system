<?php
/** Opt-in SQL timing for exports and local request audits; native mysqli behaviour and parameter binding are preserved. */
final class ReportMeasuredStatement extends mysqli_stmt {
    private string $measuredSql='';
    public function prepare(string $query):bool {$this->measuredSql=$query;return parent::prepare($query);}
    public function execute(?array $params=null):bool {
        $start=microtime(true);
        try{$GLOBALS['report_sql_count']=($GLOBALS['report_sql_count']??0)+1;return parent::execute($params);}
        finally{$elapsed=(microtime(true)-$start)*1000;$GLOBALS['report_sql_ms']=($GLOBALS['report_sql_ms']??0)+$elapsed;if(getenv('SPP_REQUEST_METRICS')==='1')$GLOBALS['report_sql_events'][]=['ms'=>$elapsed,'sql'=>$this->measuredSql];}
    }
}
final class ReportMeasuredConnection extends mysqli {
    public function query(string $query,int $result_mode=MYSQLI_STORE_RESULT):mysqli_result|bool {
        $start=microtime(true);
        try{$GLOBALS['report_sql_count']=($GLOBALS['report_sql_count']??0)+1;return parent::query($query,$result_mode);}
        finally{$elapsed=(microtime(true)-$start)*1000;$GLOBALS['report_sql_ms']=($GLOBALS['report_sql_ms']??0)+$elapsed;if(getenv('SPP_REQUEST_METRICS')==='1')$GLOBALS['report_sql_events'][]=['ms'=>$elapsed,'sql'=>$query];}
    }
    public function prepare(string $query):mysqli_stmt|false {
        $start=microtime(true);
        try{$statement=new ReportMeasuredStatement($this);if($statement->prepare($query))return $statement;$statement->close();return false;}
        finally{$GLOBALS['report_sql_ms']=($GLOBALS['report_sql_ms']??0)+(microtime(true)-$start)*1000;}
    }
}
