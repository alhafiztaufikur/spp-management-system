"""HTML/PDF share one typed model; benchmark cold/warm generation separately."""
from pathlib import Path
from decimal import Decimal
from lxml import html
import json, statistics, sys, time, urllib.parse, urllib.request

qa=Path(sys.argv[1]).resolve();f=json.loads((qa/'fixture.json').read_text());models=json.loads((qa/'payment-models.json').read_text())
assert f['base']=='http://127.0.0.1:58471' and f['port']==58470
checks=0;timings=[]
def check(ok,label):
    global checks
    assert ok,label
    checks+=1
def fetch(unit,query):
    start=time.perf_counter();request=urllib.request.Request(f['base']+'/laporan/export_pdf.php?'+urllib.parse.urlencode(query,doseq=True),headers={'Cookie':'PHPSESSID='+f['sessions'][unit]})
    with urllib.request.urlopen(request,timeout=300) as response:body=response.read();headers=response.headers
    assert b'Fatal error' not in body[:20000]
    timings.append({'unit':unit,'output':query.get('output'),'seconds':round(time.perf_counter()-start,3),'rows':headers.get('X-Report-Row-Count'),'cache':headers.get('X-Report-Cache'),'server_timing':headers.get('Server-Timing')})
    return body,headers
for unit in [1,2,3,0]:
 for profile in ['dated','undated','empty']:
    query={'unit':'all' if unit==0 else 'active','tanggal_awal':'2090-01-01' if profile=='empty' else '2026-10-01','tanggal_akhir':'2090-01-31' if profile=='empty' else '2026-10-22','include_undated':'1' if profile=='undated' else '0','output':'html','panel':'komponen','jenis_laporan[]':['*','belum_spp']}
    body,headers=fetch(unit,query);page=html.fromstring(body);frame=page.xpath('//*[@data-preview-frame]')[0];document=html.fromstring(frame.get('srcdoc'));model=models[str(unit)][profile]
    check(len(page.xpath('//*[@data-transaction-preview]'))==1 and not page.xpath('//*[@data-native-pdf]'),'Explicit HTML mode bypasses native PDF viewer')
    check(headers.get('X-Report-Model')==page.xpath('//body')[0].get('data-model'),'Preview carries the server source fingerprint')
    check(int(headers['X-Report-Row-Count'])==model['count'],'HTML includes all filtered payments regardless of presentation')
    table=document.xpath('//table')[0];rows=table.xpath('.//tbody/tr[not(@class="total")]')
    if model['count']:
        check(len(rows)==model['count'],'HTML detail count matches independently reconciled rows')
        for row,expected in zip(rows,model['export']['rows']):
            check(str(expected['NO_INDUK']) in row.text_content(),'HTML preserves immutable payment identity/order')
            amount=f'{Decimal(str(expected["total_jumlah"])):,.2f}'.replace(',','@').replace('.',',').replace('@','.').removesuffix(',00')
            check(amount in row.text_content(),'HTML preserves source cents')
            check(row.xpath('./td[last()]')[0].text_content().strip()==expected['komponen'],'Export component detail remains identical to reconciled PDF/XLSX baseline')
    check('Transaksi Tabungan' not in document.text_content() and 'belum lunas' not in document.text_content(),'Preview remains payment only')
    for link in page.xpath('//*[@data-preview-download]'):
        params=urllib.parse.parse_qs(urllib.parse.urlsplit(link.get('href')).query)
        check(params['output']==['pdf'] and 'jenis_laporan[]' not in params,'HTML actions link to the real PDF, without report selectors')
    if unit==1 and profile=='dated':(qa/'artifacts/html-preview-response.html').write_bytes(body)
# A date range not previously rendered makes this first request a genuine cold artifact.
query={'tanggal_awal':'2026-10-01','tanggal_akhir':'2026-10-21','output':'html','qa_run':str(time.time_ns())}
html_times=[]
for _ in range(3):body,h=fetch(1,query);html_times.append(timings[-1]['seconds'])
pdf,h=fetch(1,dict(query,output='inline'));cold=timings[-1];check(pdf.startswith(b'%PDF-') and h['X-Report-Model'] and h['X-Report-Cache']=='MISS','Cold PDF is a newly rendered server file with source fingerprint');(qa/'artifacts/benchmark-240.pdf').write_bytes(pdf)
warm=[]
for panel in ['komponen','transaksi','ringkasan']:
    again,a=fetch(1,dict(query,output='pdf',panel=panel));warm.append(timings[-1]['seconds']);check(a['X-Report-Cache']=='HIT' and again==pdf,'Presentation does not split cache or alter PDF bytes')
check(statistics.median(html_times)<cold['seconds'],'Table preview appears before cold PDF generation finishes')
(qa/'preview-http-result.json').write_text(json.dumps({'checks':checks,'timings':timings,'benchmark':{'html_median_seconds':statistics.median(html_times),'pdf_first':cold,'pdf_warm_median_seconds':statistics.median(warm)}},indent=2))
print('OK:',checks,'HTML preview checks; HTML median',statistics.median(html_times),'s; PDF cold',cold['seconds'],'s; warm',statistics.median(warm),'s')
