[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][string]$ConfigFile,
    [Parameter(Mandatory=$true)][string]$JobDirectory,
    [string]$DumpFile = '',
    [switch]$Recover
)

# Every restore uses a separate mysqld process and datadir. It never connects to
# the source server and never alters SQL to disguise database-qualified views.
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$utf8 = New-Object Text.UTF8Encoding($false)
$ownedProcess = $null
$instance = $null
$cleanupSucceeded = $false
$failure = $null
$validatedJob = $false

function Canonical-Path([string]$Path) {
    return [IO.Path]::GetFullPath($Path.Replace('/', '\')).TrimEnd('\')
}

function Assert-PlainPath([string]$Path) {
    $absolute = Canonical-Path $Path
    $cursor = $absolute
    while ($cursor -and [IO.Path]::GetPathRoot($cursor) -ne $cursor) {
        if (Test-Path -LiteralPath $cursor) {
            $item = Get-Item -LiteralPath $cursor -Force
            if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'Lokasi verifikasi tidak boleh memakai symlink atau junction.' }
        }
        $cursor = Split-Path -Parent $cursor
    }
    return $absolute
}

function Write-Json([string]$Path, $Value) {
    [IO.File]::WriteAllText($Path + '.new', ($Value | ConvertTo-Json -Depth 12), $utf8)
    Move-Item -LiteralPath ($Path + '.new') -Destination $Path -Force
}

function Quote-NativeArgument([string]$Value) {
    if ($Value -notmatch '[\s"]') { return $Value }
    return '"' + [regex]::Replace([regex]::Replace($Value, '(\\*)"', '$1$1\"'), '(\\+)$', '$1$1') + '"'
}

function Invoke-Child([string]$Executable, [string[]]$Arguments, [string]$InputText = '', [string]$InputFile = '', [int]$TimeoutSeconds = 60, [string]$ErrorLog = '') {
    $psi = New-Object Diagnostics.ProcessStartInfo
    $psi.FileName = $Executable
    $psi.Arguments = ($Arguments | ForEach-Object { Quote-NativeArgument $_ }) -join ' '
    $psi.UseShellExecute = $false
    $psi.CreateNoWindow = $true
    $psi.RedirectStandardInput = $true
    $psi.RedirectStandardOutput = $true
    $psi.RedirectStandardError = $true
    $child = New-Object Diagnostics.Process
    $child.StartInfo = $psi
    try {
        if (!$child.Start()) { throw 'Proses verifikasi tidak dapat dimulai.' }
        $stdoutTask = $child.StandardOutput.ReadToEndAsync()
        $stderrTask = $child.StandardError.ReadToEndAsync()
        if ($InputFile) {
            $inputStream = [IO.File]::OpenRead($InputFile)
            try { $inputStream.CopyTo($child.StandardInput.BaseStream) } finally { $inputStream.Dispose() }
        } elseif ($InputText) { $child.StandardInput.WriteLine($InputText) }
        $child.StandardInput.Close()
        if (!$child.WaitForExit($TimeoutSeconds * 1000)) { $child.Kill(); throw 'Proses verifikasi melewati batas waktu.' }
        $stdout = $stdoutTask.GetAwaiter().GetResult()
        $stderr = $stderrTask.GetAwaiter().GetResult()
        if ($ErrorLog -and $stderr) { [IO.File]::AppendAllText($ErrorLog, $stderr, $utf8) }
        return @{exit_code=$child.ExitCode;output=$stdout.Trim()}
    } finally { $child.Dispose() }
}

function Invoke-InstanceSql([string]$Sql, [int]$TimeoutSeconds = 30) {
    $result = Invoke-Child (Join-Path $config.mysql_bin 'mysql.exe') @(('--defaults-file=' + $instance.defaults_file), '--batch', '--skip-column-names', '--raw', '--connect-timeout=2', '--default-character-set=utf8mb4') -InputText $Sql -TimeoutSeconds $TimeoutSeconds
    if ($result.exit_code -ne 0) { throw 'Instance verifikasi belum siap atau menolak pemeriksaan.' }
    return $result.output
}

function Assert-InstanceIdentity {
    $identity = (Invoke-InstanceSql 'SELECT @@server_uuid, @@datadir, @@port, @@event_scheduler;').Split("`t")
    if ($identity.Count -ne 4 -or $identity[0] -eq $config.server_uuid -or (Canonical-Path $identity[1]) -eq (Canonical-Path $config.datadir) -or (Canonical-Path $identity[1]) -ne $dataDirectory -or [int]$identity[2] -ne $instance.port -or $identity[3] -ne 'OFF') {
        throw 'Identitas instance terisolasi tidak cocok; impor atau pembersihan dibatalkan.'
    }
    if ($instance.server_uuid -and $instance.server_uuid -ne $identity[0]) { throw 'UUID instance verifikasi telah berubah.' }
    $instance.server_uuid = $identity[0]
}

function Get-OwnedServerProcess {
    if (!$instance -or !$instance.pid) { return $null }
    $processInfo = Get-CimInstance Win32_Process -Filter ('ProcessId=' + [int]$instance.pid) -ErrorAction SilentlyContinue
    $metadataDeadline = [DateTime]::UtcNow.AddSeconds(2)
    while ($processInfo) {
        if ($processInfo.CreationDate -and $processInfo.CreationDate.ToUniversalTime().Ticks.ToString() -ne $instance.process_started_utc_ticks) {
            # PID reuse proves our recorded process exited. Never inspect or
            # terminate the new occupant; exact-datadir scan still gates delete.
            return $null
        }
        if ($processInfo.CreationDate -and $processInfo.ExecutablePath -and $processInfo.CommandLine) { break }
        # CIM can observe a terminating process after its executable/arguments
        # have been released. Wait for disappearance or a complete snapshot.
        if ([DateTime]::UtcNow -ge $metadataDeadline) { throw 'Metadata proses belum tersedia; workspace dipertahankan.' }
        Start-Sleep -Milliseconds 100
        $processInfo = Get-CimInstance Win32_Process -Filter ('ProcessId=' + [int]$instance.pid) -ErrorAction SilentlyContinue
    }
    $datadirArgument = '(?:^|\s)"?--datadir=' + [regex]::Escape($dataDirectory) + '"?(?:\s|$)'
    $portArgument = '(?:^|\s)"?--port=' + [regex]::Escape([string]$instance.port) + '"?(?:\s|$)'
    # Windows MySQL 8.4 uses a launcher which spawns the actual server. Only
    # adopt its immediate child with the same private arguments/executable,
    # created within two minutes of the recorded owned launch.
    $children = @(Get-CimInstance Win32_Process -Filter ("Name='mysqld.exe' AND ParentProcessId=" + [int]$instance.pid) | Where-Object {
        $_.CreationDate -and $_.ExecutablePath -and (Canonical-Path $_.ExecutablePath) -eq $mysqld -and $_.CommandLine -match $datadirArgument -and $_.CommandLine -match $portArgument -and $_.CreationDate.ToUniversalTime().Ticks -ge [long]$instance.process_started_utc_ticks -and $_.CreationDate.ToUniversalTime().Ticks -le ([long]$instance.process_started_utc_ticks + [TimeSpan]::FromMinutes(2).Ticks)
    })
    if ($children.Count -gt 1) { throw 'Lebih dari satu anak proses cocok; periksa manifest privat.' }
    if ($children.Count -eq 1) {
        if ($processInfo -and $processInfo.CreationDate.ToUniversalTime().Ticks.ToString() -ne $instance.process_started_utc_ticks) { throw 'PID peluncur sudah dipakai proses lain.' }
        $processInfo = $children[0]
        $instance.pid = [int]$processInfo.ProcessId
        $instance.process_started_utc_ticks = $processInfo.CreationDate.ToUniversalTime().Ticks.ToString()
        Write-Json $instanceFile $instance
    }
    if (!$processInfo) { return $null }
    $created = $processInfo.CreationDate.ToUniversalTime().Ticks.ToString()
    if ($created -ne $instance.process_started_utc_ticks -or !$processInfo.ExecutablePath -or (Canonical-Path $processInfo.ExecutablePath) -ne $mysqld -or !$processInfo.CommandLine -or $processInfo.CommandLine -notmatch $datadirArgument -or $processInfo.CommandLine -notmatch $portArgument) {
        throw 'PID instance dipakai proses lain; proses tersebut tidak dihentikan.'
    }
    return $processInfo
}

function Stop-OwnedServer {
    $processInfo = Get-OwnedServerProcess
    if (!$processInfo) { return }
    try {
        Assert-InstanceIdentity
        Invoke-InstanceSql 'SHUTDOWN;' 10 | Out-Null
    } catch {
        # A broken startup may not accept SQL. Killing still requires matching
        # executable, exact datadir argument, PID and creation timestamp.
    }
    $until = [DateTime]::UtcNow.AddSeconds(20)
    do {
        $processInfo = Get-OwnedServerProcess
        if (!$processInfo) { return }
        Start-Sleep -Milliseconds 250
    } while ([DateTime]::UtcNow -lt $until)
    $processInfo = Get-OwnedServerProcess
    if ($processInfo) { Stop-Process -Id ([int]$instance.pid) -Force -ErrorAction Stop }
    Start-Sleep -Milliseconds 250
    if (Get-OwnedServerProcess) { throw 'Instance verifikasi belum berhenti; datadir dipertahankan.' }
}

function Remove-OwnedWorkspace {
    if (!(Test-Path -LiteralPath $workspace)) { return }
    Assert-PlainPath $workspace | Out-Null
    $markerFile = Join-Path $workspace 'owner.json'
    if (!(Test-Path -LiteralPath $markerFile -PathType Leaf)) { throw 'Penanda kepemilikan workspace tidak ditemukan; workspace dipertahankan.' }
    $marker = Get-Content -LiteralPath $markerFile -Raw | ConvertFrom-Json
    if ($marker.job_id -ne $jobId -or (Canonical-Path $marker.datadir) -ne $dataDirectory -or $marker.source_server_uuid -ne $config.server_uuid -or (Canonical-Path $marker.mysqld_executable) -ne $mysqld) {
        throw 'Penanda workspace tidak cocok; workspace dipertahankan.'
    }
    if (Get-OwnedServerProcess) { throw 'Instance masih aktif; workspace dipertahankan.' }
    $datadirArgument = '(?:^|\s)"?--datadir=' + [regex]::Escape($dataDirectory) + '"?(?:\s|$)'
    # The server can exit just before its Windows launcher releases handles.
    # Wait for all exact-datadir processes; never kill an unrecorded process.
    $quiescenceDeadline = [DateTime]::UtcNow.AddSeconds(10)
    do {
        $unrecorded = @(Get-CimInstance Win32_Process -Filter "Name='mysqld.exe'" | Where-Object { $_.CommandLine -and $_.CommandLine -match $datadirArgument })
        if ($unrecorded.Count -eq 0) { break }
        Start-Sleep -Milliseconds 250
    } while ([DateTime]::UtcNow -lt $quiescenceDeadline)
    if ($unrecorded) { throw 'Instance tanpa manifest PID masih aktif; workspace dipertahankan.' }
    # Refuse nested junctions before recursive removal, and validate the exact
    # target again. Never remove the job, backup, source datadir or parent root.
    $nestedReparse = Get-ChildItem -LiteralPath $workspace -Force -Recurse | Where-Object { ($_.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0 } | Select-Object -First 1
    if ($nestedReparse -or (Canonical-Path $workspace) -ne ($JobDirectory + '\verifier-work') -or $dataDirectory -eq (Canonical-Path $config.datadir)) { throw 'Target pembersihan tidak aman; workspace dipertahankan.' }
    $removalDeadline = [DateTime]::UtcNow.AddSeconds(10)
    do {
        try { Remove-Item -LiteralPath $workspace -Recurse -Force -ErrorAction Stop; return }
        catch {
            if ([DateTime]::UtcNow -ge $removalDeadline) { throw }
            # Antivirus/indexing can briefly retain a just-closed log handle.
            # Recheck the exact target before retrying private-workspace only.
            Assert-PlainPath $workspace | Out-Null
            if ((Canonical-Path $workspace) -ne ($JobDirectory + '\verifier-work')) { throw 'Target pembersihan berubah.' }
            Start-Sleep -Milliseconds 250
        }
    } while ($true)
}

try {
    $ConfigFile = Assert-PlainPath $ConfigFile
    $config = Get-Content -LiteralPath $ConfigFile -Raw | ConvertFrom-Json
    if ($config.version -ne 1 -or $config.database -notmatch '^db_spp(?:_(?:audit|test)_[a-z0-9_]+)?$') { throw 'Konfigurasi verifikasi tidak valid.' }
    $JobDirectory = Assert-PlainPath $JobDirectory
    $storage = Assert-PlainPath $config.storage_root
    $jobId = Split-Path -Leaf $JobDirectory
    if ($jobId -notmatch '^[a-f0-9]{32}$' -or (Split-Path -Parent $JobDirectory) -ne (Join-Path $storage 'jobs') -or !(Test-Path -LiteralPath $JobDirectory -PathType Container)) { throw 'Direktori pekerjaan tidak valid.' }
    $validatedJob = $true
    $workspace = Join-Path $JobDirectory 'verifier-work'
    $dataDirectory = Join-Path $workspace 'data'
    $instanceFile = Join-Path $JobDirectory 'verifier-instance.json'
    $mysqld = Assert-PlainPath (Join-Path $config.mysql_bin 'mysqld.exe')
    if ((Canonical-Path $config.datadir) -eq $dataDirectory -or !(Test-Path -LiteralPath $mysqld -PathType Leaf)) { throw 'Lokasi server verifikasi tidak valid.' }
    if (Test-Path -LiteralPath $instanceFile) {
        Assert-PlainPath $instanceFile | Out-Null
        $instance = Get-Content -LiteralPath $instanceFile -Raw | ConvertFrom-Json
        if ($instance.job_id -ne $jobId -or (Canonical-Path $instance.datadir) -ne $dataDirectory -or $instance.source_server_uuid -ne $config.server_uuid -or (Canonical-Path $instance.mysqld_executable) -ne $mysqld) { throw 'Manifest instance tidak cocok dengan pekerjaan.' }
        Stop-OwnedServer
        Remove-OwnedWorkspace
        Remove-Item -LiteralPath $instanceFile -Force
        $instance = $null
    } elseif (Test-Path -LiteralPath $workspace) {
        # Initialization interrupted before a server was launched. A marker
        # alone is sufficient only when no matching mysqld is running.
        $unrecorded = Get-CimInstance Win32_Process -Filter "Name='mysqld.exe'" | Where-Object { $_.CommandLine -and $_.CommandLine.Contains('--datadir=' + $dataDirectory) }
        if ($unrecorded) { throw 'Instance tanpa manifest masih berjalan; periksa kepemilikannya sebelum pemulihan.' }
        Remove-OwnedWorkspace
    }
    if (!$Recover) {
    $DumpFile = Assert-PlainPath $DumpFile
    if (!(Test-Path -LiteralPath $DumpFile -PathType Leaf) -or (Split-Path -Parent $DumpFile) -ne $JobDirectory -or [IO.Path]::GetExtension($DumpFile) -ne '.sql') { throw 'Dump SQL wajib merupakan berkas reguler dalam direktori pekerjaan.' }
    if (!(Test-Path -LiteralPath (Join-Path $JobDirectory 'schema.json') -PathType Leaf)) { throw 'Snapshot definisi objek sumber belum tersedia.' }
    $schemaFile = Assert-PlainPath (Join-Path $JobDirectory 'schema.json')
    [IO.Directory]::CreateDirectory($workspace) | Out-Null
    Write-Json (Join-Path $workspace 'owner.json') ([ordered]@{job_id=$jobId;datadir=$dataDirectory;source_server_uuid=$config.server_uuid;mysqld_executable=$mysqld;created_at=[DateTimeOffset]::UtcNow.ToString('o')})
    $initResult = Invoke-Child $mysqld @('--no-defaults', '--initialize-insecure', ('--basedir=' + (Split-Path -Parent $config.mysql_bin)), ('--datadir=' + $dataDirectory), ('--log-error=' + (Join-Path $JobDirectory 'mysql-initialize.log'))) -TimeoutSeconds 180
    if ($initResult.exit_code -ne 0) { throw 'Inisialisasi server verifikasi gagal.' }
    $bytes = New-Object byte[] 36
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    $password = [Convert]::ToBase64String($bytes)
    $listener = New-Object Net.Sockets.TcpListener([Net.IPAddress]::Loopback, 0)
    $listener.Start()
    try { $port = ([Net.IPEndPoint]$listener.LocalEndpoint).Port } finally { $listener.Stop() }
    $defaultsFile = Join-Path $workspace 'client.cnf'
    [IO.File]::WriteAllText($defaultsFile, "[client]`r`nhost=127.0.0.1`r`nport=$port`r`nprotocol=TCP`r`nuser=root`r`npassword=`"$password`"`r`n", $utf8)
    $initFile = Join-Path $workspace 'startup.sql'
    [IO.File]::WriteAllText($initFile, "ALTER USER 'root'@'localhost' IDENTIFIED BY '$password';`nCREATE USER 'root'@'127.0.0.1' IDENTIFIED BY '$password';`nGRANT ALL PRIVILEGES ON *.* TO 'root'@'127.0.0.1' WITH GRANT OPTION;`n", $utf8)
    $instance = [ordered]@{
        version=1;job_id=$jobId;source_server_uuid=$config.server_uuid;host='127.0.0.1';port=$port
        username='root';password=$password;database=$config.database;server_uuid='';datadir=$dataDirectory
        defaults_file=$defaultsFile;pid=0;process_started_utc_ticks='';mysqld_executable=$mysqld
    }
    $serverArgs = @('--no-defaults', ('--basedir=' + (Split-Path -Parent $config.mysql_bin)), ('--datadir=' + $dataDirectory), ('--port=' + $port), '--bind-address=127.0.0.1', '--mysqlx=0', '--skip-log-bin', '--skip-name-resolve', '--event-scheduler=OFF', '--secure-file-priv=NULL', '--local-infile=0', '--general-log=OFF', '--slow-query-log=OFF', '--performance-schema=OFF', '--innodb-buffer-pool-size=64M', '--innodb-redo-log-capacity=64M', '--max-allowed-packet=1G', ('--log-error=' + (Join-Path $JobDirectory 'mysql-verifier.log')), ('--pid-file=' + (Join-Path $workspace 'mysqld.pid')), ('--init-file=' + $initFile))
    # Persist planned ownership before launching. PID=0 never permits recovery
    # to kill anything; a real PID is recorded immediately after CreateProcess.
    Write-Json $instanceFile $instance
    $ownedProcess = Start-Process -FilePath $mysqld -ArgumentList (($serverArgs | ForEach-Object { Quote-NativeArgument $_ }) -join ' ') -WindowStyle Hidden -PassThru
    $processInfo = Get-CimInstance Win32_Process -Filter ('ProcessId=' + $ownedProcess.Id)
    if (!$processInfo) { throw 'Server verifikasi berhenti saat startup.' }
    $instance.pid = $ownedProcess.Id
    $instance.process_started_utc_ticks = $processInfo.CreationDate.ToUniversalTime().Ticks.ToString()
    Write-Json $instanceFile $instance
    $until = [DateTime]::UtcNow.AddSeconds(90)
    $ready = $false
    do {
        if ($ownedProcess.HasExited) { throw 'Server verifikasi berhenti sebelum siap.' }
        try { Assert-InstanceIdentity; $ready = $true; break } catch { Start-Sleep -Milliseconds 500 }
    } while ([DateTime]::UtcNow -lt $until)
    if (!$ready) { throw 'Server verifikasi tidak siap dalam batas waktu.' }
    Get-OwnedServerProcess | Out-Null
    Write-Json $instanceFile $instance
    # Remove the startup SQL as soon as the random password is established.
    Remove-Item -LiteralPath $initFile -Force
    Assert-InstanceIdentity
    $import = Invoke-Child (Join-Path $config.mysql_bin 'mysql.exe') @(('--defaults-file=' + $defaultsFile), '--binary-mode', '--default-character-set=utf8mb4', '--max-allowed-packet=1G') -InputFile $DumpFile -TimeoutSeconds 7200 -ErrorLog (Join-Path $JobDirectory 'mysql-import.log')
    if ($import.exit_code -ne 0) { throw 'Pemulihan SQL pada instance verifikasi gagal.' }
    Assert-InstanceIdentity
    $auditFile = Assert-PlainPath (Join-Path $config.app_root 'scripts\backup_verify_audit.php')
    if (!(Test-Path -LiteralPath $auditFile -PathType Leaf)) { throw 'Alat audit pemulihan belum tersedia.' }
    $previousEnvironment = @{}
    $auditEnvironment = @{SPP_DB_HOST='127.0.0.1';SPP_DB_PORT=[string]$port;SPP_DB_USER='root';SPP_DB_PASS=$password;SPP_DB_NAME=$config.database}
    try {
        foreach ($entry in $auditEnvironment.GetEnumerator()) {
            $previousEnvironment[$entry.Key] = [Environment]::GetEnvironmentVariable($entry.Key, 'Process')
            [Environment]::SetEnvironmentVariable($entry.Key, $entry.Value, 'Process')
        }
        $audit = Invoke-Child $config.php_executable @($auditFile, ('--config=' + $ConfigFile), ('--instance=' + $instanceFile), ('--job=' + $JobDirectory)) -TimeoutSeconds 3600 -ErrorLog (Join-Path $JobDirectory 'verification-audit.log')
    } finally {
        foreach ($entry in $previousEnvironment.GetEnumerator()) { [Environment]::SetEnvironmentVariable($entry.Key, $entry.Value, 'Process') }
    }
    if ($audit.exit_code -ne 0 -or !(Test-Path -LiteralPath (Join-Path $JobDirectory 'verification.json') -PathType Leaf)) { throw 'Audit pemulihan tidak lulus.' }
    }
} catch {
    $failure = 'Verifikasi cadangan gagal. Periksa log privat pekerjaan; cadangan sebelumnya tetap dipertahankan.'
    if ($validatedJob) { [IO.File]::AppendAllText((Join-Path $JobDirectory 'verification-runner.log'), ([DateTimeOffset]::UtcNow.ToString('o') + ' ' + $_.Exception.Message + "`r`n"), $utf8) }
} finally {
    if (Get-Variable workspace -ErrorAction SilentlyContinue) {
        try {
            Stop-OwnedServer
            Remove-OwnedWorkspace
            if (Test-Path -LiteralPath $instanceFile) { Remove-Item -LiteralPath $instanceFile -Force }
            $cleanupSucceeded = $true
        } catch {
            $failure = 'Pembersihan instance verifikasi tertunda. Manifest privat dipertahankan untuk pemulihan aman.'
            if ($validatedJob) { [IO.File]::AppendAllText((Join-Path $JobDirectory 'verification-runner.log'), ([DateTimeOffset]::UtcNow.ToString('o') + ' CLEANUP ' + $_.Exception.GetType().FullName + ': ' + $_.Exception.Message + "`r`n"), $utf8) }
        }
    }
    if ($ownedProcess) { $ownedProcess.Dispose() }
    if ($validatedJob) {
        if (Test-Path -LiteralPath $JobDirectory -PathType Container) {
            Write-Json (Join-Path $JobDirectory 'verifier-result.json') ([ordered]@{ok=($null -eq $failure);cleanup_complete=$cleanupSucceeded;finished_at=[DateTimeOffset]::UtcNow.ToString('o');message=$failure})
        }
    }
}
if ($failure) { Write-Output '{"ok":false,"message":"Verifikasi atau pembersihan gagal; periksa riwayat privat."}'; exit 1 }
if ($Recover) { Write-Output '{"ok":true,"recovered":true}'; exit 0 }
Write-Output '{"ok":true,"cleanup_complete":true}'
exit 0
