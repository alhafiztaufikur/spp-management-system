[CmdletBinding()]
param(
    [string]$SourceDatabase = 'db_spp_audit_20260820_090000',
    [string]$SuiteDatabase = ('db_spp_audit_' + (Get-Date -Format 'yyyyMMdd_HHmmss') + '_suite_' + (Get-Random -Minimum 1000 -Maximum 9999)),
    [string]$DatabaseUser = 'spp_audit_local',
    [int]$PortA = 8121,
    [int]$PortB = 8122,
    [string]$EvidenceRoot = ('C:\xampp\sistemspp-audit-evidence\20260820-090000-wave0\concurrency-' + (Get-Date -Format 'yyyyMMdd_HHmmss')),
    [switch]$AllowEmptyRootPassword
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$workspace = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$mysql = 'C:\xampp\mysql\bin\mysql.exe'
$mysqldump = 'C:\xampp\mysql\bin\mysqldump.exe'
$php = 'C:\xampp\php\php.exe'
$curl = 'C:\Windows\System32\curl.exe'
$serverProcesses = [System.Collections.Generic.List[System.Diagnostics.Process]]::new()
$blockerJobs = [System.Collections.Generic.List[object]]::new()
$sensitiveFiles = [System.Collections.Generic.List[string]]::new()
$databaseCreated = $false
$grantCreated = $false
$suiteCompleted = $false

if ($SourceDatabase -notmatch '^db_spp_audit_[0-9_]+$') {
    throw 'Source database must be a named audit database.'
}
if ($SuiteDatabase -notmatch '^db_spp_audit_[0-9_]+_suite_[0-9_]+$' -or $SuiteDatabase -eq 'db_spp') {
    throw 'Suite database identity is unsafe.'
}
if ($DatabaseUser -ne 'spp_audit_local') {
    throw 'Concurrency runner requires spp_audit_local.'
}
if ($PortA -eq $PortB) {
    throw 'Concurrency servers must use different ports.'
}
foreach ($path in @($mysql, $mysqldump, $php, $curl)) {
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        throw "Required executable is missing: $path"
    }
}
foreach ($port in @($PortA, $PortB)) {
    if (Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue) {
        throw "Port $port is already listening."
    }
}

New-Item -ItemType Directory -Path $EvidenceRoot -Force | Out-Null

function Invoke-MySqlRoot([string]$Sql, [string]$Label, [string]$Database = '') {
    $arguments = @('-u', 'root', '--batch', '--raw', '--skip-column-names')
    if (-not [string]::IsNullOrWhiteSpace($Database)) {
        $arguments += "--database=$Database"
    }
    $output = @($Sql | & $mysql @arguments 2>&1)
    if ($LASTEXITCODE -ne 0) {
        throw "$Label failed.`n$($output -join [Environment]::NewLine)"
    }
    return $output
}

function Add-SensitiveFile([string]$Path) {
    if (-not $sensitiveFiles.Contains($Path)) {
        $sensitiveFiles.Add($Path)
    }
}

function Write-CurlConfig {
    param(
        [string]$Path,
        [string]$Url,
        [string]$CookiePath,
        [string]$BodyPath,
        [string]$HeaderPath,
        [hashtable]$Fields = $null
    )
    $normalize = {
        param([string]$Value)
        return $Value.Replace('\', '/').Replace('"', '\"')
    }
    $lines = @(
        'silent',
        'show-error',
        ('url = "{0}"' -f (& $normalize $Url)),
        ('cookie = "{0}"' -f (& $normalize $CookiePath)),
        ('cookie-jar = "{0}"' -f (& $normalize $CookiePath)),
        ('output = "{0}"' -f (& $normalize $BodyPath)),
        ('dump-header = "{0}"' -f (& $normalize $HeaderPath)),
        'write-out = "%{http_code}\t%{time_total}"'
    )
    if ($null -ne $Fields) {
        foreach ($entry in $Fields.GetEnumerator()) {
            $field = ([string]$entry.Key) + '=' + ([string]$entry.Value)
            $lines += ('data-urlencode = "{0}"' -f (& $normalize $field))
        }
    }
    Set-Content -LiteralPath $Path -Value $lines -Encoding ascii
    Add-SensitiveFile $Path
}

function Invoke-CurlConfig([string]$ConfigPath) {
    $previousPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    $output = @(& $curl --config $ConfigPath 2>&1)
    $curlExitCode = $LASTEXITCODE
    $ErrorActionPreference = $previousPreference
    if ($curlExitCode -ne 0) {
        $safeConfig = if ($ConfigPath -like '*.login-get.curl') {
            (Get-Content -LiteralPath $ConfigPath -Raw)
        } else {
            '[redacted config]'
        }
        throw "curl failed for config $ConfigPath with exit $curlExitCode.`n$safeConfig`n$($output -join [Environment]::NewLine)"
    }
    return ($output -join '').Trim()
}

function Get-CsrfFromFile([string]$Path, [string]$Label) {
    $html = Get-Content -LiteralPath $Path -Raw
    $matches = [regex]::Matches($html, 'name=["'']csrf_token["''][^>]*value=["'']([^"'']+)["'']', 'IgnoreCase')
    if ($matches.Count -eq 0) {
        throw "CSRF token missing from $Label."
    }
    return [Net.WebUtility]::HtmlDecode($matches[$matches.Count - 1].Groups[1].Value)
}

function Get-HiddenValueFromFile([string]$Path, [string]$Name) {
    $html = Get-Content -LiteralPath $Path -Raw
    $pattern = 'name=["'']' + [regex]::Escape($Name) + '["''][^>]*value=["'']([^"'']*)["'']'
    $matches = [regex]::Matches($html, $pattern, 'IgnoreCase')
    if ($matches.Count -eq 0) {
        return ''
    }
    return [Net.WebUtility]::HtmlDecode($matches[$matches.Count - 1].Groups[1].Value)
}

function Get-HttpLocation([string]$HeaderPath) {
    $headers = Get-Content -LiteralPath $HeaderPath -Raw
    $match = [regex]::Match($headers, '(?im)^Location:\s*([^\r\n]+)')
    if ($match.Success) {
        return $match.Groups[1].Value.Trim()
    }
    return ''
}

function New-AuthenticatedCurlSession {
    param(
        [string]$Name,
        [string]$BaseUrl,
        [string]$Username,
        [string]$Password
    )
    $cookiePath = Join-Path $EvidenceRoot "$Name.cookies.txt"
    $getBody = Join-Path $EvidenceRoot "$Name.login-get.html"
    $getHeaders = Join-Path $EvidenceRoot "$Name.login-get.headers"
    $getConfig = Join-Path $EvidenceRoot "$Name.login-get.curl"
    foreach ($path in @($cookiePath, $getBody, $getHeaders)) { Add-SensitiveFile $path }
    Write-CurlConfig $getConfig "$BaseUrl/login.php" $cookiePath $getBody $getHeaders
    $getStatus = Invoke-CurlConfig $getConfig
    if (-not $getStatus.StartsWith('200')) { throw "Login GET failed for ${Name}: $getStatus" }
    $token = Get-CsrfFromFile $getBody "$Name login"

    $postBody = Join-Path $EvidenceRoot "$Name.login-post.body"
    $postHeaders = Join-Path $EvidenceRoot "$Name.login-post.headers"
    $postConfig = Join-Path $EvidenceRoot "$Name.login-post.curl"
    foreach ($path in @($postBody, $postHeaders)) { Add-SensitiveFile $path }
    Write-CurlConfig $postConfig "$BaseUrl/login.php" $cookiePath $postBody $postHeaders @{
        csrf_token = $token
        username = $Username
        password = $Password
    }
    $postStatus = Invoke-CurlConfig $postConfig
    if (-not $postStatus.StartsWith('302')) { throw "Login POST failed for ${Name}: $postStatus" }
    return [pscustomobject]@{
        Name = $Name
        BaseUrl = $BaseUrl
        CookiePath = $cookiePath
    }
}

function Get-SessionFormToken {
    param($Session, [string]$Path, [string]$Label)
    $body = Join-Path $EvidenceRoot "$($Session.Name).$Label.form.html"
    $headers = Join-Path $EvidenceRoot "$($Session.Name).$Label.form.headers"
    $config = Join-Path $EvidenceRoot "$($Session.Name).$Label.form.curl"
    foreach ($item in @($body, $headers)) { Add-SensitiveFile $item }
    Write-CurlConfig $config ($Session.BaseUrl + $Path) $Session.CookiePath $body $headers
    $status = Invoke-CurlConfig $config
    if (-not $status.StartsWith('200')) { throw "Form GET failed for ${Label}: $status" }
    return Get-CsrfFromFile $body $Label
}

function Start-BlockedMySqlTransaction {
    param([string]$Name, [string]$Sql)
    $sqlPath = Join-Path $EvidenceRoot "$Name.blocker.sql"
    Set-Content -LiteralPath $sqlPath -Value $Sql -Encoding utf8
    Add-SensitiveFile $sqlPath
    $job = Start-Job -ScriptBlock {
        param($MySqlExe, $Database, $SqlFile)
        Get-Content -LiteralPath $SqlFile -Raw | & $MySqlExe -u root --batch --raw --database=$Database
        if ($LASTEXITCODE -ne 0) { throw "Blocker mysql exited with $LASTEXITCODE" }
    } -ArgumentList $mysql, $SuiteDatabase, $sqlPath
    $blockerJobs.Add($job)
    $locked = $false
    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        Start-Sleep -Milliseconds 100
        $sleepProbe = Invoke-MySqlRoot "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB='$SuiteDatabase' AND INFO LIKE 'DO SLEEP(6)%';" "blocker $Name process probe"
        if ([int](($sleepProbe -join '').Trim()) -ge 1) { $locked = $true; break }
        if ($job.State -in @('Failed', 'Stopped', 'Completed')) { break }
    }
    if (-not $locked) {
        $detail = @(Receive-Job -Job $job -Keep -ErrorAction SilentlyContinue)
        throw "Blocker $Name did not report LOCKED. State=$($job.State) Output=$($detail -join '; ')"
    }
    return $job
}

