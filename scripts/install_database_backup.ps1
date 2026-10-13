[CmdletBinding()]
param(
    [string]$ConfigFile = 'C:\laragon\data\spp-backup\db_spp\config.json',
    [string]$MysqlBin = 'C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin',
    [string]$PhpExecutable = 'C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe',
    [string]$StorageRoot = 'C:\laragon\backups\spp-management-system\daily\db_spp',
    [string]$AdminDefaultsFile = '',
    [string]$TaskName = 'SistemSPP Database Backup',
    [switch]$ConfirmMain,
    [switch]$RegisterTask,
    [switch]$RunNow
)

# Configuration and passwords belong outside the document root. This installer
# never needs or persists a Windows password: the scheduled task uses S4U.
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$appRoot = [IO.Path]::GetFullPath((Split-Path -Parent $PSScriptRoot)).TrimEnd('\')
$utf8 = New-Object Text.UTF8Encoding($false)
$owner = [Security.Principal.WindowsIdentity]::GetCurrent()
$ownerSid = $owner.User.Value
$temporaryAdminFile = $null

function Resolve-BackupIdentitySid([string]$Identity) {
    if ([string]::IsNullOrWhiteSpace($Identity)) { return $null }
    try {
        if ($Identity -match '^S-\d-') {
            return ([Security.Principal.SecurityIdentifier]::new($Identity)).Value
        }
        # Task Scheduler may return a local short name rather than the full
        # MACHINE\user name submitted during registration. Resolve both through
        # Windows; never trust a short-name string comparison for ownership.
        $account = [Security.Principal.NTAccount]::new($Identity)
        return $account.Translate([Security.Principal.SecurityIdentifier]).Value
    } catch { return $null }
}

function Test-BackupTaskOwnership($Task, $Manifest, [string]$ExpectedConfig, [string]$ExpectedOwnerSid, [string]$ExpectedExecutable, [string]$ExpectedArguments) {
    if (!$Task -or !$Manifest -or !$ExpectedOwnerSid) { return $false }
    try {
        $actions = @($Task.Actions)
        $principalSid = Resolve-BackupIdentitySid ([string]$Task.Principal.UserId)
        return ($Manifest.config_file -eq $ExpectedConfig -and $Manifest.owner_sid -eq $ExpectedOwnerSid -and $actions.Count -eq 1 -and $actions[0].Execute -eq $ExpectedExecutable -and $actions[0].Arguments -eq $ExpectedArguments -and $principalSid -eq $ExpectedOwnerSid)
    } catch { return $false }
}

function Assert-PlainPath([string]$Path) {
    $absolute = [IO.Path]::GetFullPath($Path)
    $cursor = $absolute
    while ($cursor -and [IO.Path]::GetPathRoot($cursor) -ne $cursor) {
        if (Test-Path -LiteralPath $cursor) {
            $item = Get-Item -LiteralPath $cursor -Force
            if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
                throw 'Lokasi pencadangan tidak boleh memakai symlink atau junction.'
            }
        }
        $cursor = Split-Path -Parent $cursor
    }
    return $absolute
}

