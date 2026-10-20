"""Native payment/savings HTTP lifecycle on the owned clone only."""
from decimal import Decimal
import html
import json
from pathlib import Path
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

root=Path(sys.argv[1]).resolve()
assert any(name in str(root) for name in ["import_audit_20261020","ui_time_audit_20261021"])
config=json.loads((root/'http.json').read_text());assert config['base']=='http://127.0.0.1:58361'
assert config.get('database_port')==58360, 'Mutation HTTP server must use the owned isolated database'
fixtures=json.loads((root/'mutations.json').read_text())['fixture'];checks=[]

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args,**kwargs): return None

opener=urllib.request.build_opener(NoRedirect())

def request(unit,route,values=None):
    session=config['sessions'][str(unit)] if isinstance(config['sessions'],dict) else config['sessions'][unit]
    req=urllib.request.Request(config['base']+'/'+route,data=urllib.parse.urlencode(values).encode() if values is not None else None,headers={'Cookie':'PHPSESSID='+session})
    try:response=opener.open(req,timeout=120)
    except urllib.error.HTTPError as error:response=error
    with response:body=response.read();code=response.status;headers=dict(response.headers)
    assert not any(x in body for x in [b'Fatal error:',b'Warning:',b'Uncaught ']),body[-500:]
    return code,body,headers

def form(unit,route,marker,action=None):
    code,body,_=request(unit,route);assert code==200,(route,code)
    for attrs,content in re.findall(r'<form\b([^>]*)>(.*?)</form>',body.decode(),re.S|re.I):
        if marker not in content or (action and action not in attrs):continue
        values={}
        for tag in re.findall(r'<input\b([^>]*)>',content,re.S|re.I):
            fields={k:html.unescape(v) for k,v in re.findall(r'([\w_-]+)="([^"]*)"',tag)}
            if fields.get('type') not in ['checkbox','radio','submit','button'] and 'name' in fields:values[fields['name']]=fields.get('value','')
        return values
    raise AssertionError('Form missing: '+route)

def check(ok,label):
    assert ok,label
    checks.append(label);print('PASS',label,flush=True)

def history(unit,fixture):
    code,body,_=request(unit,'pembayaran/history_siswa.php?'+urllib.parse.urlencode({'no_induk':fixture['nis'],'student_id':fixture['student_id'],'unit_id':unit,'scope':'all'}))
    check(code==200,'History JSON available');data=json.loads(body);assert data['ok'];return data['rows']

for unit in [1,2,3]:
    f=fixtures[str(unit)]
    values=form(unit,'pembayaran/form.php','name="no_induk"')
    values.update(aksi='input',payment_plan='monthly',no_induk=f['nis'],student_id=f['student_id'],bulan_bayar=f['month'],tahun_bayar=f['year'],sistem_pembayaran='Tunai',uang_spp=f['spp'].replace('.',','),uang_komite=f['komite'].replace('.',','),uang_psb='123,45',spp_action='bayar',_operational_unit=str(unit))
    code,_,headers=request(unit,'pembayaran/proses.php',values);check(code in [302,303],f'Unit {unit} native payment POST redirects')
    rows=history(unit,f);check(len(rows)==1,f'Unit {unit} native payment created')
    expected=Decimal(f['spp'])+Decimal(f['komite'])+Decimal('123.45');check(Decimal(str(rows[0]['total_raw']))==expected,f'Unit {unit} native payment exact total')
    request(unit,'pembayaran/proses.php',values);check(len(history(unit,f))==1,f'Unit {unit} native payment replay applies once')
    pid=rows[0]['id'];edit=form(unit,'pembayaran/edit.php?id='+str(pid),'name="no_induk"')
    check(rows[0].get('time_precision')=='datetime' and bool(rows[0].get('tanggal_time')),f'Unit {unit} native creation records server time')
    explicit=dict(values);explicit.update(edit);explicit.update(aksi='update',id=str(pid),tanggal_bayar=rows[0]['tanggal_raw'][:10]+' 08:12:34',_operational_unit=str(unit))
    request(unit,'pembayaran/proses.php',explicit);rows=history(unit,f)
    check(rows[0]['tanggal_raw'].endswith('08:12:34'),f'Unit {unit} explicit native clock change recorded')
    edit=form(unit,'pembayaran/edit.php?id='+str(pid),'name="no_induk"')
    update=dict(values);update.update(edit);update.update(aksi='update',id=str(pid),uang_psb='124,45',_operational_unit=str(unit))
    request(unit,'pembayaran/proses.php',update);rows=history(unit,f);check(Decimal(str(rows[0]['total_raw']))==expected+1,f'Unit {unit} native edit exact total')
    check(rows[0]['tanggal_raw'].endswith('08:12:34'),f'Unit {unit} date-only native edit preserves original clock')
    remove={'aksi':'hapus','id':str(pid),'csrf_token':edit['csrf_token'],'_operational_unit':str(unit)}
    request(unit,'pembayaran/proses.php',remove);check(not history(unit,f),f'Unit {unit} native cancellation removes active payment')
    def balance():
        code,data,_=request(unit,'tabungan/get_saldo.php?student_id='+str(f['student_id']));assert code==200
        return Decimal(str(json.loads(data)['raw_saldo'])).quantize(Decimal('0.01'))
    baseline=balance()
    deposit=form(unit,'tabungan/masuk.php?student_id='+str(f['student_id']),'name="request_key"','proses.php');deposit.update(nominal='123.13',keterangan='Audit HTTP terisolasi',_operational_unit=str(unit))
    code,_,headers=request(unit,'tabungan/proses.php',deposit);check(code in [302,303],f'Unit {unit} deposit accepted');check(balance()==baseline+Decimal('123.13'),f'Unit {unit} deposit updates exact balance')
    request(unit,'tabungan/proses.php',deposit);check(balance()==baseline+Decimal('123.13'),f'Unit {unit} savings replay applies once')
    saved=urllib.parse.parse_qs(urllib.parse.urlparse(headers['Location']).query);jid=saved['id'][0]
    correction=form(unit,'tabungan/riwayat.php?student_id='+str(f['student_id'])+'&jenis=masuk&id='+jid,'value="direct_edit"','koreksi.php');correction.update(nominal='124.13',keterangan='Audit HTTP koreksi',reason='Koreksi pada salinan pengujian',_operational_unit=str(unit))
    request(unit,'tabungan/koreksi.php',correction);check(balance()==baseline+Decimal('124.13'),f'Unit {unit} native savings correction updates balance')
    withdrawal=form(unit,'tabungan/keluar.php?student_id='+str(f['student_id']),'name="request_key"','proses.php');withdrawal.update(nominal='999999.00',keterangan='Uji saldo tidak cukup',_operational_unit=str(unit));request(unit,'tabungan/proses.php',withdrawal);check(balance()==baseline+Decimal('124.13'),f'Unit {unit} excessive withdrawal leaves balance unchanged')
    withdrawal=form(unit,'tabungan/keluar.php?student_id='+str(f['student_id']),'name="request_key"','proses.php');withdrawal.update(nominal='124.13',keterangan='Audit HTTP penarikan',_operational_unit=str(unit));request(unit,'tabungan/proses.php',withdrawal);check(balance()==baseline,f'Unit {unit} withdrawal reconciles balance')

(root/'mutation-http-results.json').write_text(json.dumps({'checks':len(checks),'passed':checks},indent=2))
