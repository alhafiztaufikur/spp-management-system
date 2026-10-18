<?php
/** Export-only SQL timing; native mysqli behaviour and parameter binding are preserved. */
final class ReportMeasuredStatement extends mysqli_stmt {
    public function execute(?array $params=null):bool {
        $start=microtime(true);
        try{return parent::execute($params);}
        finally{$GLOBALS['report_sql_ms']=($GLOBALS['report_sql_ms']??0)+(microtime(true)-$start)*1000;}
    }
}
final class ReportMeasuredConnection extends mysqli {
    public function query(string $query,int $result_mode=MYSQLI_STORE_RESULT):mysqli_result|bool {
        $start=microtime(true);
        try{return parent::query($query,$result_mode);}
        finally{$GLOBALS['report_sql_ms']=($GLOBALS['report_sql_ms']??0)+(microtime(true)-$start)*1000;}
    }
    public function prepare(string $query):mysqli_stmt|false {
        $start=microtime(true);
        try{$statement=new ReportMeasuredStatement($this);if($statement->prepare($query))return $statement;$statement->close();return false;}
        finally{$GLOBALS['report_sql_ms']=($GLOBALS['report_sql_ms']??0)+(microtime(true)-$start)*1000;}
    }
}
