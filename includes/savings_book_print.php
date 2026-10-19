<?php
require_once __DIR__.'/savings_book.php';
require_once __DIR__.'/legacy_finance.php';
require_once __DIR__.'/legacy_full_read.php';
require_once __DIR__.'/migrated_history.php';

final class SavingsBookRequestError extends RuntimeException {}

function savings_book_owner_load(mysqli $db,int $ownerId): array {
    if(!lop_ready($db))throw new SavingsBookRequestError('Rekening tidak ditemukan.',404);
    $db->begin_transaction();
    try {
        $o=lf_one($db,"SELECT o.*,a.opening,a.balance,a.effective_date,g.master_kelas_id group_class_id FROM legacy_op_owners o LEFT JOIN legacy_op_groups g ON g.id=o.group_id AND g.unit_id=o.unit_id JOIN legacy_op_accounts a ON a.owner_id=o.id AND a.unit_id=o.unit_id JOIN legacy_full_batches b ON b.id=o.batch_id AND b.unit_id=o.unit_id WHERE o.id=? AND o.state='ready' AND b.state='completed'",[$ownerId]);
        if(!$o)throw new SavingsBookRequestError('Rekening tidak ditemukan dalam cakupan unit Anda.',404);
        if($o['student_id'])throw new SavingsBookRequestError('Gunakan identitas siswa untuk rekening siswa.',400);
        $legacy=[];$new=[];
        foreach(lf_rows($db,"SELECT id,source_date tanggal,incoming masuk,outgoing keluar,IF(incoming>0,0,1) urutan_mutasi,origin FROM legacy_op_journals WHERE owner_id=? AND cancelled=0 ORDER BY source_date,(origin='adjustment'),id",[$ownerId]) as $j){if($j['origin']==='new')$new[]=$j;else $legacy[]=$j;}
        $student=['id'=>0,'owner_id'=>$ownerId,'unit_id'=>$o['unit_id'],'NO_INDUK'=>$o['source_identity'],'NAMA'=>$o['name'],'KELAS'=>$o['source_class'],'kelas_label'=>$o['source_class'],'SALDO'=>$o['balance']];
        require_once __DIR__.'/kelas.php';if($o['group_class_id']&&($c=class_find($db,(int)$o['group_class_id'])))$student['kelas_label']=class_label($c);
        $book=savings_book_make($new,(float)$o['balance'],['amount'=>$o['opening'],'effective_date'=>$o['effective_date']],$legacy);
        $db->commit();return [$student,$book];
    }catch(Throwable $e){$db->rollback();throw $e;}
}

/** Read one student's complete ledger and balance in the same database snapshot. */
function savings_book_load(mysqli $db, string $nis, int $studentId = 0, int $unitId = 0): array
{
    $db->begin_transaction();
    try {
        $students=mh_students_source($db);
        $stmt = $db->prepare("SELECT s.id, s.NO_INDUK, s.unit_id, s.NO_induk_diknas, s.NAMA, s.KELAS, COALESCE(t.SALDO, 0) AS SALDO FROM $students s LEFT JOIN tabungan t ON t.NO_INDUK=s.NO_INDUK AND t.unit_id=s.unit_id WHERE s.NO_INDUK=? AND (?=0 OR s.id=?) AND (?=0 OR s.unit_id=?)");
        $stmt->bind_param('siiii', $nis, $studentId, $studentId, $unitId, $unitId);
        $stmt->execute();
        $matches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (count($matches) > 1) throw new SavingsBookRequestError('NIS ambigu. Pilih siswa dan unit dari Cetak Tabungan.', 409);
        if (!$matches) throw new SavingsBookRequestError('Siswa tidak ditemukan dalam cakupan unit Anda.', 404);
        $student = $matches[0];
        require_once __DIR__.'/kelas.php';
        $c=$db->prepare("SELECT mk.* FROM $students s JOIN master_kelas mk ON mk.id=s.master_kelas_id AND mk.unit_id=s.unit_id WHERE s.id=?");$c->bind_param('i',$student['id']);$c->execute();$class=$c->get_result()->fetch_assoc();$c->close();$student['kelas_label']=$class?class_label($class):$student['KELAS'];
        $unitId = (int)$student['unit_id'];
        try{lop_bank_assert_ready($db,(string)$student['NO_INDUK'],$unitId);}catch(DomainException $e){throw new SavingsBookRequestError($e->getMessage(),409);}
        $stmt = $db->prepare("SELECT x.id, x.tanggal, x.masuk, x.keluar, x.urutan_mutasi FROM (
            SELECT id, TANGGAL AS tanggal, MASUK AS masuk, 0 AS keluar, 0 AS urutan_mutasi FROM transaksi_m WHERE NO_INDUK=? AND unit_id=?
            UNION ALL
            SELECT id, TANGGAL AS tanggal, 0 AS masuk, KELUAR AS keluar, 1 AS urutan_mutasi FROM transaksi_k WHERE NO_INDUK=? AND unit_id=?
        ) x ORDER BY x.tanggal, x.urutan_mutasi, x.id");
        $stmt->bind_param('sisi', $nis, $unitId, $nis, $unitId);
        $stmt->execute();
        $transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $book = savings_book_make($transactions, (float)$student['SALDO'], lf_opening_for_student($db,$unitId,(int)$student['id']),lful_journals($db,(int)$student['id'],$unitId));
        $db->commit();
        return [$student, $book];
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
}

function savings_book_http_load(mysqli $db, string $nis, int $studentId = 0, int $unitId = 0): array
{
    try {
        return savings_book_load($db, $nis, $studentId, $unitId);
    } catch (SavingsBookRequestError $error) {
        book_error($error->getCode(), $error->getMessage());
    } catch (SavingsBookBalanceMismatch $error) {
        book_error(409, $error->getMessage());
    } catch (Throwable $error) {
        error_log('Gagal menyiapkan buku tabungan: '.$error->getMessage());
        book_error(500, 'Terjadi kesalahan saat menyiapkan buku tabungan. Coba lagi atau hubungi administrator.');
    }
}

function book_escape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function book_error(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Buku tabungan belum bisa dicetak</title><style>body{margin:0;padding:40px 16px;background:#eff7f2;color:#193329;font:16px/1.6 Arial,sans-serif}'
        . 'main{max-width:640px;margin:5vh auto;padding:28px;background:#fff;border:1px solid #cee5d6;border-radius:16px}h1{font-size:24px;margin:0 0 12px}'
        . 'a{color:#08794a;font-weight:bold}</style></head><body><main><h1>Buku tabungan belum bisa dicetak</h1><p>'
        . book_escape($message) . '</p><p><a href="cetak.php">Kembali ke Cetak Tabungan</a></p></main></body></html>';
    exit;
}

function savings_book_output(array $student, array $book, string $output, string $pdfUrl, string $backUrl, string $backLabel, ?array $settings=null): void
{
require_once __DIR__.'/savings_book_layout.php';
$settings=$settings??savings_book_print_settings([],count($book['entries']));
$nis = (string)$student['NO_INDUK'];
if ($output === 'preview'):
    require __DIR__.'/savings_book_preview.php';exit;
endif;
require_once __DIR__.'/savings_book_render.php';
try {$dompdf=savings_book_pdf($book,$settings,$output==='guide');}
catch(InvalidArgumentException $e){book_error(400,$e->getMessage());}
$filename=($settings['calibration']?'uji-kalibrasi-':'buku-tabungan-').preg_replace('/[^A-Za-z0-9_-]/','',$nis).'.pdf';
$dompdf->stream($filename,['Attachment'=>false]);
}
