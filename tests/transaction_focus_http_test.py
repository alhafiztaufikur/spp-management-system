"""Payment-only routes and real PDF/XLSX readback against independent source cents."""
from pathlib import Path
from decimal import Decimal
import datetime, json, re, sys, time, urllib.error, urllib.parse, urllib.request
from lxml import html
from pypdf import PdfReader
import openpyxl

qa=Path(sys.argv[1]).resolve()
f=json.loads((qa/'fixture.json').read_text()); models=json.loads((qa/'payment-models.json').read_text())
assert f['base']=='http://127.0.0.1:58471' and f['port']==58470
checks=[]; timings=[]
def check(ok,label):
    assert ok,label
    checks.append(label)
def fetch(unit,path,params):
    request=urllib.request.Request(f['base']+'/'+path+'?'+urllib.parse.urlencode(params,doseq=True),headers={'Cookie':'PHPSESSID='+f['sessions'][unit]})
    start=time.perf_counter()
    try: response=urllib.request.urlopen(request,timeout=300)
    except urllib.error.HTTPError as error: response=error
    with response: body=response.read(); status=response.status; headers=dict(response.headers)
    timings.append({'unit':unit,'path':path,'seconds':round(time.perf_counter()-start,3),'bytes':len(body)})
    assert not any(x in body[:20000] for x in [b'Fatal error:',b'Warning:',b'Notice:',b'Uncaught ']),path
    return status,body,headers
def params(unit,profile='dated'):
    return {'unit':'all' if unit==0 else 'active','tanggal_awal':'2090-01-01' if profile=='empty' else '2026-10-01','tanggal_akhir':'2090-01-31' if profile=='empty' else '2026-10-22','include_undated':'1' if profile=='undated' else '0','urut':'terbaru','per_page':'25'}
def cls(name): return "contains(concat(' ',normalize-space(@class),' '),' "+name+" ')"
def money(value): return int(Decimal(str(value))*100)
def nominal(value):
    value=Decimal(str(value)); return 'Rp '+f'{value:,.2f}'.replace(',','@').replace('.',',').replace('@','.').removesuffix(',00')
def workbook(path,section):
    book=openpyxl.load_workbook(path,data_only=True)
    check(book.sheetnames==['Transaksi Pembayaran'],'Exactly one payment worksheet')
    rows=list(book.active.values); columns=section['columns']; cursor=None
    for i,row in enumerate(rows):
        if list(row[:len(columns)])==[c['label'] for c in columns]:cursor=i+1;break
    assert cursor is not None,'Typed payment column headings'
    for expected,actual in zip(section['rows'],rows[cursor:]):
        for col,value in zip(columns,actual):
            stored=expected.get(col['key'],'')
            if col['type'] in ('money','money_optional','number','count'):check(Decimal(str(value))==Decimal(str(stored)),'Excel numeric cents and row number')
            elif col['type']=='datetime':
                if expected.get('time_precision')=='none':check(value=='Tanggal dan jam tidak tercatat','Unknown time remains unknown in Excel')
                elif stored:check(isinstance(value,datetime.datetime) and value.strftime('%Y-%m-%d')==str(stored)[:10],'Excel actual source date')
            else:check(str(value or '')==('\u2014' if stored is None else str(stored or '')),'Excel identity, class, method, period and component snapshot')
    total_rows=[r for r in rows if r[0]=='Total Pembayaran']
    check(len(total_rows)==1,'One payment total')
    check(money(next(v for v in total_rows[0][1:] if isinstance(v,(float,int))))==money(section['totals'][0]['value']),'Excel total equals independent source cents')

