# Open generated copies read-only in an owned, invisible Excel instance.
$ErrorActionPreference='Stop'
$qaOutput=(Resolve-Path -LiteralPath $env:SPP_UI_OUTPUT).Path
if($qaOutput -notlike 'C:\laragon\backups\spp-management-system\*' -or -not(Test-Path -LiteralPath (Join-Path $qaOutput 'model-reconciliation.json'))){throw 'Private artifacts and successful clone reconciliation required'}
$excelApp=$null;$qaBook=$null;$owned=$false;$results=@()
try{
 $excelApp=New-Object -ComObject Excel.Application
 if($excelApp.Workbooks.Count -ne 0){throw 'Expected an empty owned Excel instance'}
 $owned=$true;$excelApp.Visible=$false;$excelApp.DisplayAlerts=$false;$excelApp.AskToUpdateLinks=$false;$excelApp.AutomationSecurity=3;$excelApp.MapPaperSize=$false
 foreach($name in @('riwayat-tagihan','penerimaan','spp-tahunan','per-item','status','tabungan-siswa','saldo-tabungan')){
  $source=Join-Path $qaOutput ($name+'.xlsx');$before=(Get-FileHash -LiteralPath $source).Hash
  $qaBook=$excelApp.Workbooks.Open($source,0,$true);$sheet=$qaBook.Worksheets.Item(1)
  if(-not $qaBook.ReadOnly -or $sheet.PageSetup.PaperSize -ne 9){throw "Expected read-only A4: $name"}
  if($sheet.PageSetup.FitToPagesWide -ne 1){throw "Expected one horizontal page: $name"}
  $pdf=Join-Path $qaOutput ($name+'-excel.pdf');$qaBook.ExportAsFixedFormat(0,$pdf)
  $results+=@{report=$name;readOnly=$true;A4=$true;repeatRows=$sheet.PageSetup.PrintTitleRows;pdf=$pdf}
  $qaBook.Close($false);$qaBook=$null
  if((Get-FileHash -LiteralPath $source).Hash -ne $before){throw "Workbook modified: $name"}
  Write-Output "PASS native Excel $name"
 }
 $results|ConvertTo-Json -Depth 5|Set-Content -LiteralPath (Join-Path $qaOutput 'native-excel-results.json') -Encoding UTF8
}finally{
 if($qaBook){$qaBook.Close($false)}
 if($excelApp -and $owned){$excelApp.Quit()}
 if($excelApp){[void][Runtime.InteropServices.Marshal]::FinalReleaseComObject($excelApp)}
 [GC]::Collect();[GC]::WaitForPendingFinalizers()
}
