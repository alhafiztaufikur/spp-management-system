"""Read existing report PDFs/XLSX back against private source-model fixtures."""
import io,json,re,sys,time,urllib.request,urllib.parse,zipfile
from decimal import Decimal,InvalidOperation
from pathlib import Path
import xml.etree.ElementTree as ET
import fitz

root=Path(sys.argv[1]).resolve();assert any(name in str(root) for name in ["import_audit_20261020","ui_time_audit_20261021"])
config=json.loads((root/'http.json').read_text());assert config['base']=='http://127.0.0.1:58361'
fixture=json.loads((root/'export-fixture.json').read_text(encoding='utf-8-sig'))
only_letters='--only-letters' in sys.argv[2:]
previous=json.loads((root/'document-results.json').read_text()) if only_letters else {}
results=previous.get('requests',[]);failures=previous.get('failures',[])
def request(unit,route):
    t=time.perf_counter();req=urllib.request.Request(config['base']+'/'+route,headers={'Cookie':'PHPSESSID='+config['sessions'][unit]})
    with urllib.request.urlopen(req,timeout=240) as response:
        body=response.read();assert response.status==200
        results.append({'unit':unit,'route':route,'ms':round((time.perf_counter()-t)*1000),'bytes':len(body),'timing':response.headers.get('Server-Timing')})
    assert not any(e in body for e in [b'Fatal error:',b'Warning:',b'Uncaught ']),body[-1000:]
    return body
def xlsx(data):
    with zipfile.ZipFile(io.BytesIO(data)) as z:
        ns={'s':'http://schemas.openxmlformats.org/spreadsheetml/2006/main'};strings=[];values=[];numbers=[]
        if 'xl/sharedStrings.xml' in z.namelist():strings=[''.join(s.itertext()) for s in ET.fromstring(z.read('xl/sharedStrings.xml')).findall('s:si',ns)]
        for name in z.namelist():
            if not re.fullmatch(r'xl/worksheets/sheet\d+.xml',name):continue
            for cell in ET.fromstring(z.read(name)).findall('.//s:sheetData/s:row/s:c',ns):
                value=cell.findtext('s:v','',ns);kind=cell.attrib.get('t','n')
                if kind=='s':value=strings[int(value)]
                elif kind=='inlineStr':value=''.join(cell.find('s:is',ns).itertext())
                elif kind=='n' and value:
                    try:numbers.append(Decimal(value).quantize(Decimal('.01')))
                    except InvalidOperation:pass
                values.append(value)
        return values,numbers
def compact(value):return re.sub(r'\s+','',value).casefold()
def pdf(data):
    with fitz.open(stream=data,filetype='pdf') as doc:
        assert len(doc)>0;return '\n'.join(page.get_text() for page in doc),len(doc)
def check(label,callback):
    try:
        callback();failures[:]=[f for f in failures if f['case']!=label];print('PASS',label,flush=True)
    except Exception as error:failures.append({'case':label,'error':str(error)});print('FAIL',label,str(error)[:350],flush=True)
    (root/'document-results.json').write_text(json.dumps({'requests':results,'failures':failures},indent=2))

for unit in [1,2,3,0]:
    for template,model in ({} if only_letters else fixture[str(unit)]['reports']).items():
        for fmt in ['excel','pdf']:
            def verify(u=unit,t=template,m=model,f=fmt):
                route='laporan/export_global.php?'+urllib.parse.urlencode(m['query']|{'format':f,'download':1})
                data=request(u,route);(root/f'artifacts/report-{u}-{t}.{ "xlsx" if f=="excel" else "pdf"}').write_bytes(data)
                if f=='excel':
                    values,numbers=xlsx(data);text=compact(' '.join(values))
                    for total in m['totals']:
                        if Decimal(total['value'])!=0:assert Decimal(total['value']) in numbers,('Missing exact Excel total',total)
                else:
                    text,pages=pdf(data);text=compact(text);results[-1]['pages']=pages
                    for total in m['totals']:
                        if Decimal(total['value'])!=0:assert compact(total['formatted']) in text,('Missing PDF total',total)
                for name in m['names']:assert compact(name) in text,('Missing student',name)
            check(f'Unit {unit} {template} {fmt}',verify)
    student=fixture[str(unit)]['student']
    def parent(u=unit,s=fixture[str(unit)]['parent_student']):
        page=request(u,'laporan/surat_orang_tua_susun.php?'+urllib.parse.urlencode({'mode':'single','student_key':s['key'],'siswa_status':'all'})).decode()
        token=re.search(r'"token":"([a-f0-9]{48})"',page);assert token,page[:500]
        data=request(u,'laporan/surat_orang_tua_pdf.php?draft='+token[1]);text,pages=pdf(data);assert compact(s['name']) in compact(text)
        (root/f'artifacts/parent-letter-{u}.pdf').write_bytes(data)
    check(f'Unit {unit} parent letter PDF',parent)
    if only_letters:continue
    for fmt in ['excel','pdf']:
        def general(u=unit,f=fmt,s=student):
            params={'tanggal_awal':'2022-01-01','tanggal_akhir':'2026-12-31','q':s['nis'],'unit':'all' if u==0 else 'active'}
            route='laporan/'+('export_excel.php?' if f=='excel' else 'export_pdf.php?')+urllib.parse.urlencode(params|({'download':1} if f=='excel' else {'output':'pdf'}))
            data=request(u,route);(root/f'artifacts/general-{u}.{ "xlsx" if f=="excel" else "pdf"}').write_bytes(data)
            if f=='excel':values,numbers=xlsx(data);text=' '.join(values)
            else:text,pages=pdf(data)
            assert compact(s['name']) in compact(text),'Historical student missing from general report export'
        check(f'Unit {unit} general {fmt}',general)

def selected():
    page=request(1,'laporan/index.php?tanggal_awal=2022-01-01&tanggal_akhir=2026-12-31').decode()
    ids=re.findall(r'name="ids\[\]"[^>]*value="(-\d+)"',page)[:2];assert len(ids)==2
    query=urllib.parse.urlencode({'mode':'selected','output':'pdf','tanggal_awal':'2022-01-01','tanggal_akhir':'2026-12-31','ids[]':ids},doseq=True)
    data=request(1,'laporan/export_pdf.php?'+query);text,pages=pdf(data);assert pages>=2
    (root/'artifacts/general-selected-legacy.pdf').write_bytes(data)
if not only_letters:check('Selected Legacy receipts preserve existing receipt layout',selected)
print(json.dumps({'requests':len(results),'failures':failures},indent=2),flush=True)
sys.exit(bool(failures))
