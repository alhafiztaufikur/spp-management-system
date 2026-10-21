"""Read production PDF output and compare rows/amounts to the existing independent model."""
from pathlib import Path
from decimal import Decimal
import datetime,json,re,sys,time,urllib.parse,urllib.request
import fitz
root=Path(sys.argv[1]).resolve();assert root.name.startswith('desktop_refinements_')
config=json.loads((root/'config.json').read_text(encoding='utf-8'));fixture=json.loads((root/'fixture.json').read_text(encoding='utf-8'));models=json.loads((root.parent/'recap_refinements_20261022/export-models.json').read_text(encoding='utf-8'))
def money(value):
    value=Decimal(str(value)).quantize(Decimal('.01'));whole,fraction=f'{value:.2f}'.split('.');return 'Rp '+f'{int(whole):,}'.replace(',','.')+(','+fraction if fraction!='00' else '')
def text(value,column,row):
    if value is None:return ''
    if column['type'] in ['money','money_optional']:return money(value)
    if column['type'] in ['date','datetime']:
        if row.get('time_precision')=='none':return 'Tanggal dan jam tidak tercatat'
        stamp=datetime.datetime.fromisoformat(value);label=stamp.strftime('%d/%m/%Y')
        return label+(' Jam tidak tercatat' if row.get('time_precision')=='date' else stamp.strftime(' %H:%M:%S WIB'))
    return str(value)
results=[]
for name in ['sd','all','undated']:
    model=models[name];query=urllib.parse.urlencode({'jenis_laporan[]':'*','unit':'all' if model['unit']==0 else 'active','tanggal_awal':'2026-10-01','tanggal_akhir':'2026-10-21','urut':'nama','include_undated':'1' if model['undated'] else '0','output':'pdf'})
    sessions=fixture['sessions']['super_admin'];unit=model['unit'];session=sessions[unit] if isinstance(sessions,list) else sessions[str(unit)]
    request=urllib.request.Request('http://127.0.0.1:'+str(config['port'])+'/laporan/export_pdf.php?'+query,headers={'Cookie':'PHPSESSID='+session});started=time.perf_counter()
    with urllib.request.urlopen(request,timeout=180) as response:binary=response.read();assert response.status==200 and binary.startswith(b'%PDF-')
    file=root/'artifacts'/('centered-'+name+'.pdf');file.write_bytes(binary)
    with fitz.open(file) as document:
        content=' '.join(' '.join(page.get_text() for page in document).split());compact=re.sub(r'\s+','',content);cursor=0;count=0
        for section in model['sections']:
            cursor=compact.find(re.sub(r'\s+','',section['title']),cursor);assert cursor>=0,(name,section['title'])
            for row in section['rows']:
                expected=' '.join(' '.join(text(row.get(column['key']),column,row) for column in section['columns']).split())
                # PDF wrapping may break an unspaced class/name; retain every character and its order.
                expected=re.sub(r'\s+','',expected);position=compact.find(expected,cursor);assert position>=0,(name,section['title'],row.get('no'),'row must retain every field and amount');cursor=position+len(expected);count+=1
            for total in section['totals']:assert total['label']+': '+money(total['value']) in content
        if name=='sd':
            page=next(page for page in document if 'Nama Siswa' in page.get_text() and 'Abdul Malik' in page.get_text());page.get_pixmap(matrix=fitz.Matrix(1.5,1.5)).save(root/'artifacts/centered-pdf-detail.png')
        results.append({'document':file.name,'rows_verified':count,'pages':len(document),'ms':round((time.perf_counter()-started)*1000,2)})
    print('PASS',name,count,'complete payment/debt rows, five sections and numeric subtotals',flush=True)
with fitz.open(root/'artifacts/pdf-centering-sample.pdf') as document:
    page=document[0];spans=[span for block in page.get_text('dict')['blocks'] if 'lines' in block for line in block['lines'] for span in line['spans']]
    def span(value):return next(item['bbox'] for item in spans if item['text'].strip()==value)
    def center(box,axis):return (box[axis]+box[axis+2])/2
    for heading,value in [('NIS','00001234'),('Nama Siswa','Nama Uji'),('Kelas','2A'),('Total Bayar','Rp 100.000,13')]:assert abs(center(span(heading),0)-center(span(value),0))<1,(heading,'column/header center')
    date=span('19/10/2026');clock=span('14:30:15 WIB');date_center=(date[1]+clock[3])/2;assert abs(center(span('Nama Uji'),1)-date_center)<1,'Vertical alignment across one/two-line cells'
    page.get_pixmap(matrix=fitz.Matrix(1.5,1.5)).save(root/'artifacts/pdf-centering-sample.png')
(root/'pdf-results.json').write_text(json.dumps({'ok':True,'documents':results,'centering':'Header, identity, amount and multiline vertical centers verified from PDF coordinates'},indent=2),encoding='utf-8')
print('PASS binary PDF header/body horizontal centers and vertical alignment')