function Start-ConcurrentCurl {
    param([string]$Name, [string]$ConfigPath)
    $statusPath = Join-Path $EvidenceRoot "$Name.status.txt"
    $errorPath = Join-Path $EvidenceRoot "$Name.stderr.txt"
    $process = Start-Process -FilePath $curl `
        -ArgumentList @('--config', ('"' + $ConfigPath + '"')) `
        -WindowStyle Hidden `
        -RedirectStandardOutput $statusPath `
        -RedirectStandardError $errorPath `
        -PassThru
    return [pscustomobject]@{
        Name = $Name
        Process = $process
        StatusPath = $statusPath
        ErrorPath = $errorPath
    }
}

function Complete-ConcurrentCurl($Worker) {
    $Worker.Process.WaitForExit()
    if (-not (Test-Path -LiteralPath $Worker.StatusPath -PathType Leaf)) {
        $detail = if (Test-Path -LiteralPath $Worker.ErrorPath) { Get-Content -LiteralPath $Worker.ErrorPath -Raw } else { '' }
        throw "$($Worker.Name) curl did not create a status artifact: $detail"
    }
    $raw = (Get-Content -LiteralPath $Worker.StatusPath -Raw).Trim()
    $parts = $raw -split "`t"
    if ($parts.Count -ne 2) { throw "$($Worker.Name) status output is malformed: $raw" }
    return [pscustomobject]@{
        Status = [int]$parts[0]
        Duration = [double]::Parse($parts[1], [Globalization.CultureInfo]::InvariantCulture)
    }
}

function Wait-Blocker($Job, [string]$Name) {
    Wait-Job -Job $Job -Timeout 20 | Out-Null
    if ($Job.State -ne 'Completed') {
        throw "Blocker $Name did not complete. State=$($Job.State)"
    }
    $output = @(Receive-Job -Job $Job -ErrorAction Stop)
    if ($output -notcontains 'RELEASED') {
        throw "Blocker $Name did not report RELEASED."
    }
}

$results = [System.Collections.Generic.List[object]]::new()
function Add-Result([string]$Scenario, [string]$Assertion, [bool]$Passed, [string]$Actual) {
    $results.Add([pscustomobject]@{
        scenario = $Scenario
        assertion = $Assertion
        result = if ($Passed) { 'PASS' } else { 'FAIL' }
        actual = $Actual
    })
}

