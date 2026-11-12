<?php
require __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
$db=$koneksi;$checks=0;
$db->begin_transaction();
try {
    foreach([1,2,3] as $unit) {
        foreach([false,true] as $out) {
            $sql=$out
                ? "SELECT s.NO_INDUK FROM siswa_data s JOIN student_out_periods_data o ON o.student_id=s.id AND o.unit_id=s.unit_id WHERE s.unit_id=? AND o.returned_at IS NULL LIMIT 1"
                : "SELECT s.NO_INDUK FROM siswa_data s LEFT JOIN master_kelas_data m ON m.id=s.master_kelas_id AND m.unit_id=s.unit_id WHERE s.unit_id=? AND s.is_active=1 AND s.KELAS<>'OUT' AND COALESCE(m.jenis,'')<>'out' AND NOT EXISTS(SELECT 1 FROM student_out_periods_data o WHERE o.unit_id=s.unit_id AND o.student_id=s.id AND o.returned_at IS NULL) LIMIT 1";
            $stmt=$db->prepare($sql);$stmt->bind_param('i',$unit);$stmt->execute();$nis=$stmt->get_result()->fetch_row()[0]??null;$stmt->close();
            if($nis===null)throw new RuntimeException('Restored active and OUT students are required in every unit.');
            $stmt=$db->prepare('CALL out_assert_student(?,?)');$stmt->bind_param('is',$unit,$nis);$rejected=false;
            try {$stmt->execute();}
            catch(mysqli_sql_exception $error) {
                if(!$out||$error->getCode()!==1644||!str_contains($error->getMessage(),'Siswa OUT'))throw $error;
                $rejected=true;
            } finally {$stmt->close();while($db->more_results()){$db->next_result();$result=$db->store_result();if($result)$result->free();}}
            if($rejected!==$out)throw new RuntimeException('The database guard must allow active students and reject OUT students.');
            $checks++;
        }
    }
} finally {$db->rollback();}
echo "PASS $checks database OUT guards across restored NIS and database collations; rolled back.\n";
