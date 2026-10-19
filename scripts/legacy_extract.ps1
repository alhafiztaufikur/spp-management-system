param([Parameter(Mandatory=$true)][string]$JobDirectory,[Parameter(Mandatory=$true)][ValidateSet(1,2,3)][int]$Unit,[switch]$Setup,[switch]$Health,[switch]$Recover,[ValidateSet('students','finance','full')][string]$Category='students')
$ErrorActionPreference='Stop'
$exe='C:\Program Files\Microsoft SQL Server\150\Tools\Binn\SqlLocalDB.exe'
$root=[IO.Path]::GetFullPath($JobDirectory)
$instance='SppLegacyImporter_'+(([Security.Cryptography.SHA256]::Create().ComputeHash([Text.Encoding]::UTF8.GetBytes($(if($Health){$root}else{Split-Path -Parent $root})))|ForEach-Object{$_.ToString('x2')}) -join '').Substring(0,12)
if($Setup){
    $instance='SppLegacyImporter_'+(([Security.Cryptography.SHA256]::Create().ComputeHash([Text.Encoding]::UTF8.GetBytes($root))|ForEach-Object{$_.ToString('x2')}) -join '').Substring(0,12)
    $sid=[Security.Principal.WindowsIdentity]::GetCurrent().User.Value
    & icacls.exe $root '/inheritance:r' '/grant:r' "*$($sid):(OI)(CI)F" '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' | Out-Null
    if($LASTEXITCODE -ne 0){throw 'Private storage ACL failed'}
    $owner=Join-Path $root 'instance.json'
    $existing=@(& $exe info)
    if($existing -contains $instance){if(!(Test-Path -LiteralPath $owner)){throw 'Existing instance has no ownership manifest'}}
    else{& $exe create $instance '15.0' | Out-Null;if($LASTEXITCODE -ne 0){throw 'LocalDB create failed'};@{instance=$instance;created_by='SistemSPP Legacy Importer';root=$root;user=[Security.Principal.WindowsIdentity]::GetCurrent().Name}|ConvertTo-Json|Set-Content -LiteralPath $owner -Encoding UTF8}
    & $exe start $instance | Out-Null;if($LASTEXITCODE -ne 0){throw 'LocalDB start failed'}
    exit
}
$ownerPath=Join-Path $(if($Health){$root}else{Split-Path -Parent $root}) 'instance.json'
if(!(Test-Path -LiteralPath $ownerPath)){throw 'Importer ownership missing'}
$owner=Get-Content -LiteralPath $ownerPath -Raw|ConvertFrom-Json
if($owner.instance -ne $instance -or $owner.created_by -ne 'SistemSPP Legacy Importer' -or $owner.user -ne [Security.Principal.WindowsIdentity]::GetCurrent().Name){throw 'Importer identity mismatch'}
$id=Split-Path -Leaf $root
if(!$Health -and $id -notmatch '^[a-f0-9]{32}$'){throw 'Invalid job identity'}
$dbName='spp_import_'+$id
$backup=(Join-Path $root 'source.dat').Replace("'","''")
function Check-Cancel {if(Test-Path -LiteralPath (Join-Path $root 'cancel')){throw 'Job cancelled'}}
function Progress([string]$stage,[int]$done=0,[int]$total=0){
    Check-Cancel
    @{stage=$stage;done=$done;total=$total}|ConvertTo-Json|Set-Content -LiteralPath (Join-Path $root 'progress.next') -Encoding UTF8
    Move-Item -LiteralPath (Join-Path $root 'progress.next') -Destination (Join-Path $root 'progress.json') -Force
}
function Sql([string]$query,[string]$database='master'){
    $cn=New-Object System.Data.SqlClient.SqlConnection("Server=(localdb)\$instance;Database=$database;Integrated Security=true;Connect Timeout=30;Encrypt=false")
    $cn.Open()
    try{$cmd=$cn.CreateCommand();$cmd.CommandText=$query;$cmd.CommandTimeout=180;$r=$cmd.ExecuteReader();$rows=New-Object 'System.Collections.Generic.List[object]'
        try{do{while($r.Read()){$record=[ordered]@{};for($i=0;$i -lt $r.FieldCount;$i++){$v=if($r.IsDBNull($i)){$null}else{$r.GetValue($i)};if($v -is [DateTime]){$v=$v.ToString('o')};$record[$r.GetName($i)]=$v};$rows.Add([pscustomobject]$record)}}while($r.NextResult())}finally{$r.Dispose()};return $rows.ToArray()
    }finally{$cn.Dispose()}
}
if($Health){Sql 'SELECT 1 AS ready'|Out-Null;exit}
if($Recover){
    $processPath=Join-Path $root 'extract-process.json'
    if(Test-Path -LiteralPath $processPath){
        $saved=Get-Content -LiteralPath $processPath -Raw|ConvertFrom-Json
        $old=Get-CimInstance Win32_Process -Filter "ProcessId=$([int]$saved.pid)"
        if($old -and [int]$saved.pid -ne $PID){
            if($old.Name -ne 'powershell.exe' -or !$old.CommandLine.Contains('legacy_extract.ps1') -or !$old.CommandLine.Replace('/','\').Contains($root) -or $old.CreationDate.ToUniversalTime().Ticks.ToString() -ne $saved.created){throw 'Interrupted extractor ownership mismatch'}
            Stop-Process -Id ([int]$saved.pid) -Force
        }
    }
    $paths=@(Sql "SELECT physical_name FROM sys.master_files WHERE database_id=DB_ID(N'$dbName')")
    foreach($path in $paths){if(!([IO.Path]::GetFullPath($path.physical_name).StartsWith($root+'\',[StringComparison]::OrdinalIgnoreCase))){throw 'Recovery path ownership mismatch'}}
    if($paths.Count){Sql "ALTER DATABASE [$dbName] SET SINGLE_USER WITH ROLLBACK IMMEDIATE; DROP DATABASE [$dbName]"|Out-Null}
    exit
}
$created=$false
$self=Get-CimInstance Win32_Process -Filter "ProcessId=$PID"
@{pid=$PID;created=$self.CreationDate.ToUniversalTime().Ticks.ToString()}|ConvertTo-Json|Set-Content -LiteralPath (Join-Path $root 'extract-process.next') -Encoding UTF8
Move-Item -LiteralPath (Join-Path $root 'extract-process.next') -Destination (Join-Path $root 'extract-process.json') -Force
try {
    Progress 'Periksa Backup'
    $headers=@(Sql "RESTORE HEADERONLY FROM DISK=N'$backup'")
    if($headers.Count -ne 1){throw 'Exactly one full backup set required'}
    $h=$headers[0];$expected=@{1='sdit_mh';2='smpit_mh';3='smait_mh'}[$Unit]
    if($h.DatabaseName -ne $expected -or $h.BackupType -ne 1 -or $h.IsDamaged -or $h.SoftwareVersionMajor -gt 15 -or $h.EncryptorThumbprint){throw 'Backup profile/unit/version/encryption is not supported'}
    $files=@(Sql "RESTORE FILELISTONLY FROM DISK=N'$backup'")
    Sql "RESTORE VERIFYONLY FROM DISK=N'$backup'"|Out-Null
    $size=($files|Measure-Object -Property Size -Sum).Sum
    if($size -gt 2147483648){throw 'Restored source exceeds importer size limit'}
    $drive=Get-PSDrive -Name ([IO.Path]::GetPathRoot($root).Substring(0,1))
    if($drive.Free -lt ($size+536870912)){throw 'Insufficient disk space for restored source'}
    $moves=@();$n=0
    foreach($file in $files){$n++;$ext=if($file.Type -eq 'D'){'mdf'}elseif($file.Type -eq 'L'){'ldf'}else{throw 'Unsupported backup file type'};$logical=$file.LogicalName.Replace("'","''");$target=(Join-Path $root "source_$n.$ext").Replace("'","''");$moves+="MOVE N'$logical' TO N'$target'"}
    if(@(Sql "SELECT name FROM sys.databases WHERE name=N'$dbName'").Count){throw 'Temporary database already exists; recovery review required'}
    Progress 'Buka Sumber'
    Sql "RESTORE DATABASE [$dbName] FROM DISK=N'$backup' WITH FILE=1,$($moves -join ','),RECOVERY"|Out-Null;$created=$true
    Sql "ALTER DATABASE [$dbName] SET TRUSTWORTHY OFF; ALTER DATABASE [$dbName] SET DB_CHAINING OFF"|Out-Null
    if(@(Sql "DBCC CHECKDB ([$dbName]) WITH NO_INFOMSGS,ALL_ERRORMSGS,TABLERESULTS").Count){throw 'Source integrity check failed'}
    Sql "ALTER DATABASE [$dbName] SET READ_ONLY WITH ROLLBACK IMMEDIATE"|Out-Null
    . (Join-Path $PSScriptRoot 'legacy_source_profiles.ps1')
    $sourceProfile=Select-LegacySourceProfile
    if($Category -in @('finance','full')){
        . (Join-Path $PSScriptRoot 'legacy_finance_extract.ps1')
        Export-LegacyFinance
        return
    }
    $columns=@(Sql "SELECT c.name,t.name AS data_type,c.is_computed FROM sys.columns c JOIN sys.types t ON t.user_type_id=c.user_type_id WHERE c.object_id=OBJECT_ID('dbo.siswa','U') ORDER BY c.column_id" $dbName)
    $required=@('NO_INDUK','NAMA','KELAS','SPP_PERBULAN','PANGKAL','BANGUNAN','SERAGAM','KEGIATAN','PANGKAL_BAYAR','BANGUNAN_BAYAR','SERAGAM_BAYAR','KEGIATAN_BAYAR','POMG','DAFTAR_ULANG','NO_induk_diknas','potong_pangkal','tot_pangkal','tot_du','potong_du','id')
    if($sourceProfile.version -eq '2026' -and $Unit -eq 1){$required[0]='no_induk';$required+='NO_INDUK1'}
    if($columns.Count -ne $required.Count -or @($columns|Where-Object is_computed).Count){throw 'Unexpected or computed source columns'}
    foreach($column in $required){if($columns.name -notcontains $column){throw 'Audited student profile does not match'}}
    if(@($columns|Where-Object{$_.data_type -notin @('nvarchar','varchar','int','smallint','bigint','money','decimal','numeric','float','real','bit','datetime','nchar','char','tinyint')}).Count){throw 'Unexpected source column type'}
    $total=[int](@(Sql 'SELECT COUNT(*) AS n FROM dbo.siswa' $dbName)[0].n)
    if($total -gt 200000){throw 'Source row limit exceeded'}
    Progress 'Ekstraksi' 0 $total
    $rows=@(Sql 'SELECT * FROM dbo.siswa ORDER BY NO_INDUK,id' $dbName)
    $output=New-Object IO.StreamWriter((Join-Path $root 'rows.jsonl'),$false,(New-Object Text.UTF8Encoding($false)))
    try{for($i=0;$i -lt $rows.Count;$i++){$output.WriteLine((ConvertTo-Json -InputObject $rows[$i] -Depth 8 -Compress));if(($i%25)-eq 0){Progress 'Ekstraksi' ($i+1) $total}}}finally{$output.Dispose()}
    Progress 'Ekstraksi' $rows.Count $total
    @{database=$h.DatabaseName;date=[string]$h.BackupFinishDate;version=$h.SoftwareVersionMajor;rows=$total;columns=$columns;source_profile=$sourceProfile.id;source_profile_version=$sourceProfile.version;source_schema_signature=$sourceProfile.signature;reader_version=1}|ConvertTo-Json -Depth 8|Set-Content -LiteralPath (Join-Path $root 'source_manifest.json') -Encoding UTF8
}finally{
    if($created){Sql "ALTER DATABASE [$dbName] SET SINGLE_USER WITH ROLLBACK IMMEDIATE; DROP DATABASE [$dbName]"|Out-Null}
}
