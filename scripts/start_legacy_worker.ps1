param([string]$PhpExecutable='C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe')
$ErrorActionPreference='Stop'
$app=Split-Path -Parent $PSScriptRoot
$db=if($env:SPP_DB_NAME){$env:SPP_DB_NAME}else{'db_spp'}
if($db -ne 'db_spp' -and ($db -notmatch '^db_spp_(?:(?:audit|test)_[a-z0-9_]+|test[0-9]+)$' -or $env:SPP_TEST_ALLOW_MUTATION -ne '1')){throw 'Target worker tidak diizinkan'}
$storage=if($env:SPP_LEGACY_STORAGE){$env:SPP_LEGACY_STORAGE}else{'C:\laragon\data\spp-legacy-import'}
$root=Join-Path $storage $db
if(!(Test-Path -LiteralPath $PhpExecutable)){throw 'PHP executable tidak ditemukan'}
& $PhpExecutable (Join-Path $PSScriptRoot 'legacy_worker.php') '--setup'
if($LASTEXITCODE -ne 0){throw 'Setup importer gagal'}
$manifest=Join-Path $root 'worker-process.json'
if(Test-Path -LiteralPath $manifest){
    $saved=Get-Content -LiteralPath $manifest -Raw|ConvertFrom-Json
    $old=Get-CimInstance Win32_Process -Filter "ProcessId=$([int]$saved.pid)"
    if($old){
        if($old.CreationDate.ToUniversalTime().Ticks.ToString() -eq $saved.created -and $old.CommandLine.Replace('/','\').Contains((Join-Path $PSScriptRoot 'legacy_worker.php'))){Write-Output 'Worker importer sudah berjalan';exit}
        throw 'Manifest proses berubah; periksa kepemilikan sebelum melanjutkan'
    }
}
$worker=Start-Process -FilePath $PhpExecutable -ArgumentList ('-d memory_limit=1536M "'+(Join-Path $PSScriptRoot 'legacy_worker.php')+'"') -WorkingDirectory $app -WindowStyle Hidden -RedirectStandardOutput (Join-Path $root 'worker-output.log') -RedirectStandardError (Join-Path $root 'worker-error.log') -PassThru
$process=Get-CimInstance Win32_Process -Filter "ProcessId=$($worker.Id)"
@{pid=$worker.Id;created=$process.CreationDate.ToUniversalTime().Ticks.ToString();app=$app;database=$db;user=[Security.Principal.WindowsIdentity]::GetCurrent().Name}|ConvertTo-Json|Set-Content -LiteralPath $manifest -Encoding UTF8
Write-Output "Worker importer dimulai: $($worker.Id)"
