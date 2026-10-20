"""Two-column Legacy preview/save/replay using the owned HTTP/database clone."""
import html,json,re,subprocess,sys,urllib.error,urllib.parse,urllib.request
from pathlib import Path
root=Path(sys.argv[1]).resolve();assert root.name=='ui_time_audit_20261021'
c=json.loads((root/'http.json').read_text());assert c['base']=='http://127.0.0.1:58361' and c['database_port']==58360
f=json.loads((root/'time-fixture.json').read_text());checks=[]
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args,**kwargs):return None
opener=urllib.request.build_opener(NoRedirect())
def request(unit,route,values=None):
    data=urllib.parse.urlencode(values).encode() if values is not None else None
    req=urllib.request.Request(c['base']+'/'+route,data=data,headers={'Cookie':'PHPSESSID='+c['sessions'][unit]})
    try:r=opener.open(req,timeout=120)
    except urllib.error.HTTPError as e:r=e
    with r:body=r.read().decode();code=r.status
    assert not re.search(r'Fatal error:|Warning:|Uncaught ',body),body[-500:]
    return code,body
def form(page,marker):
    for _,body in re.findall(r'<form\b([^>]*)>(.*?)</form>',page,re.S|re.I):
        if marker not in body:continue
        values={}
        for tag in re.findall(r'<input\b([^>]*)>',body,re.S|re.I):
            a={k:html.unescape(v) for k,v in re.findall(r'([\w_-]+)="([^"]*)"',tag)}
            if 'name' in a:values[a['name']]=a.get('value','')
        for tag,text in re.findall(r'<textarea\b([^>]*)>(.*?)</textarea>',body,re.S|re.I):
            name=re.search(r'name="([^"]+)"',tag);values[name[1]]=html.unescape(text)
        return values
    raise AssertionError('Form missing '+marker)
def check(ok,label):
    assert ok,label
    checks.append(label);print('PASS',label,flush=True)
def stored(unit,payment_id):
    sql=f'SET @app_unit_id={int(unit)}; SELECT source_date FROM legacy_op_payments WHERE id={-int(payment_id)-1000000000};'
    return subprocess.check_output(['C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe','--host=127.0.0.1','--port=58360','--user=root','--database=db_spp_test2','--batch','--skip-column-names','--execute='+sql],text=True).strip()
for unit in [1,2,3]:
    p=f['units'][str(unit)]['known'];route='pembayaran/edit.php?id='+str(p['id'])
    code,page=request(unit,route);check(code==200 and 'legacy-edit-aside' in page,f'Unit {unit} edit two-column form available')
    values=form(page,'name="date_day"');check(values['date_clock']==p['TGL_BYR'][11:],f'Unit {unit} original archive clock prefills the input')
    values['reason']='Pemeriksaan halaman koreksi waktu pada salinan';values['note']='Pemeriksaan catatan tanpa mengubah waktu'
    code,preview=request(unit,route,values);check(code==200 and 'Konfirmasi Perubahan' in preview and 'Ringkasan perubahan' in preview,f'Unit {unit} note-only edit previews on the same page')
    save=form(preview,'name="preview_key"');check(request(unit,route,save)[0] in [302,303],f'Unit {unit} preview saves through existing workflow')
    check(stored(unit,p['id'])==p['stored_datetime'],f'Unit {unit} note-only HTTP save does not write the recovered archive clock')
    check(request(unit,route,save)[0] in [302,303],f'Unit {unit} repeated save remains idempotent')
    code,page=request(unit,route);check(form(page,'name="date_day"')['date_clock']==p['TGL_BYR'][11:],f'Unit {unit} archive clock survives a note-only save')
    values=form(page,'name="date_day"');values.update(reason='Pemeriksaan perubahan jam eksplisit pada salinan',date_clock='03:21:09')
    code,preview=request(unit,route,values);check(code==200 and '03:21:09 WIB' in preview,f'Unit {unit} explicit clock change is visible before save')
    check(request(unit,route,form(preview,'name="preview_key"'))[0] in [302,303],f'Unit {unit} explicit clock edit saves')
    _,page=request(unit,route);check(form(page,'name="date_day"')['date_clock']=='03:21:09',f'Unit {unit} saved explicit clock takes precedence')
    check(stored(unit,p['id'])==p['TGL_BYR'][:11]+'03:21:09',f'Unit {unit} explicit HTTP clock edit updates the stored time')
    check(request(2 if unit==1 else 1,route)[0] in [403,404],f'Unit {unit} cross-unit edit rejected')
(root/'time-edit-http-results.json').write_text(json.dumps({'checks':len(checks),'passed':checks},indent=2))
