[CmdletBinding()]
param(
    [string]$PlanPath = '',
    [string]$MatrixPath = ''
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$workspace = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
if ([string]::IsNullOrWhiteSpace($PlanPath)) {
    $PlanPath = Join-Path $workspace 'documentation\RENCANA_AUDIT_FINAL_SISTEMSPP.md'
}
if ([string]::IsNullOrWhiteSpace($MatrixPath)) {
    $MatrixPath = Join-Path $workspace 'documentation\audit\TEST_COVERAGE_MATRIX.md'
}

foreach ($path in @($PlanPath, $MatrixPath)) {
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        throw "Coverage input file is missing: $path"
    }
}

$planTcPattern = '(?m)^\|[^|\r\n]*\|\s*(TC-[A-Z]+-[0-9]{3})\s*\|'
$matrixTcPattern = '(?m)^\|\s*(TC-[A-Z]+-[0-9]{3})\s*\|'
$statusPattern = '(?m)^\|\s*(TC-[A-Z]+-[0-9]{3})\s*\|[^\r\n]*\|\s*(PASS|FAIL|NOT TESTED|PENDING DECISION)\s*\|\s*$'
$planText = Get-Content -LiteralPath $PlanPath -Raw
$matrixText = Get-Content -LiteralPath $MatrixPath -Raw

$expectedIds = @([regex]::Matches($planText, $planTcPattern) | ForEach-Object { $_.Groups[1].Value })
$matrixIds = @([regex]::Matches($matrixText, $matrixTcPattern) | ForEach-Object { $_.Groups[1].Value })
$statusRows = @([regex]::Matches($matrixText, $statusPattern))

$expectedDuplicates = @($expectedIds | Group-Object | Where-Object Count -ne 1)
if ($expectedDuplicates.Count -gt 0) {
    throw 'Master plan contains duplicate TC IDs: ' + (($expectedDuplicates | ForEach-Object Name) -join ', ')
}

$matrixDuplicates = @($matrixIds | Group-Object | Where-Object Count -ne 1)
if ($matrixDuplicates.Count -gt 0) {
    throw 'Coverage matrix contains duplicate TC IDs: ' + (($matrixDuplicates | ForEach-Object Name) -join ', ')
}

$missing = @($expectedIds | Where-Object { $_ -notin $matrixIds })
$unexpected = @($matrixIds | Where-Object { $_ -notin $expectedIds })
if ($missing.Count -gt 0 -or $unexpected.Count -gt 0) {
    throw "Coverage ID mismatch. Missing=[$($missing -join ', ')] Unexpected=[$($unexpected -join ', ')]"
}

if ($statusRows.Count -ne $matrixIds.Count) {
    throw "Not every matrix TC row ends with an allowed status. TC_ROWS=$($matrixIds.Count) STATUS_ROWS=$($statusRows.Count)"
}

$statusCounts = @{}
foreach ($status in @('PASS', 'FAIL', 'NOT TESTED', 'PENDING DECISION')) {
    $statusCounts[$status] = 0
}
foreach ($row in $statusRows) {
    $statusCounts[$row.Groups[2].Value]++
}

Write-Output "COVERAGE_TC_EXPECTED=$($expectedIds.Count)"
Write-Output "COVERAGE_TC_MAPPED=$($matrixIds.Count)"
Write-Output "COVERAGE_PASS=$($statusCounts['PASS'])"
Write-Output "COVERAGE_FAIL=$($statusCounts['FAIL'])"
Write-Output "COVERAGE_NOT_TESTED=$($statusCounts['NOT TESTED'])"
Write-Output "COVERAGE_PENDING_DECISION=$($statusCounts['PENDING DECISION'])"
Write-Output 'COVERAGE_MATRIX_STATUS=PASS'