try {
    if (-not $AllowEmptyRootPassword -and [string]::IsNullOrEmpty($env:SPP_AUDIT_ADMIN_DB_PASS)) {
        throw 'Empty root password refused. Use -AllowEmptyRootPassword only on the isolated local audit instance.'
    }
    $sourceExists = Invoke-MySqlRoot "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$SourceDatabase';" 'source identity probe'
    if (($sourceExists -join '').Trim() -ne $SourceDatabase) { throw 'Source audit database not found.' }

    $dumpPath = Join-Path $EvidenceRoot 'source-clone-dump.sql'
    Add-SensitiveFile $dumpPath
    & $mysqldump -u root --single-transaction --routines --triggers --databases $SourceDatabase --result-file=$dumpPath
    if ($LASTEXITCODE -ne 0) { throw 'Source audit dump failed.' }
    $dumpHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $dumpPath).Hash

    Invoke-MySqlRoot "CREATE DATABASE ``$SuiteDatabase`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 'suite create' | Out-Null
    $databaseCreated = $true
    $dumpSql = Get-Content -LiteralPath $dumpPath -Raw
    $dumpSql = $dumpSql.Replace(('`' + $SourceDatabase + '`'), ('`' + $SuiteDatabase + '`'))
    $importPath = Join-Path $EvidenceRoot 'suite-import.sql'
    Add-SensitiveFile $importPath
    Set-Content -LiteralPath $importPath -Value $dumpSql -Encoding utf8
    Get-Content -LiteralPath $importPath -Raw | & $mysql -u root
    if ($LASTEXITCODE -ne 0) { throw 'Suite import failed.' }

    Get-Content -LiteralPath (Join-Path $workspace 'sql\add_mutation_idempotency.sql') -Raw |
        & $mysql -u root --database=$SuiteDatabase
    if ($LASTEXITCODE -ne 0) { throw 'Idempotency migration failed on disposable suite database.' }

    Invoke-MySqlRoot "GRANT SELECT, INSERT, UPDATE, DELETE ON ``$SuiteDatabase``.* TO 'spp_audit_local'@'localhost'; FLUSH PRIVILEGES;" 'suite grant' | Out-Null
    $grantCreated = $true

    $password = 'Concurrent!' + [Guid]::NewGuid().ToString('N') + '9aA'
    $env:SPP_CONCURRENCY_PASSWORD = $password
    $hash = & $php -r 'echo password_hash(getenv(''SPP_CONCURRENCY_PASSWORD''), PASSWORD_DEFAULT);'
    if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($hash)) { throw 'Temporary password hash failed.' }
    $suffix = Get-Random -Minimum 100000 -Maximum 999999
    $adminA = "conc_${suffix}_admin_a"
    $adminB = "conc_${suffix}_admin_b"
    $kasirA = "conc_${suffix}_kasir_a"
    $kasirB = "conc_${suffix}_kasir_b"
    $nis = [string](9700000000 + (Get-Random -Minimum 10000 -Maximum 99999))
    $today = Get-Date -Format 'yyyy-MM-dd'
    $duStartYear = Get-Random -Minimum 2090 -Maximum 2098
    $duLabel = "$duStartYear/$($duStartYear + 1)"
    $duStartDate = "$duStartYear-07-01"
    $duEndDate = "$($duStartYear + 1)-06-30"
    $feeName = "Concurrency Fee $suffix"

    $fixtureSql = @"
UPDATE admin SET role='bendahara', session_version=session_version+1 WHERE role='admin';
INSERT INTO admin (username,password,nama,role,session_version,password_reset_required) VALUES
('$adminA','$hash','Concurrency Admin A','admin',1,0),
('$adminB','$hash','Concurrency Admin B','admin',1,0),
('$kasirA','$hash','Concurrency Kasir A','kasir',1,0),
('$kasirB','$hash','Concurrency Kasir B','kasir',1,0);
INSERT INTO siswa (NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,is_active)
VALUES ('$nis','Concurrency Student','1',(SELECT id FROM master_kelas WHERE tingkat=1 AND is_active=1 ORDER BY is_placeholder DESC,id LIMIT 1),1000.00,1);
INSERT INTO tabungan (NO_INDUK,SALDO) VALUES ('$nis',10000.00);
INSERT INTO tahun_ajaran (label,tanggal_mulai,tanggal_selesai,status) VALUES ('$duLabel','$duStartDate','$duEndDate','draft');
SET @concurrency_du_year_id = LAST_INSERT_ID();
INSERT INTO Daftar_ulang (tahun_ajaran_id,th_ajaran,kelas,Jumlah)
SELECT @concurrency_du_year_id,'$duLabel',kelas,5000.00 FROM (SELECT '1' kelas UNION ALL SELECT '2' UNION ALL SELECT '3' UNION ALL SELECT '4' UNION ALL SELECT '5' UNION ALL SELECT '6') classes;
INSERT INTO master_biaya_lain (nama,nominal,is_active) VALUES ('$feeName',7500.00,1);
"@
    Invoke-MySqlRoot $fixtureSql 'fixture setup' $SuiteDatabase | Out-Null
    $ids = Invoke-MySqlRoot "SELECT username,id FROM admin WHERE username IN ('$adminA','$adminB','$kasirA','$kasirB') ORDER BY username;" 'fixture account ids' $SuiteDatabase
    $idMap = @{}
    foreach ($row in $ids) {
        $parts = $row -split "`t"
        $idMap[$parts[0]] = [int]$parts[1]
    }
    if ($idMap.Count -ne 4) { throw 'Temporary account IDs are incomplete.' }

    $env:SPP_TEST_DISPOSABLE_SUITE = '1'
    $env:SPP_APP_ENV = 'test'
    $env:SPP_DB_HOST = '127.0.0.1'
    $env:SPP_DB_PORT = '3306'
    $env:SPP_DB_USER = $DatabaseUser
    $env:SPP_DB_NAME = $SuiteDatabase
    $router = Join-Path $workspace 'tests\support\router.php'
    foreach ($port in @($PortA, $PortB)) {
        $stdout = Join-Path $EvidenceRoot "server-$port.stdout.log"
        $stderr = Join-Path $EvidenceRoot "server-$port.stderr.log"
        $process = Start-Process -FilePath $php `
            -ArgumentList @('-S', "127.0.0.1:$port", '-t', $workspace, $router) `
            -WorkingDirectory $workspace `
            -WindowStyle Hidden `
            -RedirectStandardOutput $stdout `
            -RedirectStandardError $stderr `
            -PassThru
        $serverProcesses.Add($process)
    }
    foreach ($port in @($PortA, $PortB)) {
        $ready = $false
        for ($attempt = 0; $attempt -lt 30; $attempt++) {
            Start-Sleep -Milliseconds 200
            try {
                $response = Invoke-WebRequest -Uri "http://127.0.0.1:$port/login.php" -UseBasicParsing -TimeoutSec 2
                if ([int]$response.StatusCode -eq 200) { $ready = $true; break }
            } catch {
                # Bounded readiness polling.
            }
        }
        if (-not $ready) { throw "Server $port did not become ready." }
    }

    $baseA = "http://127.0.0.1:$PortA"
    $baseB = "http://127.0.0.1:$PortB"
    $sessionKasirA = New-AuthenticatedCurlSession 'kasir-a' $baseA $kasirA $password
    $sessionKasirB = New-AuthenticatedCurlSession 'kasir-b' $baseB $kasirB $password
    $savingTokenA = Get-SessionFormToken $sessionKasirA "/tabungan/keluar.php?nis=$nis" 'saving-withdraw'
    $savingTokenB = Get-SessionFormToken $sessionKasirB "/tabungan/keluar.php?nis=$nis" 'saving-withdraw'
    $savingKeyA = Get-HiddenValueFromFile (Join-Path $EvidenceRoot "$($sessionKasirA.Name).saving-withdraw.form.html") 'idempotency_key'
    $savingKeyB = Get-HiddenValueFromFile (Join-Path $EvidenceRoot "$($sessionKasirB.Name).saving-withdraw.form.html") 'idempotency_key'
    if ($savingKeyA -notmatch '^[a-f0-9]{64}$' -or $savingKeyB -notmatch '^[a-f0-9]{64}$') {
        throw 'Withdrawal forms did not provide strong idempotency keys.'
    }

    $withdrawConfigA = Join-Path $EvidenceRoot 'withdraw-a.curl'
    $withdrawConfigB = Join-Path $EvidenceRoot 'withdraw-b.curl'
    $withdrawBodyA = Join-Path $EvidenceRoot 'withdraw-a.body'
    $withdrawBodyB = Join-Path $EvidenceRoot 'withdraw-b.body'
    $withdrawHeaderA = Join-Path $EvidenceRoot 'withdraw-a.headers'
    $withdrawHeaderB = Join-Path $EvidenceRoot 'withdraw-b.headers'
    foreach ($path in @($withdrawBodyA,$withdrawBodyB,$withdrawHeaderA,$withdrawHeaderB)) { Add-SensitiveFile $path }
    Write-CurlConfig $withdrawConfigA "$baseA/tabungan/proses.php" $sessionKasirA.CookiePath $withdrawBodyA $withdrawHeaderA @{
        csrf_token=$savingTokenA;idempotency_key=$savingKeyA;aksi='keluar';no_induk=$nis;tanggal=$today;nominal='8000';keterangan='Concurrency withdrawal A'
    }
    Write-CurlConfig $withdrawConfigB "$baseB/tabungan/proses.php" $sessionKasirB.CookiePath $withdrawBodyB $withdrawHeaderB @{
        csrf_token=$savingTokenB;idempotency_key=$savingKeyB;aksi='keluar';no_induk=$nis;tanggal=$today;nominal='8000';keterangan='Concurrency withdrawal B'
    }
    $savingBlocker = Start-BlockedMySqlTransaction 'saving' @"
