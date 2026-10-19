param([Parameter(Mandatory=$true)][string]$PrivateDirectory)
$ErrorActionPreference='Stop'
$root=[IO.Path]::GetFullPath($PrivateDirectory)
if(!$root.StartsWith('C:\laragon\backups\spp-management-system\legacy_latest_qa_',[StringComparison]::OrdinalIgnoreCase)){throw 'Use the owned private QA directory'}
[void][IO.Directory]::CreateDirectory($root)
. (Join-Path $PSScriptRoot '..\scripts\legacy_source_profiles.ps1')
function Sql([string]$Query,[string]$Database){return $script:sourceColumns}
$dbName='owned-test'
$cases=0
foreach($version in @('2019','2026')){foreach($Unit in @(1,2,3)){
    $label=@{1='SD';2='SMP';3='SMA'}[$Unit];$suffix=if($version -eq '2019'){''}else{'_2026'}
    $definition=Get-Content -LiteralPath (Join-Path $PSScriptRoot ('..\scripts\legacy_full_profile'+$suffix+'.json')) -Raw -Encoding UTF8|ConvertFrom-Json
    $script:sourceColumns=@($definition.$label|ForEach-Object{[pscustomobject]@{schema_name=$_.schema;table_name=$_.table;column_name=$_.column;data_type=$_.type;max_length=$_.length;precision=$_.precision;scale=$_.scale;is_nullable=$_.nullable;is_computed=$false}})
    $profile=Select-LegacySourceProfile
    if($profile.id -cne ('legacy-'+$version+'-'+$label.ToLowerInvariant())){throw 'Wrong profile chosen'};$cases++
    $script:sourceColumns[0].max_length++
    $rejected=$false;try{[void](Select-LegacySourceProfile)}catch{if($_.Exception.Message -eq 'Struktur backup belum didukung'){$rejected=$true}else{throw}}
    if(!$rejected){throw 'Changed type definition accepted'};$cases++
    $script:sourceColumns[0].max_length--;$script:sourceColumns[0].is_computed=$true
    $rejected=$false;try{[void](Select-LegacySourceProfile)}catch{if($_.Exception.Message -eq 'Struktur backup belum didukung'){$rejected=$true}else{throw}}
    if(!$rejected){throw 'Computed column accepted'};$cases++
}}
$jobs=Get-Content -LiteralPath (Join-Path (Split-Path -Parent $root) 'latest-source-jobs.json') -Raw -Encoding UTF8|ConvertFrom-Json
$job=$jobs|Where-Object {$_.version -eq '2026' -and $_.mode -eq 'full' -and $_.unit -eq 2}|Select-Object -First 1
$objects=Get-Content -LiteralPath (Join-Path (Split-Path -Parent $root) ('importer\db_spp\'+$job.id+'\executable-catalog.json')) -Raw -Encoding UTF8|ConvertFrom-Json
$Unit=2;Assert-LegacySourceObjects -Objects $objects -SourceProfile @{version='2026'};$cases++
Assert-LegacySourceObjects -Objects @() -SourceProfile @{version='2019'};$cases++
$objects[0].definition+=' -- changed'
$rejected=$false;try{Assert-LegacySourceObjects -Objects $objects -SourceProfile @{version='2026'}}catch{$rejected=$true}
if(!$rejected){throw 'Changed executable definition accepted'};$cases++
$rejected=$false;try{Assert-LegacySourceObjects -Objects $objects -SourceProfile @{version='2019'}}catch{$rejected=$true}
if(!$rejected){throw 'Executable object accepted on old profile'};$cases++
Write-Output "PASS: $cases source profile/type/computed-column/executable-definition cases; no SQL Server/database writes."
