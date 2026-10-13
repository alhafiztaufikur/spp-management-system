[CmdletBinding()]
param(
    [string]$RootDirectory = 'C:\laragon\backups\spp-management-system\daily_backup_qa_20261013',
    [string]$MysqlBin = 'C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin',
    [string]$PhpExecutable = 'C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe',
    [switch]$Cleanup
)
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
$utf8=New-Object Text.UTF8Encoding($false)
$appRoot=Split-Path -Parent $PSScriptRoot
$helperFile=Join-Path $appRoot 'scripts\verify_backup_instance.ps1'
$parseErrors=$null
$ast=[Management.Automation.Language.Parser]::ParseFile($helperFile,[ref]$null,[ref]$parseErrors)
if($parseErrors.Count){throw 'Helper produksi tidak dapat diparse.'}
# Reuse argument handling/process helpers under test without invoking its entrypoint.
foreach($name in @('Canonical-Path','Assert-PlainPath','Write-Json','Quote-NativeArgument','Invoke-Child')){
    $node=$ast.Find({param($candidate) $candidate -is [Management.Automation.Language.FunctionDefinitionAst] -and $candidate.Name -eq $name},$true)
    if(!$node){throw 'Helper produksi tidak ditemukan.'}
    Invoke-Expression $node.Extent.Text
}
$RootDirectory=Assert-PlainPath $RootDirectory
$backupParent='C:\laragon\backups\spp-management-system\'
if(!$RootDirectory.StartsWith($backupParent,[StringComparison]::OrdinalIgnoreCase)-or $RootDirectory -eq $backupParent.TrimEnd('\')){throw 'Fixture wajib berada dalam direktori backup privat.'}
$workspace=Join-Path $RootDirectory 'source-instance'
$dataDirectory=Join-Path $workspace 'data'
$manifestFile=Join-Path $RootDirectory 'isolated-instance.json'
$defaultsFile=Join-Path $workspace 'client.cnf'
$configFile=Join-Path $RootDirectory 'QAconfig.json'
$mysqld=Assert-PlainPath (Join-Path $MysqlBin 'mysqld.exe')
$sourceOptions=Join-Path $RootDirectory 'operational-read.cnf'

function Fixture-Sql([string]$Defaults,[string]$Sql){
    $r=Invoke-Child (Join-Path $MysqlBin 'mysql.exe') @(('--defaults-file='+$Defaults),'--batch','--raw','--skip-column-names','--connect-timeout=3') -InputText $Sql
    if($r.exit_code -ne 0){throw 'Pemeriksaan MySQL fixture gagal.'}
    return $r.output
}
function Fixture-OwnedProcess($record){
    $p=Get-CimInstance Win32_Process -Filter ('ProcessId='+[int]$record.pid) -ErrorAction SilentlyContinue
    $argument='(?:^|\s)"?--datadir='+[regex]::Escape($dataDirectory)+'"?(?:\s|$)'
    $children=@(Get-CimInstance Win32_Process -Filter ("Name='mysqld.exe' AND ParentProcessId="+[int]$record.pid)|Where-Object{$_.ExecutablePath -and (Canonical-Path $_.ExecutablePath)-eq $mysqld -and $_.CommandLine -match $argument -and $_.CreationDate.ToUniversalTime().Ticks -ge [long]$record.process_started_utc_ticks -and $_.CreationDate.ToUniversalTime().Ticks -le ([long]$record.process_started_utc_ticks+[TimeSpan]::FromMinutes(2).Ticks)})
    if($children.Count -gt 1){throw 'Lebih dari satu anak server cocok.'}
    if($children.Count -eq 1){
        if($p -and $p.CreationDate.ToUniversalTime().Ticks.ToString() -ne $record.process_started_utc_ticks){throw 'PID peluncur dipakai proses lain.'}
        $p=$children[0]
        $record.pid=[int]$p.ProcessId
        $record.process_started_utc_ticks=$p.CreationDate.ToUniversalTime().Ticks.ToString()
        Write-Json $manifestFile $record
    }
    if(!$p){return $null}
    if(!$p.ExecutablePath -or (Canonical-Path $p.ExecutablePath) -ne $mysqld -or $p.CreationDate.ToUniversalTime().Ticks.ToString() -ne $record.process_started_utc_ticks -or !$p.CommandLine -or $p.CommandLine -notmatch $argument){throw 'PID fixture tidak cocok; proses tidak dihentikan.'}
    return $p
}
function Fixture-Identity($record){
    $i=(Fixture-Sql $record.defaults_file 'SELECT @@server_uuid,@@datadir,@@port,@@event_scheduler;').Split("`t")
    if($i.Count -ne 4 -or $i[0] -eq $record.source_server_uuid -or $i[0] -ne $record.server_uuid -or (Canonical-Path $i[1]) -ne $dataDirectory -or (Canonical-Path $i[1]) -eq (Canonical-Path $record.source_datadir) -or [int]$i[2] -ne $record.port -or $i[3] -ne 'OFF'){throw 'Identitas fixture bukan instance terisolasi.'}
}

if($Cleanup){
    if(!(Test-Path -LiteralPath $manifestFile -PathType Leaf)){throw 'Manifest fixture tidak ditemukan; tidak ada data dihapus.'}
    $record=Get-Content -LiteralPath $manifestFile -Raw|ConvertFrom-Json
    if($record.isolated_test -ne $true -or (Canonical-Path $record.datadir) -ne $dataDirectory -or (Canonical-Path $record.mysqld_executable) -ne $mysqld -or $record.server_uuid -eq $record.source_server_uuid){throw 'Kepemilikan fixture tidak cocok.'}
    $p=Fixture-OwnedProcess $record
    if($p){
        if($record.server_uuid){
            Fixture-Identity $record
            try{Fixture-Sql $record.defaults_file 'SHUTDOWN;'|Out-Null}catch{}
        }else{
            # Failed initial startup has no reachable authenticated endpoint;
            # exact PID, creation time, executable and private datadir still
            # prove ownership of this initialized, never-imported process.
            Stop-Process -Id ([int]$record.pid) -Force
        }
        $until=[DateTime]::UtcNow.AddSeconds(30)
        while((Fixture-OwnedProcess $record)-and [DateTime]::UtcNow -lt $until){Start-Sleep -Milliseconds 250}
        if(Fixture-OwnedProcess $record){throw 'Instance belum berhenti; datadir dipertahankan.'}
    }
    Assert-PlainPath $workspace|Out-Null
    if((Canonical-Path $workspace) -ne ($RootDirectory+'\source-instance') -or $dataDirectory -eq (Canonical-Path $record.source_datadir)){throw 'Target pembersihan tidak aman.'}
    $links=Get-ChildItem -LiteralPath $workspace -Force -Recurse|Where-Object{($_.Attributes -band [IO.FileAttributes]::ReparsePoint)-ne 0}|Select-Object -First 1
    if($links){throw 'Workspace berisi reparse point; dipertahankan.'}
    Remove-Item -LiteralPath $workspace -Recurse -Force
    Remove-Item -LiteralPath $manifestFile -Force
    Write-Output 'Instance sumber QA dihentikan; dump, fingerprint, dan hasil pengujian privat dipertahankan.'
    exit 0
}

if(Test-Path -LiteralPath $manifestFile){
    $record=Get-Content -LiteralPath $manifestFile -Raw|ConvertFrom-Json
    if($record.isolated_test -ne $true -or !(Fixture-OwnedProcess $record)){throw 'Fixture lama sudah tidak aktif; bersihkan secara eksplisit sebelum mengulang.'}
    Fixture-Identity $record
    Write-Output ('Fixture terisolasi sudah berjalan. Konfigurasi privat: '+$configFile)
    exit 0
}
if(Test-Path -LiteralPath $workspace){throw 'Workspace tanpa manifest ditemukan; tidak ditimpa.'}
[IO.Directory]::CreateDirectory($RootDirectory)|Out-Null
$identity=[Security.Principal.WindowsIdentity]::GetCurrent()
$acl=[IO.Directory]::GetAccessControl($RootDirectory,[Security.AccessControl.AccessControlSections]::Access)
$acl.SetAccessRuleProtection($true,$false)
foreach($existingRule in @($acl.Access)){$acl.RemoveAccessRuleSpecific($existingRule)}
foreach($sid in @($identity.User.Value,'S-1-5-18','S-1-5-32-544')|Select-Object -Unique){
    $principal=New-Object Security.Principal.SecurityIdentifier($sid)
    $rule=New-Object Security.AccessControl.FileSystemAccessRule($principal,'FullControl','ContainerInherit,ObjectInherit','None','Allow')
    $acl.AddAccessRule($rule)
}
[IO.Directory]::SetAccessControl($RootDirectory,$acl)
[IO.Directory]::CreateDirectory($workspace)|Out-Null
$sourceUser=if($env:SPP_DB_USER){$env:SPP_DB_USER}else{'root'}
$sourcePassword=if($null -ne $env:SPP_DB_PASS){$env:SPP_DB_PASS}else{''}
if($sourceUser -match '[\r\n]' -or $sourcePassword -match '[\r\n]'){throw 'Kredensial sumber tidak valid.'}
$escapedUser=$sourceUser.Replace('\','\\').Replace('"','\"')
$escapedPassword=$sourcePassword.Replace('\','\\').Replace('"','\"')
[IO.File]::WriteAllText($sourceOptions,"[client]`r`nhost=127.0.0.1`r`nport=3306`r`nprotocol=TCP`r`nuser=`"$escapedUser`"`r`npassword=`"$escapedPassword`"`r`n",$utf8)
try{
    $sourceIdentity=(Fixture-Sql $sourceOptions 'SELECT @@server_uuid,@@datadir;').Split("`t")
    if($sourceIdentity.Count -ne 2 -or (Canonical-Path $sourceIdentity[1]) -eq $dataDirectory){throw 'Sumber fixture tidak valid.'}
    $before=Invoke-Child $PhpExecutable @((Join-Path $PSScriptRoot 'all_units_test_support.php'),'fingerprint','db_spp') -TimeoutSeconds 120
    if($before.exit_code -ne 0){throw 'Fingerprint sebelum dump gagal.'}
    [IO.File]::WriteAllText((Join-Path $RootDirectory 'operational-before.json'),$before.output,$utf8)
    $dumpFile=Join-Path $RootDirectory 'operational-readonly.sql'
    $dump=Invoke-Child (Join-Path $MysqlBin 'mysqldump.exe') @(('--defaults-file='+$sourceOptions),'--single-transaction','--routines','--triggers','--events','--set-gtid-purged=OFF','--no-tablespaces','--hex-blob','--default-character-set=utf8mb4',('--result-file='+$dumpFile),'--databases','db_spp') -TimeoutSeconds 300 -ErrorLog (Join-Path $RootDirectory 'source-dump.log')
    if($dump.exit_code -ne 0){throw 'Dump baca sumber gagal.'}
    $after=Invoke-Child $PhpExecutable @((Join-Path $PSScriptRoot 'all_units_test_support.php'),'fingerprint','db_spp') -TimeoutSeconds 120
    if($after.exit_code -ne 0){throw 'Fingerprint setelah dump gagal.'}
    [IO.File]::WriteAllText((Join-Path $RootDirectory 'operational-after-initial-read.json'),$after.output,$utf8)
    if($before.output -ne $after.output){throw 'Data operasional berubah selama pembacaan; hentikan fixture dan periksa transaksi.'}
}finally{
    if(Test-Path -LiteralPath $sourceOptions){Remove-Item -LiteralPath $sourceOptions -Force}
}
$initialize=Invoke-Child $mysqld @('--no-defaults','--initialize-insecure',('--basedir='+(Split-Path -Parent $MysqlBin)),('--datadir='+$dataDirectory),('--log-error='+(Join-Path $RootDirectory 'source-initialize.log'))) -TimeoutSeconds 180
if($initialize.exit_code -ne 0){throw 'Inisialisasi sumber QA gagal.'}
$bytes=New-Object byte[] 36
$rng=[Security.Cryptography.RandomNumberGenerator]::Create()
try{$rng.GetBytes($bytes)}finally{$rng.Dispose()}
$password=[Convert]::ToBase64String($bytes)
$listener=New-Object Net.Sockets.TcpListener([Net.IPAddress]::Loopback,0)
$listener.Start()
try{$port=([Net.IPEndPoint]$listener.LocalEndpoint).Port}finally{$listener.Stop()}
[IO.File]::WriteAllText($defaultsFile,"[client]`r`nhost=127.0.0.1`r`nport=$port`r`nprotocol=TCP`r`nuser=root`r`npassword=`"$password`"`r`n",$utf8)
$initFile=Join-Path $workspace 'startup.sql'
[IO.File]::WriteAllText($initFile,"ALTER USER 'root'@'localhost' IDENTIFIED BY '$password';`nCREATE USER 'root'@'127.0.0.1' IDENTIFIED BY '$password';`nGRANT ALL PRIVILEGES ON *.* TO 'root'@'127.0.0.1' WITH GRANT OPTION;`n",$utf8)
$serverArgs=@('--no-defaults',('--basedir='+(Split-Path -Parent $MysqlBin)),('--datadir='+$dataDirectory),('--port='+$port),'--bind-address=127.0.0.1','--mysqlx=0','--skip-log-bin','--skip-name-resolve','--event-scheduler=OFF','--secure-file-priv=NULL','--local-infile=0','--performance-schema=OFF','--innodb-buffer-pool-size=64M','--innodb-redo-log-capacity=64M',('--log-error='+(Join-Path $RootDirectory 'source-mysqld.log')),('--pid-file='+(Join-Path $workspace 'mysqld.pid')),('--init-file='+$initFile))
$process=Start-Process -FilePath $mysqld -ArgumentList (($serverArgs|ForEach-Object{Quote-NativeArgument $_}) -join ' ') -WindowStyle Hidden -PassThru
$processInfo=Get-CimInstance Win32_Process -Filter ('ProcessId='+$process.Id)
if(!$processInfo){throw 'Sumber QA berhenti saat startup.'}
$record=[ordered]@{version=1;isolated_test=$true;server_uuid='';source_server_uuid=$sourceIdentity[0];source_datadir=$sourceIdentity[1];datadir=$dataDirectory;host='127.0.0.1';port=$port;username='root';user='root';password=$password;database='db_spp';defaults_file=$defaultsFile;source_defaults_file=$defaultsFile;pid=$process.Id;process_started_utc_ticks=$processInfo.CreationDate.ToUniversalTime().Ticks.ToString();mysqld_executable=$mysqld}
Write-Json $manifestFile $record
$until=[DateTime]::UtcNow.AddSeconds(90)
$ready=$false
do{
    if($process.HasExited){throw 'Sumber QA berhenti sebelum siap.'}
    try{
        $instanceIdentity=(Fixture-Sql $defaultsFile 'SELECT @@server_uuid,@@datadir,@@event_scheduler;').Split("`t")
        if($instanceIdentity.Count -eq 3 -and $instanceIdentity[0] -ne $sourceIdentity[0] -and (Canonical-Path $instanceIdentity[1]) -eq $dataDirectory -and $instanceIdentity[2] -eq 'OFF'){$record.server_uuid=$instanceIdentity[0];$ready=$true;break}
    }catch{Start-Sleep -Milliseconds 500}
}while([DateTime]::UtcNow -lt $until)
if(!$ready){throw 'Sumber QA tidak siap; manifest dipertahankan untuk diagnosis.'}
Fixture-OwnedProcess $record|Out-Null
Write-Json $manifestFile $record
Remove-Item -LiteralPath $initFile -Force
Fixture-Identity $record
$import=Invoke-Child (Join-Path $MysqlBin 'mysql.exe') @(('--defaults-file='+$defaultsFile),'--binary-mode','--default-character-set=utf8mb4') -InputFile $dumpFile -TimeoutSeconds 600 -ErrorLog (Join-Path $RootDirectory 'source-import.log')
if($import.exit_code -ne 0){throw 'Impor sumber QA gagal; instance tetap terisolasi untuk diagnosis.'}
$storageRoot=Join-Path $RootDirectory 'storage'
[IO.Directory]::CreateDirectory((Join-Path $storageRoot 'jobs'))|Out-Null
Write-Json $configFile ([ordered]@{version=1;app_root=$appRoot;mysql_bin=$MysqlBin;php_executable=$PhpExecutable;storage_root=$storageRoot;database='db_spp';host='127.0.0.1';port=$port;server_uuid=$record.server_uuid;datadir=$dataDirectory;source_defaults_file=$defaultsFile;task_name='SistemSPP Backup QA';task_user=$identity.Name;installed_at=[DateTimeOffset]::UtcNow.ToString('o');allowed_test=$true;isolated_instance_manifest=$manifestFile})
Write-Output ('Fixture terisolasi siap. Konfigurasi privat: '+$configFile)