START TRANSACTION;
SELECT id FROM siswa WHERE NO_INDUK='$nis' FOR UPDATE;
SELECT 'LOCKED';
DO SLEEP(6);
COMMIT;
SELECT 'RELEASED';
"@
    $withdrawWorkerA = Start-ConcurrentCurl 'withdraw-a' $withdrawConfigA
    $withdrawWorkerB = Start-ConcurrentCurl 'withdraw-b' $withdrawConfigB
    $withdrawResultA = Complete-ConcurrentCurl $withdrawWorkerA
    $withdrawResultB = Complete-ConcurrentCurl $withdrawWorkerB
    Wait-Blocker $savingBlocker 'saving'

    $savingState = @(Invoke-MySqlRoot "SELECT t.SALDO,(SELECT COUNT(*) FROM transaksi_k k WHERE k.NO_INDUK='$nis'),(SELECT COALESCE(SUM(k.KELUAR),0) FROM transaksi_k k WHERE k.NO_INDUK='$nis'),(SELECT COUNT(*) FROM audit_event WHERE entity_type='transaksi_k' AND event_type='savings.withdrawn' AND JSON_UNQUOTE(JSON_EXTRACT(after_data,'$.no_induk'))='$nis') FROM tabungan t WHERE t.NO_INDUK='$nis';" 'saving state' $SuiteDatabase)
    $savingParts = $savingState[0] -split "`t"
    $savingLocations = @((Get-HttpLocation $withdrawHeaderA), (Get-HttpLocation $withdrawHeaderB))
    Add-Result 'TC-SAV-002' 'kedua request mencapai handler dan menunggu row lock' ($withdrawResultA.Duration -ge 4 -and $withdrawResultB.Duration -ge 4) "duration_a=$($withdrawResultA.Duration);duration_b=$($withdrawResultB.Duration)"
    Add-Result 'TC-SAV-002' 'kedua response aman tanpa 500' ($withdrawResultA.Status -eq 302 -and $withdrawResultB.Status -eq 302) "status_a=$($withdrawResultA.Status);status_b=$($withdrawResultB.Status)"
    $successfulWithdrawRedirects = @($savingLocations | Where-Object { $_ -match 'riwayat\.php' })
    $rejectedWithdrawRedirects = @($savingLocations | Where-Object { $_ -match 'keluar\.php' })
    Add-Result 'TC-SAV-002' 'tepat satu withdrawal commit dan satu ditolak' ($successfulWithdrawRedirects.Count -eq 1 -and $rejectedWithdrawRedirects.Count -eq 1) "locations=$($savingLocations -join ',')"
    Add-Result 'TC-SAV-002' 'saldo tidak negatif dan ledger/cache seimbang' ([decimal]$savingParts[0] -eq 2000 -and [int]$savingParts[1] -eq 1 -and [decimal]$savingParts[2] -eq 8000) "saldo=$($savingParts[0]);rows=$($savingParts[1]);sum=$($savingParts[2])"
    Add-Result 'TC-SAV-002' 'audit hanya untuk commit sukses' ([int]$savingParts[3] -eq 1) "audit_events=$($savingParts[3])"

    $replayToken = Get-SessionFormToken $sessionKasirA "/tabungan/masuk.php?nis=$nis" 'saving-replay'
    $replayFormPath = Join-Path $EvidenceRoot "$($sessionKasirA.Name).saving-replay.form.html"
    $replayKey = Get-HiddenValueFromFile $replayFormPath 'idempotency_key'
    Add-Result 'TC-SAV-002' 'form menyediakan idempotency key kuat' ($replayKey -match '^[a-f0-9]{64}$') "key_present=$(-not [string]::IsNullOrWhiteSpace($replayKey))"
    $replayFields = @{
        csrf_token=$replayToken;aksi='masuk';no_induk=$nis;tanggal=$today;nominal='1000';keterangan='Replay probe'
    }
    if (-not [string]::IsNullOrWhiteSpace($replayKey)) {
        $replayFields.idempotency_key = $replayKey
    }
    $replayConfigA = Join-Path $EvidenceRoot 'replay-a.curl'
    $replayConfigB = Join-Path $EvidenceRoot 'replay-b.curl'
    $replayBodyA = Join-Path $EvidenceRoot 'replay-a.body'
    $replayBodyB = Join-Path $EvidenceRoot 'replay-b.body'
    $replayHeaderA = Join-Path $EvidenceRoot 'replay-a.headers'
    $replayHeaderB = Join-Path $EvidenceRoot 'replay-b.headers'
    foreach ($path in @($replayBodyA,$replayBodyB,$replayHeaderA,$replayHeaderB)) { Add-SensitiveFile $path }
    Write-CurlConfig $replayConfigA "$baseA/tabungan/proses.php" $sessionKasirA.CookiePath $replayBodyA $replayHeaderA $replayFields
    Write-CurlConfig $replayConfigB "$baseA/tabungan/proses.php" $sessionKasirA.CookiePath $replayBodyB $replayHeaderB $replayFields
    $replayStatusA = Invoke-CurlConfig $replayConfigA
    $replayStatusB = Invoke-CurlConfig $replayConfigB
    $replayLocations = @((Get-HttpLocation $replayHeaderA), (Get-HttpLocation $replayHeaderB))
    $replayState = @(Invoke-MySqlRoot "SELECT t.SALDO,(SELECT COUNT(*) FROM transaksi_m m WHERE m.NO_INDUK='$nis'),(SELECT COALESCE(SUM(m.MASUK),0) FROM transaksi_m m WHERE m.NO_INDUK='$nis'),(SELECT COUNT(*) FROM audit_event WHERE entity_type='transaksi_m' AND event_type='savings.deposited' AND JSON_UNQUOTE(JSON_EXTRACT(after_data,'$.no_induk'))='$nis') FROM tabungan t WHERE t.NO_INDUK='$nis';" 'saving replay state' $SuiteDatabase)
    $replayParts = $replayState[0] -split "`t"
    $replaySuccessRedirects = @($replayLocations | Where-Object { $_ -match 'riwayat\.php' })
    $replayRejectedRedirects = @($replayLocations | Where-Object { $_ -match 'masuk\.php' })
    Add-Result 'TC-SAV-002' 'replay mendapat response aman' ($replayStatusA.StartsWith('302') -and $replayStatusB.StartsWith('302')) "status_a=$replayStatusA;status_b=$replayStatusB"
    Add-Result 'TC-SAV-002' 'replay hanya satu kali sukses' ($replaySuccessRedirects.Count -eq 1 -and $replayRejectedRedirects.Count -eq 1) "locations=$($replayLocations -join ',')"
    Add-Result 'TC-SAV-002' 'replay tidak menggandakan saldo/jurnal/audit' ([decimal]$replayParts[0] -eq 3000 -and [int]$replayParts[1] -eq 1 -and [decimal]$replayParts[2] -eq 1000 -and [int]$replayParts[3] -eq 1) "saldo=$($replayParts[0]);rows=$($replayParts[1]);sum=$($replayParts[2]);audit=$($replayParts[3])"

    # Concurrent payment probe: both operators target the same student/period
    # with distinct idempotency keys. The student/period locks and unique claim
    # must leave exactly one committed payment and one rejected request.
    $paymentJulyToken = Get-SessionFormToken $sessionKasirA "/pembayaran/form.php" 'payment-prerequisite'
    $paymentJulyFormPath = Join-Path $EvidenceRoot "$($sessionKasirA.Name).payment-prerequisite.form.html"
    $paymentJulyKey = Get-HiddenValueFromFile $paymentJulyFormPath 'idempotency_key'
    if ($paymentJulyKey -notmatch '^[a-f0-9]{64}$') { throw 'Payment prerequisite form did not provide a strong idempotency key.' }
    $paymentJulyConfig = Join-Path $EvidenceRoot 'payment-prerequisite.curl'
    $paymentJulyBody = Join-Path $EvidenceRoot 'payment-prerequisite.body'
    $paymentJulyHeader = Join-Path $EvidenceRoot 'payment-prerequisite.headers'
    foreach ($path in @($paymentJulyBody,$paymentJulyHeader)) { Add-SensitiveFile $path }
    Write-CurlConfig $paymentJulyConfig "$baseA/pembayaran/proses.php" $sessionKasirA.CookiePath $paymentJulyBody $paymentJulyHeader @{
        csrf_token=$paymentJulyToken;idempotency_key=$paymentJulyKey;aksi='input';payment_plan='monthly';no_induk=$nis;tanggal_bayar=$today;bulan_bayar='07';tahun_bayar='2026';sistem_pembayaran='Tunai';uang_spp='1000';audit_reason='Concurrency payment prerequisite'
    }
    $paymentJulyStatus = Invoke-CurlConfig $paymentJulyConfig
    if (-not $paymentJulyStatus.StartsWith('302')) { throw "Payment prerequisite failed: $paymentJulyStatus" }

    $paymentTokenA = Get-SessionFormToken $sessionKasirA "/pembayaran/form.php" 'payment-concurrent-a'
    $paymentTokenB = Get-SessionFormToken $sessionKasirB "/pembayaran/form.php" 'payment-concurrent-b'
    $paymentKeyA = Get-HiddenValueFromFile (Join-Path $EvidenceRoot "$($sessionKasirA.Name).payment-concurrent-a.form.html") 'idempotency_key'
    $paymentKeyB = Get-HiddenValueFromFile (Join-Path $EvidenceRoot "$($sessionKasirB.Name).payment-concurrent-b.form.html") 'idempotency_key'
    if ($paymentKeyA -notmatch '^[a-f0-9]{64}$' -or $paymentKeyB -notmatch '^[a-f0-9]{64}$' -or $paymentKeyA -eq $paymentKeyB) {
        throw 'Concurrent payment forms did not provide distinct strong idempotency keys.'
    }
    $paymentConfigA = Join-Path $EvidenceRoot 'payment-a.curl'
    $paymentConfigB = Join-Path $EvidenceRoot 'payment-b.curl'
    $paymentBodyA = Join-Path $EvidenceRoot 'payment-a.body'
    $paymentBodyB = Join-Path $EvidenceRoot 'payment-b.body'
    $paymentHeaderA = Join-Path $EvidenceRoot 'payment-a.headers'
    $paymentHeaderB = Join-Path $EvidenceRoot 'payment-b.headers'
    foreach ($path in @($paymentBodyA,$paymentBodyB,$paymentHeaderA,$paymentHeaderB)) { Add-SensitiveFile $path }
    $paymentFieldsA = @{ csrf_token=$paymentTokenA;idempotency_key=$paymentKeyA;aksi='input';payment_plan='monthly';no_induk=$nis;tanggal_bayar=$today;bulan_bayar='08';tahun_bayar='2026';sistem_pembayaran='Tunai';uang_spp='1000';audit_reason='Concurrent payment A' }
    $paymentFieldsB = @{ csrf_token=$paymentTokenB;idempotency_key=$paymentKeyB;aksi='input';payment_plan='monthly';no_induk=$nis;tanggal_bayar=$today;bulan_bayar='08';tahun_bayar='2026';sistem_pembayaran='Tunai';uang_spp='1000';audit_reason='Concurrent payment B' }
    Write-CurlConfig $paymentConfigA "$baseA/pembayaran/proses.php" $sessionKasirA.CookiePath $paymentBodyA $paymentHeaderA $paymentFieldsA
    Write-CurlConfig $paymentConfigB "$baseB/pembayaran/proses.php" $sessionKasirB.CookiePath $paymentBodyB $paymentHeaderB $paymentFieldsB
    $paymentBlocker = Start-BlockedMySqlTransaction 'payment' @"
