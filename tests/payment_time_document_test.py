"""Independent archive clock comparison plus actual HTML, JSON, PDF and numeric XLSX readback."""
import datetime as dt
import html,io,json,re,sys,time,urllib.parse,urllib.request,zipfile
from pathlib import Path
import xml.etree.ElementTree as ET
import fitz

root=Path(sys.argv[1]).resolve();assert root.name=='ui_time_audit_20261021'
c=json.loads((root/'http.json').read_text());assert c['base']=='http://127.0.0.1:58361'
f=json.loads((root/'time-fixture.json').read_text());results=[]
resolved={(int(r['unit_id']),int(r['id'])):r for r in f['resolved']}
assert len(resolved)==len(f['clocks'])==793
counts={1:0,2:0,3:0}
for raw in f['clocks']:
    key=(int(raw['unit_id']),int(raw['id']));r=resolved[key]
    clock=dt.datetime.strptime(raw['source_clock'].strip(),'%I:%M:%S %p').time()
    day=dt.date.fromisoformat(raw['source_day'][:10]);expected=dt.datetime.combine(day,clock).isoformat(' ')
    assert r['TGL_BYR']==expected and r['time_precision']=='datetime',(raw,r)
    assert raw['stored_date'].endswith('00:00:00'),'Archive padding must remain unchanged'
    counts[key[0]]+=1
assert counts=={1:446,2:286,3:61}
print('PASS independently matched all 793 source clocks by unit and transaction ID',flush=True)

def get(unit,route):
    t=time.perf_counter();req=urllib.request.Request(c['base']+'/'+route,headers={'Cookie':'PHPSESSID='+c['sessions'][unit]})
    with urllib.request.urlopen(req,timeout=180) as response:body=response.read();assert response.status==200
    assert not any(x in body for x in [b'Fatal error:',b'Warning:',b'Uncaught ']),body[-500:]
    results.append({'unit':unit,'route':route,'ms':round((time.perf_counter()-t)*1000),'bytes':len(body)})
    return body

def compact(text):return re.sub(r'\s+','',html.unescape(text)).casefold()
def text_pdf(body,name):
    (root/'artifacts'/name).write_bytes(body)
    with fitz.open(stream=body,filetype='pdf') as doc:return ''.join(page.get_text() for page in doc)

def spreadsheet(body,expected,precision):
    ns={'s':'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
    with zipfile.ZipFile(io.BytesIO(body)) as z:
        styles=ET.fromstring(z.read('xl/styles.xml'));fmts={int(n.attrib['numFmtId']):n.attrib['formatCode'] for n in styles.findall('s:numFmts/s:numFmt',ns)}
        xfs=styles.findall('s:cellXfs/s:xf',ns);numbers=[]
        for name in z.namelist():
            if not re.fullmatch(r'xl/worksheets/sheet\d+.xml',name):continue
            for cell in ET.fromstring(z.read(name)).findall('.//s:sheetData/s:row/s:c',ns):
                if cell.attrib.get('t','n')!='n':continue
                value=cell.findtext('s:v','',ns)
                if not value:continue
                fmt=fmts.get(int(xfs[int(cell.attrib.get('s',0))].attrib.get('numFmtId',0)),'')
                if 'dd/mm/yyyy' in fmt:numbers.append((float(value),fmt))
        value=(dt.datetime.fromisoformat(expected)-dt.datetime(1899,12,30)).total_seconds()/86400
        matches=[fmt for number,fmt in numbers if abs(number-value)<0.000001]
        assert matches,('Missing numeric sortable payment date',expected)
        if precision=='date':assert any('Jam tidak tercatat' in fmt for fmt in matches)
        else:assert any('hh:mm:ss' in fmt and 'WIB' in fmt for fmt in matches)

for unit in [1,2,3]:
    for kind,p in f['units'][str(unit)].items():
        expected=p['TGL_BYR'];clock=expected[11:]+' WIB' if kind=='known' else 'Jam tidak tercatat'
        detail=json.loads(get(unit,'pembayaran/detail.php?id='+str(p['id'])+'&readonly=1'));assert detail['ok'];page=detail['html']
        assert compact(clock) in compact(page)
        assert re.search(r'Dibuat pada</small>\s*<span>Tidak tercatat</span>',page)
        assert 'Diimpor ke SistemSPP' in page
        pdf=get(unit,'laporan/cetak_struk.php?'+urllib.parse.urlencode({'id':p['id'],'output':'pdf'}))
        text=text_pdf(pdf,f'time-{unit}-{kind}-receipt.pdf');assert compact(clock) in compact(text),(unit,kind,text)
        if p['student_id']:
            data=json.loads(get(unit,'pembayaran/history_siswa.php?'+urllib.parse.urlencode({'no_induk':p['NO_INDUK'],'student_id':p['student_id'],'unit_id':unit,'scope':'all'})))
            row=next(row for row in data['rows'] if row['id']==p['id']);assert row['tanggal_raw']==expected and row['time_precision']==p['time_precision']
            assert compact(clock)==compact(row['tanggal_time'])
        if kind=='undated':continue
        params={'tanggal_awal':expected[:10],'tanggal_akhir':expected[:10],'q':p['NO_INDUK'],'unit':'active'}
        query=urllib.parse.urlencode(params)
        page=get(unit,'laporan/index.php?'+query).decode();assert compact(clock) in compact(page)
        for fmt in ['pdf','excel']:
            route='laporan/export_'+fmt+'.php?'+urllib.parse.urlencode(params|({'output':'pdf'} if fmt=='pdf' else {'download':1}))
            body=get(unit,route)
            if fmt=='pdf':assert compact(clock) in compact(text_pdf(body,f'time-{unit}-{kind}-report.pdf'))
            else:
                (root/f'artifacts/time-{unit}-{kind}-report.xlsx').write_bytes(body);spreadsheet(body,expected,p['time_precision'])
        body=get(unit,'pembayaran/export_history.php?'+urllib.parse.urlencode(params|{'format':'xlsx'}));spreadsheet(body,expected,p['time_precision'])
        (root/f'artifacts/time-{unit}-{kind}-history.xlsx').write_bytes(body)
        print('PASS',unit,kind,'detail/history/report/PDF/numeric Excel time agree',flush=True)
(root/'time-document-results.json').write_text(json.dumps({'archive_clocks':793,'per_unit':counts,'requests':results},indent=2))
