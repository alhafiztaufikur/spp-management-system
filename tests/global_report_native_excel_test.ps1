# Open generated audit artifacts read-only in Microsoft Excel; never save input files.
$ErrorActionPreference='Stop'
if($env:SPP_DB_NAME -notmatch '^db_spp_audit_[a-z0-9_]+$'){throw 'Audit clone required'}
$qaDir=(Resolve-Path -LiteralPath $env:SPP_QA_DIR).Path
$names=@('status','penerimaan','spp-tahunan','per-item','tabungan-siswa','saldo-tabungan','riwayat-tagihan','setoran','kas-tabungan','status-multi')
$excelApp=$null
$qaBook=$null
$count=0
try {
    $excelApp=New-Object -ComObject Excel.Application
    $excelApp.Visible=$false
    $excelApp.DisplayAlerts=$false
    $excelApp.AskToUpdateLinks=$false
    $excelApp.AutomationSecurity=3
    foreach($unit in @('1','2','3','0')) {
        foreach($name in $names) {
            $source=Join-Path $qaDir ('endpoint-'+$unit+'-'+$name+'.xlsx')
            $qaBook=$excelApp.Workbooks.Open($source,0,$true)
            $qaSheet=$qaBook.Worksheets.Item(1)
            if($qaSheet.PageSetup.PaperSize -ne 9){throw ('Expected A4: '+$source)}
            if($qaSheet.PageSetup.Orientation -notin @(1,2)){throw 'Invalid orientation'}
            $qaSheet.Activate()
            if($name -notin @('setoran','kas-tabungan')) {
                if(-not $excelApp.ActiveWindow.FreezePanes){throw ('Missing frozen identity/header: '+$source)}
                if(-not $qaSheet.PageSetup.PrintTitleRows -or -not $qaSheet.PageSetup.PrintTitleColumns){throw ('Missing repeated print identity/header: '+$source)}
                $freezeColumn=[int]$excelApp.ActiveWindow.SplitColumn
                foreach($cell in @('A3','A5','A6','A7','B1','C1')) {
                    $area=$qaSheet.Range($cell).MergeArea
                    if($area.Column -le $freezeColumn -and $area.Column+$area.Columns.Count-1 -gt $freezeColumn){throw ('Heading crosses frozen columns: '+$source)}
                }
            }
            $excelApp.ActiveWindow.ScrollRow=1
            $excelApp.ActiveWindow.ScrollColumn=1
            $last=[Math]::Min(13,$qaSheet.UsedRange.Columns.Count)
            $rows=[Math]::Min(20,$qaSheet.UsedRange.Rows.Count)
            $qaRange=$qaSheet.Range($qaSheet.Cells.Item(1,1),$qaSheet.Cells.Item($rows,$last))
            $qaRange.CopyPicture(1,2)
            $qaChart=$qaSheet.ChartObjects().Add(0,0,$qaRange.Width,$qaRange.Height)
            $qaChart.Activate()
            $qaChart.Chart.Paste()
            $output=Join-Path $qaDir ('excel-screen-'+$unit+'-'+$name+'.png')
            if(-not $qaChart.Chart.Export($output,'PNG')){throw 'Excel image export failed'}
            $qaBook.Close($false)
            $qaBook=$null
            $count++
        }
    }
    $result='PASS: '+$count+' read-only Excel workbook opens and worksheet renders, nine templates/four scopes and grouped status, A4, freeze, repeat identities/headers and compact heading boundaries'
    $result | Set-Content -LiteralPath (Join-Path $qaDir 'native-excel-result.txt') -Encoding UTF8
    Write-Output $result
} finally {
    if($qaBook){$qaBook.Close($false)}
    if($excelApp){$excelApp.Quit();[void][System.Runtime.InteropServices.Marshal]::FinalReleaseComObject($excelApp)}
    [GC]::Collect()
    [GC]::WaitForPendingFinalizers()
}
