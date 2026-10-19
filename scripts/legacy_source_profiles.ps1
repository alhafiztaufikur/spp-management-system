# Only audited source definitions are eligible. The filename/date never chooses a profile.
function Select-LegacySourceProfile {
    $unitName=@{1='SD';2='SMP';3='SMA'}[$Unit]
    $actual=@(Sql "SELECT sc.name AS schema_name,t.name AS table_name,c.name AS column_name,ty.name AS data_type,c.max_length,c.precision,c.scale,c.is_nullable,c.is_computed FROM sys.tables t JOIN sys.schemas sc ON sc.schema_id=t.schema_id JOIN sys.columns c ON c.object_id=t.object_id JOIN sys.types ty ON ty.user_type_id=c.user_type_id WHERE t.is_ms_shipped=0 OR (sc.name='dbo' AND t.name='dtproperties')" $dbName)
    $actual|ConvertTo-Json -Depth 8|Set-Content -LiteralPath (Join-Path $root 'full-catalog.json') -Encoding UTF8
    foreach($version in @('2019','2026')){
        $suffix=if($version -eq '2019'){''}else{'_2026'}
        $file=Join-Path $PSScriptRoot ('legacy_full_profile'+$suffix+'.json')
        $catalog=Get-Content -LiteralPath $file -Raw -Encoding UTF8|ConvertFrom-Json
        $expected=@($catalog.$unitName)
        if($actual.Count -ne $expected.Count -or @($actual|Where-Object is_computed).Count){continue}
        $index=New-Object 'System.Collections.Generic.Dictionary[string,object]' ([StringComparer]::Ordinal)
        foreach($c in $actual){$index.Add(($c.schema_name+'|'+$c.table_name+'|'+$c.column_name),$c)}
        $matches=$true
        foreach($c in $expected){
            $key=$c.schema+'|'+$c.table+'|'+$c.column
            if(!$index.ContainsKey($key)){$matches=$false;break}
            $a=$index[$key]
            if($a.data_type -cne $c.type -or $a.max_length -ne $c.length -or $a.precision -ne $c.precision -or $a.scale -ne $c.scale -or $a.is_nullable -ne $c.nullable){$matches=$false;break}
        }
        if(!$matches){continue}
        if($version -eq '2026'){
            $roleCatalog=Get-Content -LiteralPath (Join-Path $PSScriptRoot 'legacy_roles_profile_2026.json') -Raw -Encoding UTF8|ConvertFrom-Json
            $roles=$roleCatalog.$unitName
            foreach($table in @($actual|Select-Object -ExpandProperty table_name -Unique)){
                if($roles.PSObject.Properties.Name -cnotcontains $table -or $roles.$table -notin @('primary','reference','savings_review','metadata')){throw 'Unclassified audited source table'}
            }
        }
        $bytes=[Text.Encoding]::UTF8.GetBytes((Get-FileHash -LiteralPath $file -Algorithm SHA256).Hash.ToLowerInvariant()+'|'+$unitName)
        $signature=([Security.Cryptography.SHA256]::Create().ComputeHash($bytes)|ForEach-Object{$_.ToString('x2')}) -join ''
        return @{id=('legacy-'+$version+'-'+$unitName.ToLowerInvariant());version=$version;signature=$signature;suffix=$suffix}
    }
    @{code='source_schema_unsupported';message='Struktur backup belum didukung. Gunakan hasil pemeriksaan sumber untuk menyesuaikan profil pembaca.'}|ConvertTo-Json|Set-Content -LiteralPath (Join-Path $root 'source-error.json') -Encoding UTF8
    throw 'Struktur backup belum didukung'
}

function Assert-LegacySourceObjects {
    param([object[]]$Objects,[hashtable]$SourceProfile)
        $expectedObjects=@()
        if($sourceProfile.version -eq '2026'){$objectProfiles=Get-Content -LiteralPath (Join-Path $PSScriptRoot 'legacy_objects_profile_2026.json') -Raw -Encoding UTF8|ConvertFrom-Json;$expectedObjects=@($objectProfiles.(@{1='SD';2='SMP';3='SMA'}[$Unit]))}
        $objectsMatch=$objects.Count -eq $expectedObjects.Count
        foreach($expectedObject in $expectedObjects){
            $found=@($objects|Where-Object {$_.schema_name -ceq $expectedObject.schema -and $_.name -ceq $expectedObject.name -and $_.type -ceq $expectedObject.type})
            if($found.Count -ne 1 -or !$found[0].definition -or $found[0].execute_as_principal_id -ne $expectedObject.execute_as_principal_id){$objectsMatch=$false;break}
            $definitionHash=([Security.Cryptography.SHA256]::Create().ComputeHash([Text.Encoding]::UTF8.GetBytes($found[0].definition))|ForEach-Object{$_.ToString('x2')}) -join ''
            if($definitionHash -cne $expectedObject.definition_sha256){$objectsMatch=$false;break}
        }
        if(!$objectsMatch){@{code='source_schema_unsupported'}|ConvertTo-Json|Set-Content -LiteralPath (Join-Path $root 'source-error.json') -Encoding UTF8;throw 'Source executable objects require a new audit'}
}