START TRANSACTION;
SELECT id FROM siswa WHERE NO_INDUK='$nis' FOR UPDATE;
SELECT 'LOCKED';
DO SLEEP(6);
COMMIT;
SELECT 'RELEASED';
"@
    $paymentWorkerA = Start-ConcurrentCurl 'payment-a' $paymentConfigA
    $paymentWorkerB = Start-ConcurrentCurl 'payment-b' $paymentConfigB
    $paymentResultA = Complete-ConcurrentCurl $paymentWorkerA
    $paymentResultB = Complete-ConcurrentCurl $paymentWorkerB
    Wait-Blocker $paymentBlocker 'payment'
    $paymentState = @(Invoke-MySqlRoot "SELECT (SELECT COUNT(*) FROM bayar WHERE NO_INDUK='$nis' AND TAHUN='2026' AND (BULAN='08' OR BULAN='8' OR BULAN='Agustus')),(SELECT COUNT(*) FROM bayar_spp_periode WHERE no_induk='$nis' AND tahun='2026' AND (bulan='08' OR bulan='8' OR bulan='Agustus')),(SELECT COUNT(*) FROM audit_event WHERE event_type='payment.created' AND entity_type='bayar' AND JSON_UNQUOTE(JSON_EXTRACT(after_data,'$.payment.NO_INDUK'))='$nis' AND JSON_UNQUOTE(JSON_EXTRACT(after_data,'$.payment.BULAN')) IN ('08','8','Agustus') );" 'payment concurrency state' $SuiteDatabase)
    $paymentParts = $paymentState[0] -split "`t"
    $paymentLocations = @((Get-HttpLocation $paymentHeaderA), (Get-HttpLocation $paymentHeaderB))
    $paymentSuccessRedirects = @($paymentLocations | Where-Object { $_ -match 'lihat\.php' })
    $paymentRejectedRedirects = @($paymentLocations | Where-Object { $_ -match 'form\.php' })
    Add-Result 'TC-PAY-002' 'kedua pembayaran menunggu row lock dan selesai tanpa timeout' ($paymentResultA.Duration -ge 4 -and $paymentResultB.Duration -ge 4) "duration_a=$($paymentResultA.Duration);duration_b=$($paymentResultB.Duration)"
    Add-Result 'TC-PAY-002' 'kedua response pembayaran aman tanpa 500' ($paymentResultA.Status -eq 302 -and $paymentResultB.Status -eq 302) "status_a=$($paymentResultA.Status);status_b=$($paymentResultB.Status)"
    Add-Result 'TC-PAY-002' 'tepat satu pembayaran periode berhasil dan satu ditolak' ($paymentSuccessRedirects.Count -eq 1 -and $paymentRejectedRedirects.Count -eq 1) "locations=$($paymentLocations -join ',')"
    Add-Result 'TC-PAY-002' 'tepat satu row pembayaran dan satu claim periode tersimpan' ([int]$paymentParts[0] -eq 1 -and [int]$paymentParts[1] -eq 1) "payments=$($paymentParts[0]);claims=$($paymentParts[1])"
    Add-Result 'TC-PAY-002' 'audit hanya mencatat pembayaran yang commit' ([int]$paymentParts[2] -eq 1) "audit_events=$($paymentParts[2])"

    $sessionAdminA = New-AuthenticatedCurlSession 'admin-a' $baseA $adminA $password
    $sessionAdminB = New-AuthenticatedCurlSession 'admin-b' $baseB $adminB $password
    $duFormTokenA = Get-SessionFormToken $sessionAdminA "/master_daftar_ulang.php?tahun=$([uri]::EscapeDataString($duLabel))" 'du-concurrent-a'
    $duFormTokenB = Get-SessionFormToken $sessionAdminB "/master_daftar_ulang.php?tahun=$([uri]::EscapeDataString($duLabel))" 'du-concurrent-b'
    $duConfigA = Join-Path $EvidenceRoot 'du-publish-a.curl'
    $duConfigB = Join-Path $EvidenceRoot 'du-publish-b.curl'
    $duBodyA = Join-Path $EvidenceRoot 'du-publish-a.body'
    $duBodyB = Join-Path $EvidenceRoot 'du-publish-b.body'
    $duHeaderA = Join-Path $EvidenceRoot 'du-publish-a.headers'
    $duHeaderB = Join-Path $EvidenceRoot 'du-publish-b.headers'
    foreach ($path in @($duBodyA,$duBodyB,$duHeaderA,$duHeaderB)) { Add-SensitiveFile $path }
    Write-CurlConfig $duConfigA "$baseA/master_daftar_ulang.php?tahun=$([uri]::EscapeDataString($duLabel))" $sessionAdminA.CookiePath $duBodyA $duHeaderA @{
        csrf_token=$duFormTokenA;aksi='terbitkan';tahun_ajaran=$duLabel
    }
    Write-CurlConfig $duConfigB "$baseB/master_daftar_ulang.php?tahun=$([uri]::EscapeDataString($duLabel))" $sessionAdminB.CookiePath $duBodyB $duHeaderB @{
        csrf_token=$duFormTokenB;aksi='terbitkan';tahun_ajaran=$duLabel
    }
    $duBlocker = Start-BlockedMySqlTransaction 'du-publish' @"