check(json.loads(fetch(1,'tests/browser_clone_identity.php',{})[1])['database']==f['database'],'HTTP server uses isolated clone')
for unit in ([] if '--all-periods-only' in sys.argv else [1,2,3,0]):
 for profile in ['dated','undated','empty']:
    model=models[str(unit)][profile]; query=params(unit,profile)
    status,body,_=fetch(unit,'laporan/index.php',query); tree=html.fromstring(body)
    check(status==200,'Report page accessible in each unit')
    check(len(tree.xpath('//*['+cls('report-transactions-table')+']'))==1 and not tree.xpath('//*['+cls('report-unpaid-table')+' or @name="jenis_laporan"]'),'Only payment detail table and no report type control')
    check(len(tree.xpath('//*[@data-finance-tab]'))==3 and len(tree.xpath('//*['+cls('finance-components')+']'))==1,'Desktop presentation tabs and component summary restored')
    check(len(tree.xpath('//*['+cls('finance-stat')+']'))==4,'Four summary cards retained')
    check('Hasil Laporan: Semua Transaksi' in tree.text_content(),'Fixed payment report heading')
    actual=tree.xpath('//*['+cls('report-transactions-table')+']//*['+cls('row-print-check')+']')
    check([int(node.get('value')) for node in actual]==[int(r['id']) for r in model['rows'][:25]],'First page IDs and order match full model')
    check(nominal(Decimal(model['cents'])/100) in tree.xpath('//*['+cls('finance-stat')+' and '+cls('is-payment')+']')[0].text_content(),'Payment summary equals independent source cents')
    for link in tree.xpath('//*['+cls('du-pagination')+']//a | //a[contains(@href,"export_")]'):
        query_keys=urllib.parse.parse_qs(urllib.parse.urlsplit(link.get('href')).query)
        check(not any(k.startswith('jenis_laporan') for k in query_keys),'New links omit obsolete report types')
    if profile=='empty' and unit:continue
    for endpoint,ext in [('export_excel.php','xlsx'),('export_pdf.php','pdf')]:
        export=dict(query,download='1',output='pdf',**{'jenis_laporan[]':['*','belum_spp'],'panel':'komponen'})
        status,data,headers=fetch(unit,'laporan/'+endpoint,export)
        check(status==200 and data.startswith(b'PK' if ext=='xlsx' else b'%PDF-'),'General export is a real payment document')
        path=qa/'artifacts'/f'payments-{unit}-{profile}.{ext}';path.write_bytes(data)
        if ext=='xlsx':workbook(path,model['export'])
        else:
            check(int(headers['X-Report-Row-Count'])==model['count'],'PDF row count includes all filtered payments')
            document=PdfReader(path); text=' '.join(page.extract_text() for page in document.pages)
            check(all(page.mediabox.width>page.mediabox.height for page in document.pages),'Payment PDF landscape pages')
            flat=' '.join(text.split()); compact=''.join(text.split())
            check('Transaksi Pembayaran' in flat and 'SPP belum lunas' not in flat and 'Transaksi Tabungan' not in flat,'PDF contains only payment report tables')
            check(nominal(Decimal(model['cents'])/100).replace(' ','') in compact,'PDF payment total equals independent source cents')
            for row in model['rows']:check(str(row['NO_INDUK']) in text,'PDF includes all filtered payment identities')
            if profile=='undated' and model['count']:check('termasuk transaksi tanpa tanggal sesuai periode kewajiban' in flat,'PDF preserves undated disclosure')
    print('PASS',unit,profile,model['count'],'payments',flush=True)
 for old in ['belum_spp',['*'],['semua','belum_du']]:
    query=dict(params(unit),panel='ringkasan',urut='sisa_terbesar');query['jenis_laporan[]' if isinstance(old,list) else 'jenis_laporan']=old
    status,body,_=fetch(unit,'laporan/index.php',query);tree=html.fromstring(body)
    check(status==200 and len(tree.xpath('//*[@data-report-section="semua"]'))==1 and len(tree.xpath('//*[@data-report-section]'))==1,'Old scalar/array URLs remain payment-only')
    check(tree.xpath('//select[@name="urut"]/option[@selected]')[0].get('value')=='terbaru','Old remainder sort resets to latest')
 for section in ['belum_spp','belum_komite','belum_du','belum_biaya_lain','sudah_bayar']:
    status,data,_=fetch(unit,'laporan/index.php',dict(params(unit),fragment='section',section=section))
    check(status==400 and json.loads(data)['ok'] is False,'Non-payment fragments rejected')
 status,data,_=fetch(unit,'laporan/index.php',dict(params(unit),fragment='section',section='semua',**{'section_page[semua]':'2'}))
 fragment=json.loads(data);check(status==200 and fragment['ok'] and fragment['section']=='semua','Payment AJAX fragment available')
 rows=html.fromstring(fragment['html']).xpath('//*['+cls('row-print-check')+']')
 check([int(r.get('value')) for r in rows]==[int(r['id']) for r in models[str(unit)]['dated']['rows'][25:50]],'Second page stable IDs and order')
 for sort in ['nama','kelas','nominal_terbesar','nominal_terkecil']:
    status,body,_=fetch(unit,'laporan/index.php',dict(params(unit),urut=sort));tree=html.fromstring(body)
    check(status==200 and len(tree.xpath('//*['+cls('report-transactions-table')+']'))==1,'Payment sort remains available')
    if sort.startswith('nominal_'):
        amounts=[Decimal(re.sub(r'[^0-9,-]','',n.text_content()).replace(',','.')) for n in tree.xpath('//*['+cls('report-transactions-table')+']//tbody//*['+cls('nominal')+']')]
        check(amounts==sorted(amounts,reverse=sort=='nominal_terbesar'),'Amount ordering applied')
 # Student ID is scoped even though native fixture NIS repeats in three units.
 if unit:
    own=f['native'][str(unit)];status,body,_=fetch(unit,'laporan/index.php',dict(params(unit),student_id=own['student_id']))
    check(len(html.fromstring(body).xpath('//*['+cls('row-print-check')+']'))==2,'Student filter retains own two native payments')
    foreign=f['native'][str(unit%3+1)];status,body,_=fetch(unit,'laporan/index.php',dict(params(unit),student_id=foreign['student_id']))
    check(not html.fromstring(body).xpath('//*['+cls('row-print-check')+']'),'Other-unit student ID cannot leak payments')

