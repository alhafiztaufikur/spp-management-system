# Pure Windows identity/task-ownership tests. Never execute installer setup,
# register a task, connect to MySQL, or change files/permissions outside the test.
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$installer = Join-Path (Split-Path -Parent $PSScriptRoot) 'scripts\install_database_backup.ps1'
$tokens = $null; $parseErrors = $null
$ast = [Management.Automation.Language.Parser]::ParseFile($installer, [ref]$tokens, [ref]$parseErrors)
if ($parseErrors.Count) { throw 'Installer PowerShell syntax is invalid.' }
foreach ($name in @('Resolve-BackupIdentitySid', 'Test-BackupTaskOwnership', 'Test-BackupTaskSchedule')) {
    $functionAst = $ast.Find({ param($node) $node -is [Management.Automation.Language.FunctionDefinitionAst] -and $node.Name -eq $name }, $true)
    if (!$functionAst) { throw ('Installer helper missing: ' + $name) }
    Invoke-Expression $functionAst.Extent.Text
}
$script:checks = 0
function Assert-IdentityCase([bool]$Condition, [string]$Message) {
    if (!$Condition) { throw $Message }
    $script:checks++
}
$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$ownerSid = $identity.User.Value
$fullName = $identity.Name
$shortName = ($fullName -split '\\')[-1]
$foreignSid = if ($ownerSid -eq 'S-1-5-18') { 'S-1-5-19' } else { 'S-1-5-18' }
$foreignName = ([Security.Principal.SecurityIdentifier]::new($foreignSid)).Translate([Security.Principal.NTAccount]).Value
$fakeName = 'SPP_nonexistent_' + [Guid]::NewGuid().ToString('N')
Assert-IdentityCase ((Resolve-BackupIdentitySid $fullName) -eq $ownerSid) 'Full Windows account must resolve to current owner SID.'
Assert-IdentityCase ((Resolve-BackupIdentitySid $shortName) -eq $ownerSid) 'Local short account must resolve to the same owner SID.'
Assert-IdentityCase ((Resolve-BackupIdentitySid $ownerSid) -eq $ownerSid) 'SID principal must remain canonical.'
Assert-IdentityCase ((Resolve-BackupIdentitySid $foreignName) -eq $foreignSid) 'Foreign account must resolve to its own SID.'
Assert-IdentityCase ((Resolve-BackupIdentitySid $foreignSid) -ne $ownerSid) 'Foreign SID must never equal the owner.'
foreach ($invalid in @('', ' ', $fakeName, 'S-1-broken', 'S-1-5-' + ('9' * 100))) {
    Assert-IdentityCase ($null -eq (Resolve-BackupIdentitySid $invalid)) ('Invalid identity must not resolve: ' + $invalid)
}
$expectedConfig = 'C:\private\spp\config.json'
$expectedExecutable = 'C:\php\php.exe'
$expectedArguments = '"C:\app\scripts\database_backup_runner.php" "--config=C:\private\spp\config.json" --tick'
$manifest = [pscustomobject]@{config_file=$expectedConfig;owner_sid=$ownerSid}
function New-TaskFixture([string]$Principal) {
    return [pscustomobject]@{Principal=[pscustomobject]@{UserId=$Principal};Actions=@([pscustomobject]@{Execute=$expectedExecutable;Arguments=$expectedArguments})}
}
function Test-Fixture($Task, $Manifest = $manifest) {
    return Test-BackupTaskOwnership $Task $Manifest $expectedConfig $ownerSid $expectedExecutable $expectedArguments
}
foreach ($principal in @($fullName, $shortName, $ownerSid)) {
    Assert-IdentityCase (Test-Fixture (New-TaskFixture $principal)) ('Owned existing task rejected for principal: ' + $principal)
}
foreach ($principal in @($foreignName, $foreignSid, $fakeName, '', 'S-1-broken')) {
    Assert-IdentityCase (!(Test-Fixture (New-TaskFixture $principal))) ('Unowned existing task accepted for principal: ' + $principal)
}
Assert-IdentityCase (!(Test-Fixture (New-TaskFixture $fullName) $null)) 'Missing ownership manifest must be rejected.'
Assert-IdentityCase (!(Test-Fixture (New-TaskFixture $fullName) ([pscustomobject]@{config_file='C:\foreign\config.json';owner_sid=$ownerSid}))) 'Different config path must be rejected.'
Assert-IdentityCase (!(Test-Fixture (New-TaskFixture $fullName) ([pscustomobject]@{config_file=$expectedConfig;owner_sid=$foreignSid}))) 'Different manifest owner must be rejected.'
$task = New-TaskFixture $shortName; $task.Actions[0].Execute = 'C:\foreign\php.exe'
Assert-IdentityCase (!(Test-Fixture $task)) 'Different executable must be rejected.'
$task = New-TaskFixture $shortName; $task.Actions[0].Arguments += ' --foreign'
Assert-IdentityCase (!(Test-Fixture $task)) 'Different action arguments must be rejected.'
$task = New-TaskFixture $shortName; $task.Actions += [pscustomobject]@{Execute=$expectedExecutable;Arguments=$expectedArguments}
Assert-IdentityCase (!(Test-Fixture $task)) 'Additional task actions must be rejected.'
Assert-IdentityCase (!(Test-Fixture ([pscustomobject]@{Principal=[pscustomobject]@{UserId=$ownerSid};Actions=@()}))) 'Missing task action must be rejected.'
Assert-IdentityCase (!(Test-Fixture ([pscustomobject]@{}))) 'Malformed task must be rejected.'
function New-ScheduleFixture {
    return [pscustomobject]@{
        Actions=@([pscustomobject]@{WorkingDirectory='C:\app'})
        Principal=[pscustomobject]@{LogonType='S4U';RunLevel=0}
        Settings=[pscustomobject]@{Enabled=$true;StartWhenAvailable=$true;MultipleInstances=2;WakeToRun=$true;AllowDemandStart=$true;ExecutionTimeLimit='PT6H';DisallowStartIfOnBatteries=$false;StopIfGoingOnBatteries=$false;AllowHardTerminate=$false}
        Triggers=@(
            [pscustomobject]@{CimClass=[pscustomobject]@{CimClassName='MSFT_TaskDailyTrigger'};Enabled=$true;DaysInterval=1;StartBoundary='2026-10-13T18:00:00+07:00';EndBoundary=$null},
            [pscustomobject]@{CimClass=[pscustomobject]@{CimClassName='MSFT_TaskTimeTrigger'};Enabled=$true;Repetition=[pscustomobject]@{Interval='PT1M'};EndBoundary=$null},
            [pscustomobject]@{CimClass=[pscustomobject]@{CimClassName='MSFT_TaskBootTrigger'};Enabled=$true;Delay='PT1M';EndBoundary=$null}
        )
    }
}
Assert-IdentityCase (Test-BackupTaskSchedule (New-ScheduleFixture) 'C:\app') 'Matching installed task must be reusable without registration.'
$task=New-ScheduleFixture; $task.Triggers[0].StartBoundary='2026-10-13T18:00:00+00:00'
Assert-IdentityCase (!(Test-BackupTaskSchedule $task 'C:\app')) 'A different timezone must require repair.'
$task=New-ScheduleFixture; $task.Triggers[1].Enabled=$false
Assert-IdentityCase (!(Test-BackupTaskSchedule $task 'C:\app')) 'A disabled queue trigger must require repair.'
$task=New-ScheduleFixture; $task.Triggers=$task.Triggers[0..1]
Assert-IdentityCase (!(Test-BackupTaskSchedule $task 'C:\app')) 'Missing startup recovery must require repair.'
$task=New-ScheduleFixture; $task.Settings.MultipleInstances=0
Assert-IdentityCase (!(Test-BackupTaskSchedule $task 'C:\app')) 'Concurrent task executions must require repair.'
$task=New-ScheduleFixture; $task.Principal.LogonType='InteractiveToken'
Assert-IdentityCase (!(Test-BackupTaskSchedule $task 'C:\app')) 'A login-dependent task must require repair.'
$task=New-ScheduleFixture; $task.Triggers[0].EndBoundary='2026-10-14T18:00:00+07:00'
Assert-IdentityCase (!(Test-BackupTaskSchedule $task 'C:\app')) 'An expiring daily trigger must require repair.'
Write-Output ('PASS: ' + $script:checks + ' identity, ownership and reusable schedule checks; no scheduler/database operations.')