START TRANSACTION;
SELECT id FROM tahun_ajaran WHERE label='$duLabel' FOR UPDATE;
SELECT 'LOCKED';
DO SLEEP(6);
COMMIT;
SELECT 'RELEASED';
"@
    $duWorkerA = Start-ConcurrentCurl 'du-publish-a' $duConfigA
    $duWorkerB = Start-ConcurrentCurl 'du-publish-b' $duConfigB
    $duResultA = Complete-ConcurrentCurl $duWorkerA
    $duResultB = Complete-ConcurrentCurl $duWorkerB
    Wait-Blocker $duBlocker 'du-publish'
    $duState = @(Invoke-MySqlRoot "SELECT (SELECT status FROM tahun_ajaran WHERE label='$duLabel'),(SELECT COUNT(*) FROM tagihan_daftar_ulang t JOIN tahun_ajaran y ON y.id=t.tahun_ajaran_id WHERE y.label='$duLabel'),(SELECT COUNT(*) FROM siswa WHERE is_active=1 AND KELAS IN ('1','2','3','4','5','6')),(SELECT COUNT(*) FROM daftar_ulang_audit_log l JOIN tahun_ajaran y ON y.id=l.tahun_ajaran_id WHERE y.label='$duLabel' AND l.aksi='terbitkan_tagihan');" 'du publish concurrency state' $SuiteDatabase)
    $duParts = $duState[0] -split "`t"
    $duLocations = @((Get-HttpLocation $duHeaderA), (Get-HttpLocation $duHeaderB))
    Add-Result 'TC-DU-001' 'kedua publish Daftar Ulang menunggu row lock dan selesai tanpa timeout' ($duResultA.Duration -ge 4 -and $duResultB.Duration -ge 4) "duration_a=$($duResultA.Duration);duration_b=$($duResultB.Duration)"
    Add-Result 'TC-DU-001' 'kedua response publish aman tanpa 500' ($duResultA.Status -eq 302 -and $duResultB.Status -eq 302) "status_a=$($duResultA.Status);status_b=$($duResultB.Status)"
    Add-Result 'TC-DU-001' 'tahun terbit, tagihan tepat satu per siswa aktif, dan audit tidak duplikat' ($duParts[0] -eq 'published' -and [int]$duParts[1] -eq [int]$duParts[2] -and [int]$duParts[3] -eq 1) "status=$($duParts[0]);bills=$($duParts[1]);active=$($duParts[2]);audit=$($duParts[3])"

    $feeIdRows = @(Invoke-MySqlRoot "SELECT id FROM master_biaya_lain WHERE nama='$feeName' LIMIT 1;" 'fee fixture id' $SuiteDatabase)
    if ($feeIdRows.Count -ne 1) { throw 'Fee fixture ID tidak ditemukan.' }
    $feeId = [int](($feeIdRows[0] -split "`t")[0])
    $feeFormTokenA = Get-SessionFormToken $sessionAdminA '/master_biaya_lain.php' 'fee-concurrent-a'
    $feeFormTokenB = Get-SessionFormToken $sessionAdminB '/master_biaya_lain.php' 'fee-concurrent-b'
    $feeConfigA = Join-Path $EvidenceRoot 'fee-publish-a.curl'
    $feeConfigB = Join-Path $EvidenceRoot 'fee-publish-b.curl'
    $feeBodyA = Join-Path $EvidenceRoot 'fee-publish-a.body'
    $feeBodyB = Join-Path $EvidenceRoot 'fee-publish-b.body'
    $feeHeaderA = Join-Path $EvidenceRoot 'fee-publish-a.headers'
    $feeHeaderB = Join-Path $EvidenceRoot 'fee-publish-b.headers'
    foreach ($path in @($feeBodyA,$feeBodyB,$feeHeaderA,$feeHeaderB)) { Add-SensitiveFile $path }
    Write-CurlConfig $feeConfigA "$baseA/master_biaya_lain.php" $sessionAdminA.CookiePath $feeBodyA $feeHeaderA @{
        csrf_token=$feeFormTokenA;aksi='terbitkan_tagihan';master_id=$feeId;target='all'
    }
    Write-CurlConfig $feeConfigB "$baseB/master_biaya_lain.php" $sessionAdminB.CookiePath $feeBodyB $feeHeaderB @{
        csrf_token=$feeFormTokenB;aksi='terbitkan_tagihan';master_id=$feeId;target='all'
    }
    $feeBlocker = Start-BlockedMySqlTransaction 'fee-publish' @"
