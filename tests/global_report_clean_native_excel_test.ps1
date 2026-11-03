# Verify the generated artifacts in an isolated, invisible Excel instance.
$ErrorActionPreference='Stop'
$qaOutput=(Resolve-Path -LiteralPath $env:SPP_GLOBAL_PRINT_ARTIFACTS).Path
if($qaOutput -notmatch '\\backups\\spp-management-system\\pagination_desktop_' -or -not (Test-Path -LiteralPath (Join-Path $qaOutput 'checksums-before.json'))){throw 'Verified private QA artifacts required'}
$excelApp=$null;$qaBook=$null;$ownedInstance=$false;$results=@()
try {
    $excelApp=New-Object -ComObject Excel.Application
    if($excelApp.Workbooks.Count -ne 0){throw 'Excel instance is not empty'}
    $ownedInstance=$true;$excelApp.Visible=$false;$excelApp.DisplayAlerts=$false;$excelApp.AskToUpdateLinks=$false;$excelApp.AutomationSecurity=3;$excelApp.MapPaperSize=$false
    foreach($name in @('status','penerimaan','spp-tahunan','per-item','tabungan-siswa','saldo-tabungan','setoran','kas-tabungan','per-item-multi','status-all-units','spp-empty')) {
        $source=Join-Path $qaOutput ('final/'+$name+'.xlsx');$beforeHash=(Get-FileHash -LiteralPath $source).Hash
        $qaBook=$excelApp.Workbooks.Open($source,0,$true);$qaSheet=$qaBook.Worksheets.Item(1)
        if(-not $qaBook.ReadOnly -or $qaSheet.PageSetup.PaperSize -ne 9){throw ('Expected read-only A4: '+$name)}
        if($qaSheet.PageSetup.Zoom -ne $false -or $qaSheet.PageSetup.FitToPagesWide -ne 1 -or $qaSheet.PageSetup.FitToPagesTall -ne 0){throw ('Expected one horizontal page and automatic height: '+$name)}
        if($qaSheet.PageSetup.PrintTitleColumns -ne ''){throw ('Unexpected repeated column bands: '+$name)}
        $pdf=Join-Path $qaOutput ('final/'+$name+'-excel.pdf');$qaBook.ExportAsFixedFormat(0,$pdf)
        $results+=@{report=$name;readOnly=$true;A4=$true;fitWidth=1;fitHeight=0;repeatRows=$qaSheet.PageSetup.PrintTitleRows;pdf=$pdf}
        $qaBook.Close($false);$qaBook=$null
        if((Get-FileHash -LiteralPath $source).Hash -ne $beforeHash){throw ('Workbook modified: '+$source)}
        Write-Output ('PASS native Excel '+$name)
    }
    $results|ConvertTo-Json -Depth 5|Set-Content -LiteralPath (Join-Path $qaOutput 'native-excel-results.json') -Encoding UTF8
} finally {
    if($qaBook){$qaBook.Close($false)}
    if($excelApp -and $ownedInstance){$excelApp.Quit()}
    if($excelApp){[void][System.Runtime.InteropServices.Marshal]::FinalReleaseComObject($excelApp)}
    [GC]::Collect();[GC]::WaitForPendingFinalizers()
}
