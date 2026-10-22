"""Read exact identifiers and intact row numbers from real small/large payment PDFs."""
from pathlib import Path
from decimal import Decimal
from pypdf import PdfReader
import json, os, re, subprocess, sys, urllib.request

qa=Path(sys.argv[1]).resolve();fixture=json.loads((qa/'fixture.json').read_text())
assert fixture['base']=='http://127.0.0.1:58471' and fixture['database'].startswith('db_spp_audit_')
models=json.loads((qa/'payment-models.json').read_text());results=[]
reader=os.environ['SPP_PDFTOTEXT'];renderer=os.environ.get('SPP_PDFTOPPM')
with urllib.request.urlopen(fixture['base']+'/tests/browser_clone_identity.php') as response:
    assert json.loads(response.read())['database']==fixture['database']
for unit,profile in [(0,'undated'),(3,'dated')]:
    model=models[str(unit)][profile];pdf=qa/f'artifacts/payments-{unit}-{profile}.pdf'
    if '--existing' not in sys.argv:
        query='unit='+('all' if unit==0 else 'active')+'&tanggal_awal=2026-10-01&tanggal_akhir=2026-10-22&include_undated='+('1' if profile=='undated' else '0')+'&output=pdf'
        request=urllib.request.Request(fixture['base']+'/laporan/export_pdf.php?'+query,headers={'Cookie':'PHPSESSID='+fixture['sessions'][unit]})
        with urllib.request.urlopen(request,timeout=300) as response:
            assert int(response.headers['X-Report-Row-Count'])==model['count'];pdf.write_bytes(response.read())
    text=subprocess.check_output([reader,'-enc','UTF-8','-layout',str(pdf),'-'],text=True,encoding='utf-8')
    # A broken 1061 -> 106 / 1 is no longer an intact number in the extracted layout.
    assert all(re.search(r'(?<!\d)'+str(n)+r'(?!\d)',text) for n in range(1,model['count']+1))
    assert all(str(row['NO_INDUK']) in text for row in model['rows']),'Payment identifiers must remain intact'
    amount=f'{Decimal(model["cents"])/100:,.2f}'.replace(',','@').replace('.',',').replace('@','.')
    assert amount in ''.join(text.split()),'Total must equal independent source cents'
    count=len(PdfReader(pdf).pages)
    if renderer:
        for page in ([1,(count+1)//2,count] if unit==0 else range(1,count+1)):
            subprocess.run([renderer,'-f',str(page),'-l',str(page),'-scale-to','1400','-singlefile','-png',str(pdf),str(qa/f'artifacts/review-final-{unit}-{page}')],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    results.append({'unit':unit,'rows':model['count'],'pages':count,'all_numbers_intact':True,'all_identities_present':True,'source_total_equal':True})
    print('PASS PDF layout:',unit,model['count'],'payment numbers/identities and total',flush=True)
(qa/'pdf-final-layout.json').write_text(json.dumps(results))
