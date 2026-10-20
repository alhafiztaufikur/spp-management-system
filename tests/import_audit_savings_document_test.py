"""Full student/non-student books and transaction slips, using real archived ledgers."""
from collections import Counter
import io,json,re,sys,time,urllib.parse,urllib.request
from pathlib import Path
import fitz
root=Path(sys.argv[1]).resolve();assert any(name in str(root) for name in ["import_audit_20261020","ui_time_audit_20261021"])
c=json.loads((root/'http.json').read_text());assert c['base']=='http://127.0.0.1:58361'
fixture=json.loads((root/'books-fixture.json').read_text());results=[]
def get(unit,route):
    t=time.perf_counter();request=urllib.request.Request(c['base']+'/'+route,headers={'Cookie':'PHPSESSID='+c['sessions'][unit]})
    with urllib.request.urlopen(request,timeout=120) as response:data=response.read();assert response.status==200
    results.append({'unit':unit,'route':route,'ms':round((time.perf_counter()-t)*1000),'bytes':len(data)})
    with fitz.open(stream=data,filetype='pdf') as doc:
        words=Counter(word[4] for page in doc for word in page.get_text('words'))
        text=' '.join(page.get_text() for page in doc);results[-1]['pages']=len(doc)
    return data,words,text
for model in fixture:
    unit,kind=model['unit'],model['type']
    route='tabungan/cetak_buku.php?'+urllib.parse.urlencode(model['query']|{'output':'pdf'})
    data,words,text=get(unit,route);expected=Counter()
    for row in model['entries']:
        for value in row.values():
            if value:expected[value]+=1
    assert all(words[value]>=count for value,count in expected.items()),('Book omitted a source cell',unit,kind,expected-words)
    assert sum(count for word,count in words.items() if re.fullmatch(r'\d{2}/\d{2}/\d{4}',word))==len(model['entries'])
    (root/f'artifacts/savings-book-{unit}-{kind}.pdf').write_bytes(data)
    data,words,text=get(unit,'tabungan/cetak_struk.php?'+urllib.parse.urlencode(model['receipt']|{'output':'pdf'}))
    assert re.sub(r'\s+','',model['name']).casefold() in re.sub(r'\s+','',text).casefold()
    (root/f'artifacts/savings-receipt-{unit}-{kind}.pdf').write_bytes(data)
    print('PASS unit',unit,kind,'complete book',len(model['entries']),'entries and receipt',flush=True)
(root/'savings-documents.json').write_text(json.dumps({'requests':results,'passed':12},indent=2))
