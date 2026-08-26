param(
    [string]$SourceDatabase = 'db_spp_audit_20260820_090000',
    [string]$RestoreDatabase = ('db_spp_audit_restore_' + (Get-Date -Format 'yyyyMMdd_HHmmss') + '_' + (Get-Random -Minimum 1000 -Maximum 9999)),
    [string]$EvidenceRoot = ('C:\xampp\sistemspp-audit-evidence\20260820-090000-wave0\backup-restore-' + (Get-Date -Format 'yyyyMMdd_HHmmss'))
)

$ErrorActionPreference = 'Stop'
$workspace = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$mysql = 'C:\xampp\mysql\bin\mysql.exe'
$mysqldump = 'C:\xampp\mysql\bin\mysqldump.exe'
$databaseCreated = $false
$drillCompleted = $false

if ($SourceDatabase -notmatch '^db_spp_audit_[0-9_]+$') {
    throw 'Source database must be a named audit database.'
}
if ($RestoreDatabase -notmatch '^db_spp_audit_restore_[0-9_]+$') {
    throw 'Restore database must match db_spp_audit_restore_<digits>.'
}
if ($RestoreDatabase -eq $SourceDatabase) {
    throw 'Source and restore databases must differ.'
}
if (-not (Test-Path -LiteralPath $mysql) -or -not (Test-Path -LiteralPath $mysqldump)) {
    throw 'MySQL client tools were not found at the expected XAMPP paths.'
}

New-Item -ItemType Directory -Path $EvidenceRoot -Force | Out-Null

function Invoke-RootSql([string]$sql, [string]$label) {
    $result = $sql | & $mysql -u root --batch --skip-column-names --raw 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "$label failed."
    }
    return @($result)
}

function Invoke-DatabaseSql([string]$database, [string]$sql, [string]$label) {
    $result = $sql | & $mysql -u root --database=$database --batch --skip-column-names --raw 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "$label failed."
    }
    return @($result)
}

try {
    $sourceExists = Invoke-RootSql "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$SourceDatabase';" 'source existence probe'
    if (($sourceExists -join '').Trim() -ne $SourceDatabase) {
        throw 'Source audit database was not found.'
    }

    $dumpPath = Join-Path $EvidenceRoot 'source-backup.sql'
    & $mysqldump -u root --single-transaction --routines --triggers --databases $SourceDatabase --result-file=$dumpPath 2> (Join-Path $EvidenceRoot 'mysqldump.stderr.log')
    if ($LASTEXITCODE -ne 0) { throw 'Logical backup failed.' }
    $dumpHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $dumpPath).Hash

    Invoke-RootSql "CREATE DATABASE ``$RestoreDatabase`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 'restore database creation' | Out-Null
    $databaseCreated = $true

    $dumpSql = Get-Content -Raw -LiteralPath $dumpPath
    $sourceIdentifier = [string]::Concat('`', $SourceDatabase, '`')
    $restoreIdentifier = [string]::Concat('`', $RestoreDatabase, '`')
    $dumpSql = $dumpSql.Replace($sourceIdentifier, $restoreIdentifier)
    $restoreDumpPath = Join-Path $EvidenceRoot 'restore-import.sql'
    $dumpSql | Set-Content -LiteralPath $restoreDumpPath -Encoding utf8
    Get-Content -Raw -LiteralPath $restoreDumpPath | & $mysql -u root 2> (Join-Path $EvidenceRoot 'mysql-restore.stderr.log')
    if ($LASTEXITCODE -ne 0) { throw 'Restore import failed.' }

    $probe = Invoke-DatabaseSql $RestoreDatabase 'SELECT DATABASE();' 'restore database identity probe'
    if (($probe -join '').Trim() -ne $RestoreDatabase) {
        throw "Restore identity probe mismatch: expected $RestoreDatabase."
    }

    $tableRows = New-Object System.Collections.Generic.List[string]
    $tables = Invoke-DatabaseSql $SourceDatabase "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='$SourceDatabase' AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME;" 'source table inventory'
    foreach ($table in $tables) {
        $tableName = ([string]$table).Trim()
        if ([string]::IsNullOrWhiteSpace($tableName)) { continue }
        $quotedTable = $tableName.Replace('`', '``')
        $sourceCount = (Invoke-DatabaseSql $SourceDatabase "SELECT COUNT(*) FROM ``$quotedTable``;" "source count $tableName").Trim()
        $restoreCount = (Invoke-DatabaseSql $RestoreDatabase "SELECT COUNT(*) FROM ``$quotedTable``;" "restore count $tableName").Trim()
        $tableRows.Add("$tableName`t$sourceCount`t$restoreCount")
        if ($sourceCount -ne $restoreCount) {
            throw "Row count mismatch for table $tableName."
        }
    }
    $tableRows | Set-Content -LiteralPath (Join-Path $EvidenceRoot 'row-count-comparison.tsv') -Encoding utf8

    $schemaSql = Get-Content -Raw -LiteralPath (Join-Path $workspace 'sql\verify_schema.sql')
    $schemaOutput = Invoke-DatabaseSql $RestoreDatabase $schemaSql 'schema verifier'
    $schemaOutput | Set-Content -LiteralPath (Join-Path $EvidenceRoot 'verify-schema.tsv') -Encoding utf8
    $missing = @($schemaOutput | Select-String -Pattern '\tMISSING$').Count
    if ($missing -ne 0) { throw "Schema verifier reported $missing missing requirements." }

    $dataOutput = & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $workspace 'tests\support\run_data_verifier.ps1') -Database $RestoreDatabase -AllowEmptyPassword 2>&1
    $dataOutput | Set-Content -LiteralPath (Join-Path $EvidenceRoot 'verify-data.txt') -Encoding utf8
    if ($LASTEXITCODE -ne 0 -or @($dataOutput | Select-String -Pattern '^DATA_INTEGRITY=PASS$').Count -ne 1) {
        throw 'Restored database data verifier did not pass.'
    }

    @(
        "RUN_STARTED_WIB=$((Get-Date).ToString('yyyy-MM-ddTHH:mm:sszzz'))",
        "SOURCE_DATABASE=$SourceDatabase",
        "RESTORE_DATABASE=$RestoreDatabase",
        "SOURCE_BACKUP_SHA256=$dumpHash",
        "TABLE_COUNT=$($tableRows.Count)",
        'ROW_COUNT_COMPARISON=PASS',
        'SCHEMA_VERIFIER=PASS',
        'DATA_VERIFIER=PASS'
    ) | Set-Content -LiteralPath (Join-Path $EvidenceRoot 'run-metadata.txt') -Encoding utf8
    $drillCompleted = $true
}
finally {
    if ($databaseCreated) {
        $identity = Invoke-RootSql "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$RestoreDatabase';" 'pre-drop identity probe'
        if (($identity -join '').Trim() -ne $RestoreDatabase) {
            throw 'Refusing restore database drop because schema identity did not match.'
        }
        Invoke-RootSql "DROP DATABASE ``$RestoreDatabase``;" 'restore database cleanup' | Out-Null
        "RESTORE_DATABASE_DROPPED=$RestoreDatabase" | Add-Content -LiteralPath (Join-Path $EvidenceRoot 'run-metadata.txt')
    }
}

if (-not $drillCompleted) {
    throw 'Backup/restore drill did not complete.'
}
Write-Output 'BACKUP_RESTORE_DRILL=PASS'
Write-Output "EVIDENCE_ROOT=$EvidenceRoot"
