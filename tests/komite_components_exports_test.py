"""Read actual HTML/PDF/XLSX exports from the owned clone; no third-party Python packages."""
from pathlib import Path
import json, os, re, subprocess, sys, urllib.request, urllib.parse, zipfile, xml.etree.ElementTree as ET

qa=Path(os.environ['SPP_QA_DIR']); f=json.loads((qa/'fixture.json').read_text())
assert os.environ.get('SPP_TEST_ALLOW_MUTATION')=='1' and f['port']!=3306 and f['database']==os.environ['SPP_DB_NAME']
checks=0
def check(ok,label):
    global checks
    assert ok,label
    checks+=1
def fetch(route,params):
    req=urllib.request.Request(f['base']+'/'+route+'?'+urllib.parse.urlencode(params),headers={'Cookie':'PHPSESSID='+f['sessions'][1]})
    with urllib.request.urlopen(req,timeout=300) as response:
        data=response.read(); check(response.status==200,route+' status')
    check(not any(x in data[:30000] for x in [b'Fatal error:',b'Warning:',b'Notice:']),route+' PHP output')
    return data
def pdf_text(data,name):
    check(data.startswith(b'%PDF-'),name+' PDF header'); pdf=qa/'artifacts'/name; pdf.write_bytes(data)
    result=subprocess.run([os.environ['SPP_PDFTOTEXT'],'-enc','UTF-8','-layout',str(pdf),'-'],capture_output=True,check=True)
    return result.stdout.decode('utf-8')
params={'tanggal_awal':'2026-10-01','tanggal_akhir':'2026-10-22','unit':'active','urut':'terbaru'}
preview=fetch('laporan/export_pdf.php',params|{'output':'html'}).decode()
check('Komite' in preview and 'U_SORGA' not in preview,'HTML export Komite name')
check('156.508.200' in preview,'HTML export total')
pdf=pdf_text(fetch('laporan/export_pdf.php',params|{'output':'pdf'}),'komite-transactions.pdf')
check('Komite' in pdf and 'U_SORGA' not in pdf,'PDF export Komite name'); check('156.508.200' in pdf,'PDF total')
xlsx=fetch('laporan/export_excel.php',params|{'download':'1'}); book=qa/'artifacts'/'komite-transactions.xlsx';book.write_bytes(xlsx)
ns={'x':'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
with zipfile.ZipFile(book) as z:
    strings=[]
    if 'xl/sharedStrings.xml' in z.namelist():
        strings=[''.join(n.itertext()) for n in ET.fromstring(z.read('xl/sharedStrings.xml')).findall('x:si',ns)]
    values=[]
    for name in z.namelist():
        if not re.fullmatch(r'xl/worksheets/sheet\d+\.xml',name): continue
        for cell in ET.fromstring(z.read(name)).findall('.//x:c',ns):
            value=cell.find('x:v',ns)
            if cell.attrib.get('t')=='s' and value is not None: values.append(strings[int(value.text)])
            elif cell.attrib.get('t')=='inlineStr': values.append(''.join(cell.find('x:is',ns).itertext()))
            elif value is not None: values.append(value.text)
check(any('Komite:' in v for v in values) and not any('U_SORGA' in v for v in values),'Excel detail Komite name')
check(any(v in ['156508200','156508200.0','156508200.00'] for v in values),'Excel numeric total')
receipt_html=fetch('laporan/cetak_struk.php',{'id':f['sample_id'],'unit_id':1}).decode()
check('Komite' in receipt_html and 'U_SORGA' not in receipt_html and '545.000' in receipt_html,'Receipt HTML name and total')
receipt=pdf_text(fetch('laporan/cetak_struk.php',{'id':f['sample_id'],'unit_id':1,'output':'pdf'}),'komite-receipt.pdf')
check('Komite' in receipt and 'U_SORGA' not in receipt and '545.000' in receipt,'Receipt PDF name and total')
g={'template':'penerimaan','unit':'1','tanggal_awal':'2026-10-01','tanggal_akhir':'2026-10-22','kategori':'komite','siswa_status':'all','format':'preview'}
global_preview=fetch('laporan/export_global.php',g).decode();check('Komite' in global_preview and 'U_SORGA' not in global_preview,'Global Komite cash filter')
(qa/'export-result.json').write_text(json.dumps({'checks':checks},indent=2))
print(f'PASS: {checks} real HTML, PDF text, Excel numeric and receipt/global cash checks.')
