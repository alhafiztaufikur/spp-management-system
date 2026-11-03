$ErrorActionPreference='Stop'
$qaOut=(Resolve-Path -LiteralPath $env:SPP_DESKTOP_FOUR_QA).Path
if($qaOut -notmatch '\\backups\\spp-management-system\\pagination_desktop_' -or -not (Test-Path -LiteralPath (Join-Path $qaOut 'checksums-before.json'))){throw 'Verified private QA artifacts required'}
$qaExcel=$null;$qaBook=$null;$owned=$false;$results=@()
try {
    $qaExcel=New-Object -ComObject Excel.Application
    if($qaExcel.Workbooks.Count -ne 0){throw 'Excel instance is not empty'}
    $owned=$true;$qaExcel.Visible=$false;$qaExcel.DisplayAlerts=$false;$qaExcel.AskToUpdateLinks=$false;$qaExcel.AutomationSecurity=3;$qaExcel.MapPaperSize=$false
    foreach($name in @('billing','receipts','receipts-large','receipts-all-units','receipts-empty','billing-empty')){
        $source=Join-Path $qaOut ('final/'+$name+'.xlsx');$hash=(Get-FileHash -LiteralPath $source).Hash
        $qaBook=$qaExcel.Workbooks.Open($source,0,$true);$sheet=$qaBook.Worksheets.Item(1)
        if(-not $qaBook.ReadOnly -or $sheet.PageSetup.PaperSize -ne 9 -or $sheet.PageSetup.FitToPagesWide -ne 1 -or $sheet.PageSetup.FitToPagesTall -ne 0){throw ('Invalid read-only/A4/fit settings: '+$name)}
        if($name.StartsWith('billing')){
            if($sheet.Range('A6').Value2 -or $sheet.Range('A7').Value2 -or $sheet.Rows.Item(6).RowHeight -ne 0 -or $sheet.Rows.Item(7).RowHeight -ne 0){throw 'Billing top metadata remains'}
            if($name -eq 'billing' -and $sheet.Range('E10').Value2 -ne 'D.ULANG'){throw 'Billing header differs'}
        }else{
            if($sheet.Range('A6').Value2 -or -not $sheet.Range('A7').Value2 -or $sheet.Range('A3').Font.Size -ne 10){throw 'Receipt context/address/creation metadata differs'}
        }
        $qaBook.ExportAsFixedFormat(0,(Join-Path $qaOut ('final/'+$name+'-excel.pdf')))
        $results+=@{report=$name;A4=$true;readOnly=$true;repeatRows=$sheet.PageSetup.PrintTitleRows;fitWidth=1;fitHeight=0}
        $qaBook.Close($false);$qaBook=$null
        if((Get-FileHash -LiteralPath $source).Hash -ne $hash){throw ('Input workbook modified: '+$name)}
        Write-Output ('PASS native Excel '+$name)
    }
    $results|ConvertTo-Json|Set-Content -LiteralPath (Join-Path $qaOut 'native-excel-results.json') -Encoding UTF8
}finally{
    if($qaBook){$qaBook.Close($false)}
    if($qaExcel -and $owned){$qaExcel.Quit()}
    if($qaExcel){[void][System.Runtime.InteropServices.Marshal]::FinalReleaseComObject($qaExcel)}
    [GC]::Collect();[GC]::WaitForPendingFinalizers()
}