START TRANSACTION;
SELECT id FROM master_biaya_lain WHERE id=$feeId FOR UPDATE;
SELECT 'LOCKED';
DO SLEEP(6);
COMMIT;
SELECT 'RELEASED';
"@
    $feeWorkerA = Start-ConcurrentCurl 'fee-publish-a' $feeConfigA
    $feeWorkerB = Start-ConcurrentCurl 'fee-publish-b' $feeConfigB
    $feeResultA = Complete-ConcurrentCurl $feeWorkerA
    $feeResultB = Complete-ConcurrentCurl $feeWorkerB
    Wait-Blocker $feeBlocker 'fee-publish'
    $feeState = @(Invoke-MySqlRoot "SELECT (SELECT COUNT(*) FROM tagihan_biaya_lain WHERE master_biaya_lain_id=$feeId),(SELECT COUNT(*) FROM siswa WHERE is_active=1),(SELECT COUNT(*) FROM tagihan_biaya_lain_audit_log WHERE master_biaya_lain_id=$feeId AND aksi='terbitkan_tagihan');" 'fee publish concurrency state' $SuiteDatabase)
    $feeParts = $feeState[0] -split "`t"
    Add-Result 'TC-FEE-001' 'kedua publish Biaya Lain menunggu master lock dan selesai tanpa timeout' ($feeResultA.Duration -ge 4 -and $feeResultB.Duration -ge 4) "duration_a=$($feeResultA.Duration);duration_b=$($feeResultB.Duration)"
    Add-Result 'TC-FEE-001' 'kedua response publish aman tanpa 500' ($feeResultA.Status -eq 302 -and $feeResultB.Status -eq 302) "status_a=$($feeResultA.Status);status_b=$($feeResultB.Status)"
    Add-Result 'TC-FEE-001' 'tagihan Biaya Lain tepat satu per siswa aktif tanpa duplikasi' ([int]$feeParts[0] -eq [int]$feeParts[1]) "bills=$($feeParts[0]);active=$($feeParts[1])"
    Add-Result 'TC-FEE-001' 'dua audit mencatat dua attempt tanpa mengubah jumlah tagihan' ([int]$feeParts[2] -eq 2) "audit_attempts=$($feeParts[2])"

    $adminTokenA = Get-SessionFormToken $sessionAdminA '/role_management.php' 'role-delete'
    $adminTokenB = Get-SessionFormToken $sessionAdminB '/role_management.php' 'role-delete'
    $deleteConfigA = Join-Path $EvidenceRoot 'delete-admin-a.curl'
    $deleteConfigB = Join-Path $EvidenceRoot 'delete-admin-b.curl'
    $deleteBodyA = Join-Path $EvidenceRoot 'delete-admin-a.body'
    $deleteBodyB = Join-Path $EvidenceRoot 'delete-admin-b.body'
    $deleteHeaderA = Join-Path $EvidenceRoot 'delete-admin-a.headers'
    $deleteHeaderB = Join-Path $EvidenceRoot 'delete-admin-b.headers'
    foreach ($path in @($deleteBodyA,$deleteBodyB,$deleteHeaderA,$deleteHeaderB)) { Add-SensitiveFile $path }
    Write-CurlConfig $deleteConfigA "$baseA/role_management.php" $sessionAdminA.CookiePath $deleteBodyA $deleteHeaderA @{
        csrf_token=$adminTokenA;aksi='hapus';account_id=[string]$idMap[$adminB];audit_reason='Concurrent last-admin guard A'
    }
    Write-CurlConfig $deleteConfigB "$baseB/role_management.php" $sessionAdminB.CookiePath $deleteBodyB $deleteHeaderB @{
        csrf_token=$adminTokenB;aksi='hapus';account_id=[string]$idMap[$adminA];audit_reason='Concurrent last-admin guard B'
    }
    $adminBlocker = Start-BlockedMySqlTransaction 'last-admin' @"