# All-period exports are bounded by a real student/search filter, spanning October and November.
for unit in [1,2,3,0]:
    own=f['native']['1' if unit==0 else str(unit)]
    query=dict(params(unit),all_periods='1',student_id=own['student_id'] if unit else 0,q=own['nis'])
    count=3 if unit else 9; total=count*4400169
    status,body,_=fetch(unit,'laporan/index.php',query);tree=html.fromstring(body)
    check(len(tree.xpath('//*['+cls('row-print-check')+']'))==count,'All periods includes November after October native payments')
    for card in ['is-in','is-out']:
        check(tree.xpath('//*['+cls('finance-stat')+' and '+cls(card)+']//strong')[0].text_content()=='Rp 0','Savings summary follows the selected student/search filter')
    for endpoint,ext in [('export_excel.php','xlsx'),('export_pdf.php','pdf')]:
        status,data,headers=fetch(unit,'laporan/'+endpoint,dict(query,download='1',output='pdf'))
        path=qa/'artifacts'/f'all-periods-{unit}.{ext}';path.write_bytes(data)
        if ext=='pdf':
            check(int(headers['X-Report-Row-Count'])==count,'All-period PDF includes every filtered payment')
            text=' '.join(p.extract_text() for p in PdfReader(path).pages)
            check(nominal(Decimal(total)/100).replace(' ','') in ''.join(text.split()),'All-period PDF total uses all filtered payments')
        else:
            book=openpyxl.load_workbook(path,data_only=True);check(book.sheetnames==['Transaksi Pembayaran'],'All-period Excel stays payment-only')
            rows=list(book.active.values);last=next(r for r in rows if r[0]=='Total Pembayaran')
            check(money(next(v for v in last[1:] if isinstance(v,(int,float))))==total,'All-period Excel total includes November')

# Cetak Dipilih continues to use the existing receipt flow.
own=f['native']['1']; query=dict(params(1),mode='selected',output='pdf',**{'ids[]':[own['ids'][0]]})
status,data,_=fetch(1,'laporan/export_pdf.php',query);check(status==200 and data.startswith(b'%PDF-'),'Selected payment receipt remains printable')
path=qa/'artifacts'/'selected-receipt.pdf';path.write_bytes(data)
text=' '.join(page.extract_text() for page in PdfReader(path).pages)
check('QA Fokus Transaksi 1' in text and 'Total Pembayaran' not in text,'Selected export stays a receipt, general PDF stays a report')
(qa/('http-all-periods-result.json' if '--all-periods-only' in sys.argv else 'http-result.json')).write_text(json.dumps({'checks':len(checks),'timings':timings},indent=2))
print('OK:',len(checks),'HTTP and document readback checks',flush=True)
