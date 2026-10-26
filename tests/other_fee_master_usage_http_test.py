"""Existing master -> history -> receipt/export; writes only to a verified isolated clone."""
import argparse, http.cookiejar, html, io, json, os, re, subprocess, urllib.error, urllib.parse, urllib.request, zipfile
import xml.etree.ElementTree as ET
from html.parser import HTMLParser
from pathlib import Path

class Forms(HTMLParser):
    def __init__(self):
        super().__init__(); self.forms=[]; self.current=None
    def handle_starttag(self,tag,attrs):
        a=dict(attrs)
        if tag=='form': self.current={'action':a.get('action',''),'fields':{}}; self.forms.append(self.current)
        elif tag=='input' and self.current is not None and a.get('name'): self.current['fields'][a['name']]=a.get('value','')
    def handle_endtag(self,tag):
        if tag=='form': self.current=None

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args,**kwargs): return None

def main():
    a=argparse.ArgumentParser(); a.add_argument('--base',required=True); a.add_argument('--php',required=True); a.add_argument('--output',required=True); args=a.parse_args()
    assert os.environ.get('SPP_TEST_ALLOW_MUTATION')=='1' and os.environ.get('SPP_TEST_ISOLATED_INSTANCE') and os.environ.get('SPP_DB_PORT')!='3306'
    assert urllib.parse.urlparse(args.base).hostname in ('127.0.0.1','localhost')
    checks=[]; opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),NoRedirect())
    def req(path,data=None):
        r=urllib.request.Request(args.base.rstrip('/')+'/'+path,data=None if data is None else urllib.parse.urlencode(data).encode())
        try: response=opener.open(r,timeout=180)
        except urllib.error.HTTPError as e: response=e
        body=response.read(); return response.status,response.headers,body
    def check(ok,name):
        if not ok: raise AssertionError(name)
        checks.append(name)
    def page(path):
        status,_,body=req(path); check(status==200 and b'Fatal error:' not in body,path); return body.decode('utf-8')
    def switch(unit):
        parser=Forms();parser.feed(page('master_biaya_lain.php')); f=next(f for f in parser.forms if 'unit_switch.php' in f['action']); f['fields'].update(unit_id=str(unit),next='/master_biaya_lain.php'); check(req('unit_switch.php',f['fields'])[0] in (302,303),'switch '+str(unit))
    identity=json.loads(page('tests/browser_clone_identity.php')); manifest=json.loads(Path(os.environ['SPP_TEST_ISOLATED_INSTANCE']).read_text(encoding='utf-8-sig'))
    check(identity['server_uuid']==manifest['server_uuid'] and int(identity['port'])==int(manifest['port']) and identity['server_uuid']!=manifest['source_server_uuid'],'owned isolated HTTP target')
    check(req('login.php',{'username':'superadmin','password':os.environ['SPP_TEST_SUPERADMIN_PASSWORD']})[0]==302,'login')
    switch(1); active=page('master_biaya_lain.php'); check('tab Nonaktif' in active,'empty active tab explains migrated masters')
    inactive=page('master_biaya_lain.php?master_tab=nonaktif'); row=next(r for r in re.findall(r'<tr>.*?</tr>',inactive,re.S) if 'Buku Ummi' in r)
    check('2.873x' in row and '3.547 rincian' in row and '67.177.500,00' in row,'source master totals visible')
    check(re.search(r'<button[^>]*disabled[^>]*>Hapus</button>',row) is not None,'used master delete disabled')
    href=html.unescape(re.search(r'href="(pembayaran/lihat.php\?[^"]+)"',row)[1]); book=urllib.parse.parse_qs(urllib.parse.urlparse(href).query)['fee_master_id'][0]
    history=page(href); check(re.search(r'2[.,]873 transaksi',history) is not None and 'Transaksi biaya:' in history,'master link scopes history across all dates')
    check(re.search(r'name="fee_master_id" value="'+book+'"',history) is not None,'form preserves master context')
    second=page(href+'&page=2'); check('fee_master_id='+book in second,'pagination preserves master context')
    receipt=re.search(r'data-payment-record data-id="(-?\d+)" data-unit="(\d+)"',history); check(receipt is not None,'history receipt selected')
    payment=receipt[1]; detail=json.loads(page('pembayaran/detail.php?id='+payment+'&unit_id=1&fee_master_id='+book)); check(detail['ok'] and 'Buku Ummi' in detail['html'] and 'kode ' not in detail['html'] and 'Legacy #' not in detail['html'],'detail shows item name without technical provenance')
    check('fee_master_id='+book in detail['html'],'detail print preserves master context')
    struk=page('laporan/cetak_struk.php?id='+payment+'&fee_master_id='+book); check('Buku Ummi' in struk and 'kode ' not in struk and 'Legacy #' not in struk,'receipt shows item name without technical provenance')
    status,_,pdf=req('pembayaran/export_history.php?fee_master_id='+book+'&all_periods=1&format=pdf'); check(status==200 and pdf.startswith(b'%PDF'),'PDF uses scoped history')
    status,_,xlsx=req('pembayaran/export_history.php?fee_master_id='+book+'&all_periods=1&format=xlsx'); check(status==200 and xlsx.startswith(b'PK'),'Excel uses scoped history')
    sheet=ET.fromstring(zipfile.ZipFile(io.BytesIO(xlsx)).read('xl/worksheets/sheet1.xml')); ns={'s':'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}; rows=sheet.findall('s:sheetData/s:row',ns)
    check(len(rows)==2874,'Excel exports all 2873 selected receipts')
    from decimal import Decimal
    fee_sum=sum(Decimal(c.find('s:v',ns).text) for r in rows[1:] for c in r.findall('s:c',ns) if c.attrib['r'].startswith('I'))
    check(fee_sum==Decimal('67177500.00'),'Excel component amount matches master independently of receipt cash')
    parser=Forms();parser.feed(inactive); csrf=next(f['fields']['csrf_token'] for f in parser.forms if 'master_tab' in f['fields'])
    check(req('master_biaya_lain.php',{'aksi':'hapus','id':book,'master_tab':'nonaktif','_operational_unit':'1','csrf_token':csrf})[0] in (302,303),'direct delete POST handled')
    check('Buku Ummi' in page('master_biaya_lain.php?master_tab=nonaktif'),'direct POST cannot delete used Legacy master')
    for bad in ('0','bad','9999999999'):
        check(req('pembayaran/lihat.php?fee_master_id='+bad)[0]==400,'invalid master context '+bad)
    switch(2); check(req(href)[0]==400,'SD master cannot be opened from SMP')
    for unit,expected in ((2,51),(3,23)):
        if unit!=2:switch(unit)
        text=page('master_biaya_lain.php?master_tab=hapus'); check('Tidak ada master pada bagian ini' in text,'no used masters deletable unit '+str(unit))
    switch(1)
    # Fixture service uses the same CLI and HTTP instance guards; no production writes.
    def fixture(action,state=None):
        code="""<?php
require 'tests/http_form_scope.php';spp_test_assert_database_target();require 'koneksi.php';spp_test_assert_database_target($koneksi);require 'includes/legacy_finance.php';unit_set_context($koneksi,1);
$input=json_decode(getenv('SPP_FEE_HTTP_FIXTURE'),true);$db=$koneksi;$db->begin_transaction();try{
if($input['action']==='seed'){lf_query($db,"INSERT INTO master_biaya_lain(unit_id,nama,nominal,is_active) VALUES(1,?,1000,0)",['QA fee HTTP '.bin2hex(random_bytes(5))]);$result=['master'=>(int)$db->insert_id];}
elseif($input['action']==='use'){$m=$input['master'];$o=lf_one($db,'SELECT * FROM legacy_op_owners WHERE student_id IS NOT NULL LIMIT 1');$items=[['column'=>'JUMLAH1','category'=>'biaya_lain:'.$m,'label'=>'QA HTTP','amount'=>'1234.00']];lf_query($db,"INSERT INTO legacy_op_payments(unit_id,batch_id,owner_id,row_key,source_table,source_date,source_period,class_snapshot,items,total) VALUES(1,?,?,?,'bayar','2026-10-17','10 2026','1A',?,1234)",[$o['batch_id'],$o['id'],hash('sha256',random_bytes(32)),lf_json($items)]);$result=['master'=>$m,'payment'=>(int)$db->insert_id];}
else{if(!empty($input['payment']))lf_query($db,'DELETE FROM legacy_op_payments WHERE id=?',[$input['payment']]);lf_query($db,"DELETE FROM master_biaya_lain WHERE id=? AND nama LIKE 'QA fee HTTP %'",[$input['master']]);$result=[];}
$db->commit();echo json_encode($result);
}catch(Throwable $e){$db->rollback();throw $e;}
"""
        env=os.environ.copy();env['SPP_FEE_HTTP_FIXTURE']=json.dumps(dict(state or {},action=action));r=subprocess.run([args.php],input=code.encode(),capture_output=True,env=env,cwd=Path(__file__).resolve().parent.parent)
        if r.returncode:raise RuntimeError(r.stdout.decode()+r.stderr.decode())
        return json.loads(r.stdout)
    state=fixture('seed')
    try:
        deletable=page('master_biaya_lain.php?master_tab=hapus');check('QA fee HTTP' in deletable,'unused fixture initially deletable')
        parser=Forms();parser.feed(deletable);token=next(f['fields']['csrf_token'] for f in parser.forms if 'master_tab' in f['fields'])
        state=fixture('use',state)
        req('master_biaya_lain.php',{'aksi':'hapus','id':state['master'],'master_tab':'hapus','_operational_unit':'1','csrf_token':token})
        check('QA fee HTTP' in page('master_biaya_lain.php?master_tab=nonaktif'),'usage added after render prevents deletion on POST')
        check('QA fee HTTP' not in page('master_biaya_lain.php?master_tab=hapus'),'same reader removes newly used fixture from deletable tab')
    finally:fixture('clean',state)
    Path(args.output).write_text(json.dumps({'passed':len(checks),'checks':checks},indent=2),encoding='utf-8');print('PASS',len(checks),'HTTP checks')

if __name__=='__main__':main()