START TRANSACTION;
SELECT id FROM admin WHERE role='admin' ORDER BY id FOR UPDATE;
SELECT 'LOCKED';
DO SLEEP(6);
COMMIT;
SELECT 'RELEASED';
"@
    $deleteWorkerA = Start-ConcurrentCurl 'delete-admin-a' $deleteConfigA
    $deleteWorkerB = Start-ConcurrentCurl 'delete-admin-b' $deleteConfigB
    $deleteResultA = Complete-ConcurrentCurl $deleteWorkerA
    $deleteResultB = Complete-ConcurrentCurl $deleteWorkerB
    Wait-Blocker $adminBlocker 'last-admin'

    $adminState = @(Invoke-MySqlRoot "SELECT (SELECT COUNT(*) FROM admin WHERE role='admin'),(SELECT COUNT(*) FROM admin WHERE username IN ('$adminA','$adminB')),(SELECT COUNT(*) FROM audit_event WHERE event_type='account.deleted' AND entity_type='admin' AND entity_id IN ($($idMap[$adminA]),$($idMap[$adminB])));" 'last-admin state' $SuiteDatabase)
    $adminParts = $adminState[0] -split "`t"
    Add-Result 'TC-USR-001' 'kedua delete menunggu lock admin yang sama' ($deleteResultA.Duration -ge 4 -and $deleteResultB.Duration -ge 4) "duration_a=$($deleteResultA.Duration);duration_b=$($deleteResultB.Duration)"
    Add-Result 'TC-USR-001' 'kedua response aman tanpa 500' ($deleteResultA.Status -eq 302 -and $deleteResultB.Status -eq 302) "status_a=$($deleteResultA.Status);status_b=$($deleteResultB.Status)"
    Add-Result 'TC-USR-001' 'minimal satu admin tetap ada' ([int]$adminParts[0] -eq 1 -and [int]$adminParts[1] -eq 1) "admin_count=$($adminParts[0]);fixture_admin_count=$($adminParts[1])"
    Add-Result 'TC-USR-001' 'tepat satu delete tercatat append-only' ([int]$adminParts[2] -eq 1) "audit_events=$($adminParts[2])"

    $resultCsv = Join-Path $EvidenceRoot 'concurrency-results.csv'
    $results | Export-Csv -LiteralPath $resultCsv -NoTypeInformation -Encoding utf8
    $failed = @($results | Where-Object result -eq 'FAIL')
    @(
        "RUN_WIB=$((Get-Date).ToString('yyyy-MM-ddTHH:mm:sszzz'))",
        "GIT_COMMIT=$((& git -C $workspace rev-parse HEAD).Trim())",
        "GIT_WORKTREE_DIRTY=$([int]((& git -C $workspace status --porcelain).Count -gt 0))",
        "SOURCE_DATABASE=$SourceDatabase",
        "SUITE_DATABASE=$SuiteDatabase",
        "SOURCE_DUMP_SHA256=$dumpHash",
        "ASSERTIONS=$($results.Count)",
        "FAILURES=$($failed.Count)"
    ) | Set-Content -LiteralPath (Join-Path $EvidenceRoot 'run-metadata.txt') -Encoding utf8

    Write-Output "CONCURRENCY_ASSERTIONS=$($results.Count)"
    Write-Output "CONCURRENCY_PASS=$($results.Count - $failed.Count)"
    Write-Output "CONCURRENCY_FAIL=$($failed.Count)"
    if ($failed.Count -gt 0) {
        $failed | Format-Table -AutoSize
        throw 'Concurrency matrix failed.'
    }
    $suiteCompleted = $true
} finally {
    foreach ($job in $blockerJobs) {
        if ($job.State -notin @('Completed', 'Failed', 'Stopped')) {
            Stop-Job -Job $job -ErrorAction SilentlyContinue
        }
        Remove-Job -Job $job -Force -ErrorAction SilentlyContinue
    }
    foreach ($process in $serverProcesses) {
        if (-not $process.HasExited) {
            Stop-Process -Id $process.Id -Force -ErrorAction SilentlyContinue
        }
    }
    foreach ($path in $sensitiveFiles) {
        if (Test-Path -LiteralPath $path -PathType Leaf) {
            Clear-Content -LiteralPath $path -ErrorAction SilentlyContinue
        }
    }
    if ($databaseCreated) {
        $identity = Invoke-MySqlRoot "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$SuiteDatabase';" 'pre-drop identity probe'
        if (($identity -join '').Trim() -ne $SuiteDatabase) {
            throw 'Refusing suite cleanup because database identity changed.'
        }
        if ($grantCreated) {
            Invoke-MySqlRoot "REVOKE ALL PRIVILEGES ON ``$SuiteDatabase``.* FROM 'spp_audit_local'@'localhost'; FLUSH PRIVILEGES;" 'suite grant cleanup' | Out-Null
        }
        Invoke-MySqlRoot "DROP DATABASE ``$SuiteDatabase``;" 'suite database cleanup' | Out-Null
    }
    @{
        suite_database = $SuiteDatabase
        database_dropped = $databaseCreated
        servers_stopped = $true
        sensitive_files_cleared = $true
        finished_wib = (Get-Date).ToString('yyyy-MM-ddTHH:mm:sszzz')
    } | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $EvidenceRoot 'cleanup.json') -Encoding utf8
}

if (-not $suiteCompleted) {
    throw 'Concurrency suite did not complete.'
}
Write-Output 'CONCURRENCY_MATRIX=PASS'
Write-Output "EVIDENCE_ROOT=$EvidenceRoot"
