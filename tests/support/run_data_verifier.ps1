[CmdletBinding()]
param(
    [string]$Database = 'db_spp_audit_20260820_090000',
    [string]$MySqlExe = 'C:\xampp\mysql\bin\mysql.exe',
    [string]$DbHost = '127.0.0.1',
    [ValidateRange(1, 65535)]
    [int]$DbPort = 3306,
    [string]$DbUser = 'root',
    [AllowEmptyString()]
    [string]$DbPassword = $env:SPP_AUDIT_ADMIN_DB_PASS,
    [switch]$AllowEmptyPassword
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$auditDatabasePattern = '^db_spp_audit_(?:restore_)?[0-9_]+(?:_suite_[0-9_]+)?$'
$baselineDatabase = 'db_spp'
$previousMySqlPassword = [Environment]::GetEnvironmentVariable('MYSQL_PWD', 'Process')
$workspace = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$verifierPath = Join-Path $workspace 'sql\verify_data_integrity.sql'

if ($Database -eq $baselineDatabase -or $Database -notmatch $auditDatabasePattern) {
    throw "Refusing database '$Database'. Standalone data verification only accepts $auditDatabasePattern and never '$baselineDatabase'."
}
if (-not (Test-Path -LiteralPath $MySqlExe -PathType Leaf)) {
    throw "mysql executable not found: $MySqlExe"
}
if (-not (Test-Path -LiteralPath $verifierPath -PathType Leaf)) {
    throw "Data verifier SQL not found: $verifierPath"
}
if ([string]::IsNullOrEmpty($DbPassword) -and -not $AllowEmptyPassword) {
    throw 'Empty database password refused. Supply SPP_AUDIT_ADMIN_DB_PASS or explicitly use -AllowEmptyPassword on an isolated local instance.'
}

try {
    if ([string]::IsNullOrEmpty($DbPassword)) {
        [Environment]::SetEnvironmentVariable('MYSQL_PWD', $null, 'Process')
    } else {
        [Environment]::SetEnvironmentVariable('MYSQL_PWD', $DbPassword, 'Process')
    }

    $arguments = @(
        '--protocol=tcp',
        "--host=$DbHost",
        "--port=$DbPort",
        "--user=$DbUser",
        '--default-character-set=utf8mb4',
        '--init-command=SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
        '--batch',
        '--raw',
        '--skip-column-names',
        "--database=$Database"
    )

    $escapedName = $Database.Replace("'", "''")
    $existsSql = "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$escapedName';"
    $exists = @($existsSql | & $MySqlExe @arguments 2>&1)
    if ($LASTEXITCODE -ne 0) {
        throw "Audit database existence check failed with mysql exit code $LASTEXITCODE.`n$($exists -join [Environment]::NewLine)"
    }
    if ($exists.Count -ne 1 -or $exists[0] -ne $Database) {
        throw "Audit database does not exist with the exact requested identity: $Database"
    }

    $sql = Get-Content -LiteralPath $verifierPath -Raw
    if ($sql -match '(?im)^\s*USE\s+') {
        throw 'Verifier contains a USE statement that could override --database; refusing execution.'
    }
    $sql = "SELECT CONCAT('DATABASE_PROBE=', DATABASE());`n" + $sql

    $output = @($sql | & $MySqlExe @arguments 2>&1)
    if ($LASTEXITCODE -ne 0) {
        throw "Data verifier failed with mysql exit code $LASTEXITCODE.`n$($output -join [Environment]::NewLine)"
    }

    $probe = @($output | Where-Object { $_ -like 'DATABASE_PROBE=*' })
    if ($probe.Count -ne 1 -or $probe[0] -ne "DATABASE_PROBE=$Database") {
        throw "Database identity probe failed. Actual=[$($probe -join ', ')] Expected=DATABASE_PROBE=$Database"
    }

    $summary = @($output | Where-Object { $_ -match '^INV-[0-9]{3}\t(?:CRITICAL|HIGH)\t(?:PASS|FAIL)\t' })
    if ($summary.Count -ne 17) {
        throw "Data verifier returned $($summary.Count) invariant rows; expected exactly 17."
    }

    $expectedIds = @(1..17 | ForEach-Object { 'INV-{0:D3}' -f $_ })
    $actualIds = @($summary | ForEach-Object { ($_ -split "`t", 2)[0] })
    $missingIds = @($expectedIds | Where-Object { $_ -notin $actualIds })
    $duplicateIds = @($actualIds | Group-Object | Where-Object Count -ne 1)
    if ($missingIds.Count -gt 0 -or $duplicateIds.Count -gt 0) {
        throw "Invariant identity mismatch. Missing=[$($missingIds -join ', ')] Duplicate=[$(($duplicateIds | ForEach-Object Name) -join ', ')]"
    }

    $failed = @($summary | Where-Object { $_ -match '\tFAIL\t' })
    Write-Output $probe[0]
    Write-Output "INVARIANT_ROWS=$($summary.Count)"
    Write-Output "INVARIANT_FAIL=$($failed.Count)"
    if ($failed.Count -gt 0) {
        Write-Output $failed
        throw 'Data integrity verification failed.'
    }

    Write-Output 'DATA_INTEGRITY=PASS'
} finally {
    [Environment]::SetEnvironmentVariable('MYSQL_PWD', $previousMySqlPassword, 'Process')
}
