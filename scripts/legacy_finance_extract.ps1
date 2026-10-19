# Dot-sourced only after the existing owned restore, CHECKDB, READ_ONLY and unit checks.
function Export-LegacyFinance {
    if($Category -eq 'full'){
        # Select-LegacySourceProfile has already checked the complete catalog.
        $objects=@(Sql "SELECT s.name AS schema_name,o.name,o.type,m.definition,m.execute_as_principal_id FROM sys.objects o JOIN sys.schemas s ON s.schema_id=o.schema_id LEFT JOIN sys.sql_modules m ON m.object_id=o.object_id WHERE o.is_ms_shipped=0 AND o.type IN ('P','V','TR','FN','IF','TF') ORDER BY s.name,o.name" $dbName)
        $objects|ConvertTo-Json -Depth 8|Set-Content -LiteralPath (Join-Path $root 'executable-catalog.json') -Encoding UTF8
        Assert-LegacySourceObjects -Objects $objects -SourceProfile $sourceProfile
        # Audited diagram helpers are metadata only: never invoke or import their definitions.
    }
    $profile=Get-Content -LiteralPath (Join-Path $PSScriptRoot ('legacy_finance_profile'+$sourceProfile.suffix+'.json')) -Raw -Encoding UTF8|ConvertFrom-Json
    $unitName=@{1='SD';2='SMP';3='SMA'}[$Unit]
    if($sourceProfile.version -eq '2026'){$roleCatalog=Get-Content -LiteralPath (Join-Path $PSScriptRoot 'legacy_roles_profile_2026.json') -Raw -Encoding UTF8|ConvertFrom-Json}
    $tables=$profile.$unitName
    $counts=[ordered]@{}
    $target=Join-Path $root 'finance.jsonl'
    $writer=New-Object IO.StreamWriter($target,$false,(New-Object Text.UTF8Encoding($false)))
    $total=0
    try {
        foreach($entry in $tables.PSObject.Properties){
            Check-Cancel
            $table=$entry.Name
            if($table -notmatch '^[a-zA-Z0-9_]+$'){throw 'Invalid profile table'}
            $expected=@($entry.Value)
            $actual=@(Sql "SELECT c.name,t.name AS type,c.max_length AS length,c.precision,c.scale,c.is_computed FROM sys.columns c JOIN sys.types t ON t.user_type_id=c.user_type_id WHERE c.object_id=OBJECT_ID('dbo.$table','U') ORDER BY c.column_id" $dbName)
            if(!$expected.Count -or $actual.Count -ne $expected.Count -or @($actual|Where-Object is_computed).Count){throw "Finance profile does not match: $table"}
            foreach($column in $expected){
                $found=@($actual|Where-Object {$_.name -ceq $column.name})
                if($found.Count -ne 1 -or $found[0].type -cne $column.type -or $found[0].length -ne $column.length -or $found[0].precision -ne $column.precision -or $found[0].scale -ne $column.scale){throw "Finance source type differs: $table"}
            }
            $money=@($expected|Where-Object {$_.type -in @('money','smallmoney','float','real','numeric','decimal') -and $_.name -notin @('id','nomer')}|ForEach-Object {$_.name})
            $reference=$table -notin @('siswa','bayar','bayar_du','tabungan','transaksi_m','transaksi_k')
            $cn=New-Object System.Data.SqlClient.SqlConnection("Server=(localdb)\$instance;Database=$dbName;Integrated Security=true;Connect Timeout=30;Encrypt=false")
            $cn.Open();$read=$null;$count=0
            try {
                $cmd=$cn.CreateCommand();$cmd.CommandTimeout=180
                $cmd.CommandText="SELECT * FROM dbo.[$table]"+$(if($table -eq 'siswa'){' ORDER BY NO_INDUK,id'}else{''})
                $read=$cmd.ExecuteReader()
                while($read.Read()){
                    $raw=[ordered]@{}
                    for($index=0;$index -lt $read.FieldCount;$index++){
                        $v=if($read.IsDBNull($index)){$null}else{$read.GetValue($index)}
                        if($v -is [DateTime]){$v=$v.ToString('yyyy-MM-ddTHH:mm:ss.fffffff',[Globalization.CultureInfo]::InvariantCulture)}
                        elseif($v -is [double] -or $v -is [single]){$v=$v.ToString('R',[Globalization.CultureInfo]::InvariantCulture)}
                        elseif($v -is [decimal]){$v=$v.ToString([Globalization.CultureInfo]::InvariantCulture)}
                        $raw[$read.GetName($index)]=$v
                    }
                    $count++;$total++
                    $record=[ordered]@{table=$table;raw=$raw;money_columns=$money;reference_only=$reference;student_ordinal=$(if($table -eq 'siswa'){$count}else{$null})}
                    if($sourceProfile.version -eq '2026'){$record.source_role=$roleCatalog.$unitName.$table}
                    $writer.WriteLine((ConvertTo-Json -InputObject $record -Depth 12 -Compress))
                    if(($count%500)-eq 0){Progress "Ekstraksi $table" $count 0;Check-Cancel}
                    if($total -gt 500000){throw 'Finance row limit exceeded'}
                }
            }finally{if($read){$read.Dispose()};$cn.Dispose()}
            $independent=[int](@(Sql "SELECT COUNT_BIG(*) AS n FROM dbo.[$table]" $dbName)[0].n)
            if($count -ne $independent){throw 'Incomplete finance extraction'}
            $counts[$table]=$count
        }
    }finally{$writer.Dispose()}
    $hash=(Get-FileHash -LiteralPath (Join-Path $root 'source.dat') -Algorithm SHA256).Hash.ToLowerInvariant()
    @{profile='legacy-finance-v1';source_profile=$sourceProfile.id;source_profile_version=$sourceProfile.version;source_schema_signature=$sourceProfile.signature;reader_version=1;unit=$Unit;backup_hash=$hash;source_date=$h.BackupFinishDate;database=$h.DatabaseName;counts=$counts;total=$total;rows_sha256=(Get-FileHash -LiteralPath $target -Algorithm SHA256).Hash.ToLowerInvariant()}|ConvertTo-Json -Depth 12|Set-Content -LiteralPath (Join-Path $root 'finance_manifest.json') -Encoding UTF8
    Progress 'Ekstraksi Keuangan Selesai' $total $total
}
