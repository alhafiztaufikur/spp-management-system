[CmdletBinding()]
param([Parameter(Mandatory=$true)][string]$ConfigFile,[Parameter(Mandatory=$true)][string]$JobId,[ValidateSet('backup','restore')][string]$Mode='backup')
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
function Assert-DispatchPath([string]$Path) {
    if (![IO.Path]::IsPathRooted($Path)) { throw 'Lokasi absolut diperlukan.' }
    $full=[IO.Path]::GetFullPath($Path)
    $cursor=$full
    while ($cursor -and [IO.Path]::GetPathRoot($cursor) -ne $cursor) {
        $item=Get-Item -LiteralPath $cursor -Force -ErrorAction SilentlyContinue
        if ($item) {
            if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'Lokasi pemicu tidak boleh memakai junction atau symlink.' }
        }
        $cursor=Split-Path -Parent $cursor
    }
    return $full
}
function Quote-DispatchArgument([string]$Value) {
    if ($Value -notmatch '[\s"]') { return $Value }
    return '"'+[regex]::Replace([regex]::Replace($Value,'(\\*)"','$1$1\"'),'(\\+)$','$1$1')+'"'
}
try {
    if ($JobId -notmatch '^[a-f0-9]{32}$') { throw 'ID pekerjaan tidak valid.' }
    $appRoot=Assert-DispatchPath (Split-Path -Parent $PSScriptRoot)
    $ConfigFile=Assert-DispatchPath $ConfigFile
    if ($ConfigFile.StartsWith($appRoot+'\',[StringComparison]::OrdinalIgnoreCase)) { throw 'Konfigurasi wajib privat.' }
    $config=Get-Content -LiteralPath $ConfigFile -Raw | ConvertFrom-Json
    if ($config.version -ne 1 -or (Assert-DispatchPath $config.app_root) -ne $appRoot) { throw 'Identitas aplikasi berbeda.' }
    $storage=Assert-DispatchPath $config.storage_root
    if ($storage -eq $appRoot -or $storage.StartsWith($appRoot+'\',[StringComparison]::OrdinalIgnoreCase) -or $storage.StartsWith('C:\laragon\www\',[StringComparison]::OrdinalIgnoreCase)) { throw 'Penyimpanan wajib di luar webroot.' }
    $jobDirectory=Assert-DispatchPath (Join-Path $storage ('jobs\'+$JobId))
    if (!(Test-Path -LiteralPath $jobDirectory -PathType Container)) { throw 'Pekerjaan privat tidak ditemukan.' }
    $php=Assert-DispatchPath $config.php_executable
    $runnerName=if($Mode -eq 'restore'){'database_restore_runner.php'}else{'database_backup_runner.php'}
    $runner=Assert-DispatchPath (Join-Path $appRoot ('scripts\'+$runnerName))
    foreach ($file in @($php,$runner)) { if (!(Test-Path -LiteralPath $file -PathType Leaf)) { throw 'Executable pemicu tidak tersedia.' } }
    foreach ($name in @('worker.out.log','worker.error.log')) { Assert-DispatchPath (Join-Path $jobDirectory $name) | Out-Null }
    $arguments=@($runner,('--config='+$ConfigFile),('--job='+$JobId))
    $process=Start-Process -FilePath $php -ArgumentList (($arguments|ForEach-Object { Quote-DispatchArgument $_ }) -join ' ') -WorkingDirectory $appRoot -WindowStyle Hidden -RedirectStandardOutput (Join-Path $jobDirectory 'worker.out.log') -RedirectStandardError (Join-Path $jobDirectory 'worker.error.log') -PassThru
    Write-Output ('Started '+$process.Id)
} catch {
    Write-Error 'Pemicu backup manual gagal; rincian berada pada log privat.'
    exit 1
}