function Protect-Directory([string]$Path) {
    $full = Assert-PlainPath $Path
    if ($full.StartsWith($appRoot + '\', [StringComparison]::OrdinalIgnoreCase) -or $full -eq $appRoot) {
        throw 'Konfigurasi dan cadangan wajib berada di luar webroot proyek.'
    }
    [IO.Directory]::CreateDirectory($full) | Out-Null
    $acl = [IO.Directory]::GetAccessControl($full, [Security.AccessControl.AccessControlSections]::Access)
    $acl.SetAccessRuleProtection($true, $false)
    foreach ($existingRule in @($acl.Access)) { $acl.RemoveAccessRuleSpecific($existingRule) }
    foreach ($sid in @($ownerSid, 'S-1-5-18', 'S-1-5-32-544') | Select-Object -Unique) {
        $identity = New-Object Security.Principal.SecurityIdentifier($sid)
        $rule = New-Object Security.AccessControl.FileSystemAccessRule($identity, 'FullControl', 'ContainerInherit,ObjectInherit', 'None', 'Allow')
        $acl.AddAccessRule($rule)
    }
    [IO.Directory]::SetAccessControl($full, $acl)
    return $full
}

function Write-PrivateJson([string]$Path, $Value) {
    [IO.File]::WriteAllText($Path + '.new', ($Value | ConvertTo-Json -Depth 12), $utf8)
    Move-Item -LiteralPath ($Path + '.new') -Destination $Path -Force
}

function Quote-NativeArgument([string]$Value) {
    if ($Value -notmatch '[\s"]') { return $Value }
    return '"' + [regex]::Replace([regex]::Replace($Value, '(\\*)"', '$1$1\"'), '(\\+)$', '$1$1') + '"'
}

# Reusing an unchanged, owned task avoids unnecessary administrator prompts.
function Test-BackupTaskSchedule($Task, [string]$ApplicationRoot) {
    try {
        if ($Task.Actions[0].WorkingDirectory -ne $ApplicationRoot -or [string]$Task.Principal.LogonType -ne 'S4U' -or [int]$Task.Principal.RunLevel -ne 0) { return $false }
        $settings = $Task.Settings
        if (!$settings.Enabled -or !$settings.StartWhenAvailable -or [int]$settings.MultipleInstances -ne 2 -or !$settings.WakeToRun -or !$settings.AllowDemandStart -or $settings.ExecutionTimeLimit -ne 'PT6H' -or $settings.DisallowStartIfOnBatteries -or $settings.StopIfGoingOnBatteries -or $settings.AllowHardTerminate) { return $false }
        $triggers = @($Task.Triggers)
        $daily = @($triggers | Where-Object {$_.CimClass.CimClassName -eq 'MSFT_TaskDailyTrigger'})
        $queue = @($triggers | Where-Object {$_.CimClass.CimClassName -eq 'MSFT_TaskTimeTrigger'})
        $boot = @($triggers | Where-Object {$_.CimClass.CimClassName -eq 'MSFT_TaskBootTrigger'})
        if ($triggers.Count -ne 3 -or $daily.Count -ne 1 -or $queue.Count -ne 1 -or $boot.Count -ne 1) { return $false }
        if (!$daily[0].Enabled -or $daily[0].DaysInterval -ne 1 -or $daily[0].StartBoundary -notmatch 'T18:00:00\+07:00$' -or $daily[0].EndBoundary) { return $false }
        if (!$queue[0].Enabled -or $queue[0].Repetition.Interval -ne 'PT1M' -or $queue[0].EndBoundary -or !$boot[0].Enabled -or $boot[0].Delay -ne 'PT1M' -or $boot[0].EndBoundary) { return $false }
        return $true
    } catch { return $false }
}

function Invoke-Mysql([string]$DefaultsFile, [string]$Sql) {
    $psi = New-Object Diagnostics.ProcessStartInfo
    $psi.FileName = Join-Path $MysqlBin 'mysql.exe'
    $mysqlArgs = @(('--defaults-file=' + $DefaultsFile), '--host=127.0.0.1', '--port=3306', '--protocol=TCP', '--batch', '--skip-column-names', '--raw', '--connect-timeout=10', '--default-character-set=utf8mb4')
    $psi.Arguments = ($mysqlArgs | ForEach-Object { Quote-NativeArgument $_ }) -join ' '
    $psi.UseShellExecute = $false
    $psi.CreateNoWindow = $true
    $psi.RedirectStandardInput = $true
    $psi.RedirectStandardOutput = $true
    $psi.RedirectStandardError = $true
    $process = New-Object Diagnostics.Process
    $process.StartInfo = $psi
    if (!$process.Start()) { throw 'Klien MySQL tidak dapat dimulai.' }
    $outputTask = $process.StandardOutput.ReadToEndAsync()
    $errorTask = $process.StandardError.ReadToEndAsync()
    $process.StandardInput.WriteLine($Sql)
    $process.StandardInput.Close()
    if (!$process.WaitForExit(30000)) { $process.Kill(); throw 'MySQL melewati batas waktu pemasangan.' }
    $result = $outputTask.GetAwaiter().GetResult()
    $ignoredError = $errorTask.GetAwaiter().GetResult()
    if ($process.ExitCode -ne 0) { throw 'Pemeriksaan atau penyediaan akun MySQL gagal; kredensial dan SQL tidak ditampilkan.' }
    $process.Dispose()
    return $result.Trim()
}

function New-RandomPassword {
    $bytes = New-Object byte[] 36
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return [Convert]::ToBase64String($bytes)
}

function Escape-Option([string]$Value) {
    if ($Value.Contains("`r") -or $Value.Contains("`n")) { throw 'Nilai konfigurasi MySQL tidak valid.' }
    return $Value.Replace('\', '\\').Replace('"', '\"')
}

try {
    $ConfigFile = Assert-PlainPath $ConfigFile
    $MysqlBin = Assert-PlainPath $MysqlBin
    $PhpExecutable = Assert-PlainPath $PhpExecutable
    foreach ($executable in @($PhpExecutable, (Join-Path $MysqlBin 'mysql.exe'), (Join-Path $MysqlBin 'mysqldump.exe'), (Join-Path $MysqlBin 'mysqld.exe'))) {
        if (!(Test-Path -LiteralPath $executable -PathType Leaf)) { throw 'Executable PHP/MySQL yang diperlukan tidak ditemukan.' }
    }
    if ($TaskName -match '[\\/\x00-\x1f]' -or $TaskName.Length -gt 120) { throw 'Nama task tidak valid.' }
    $configDirectory = Protect-Directory (Split-Path -Parent $ConfigFile)
    $StorageRoot = Protect-Directory $StorageRoot
    [IO.Directory]::CreateDirectory((Join-Path $StorageRoot 'jobs')) | Out-Null
    $defaultsFile = Join-Path $configDirectory 'source-client.cnf'
    $provisionFile = Join-Path $configDirectory 'account-provision.json'
    $taskManifestFile = Join-Path $configDirectory 'scheduled-task.json'
    $config = $null
    if (Test-Path -LiteralPath $ConfigFile -PathType Leaf) {
        $config = Get-Content -LiteralPath $ConfigFile -Raw | ConvertFrom-Json
        if ($config.version -ne 1 -or $config.database -ne 'db_spp' -or $config.app_root -ne $appRoot -or $config.task_user -ne $owner.Name -or $config.source_defaults_file -ne $defaultsFile) {
            throw 'Konfigurasi yang ada bukan milik instalasi atau pengguna ini; tidak diambil alih.'
        }
        if ($config.storage_root -ne $StorageRoot -or $config.mysql_bin -ne $MysqlBin -or $config.php_executable -ne $PhpExecutable -or $config.task_name -ne $TaskName) {
            throw 'Lokasi atau nama konfigurasi telah berbeda. Gunakan parameter yang sama dengan instalasi sebelumnya.'
        }
        Assert-PlainPath $defaultsFile | Out-Null
        $identity = (Invoke-Mysql $defaultsFile 'SELECT @@server_uuid, @@datadir, VERSION(), CURRENT_USER();').Split("`t")
        if ($identity.Count -ne 4 -or $identity[0] -ne $config.server_uuid -or $identity[1] -ne $config.datadir -or $identity[3] -ne 'spp_backup_local@127.0.0.1') {
            throw 'Identitas server atau akun backup berubah; konfigurasi lama dipertahankan.'
        }
    } else {
        if (!$ConfirmMain) { throw 'Penyediaan akun pada db_spp memerlukan -ConfirmMain setelah pengujian salinan lulus.' }
        if ($AdminDefaultsFile) {
            $AdminDefaultsFile = Assert-PlainPath $AdminDefaultsFile
            if (!(Test-Path -LiteralPath $AdminDefaultsFile -PathType Leaf)) { throw 'Berkas opsi administrator tidak ditemukan.' }
        } else {
            $temporaryAdminFile = Join-Path $configDirectory ('admin-' + [Guid]::NewGuid().ToString('N') + '.cnf')
            $adminUser = if ($env:SPP_DB_USER) { $env:SPP_DB_USER } else { 'root' }
            $adminPassword = if ($null -ne $env:SPP_DB_PASS) { $env:SPP_DB_PASS } else { '' }
            $options = "[client]`r`nhost=127.0.0.1`r`nport=3306`r`nprotocol=TCP`r`nuser=`"$(Escape-Option $adminUser)`"`r`npassword=`"$(Escape-Option $adminPassword)`"`r`n"
            [IO.File]::WriteAllText($temporaryAdminFile, $options, $utf8)
            $AdminDefaultsFile = $temporaryAdminFile
        }
        $identity = (Invoke-Mysql $AdminDefaultsFile 'SELECT @@server_uuid, @@datadir, VERSION();').Split("`t")
        if ($identity.Count -ne 3 -or $identity[2] -notmatch '^8\.4\.') { throw 'Pencadangan lokal ini memerlukan server MySQL 8.4.' }
        $databaseExists = Invoke-Mysql $AdminDefaultsFile "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='db_spp';"
        if ($databaseExists -ne '1') { throw 'Database lokal db_spp tidak ditemukan.' }
        $otherEngines = Invoke-Mysql $AdminDefaultsFile "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='db_spp' AND TABLE_TYPE='BASE TABLE' AND ENGINE <> 'InnoDB';"
        if ($otherEngines -ne '0') { throw 'Seluruh tabel fisik harus InnoDB agar snapshot konsisten.' }
        $accountExists = Invoke-Mysql $AdminDefaultsFile "SELECT COUNT(*) FROM mysql.user WHERE User='spp_backup_local' AND Host='127.0.0.1';"
        $provision = $null
        if (Test-Path -LiteralPath $provisionFile -PathType Leaf) {
            $provision = Get-Content -LiteralPath $provisionFile -Raw | ConvertFrom-Json
            if ($provision.version -ne 1 -or $provision.owner_sid -ne $ownerSid -or $provision.app_root -ne $appRoot -or $provision.database -ne 'db_spp' -or $provision.server_uuid -ne $identity[0] -or $provision.datadir -ne $identity[1] -or $provision.defaults_file -ne $defaultsFile) {
                throw 'Jurnal penyediaan akun tidak cocok; akun dan berkas tidak diambil alih.'
            }
        } elseif ($accountExists -ne '0' -or (Test-Path -LiteralPath $defaultsFile)) {
            throw 'Akun atau berkas opsi sudah ada tanpa jurnal kepemilikan; periksa secara manual.'
        } else {
            $provision = [ordered]@{version=1;owner_sid=$ownerSid;app_root=$appRoot;database='db_spp';server_uuid=$identity[0];datadir=$identity[1];defaults_file=$defaultsFile;created_at=[DateTimeOffset]::UtcNow.ToString('o')}
            Write-PrivateJson $provisionFile $provision
        }
        if (Test-Path -LiteralPath $defaultsFile -PathType Leaf) {
            Assert-PlainPath $defaultsFile | Out-Null
            $optionText = [IO.File]::ReadAllText($defaultsFile)
            $passwordMatch = [regex]::Match($optionText, '(?m)^password="([A-Za-z0-9+/=]+)"\r?$')
            if (!$passwordMatch.Success) { throw 'Berkas opsi penyediaan tidak valid; periksa secara manual.' }
            $password = $passwordMatch.Groups[1].Value
        } else {
            if ($accountExists -ne '0') { throw 'Akun milik jurnal tidak memiliki kredensial privat; akun tidak diubah.' }
            $password = New-RandomPassword
            $options = "[client]`r`nhost=127.0.0.1`r`nport=3306`r`nprotocol=TCP`r`nuser=spp_backup_local`r`npassword=`"$password`"`r`n"
            [IO.File]::WriteAllText($defaultsFile + '.new', $options, $utf8)
            Move-Item -LiteralPath ($defaultsFile + '.new') -Destination $defaultsFile -Force
        }
        if ($accountExists -ne '0') {
            $ownedIdentity = (Invoke-Mysql $defaultsFile 'SELECT @@server_uuid, @@datadir, CURRENT_USER();').Split("`t")
            if ($ownedIdentity.Count -ne 3 -or $ownedIdentity[0] -ne $identity[0] -or $ownedIdentity[1] -ne $identity[1] -or $ownedIdentity[2] -ne 'spp_backup_local@127.0.0.1') {
                throw 'Akun yang sudah ada tidak cocok dengan kredensial jurnal; akun tidak diubah.'
            }
        }
        $functionExists = Invoke-Mysql $AdminDefaultsFile "SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='db_spp' AND ROUTINE_NAME='current_unit_id' AND ROUTINE_TYPE='FUNCTION';"
        $grants = if ($accountExists -eq '0') { "CREATE USER 'spp_backup_local'@'127.0.0.1' IDENTIFIED BY '$password';" } else { '' }
        $grants += " GRANT SELECT, SHOW VIEW, TRIGGER, EVENT ON db_spp.* TO 'spp_backup_local'@'127.0.0.1'; GRANT SHOW_ROUTINE ON *.* TO 'spp_backup_local'@'127.0.0.1';"
        if ($functionExists -eq '1') { $grants += " GRANT EXECUTE ON FUNCTION db_spp.current_unit_id TO 'spp_backup_local'@'127.0.0.1';" }
        Invoke-Mysql $AdminDefaultsFile $grants | Out-Null
        $config = [ordered]@{
            version = 1; app_root = $appRoot; mysql_bin = $MysqlBin; php_executable = $PhpExecutable
            storage_root = $StorageRoot; database = 'db_spp'; host = '127.0.0.1'; port = 3306
            server_uuid = $identity[0]; datadir = $identity[1]; source_defaults_file = $defaultsFile
            task_name = $TaskName; task_user = $owner.Name; installed_at = $provision.created_at
        }
        Write-PrivateJson $ConfigFile $config
        $password = $null
        Write-Output 'Konfigurasi privat dan akun MySQL ekspor berhasil disiapkan.'
    }

    $runnerFile = Join-Path $appRoot 'scripts\database_backup_runner.php'
    if (!(Test-Path -LiteralPath $runnerFile -PathType Leaf)) { throw 'Runner belum tersedia; antrean belum diinisialisasi.' }
    & $PhpExecutable $runnerFile ('--config=' + $ConfigFile) '--setup'
    if ($LASTEXITCODE -ne 0) { throw 'Inisialisasi penyimpanan antrean gagal; task belum dipasang.' }

    if ($RegisterTask) {
        if (!$ConfirmMain) { throw 'Pemasangan task produksi memerlukan -ConfirmMain.' }
        $existingTask = Get-ScheduledTask -TaskName $TaskName -TaskPath '\' -ErrorAction SilentlyContinue
        $manifest = $null
        if (Test-Path -LiteralPath $taskManifestFile) { $manifest = Get-Content -LiteralPath $taskManifestFile -Raw | ConvertFrom-Json }
        $arguments = '"' + $runnerFile + '" "--config=' + $ConfigFile + '" --tick'
        if ($existingTask) {
            if (!(Test-BackupTaskOwnership $existingTask $manifest $ConfigFile $ownerSid $PhpExecutable $arguments)) {
                throw 'Task bernama sama tidak cocok dengan manifest instalasi; tidak ditimpa.'
            }
        }
        $jakarta = [TimeZoneInfo]::FindSystemTimeZoneById('SE Asia Standard Time')
        $nowJakarta = [TimeZoneInfo]::ConvertTime([DateTimeOffset]::UtcNow, $jakarta)
        $nextJakarta = [DateTimeOffset]::new($nowJakarta.Year, $nowJakarta.Month, $nowJakarta.Day, 18, 0, 0, [TimeSpan]::FromHours(7))
        if ($nextJakarta -le $nowJakarta) { $nextJakarta = $nextJakarta.AddDays(1) }
        # Boundaries with +07:00 keep the daily trigger at WIB even if the host timezone changes.
        $dailyBoundary = $nextJakarta.ToString('yyyy-MM-ddTHH:mm:sszzz')
        $queueBoundary = [DateTimeOffset]::UtcNow.AddMinutes(1).ToString('yyyy-MM-ddTHH:mm:sszzz')
        $escape = { param($s) [Security.SecurityElement]::Escape([string]$s) }
        $xml = @"
<?xml version="1.0" encoding="UTF-16"?>
<Task version="1.4" xmlns="http://schemas.microsoft.com/windows/2004/02/mit/task">
  <RegistrationInfo><Description>Backup seluruh db_spp pada 18.00 WIB, antrean manual, dan susulan. Dikelola oleh SistemSPP.</Description></RegistrationInfo>
  <Triggers>
    <CalendarTrigger><StartBoundary>$dailyBoundary</StartBoundary><Enabled>true</Enabled><ScheduleByDay><DaysInterval>1</DaysInterval></ScheduleByDay></CalendarTrigger>
    <TimeTrigger><Repetition><Interval>PT1M</Interval><StopAtDurationEnd>false</StopAtDurationEnd></Repetition><StartBoundary>$queueBoundary</StartBoundary><Enabled>true</Enabled></TimeTrigger>
    <BootTrigger><Enabled>true</Enabled><Delay>PT1M</Delay></BootTrigger>
  </Triggers>
  <Principals><Principal id="BackupOwner"><UserId>$(& $escape $owner.Name)</UserId><LogonType>S4U</LogonType><RunLevel>LeastPrivilege</RunLevel></Principal></Principals>
  <Settings><MultipleInstancesPolicy>IgnoreNew</MultipleInstancesPolicy><DisallowStartIfOnBatteries>false</DisallowStartIfOnBatteries><StopIfGoingOnBatteries>false</StopIfGoingOnBatteries><AllowHardTerminate>false</AllowHardTerminate><StartWhenAvailable>true</StartWhenAvailable><RunOnlyIfNetworkAvailable>false</RunOnlyIfNetworkAvailable><IdleSettings><StopOnIdleEnd>false</StopOnIdleEnd><RestartOnIdle>false</RestartOnIdle></IdleSettings><AllowStartOnDemand>true</AllowStartOnDemand><Enabled>true</Enabled><Hidden>false</Hidden><RunOnlyIfIdle>false</RunOnlyIfIdle><WakeToRun>true</WakeToRun><ExecutionTimeLimit>PT6H</ExecutionTimeLimit><Priority>7</Priority></Settings>
  <Actions Context="BackupOwner"><Exec><Command>$(& $escape $PhpExecutable)</Command><Arguments>$(& $escape $arguments)</Arguments><WorkingDirectory>$(& $escape $appRoot)</WorkingDirectory></Exec></Actions>
</Task>
"@
        if ($existingTask -and (Test-BackupTaskSchedule $existingTask $appRoot)) {
            Write-Output 'Task milik instalasi ini sudah sesuai: harian 18.00 WIB, antrean per menit, dan pemulihan startup (S4U, hak terbatas).'
        } else {
        # Persist ownership before registration so an interrupted install can resume.
        Write-PrivateJson $taskManifestFile ([ordered]@{task_name=$TaskName;config_file=$ConfigFile;owner_sid=$ownerSid;owner=$owner.Name;registered_at=$null;daily_timezone='Asia/Jakarta';daily_time='18:00';arguments=$arguments})
        try {
            Register-ScheduledTask -TaskName $TaskName -TaskPath '\' -Xml $xml -Force | Out-Null
        } catch {
            Write-PrivateJson (Join-Path $configDirectory 'task-registration-error.json') ([ordered]@{time=[DateTimeOffset]::UtcNow.ToString('o');error=$_.Exception.Message})
            throw 'Windows menolak pendaftaran task S4U. Jalankan pemasang sekali dari PowerShell sebagai Administrator dengan akun pemilik yang sama; konfigurasi dan cadangan tetap aman.'
        }
        Write-PrivateJson $taskManifestFile ([ordered]@{task_name=$TaskName;config_file=$ConfigFile;owner_sid=$ownerSid;owner=$owner.Name;registered_at=[DateTimeOffset]::UtcNow.ToString('o');daily_timezone='Asia/Jakarta';daily_time='18:00';arguments=$arguments})
        Write-Output 'Task harian 18.00 WIB, antrean per menit, dan pemulihan startup terpasang (S4U, hak terbatas).'
        }
        if ($RunNow) {
            Start-ScheduledTask -TaskName $TaskName -TaskPath '\'
            Write-Output 'Task sebenarnya telah dimulai. Periksa riwayat untuk hasil verifikasi.'
        }
    } elseif ($RunNow) {
        throw '-RunNow harus disertai -RegisterTask.'
    } else {
        Write-Output 'Task belum dipasang. Gunakan -RegisterTask setelah pengujian lulus.'
    }
} finally {
    if ($temporaryAdminFile -and (Test-Path -LiteralPath $temporaryAdminFile)) {
        Remove-Item -LiteralPath $temporaryAdminFile -Force
    }
}
