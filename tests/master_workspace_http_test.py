"""CRUD/regression checks against the explicitly owned local migrated clone."""
import html
import json
from pathlib import Path
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
from lxml import html as dom

qa = Path(sys.argv[1]).resolve()
f = json.loads((qa / 'fixture.json').read_text())
assert f['base'] == 'http://127.0.0.1:58461' and f['port'] == 58460
mysql = ['C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe', '--no-defaults', '--host=127.0.0.1', '--port=58460', '--user=root', '--batch', '--skip-column-names', f['database']]
def sql(query):
    return subprocess.check_output(mysql + ['--execute=' + query]).decode('utf-8').strip()
assert 'master_audit_20261021' in sql('SELECT @@datadir')
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs): return None
opener = urllib.request.build_opener(NoRedirect())
checks = []
def check(ok, label):
    assert ok, label
    checks.append(label)
    print('PASS', label, flush=True)
def request(unit, path, values=None):
    session = f['sessions'][unit] if isinstance(f['sessions'], list) else f['sessions'][str(unit)]
    data = urllib.parse.urlencode(values, doseq=True).encode() if values is not None else None
    req = urllib.request.Request(f['base'] + '/' + path, data=data, headers={'Cookie': 'PHPSESSID=' + session})
    try: response = opener.open(req, timeout=120)
    except urllib.error.HTTPError as e: response = e
    with response: body = response.read(); status = response.status; headers = dict(response.headers)
    assert not any(x in body for x in [b'Fatal error:', b'Warning:', b'Notice:', b'Uncaught ']), body[-1500:]
    return status, body, headers
def form(unit, path, action):
    status, body, _ = request(unit, path); assert status == 200, (path, status)
    tree = dom.fromstring(body)
    for node in tree.xpath('//form'):
        if node.xpath('.//input[@name="aksi" and @value="' + action + '"]'):
            values = {n.get('name'): n.get('value', '') for n in node.xpath('.//input[@name]') if n.get('type') not in ['checkbox', 'radio', 'submit', 'button']}
            for select in node.xpath('.//select[@name]'):
                selected = select.xpath('.//option[@selected]') or select.xpath('.//option')
                if selected: values[select.get('name')] = selected[0].get('value', '')
            return values
    raise AssertionError('Form absent: ' + action)

check(json.loads(request(1, 'tests/browser_clone_identity.php')[1])['database'] == f['database'], 'HTTP process uses the owned clone')
for unit in [1, 2, 3]:
    student = f['units'][str(unit)]; sid = student['student_id']
    columns = 'KELAS,master_kelas_id,is_active,SPP_PERBULAN,POMG,PSB,DAFTAR_ULANG,tot_du,potong_du,potongan_spp_nominal'
    before = sql(f'SELECT {columns} FROM siswa_data WHERE id={sid}')
    values = form(unit, 'siswa/daftar.php?edit=' + str(sid), 'update')
    values = {k: v for k, v in values.items() if k in ['id', 'aksi', 'csrf_token', '_operational_unit', 'master_kelas_id', 'no_induk']}
    values['nama'] = 'QA Edited Alumni ' + str(unit)
    status, _, _ = request(unit, 'siswa/daftar.php', values)
    check(status == 302, f'Unit {unit} Alumni identity edit redirects')
    check(sql(f'SELECT NAMA FROM siswa_data WHERE id={sid}') == values['nama'], f'Unit {unit} Alumni identity is saved')
    check(sql(f'SELECT {columns} FROM siswa_data WHERE id={sid}') == before, f'Unit {unit} Alumni edit preserves class/status and all fee fields')
    status, body, _ = request(unit, 'siswa/daftar.php?status=archived&q=' + student['nis'])
    check(status == 200 and student['alumni'].encode() in body and b'Riwayat Kelas' in body, f'Unit {unit} Alumni and timeline render')
    values['master_kelas_id'] = sql(f'SELECT id FROM master_kelas_data WHERE unit_id={unit} AND tingkat>0 AND is_active=1 LIMIT 1')
    values['nama'] = 'Must not save'
    request(unit, 'siswa/daftar.php', values)
    check(sql(f'SELECT NAMA FROM siswa_data WHERE id={sid}') != 'Must not save', f'Unit {unit} manual graduation reversal rejected')

# Native CRUD with fractional master values: a partial identity update must retain them.
native_nis='9799999901'
if sql(f"SELECT COUNT(*) FROM siswa_data WHERE unit_id=1 AND NO_INDUK='{native_nis}'")=='0':
    values=form(1,'siswa/daftar.php','tambah')
    target=sql('SELECT id FROM master_kelas_data WHERE unit_id=1 AND tingkat=1 AND is_active=1 AND is_placeholder=0 LIMIT 1')
    values.update(no_induk=native_nis,nama='QA Native Fractional',master_kelas_id=target,psb='0',pomg='25.000,13',daftar_ulang='100.000,25',potong_du='0',potongan_psb_nominal='0',komite_mulai_bulan='07')
    request(1,'siswa/daftar.php',values)
native_id=sql(f"SELECT id FROM siswa_data WHERE unit_id=1 AND NO_INDUK='{native_nis}'")
check(bool(native_id),'Native student CRUD creates a valid regular student')
before=sql(f'SELECT POMG,DAFTAR_ULANG,tot_du,potong_du FROM siswa_data WHERE id={native_id}')
check(before.startswith('25000.13\t100000.25'),'Native student master keeps exact fractional values')
values=form(1,'siswa/daftar.php?edit='+native_id,'update')
values={k:v for k,v in values.items() if k in ['id','aksi','csrf_token','_operational_unit','master_kelas_id','no_induk']}
values['nama']='QA Native Fractional Edited'
request(1,'siswa/daftar.php',values)
check(sql(f'SELECT POMG,DAFTAR_ULANG,tot_du,potong_du FROM siswa_data WHERE id={native_id}')==before,'Omitted DECIMAL master fields survive partial identity update')

for count in [0, 18, 26, 53]:
    query = 'QA Missing' if count == 0 else 'QA Count' + str(count)
    for size in [10, 25, 50]:
        page_count = max(1, (count + size - 1) // size)
        seen = []
        for page in range(1, page_count + 1):
            path = 'master_kelas.php?' + urllib.parse.urlencode({'special_q': query, 'special_per_page': size, 'special_page': page})
            status, body, _ = request(1, path); tree = dom.fromstring(body)
            rows = tree.xpath('//*[@id="special-classes"]//table[contains(@class,"sc-table")][1]/tbody/tr')
            names = [r.xpath('string(td[1]/strong)') for r in rows]
            seen.extend(names)
            check(status == 200 and len(rows) == min(size, max(0, count-(page-1)*size)), f'Pagination {count} classes / {size} rows / page {page}')
        check(len(set(seen)) == count, f'Pagination {count}/{size} has no omissions or duplicates')
    _, body, _ = request(1, 'master_kelas.php?special_q=' + urllib.parse.quote(query) + '&special_page=999')
    check(f'Halaman {max(1,(count+24)//25)} dari'.encode() in body, f'Pagination {count} clamps invalid page')

values = form(1, 'master_kelas.php?special_q=QA%20Count53&special_page=2', 'tambah_custom')
values.update(nama_custom='QA HTTP CRUD', is_active='1')
status, _, headers = request(1, 'master_kelas.php', values)
check(status == 302 and 'special_page=2' in headers['Location'], 'Special-class creation keeps list context')
class_id = int(sql("SELECT id FROM master_kelas_data WHERE unit_id=1 AND nama_custom='QA HTTP CRUD'"))
values = form(1, f'master_kelas.php?special_edit={class_id}&special_q=QA&special_page=2', 'update_custom')
values.update(nama_custom='QA HTTP CRUD renamed', is_active='0')
request(1, 'master_kelas.php', values)
check(sql(f'SELECT CONCAT(nama_custom,\':\',is_active) FROM master_kelas_data WHERE id={class_id}') == 'QA HTTP CRUD renamed:0', 'Special-class update and deactivate work')
status, body, _ = request(1, f'master_kelas.php?special_delete={class_id}')
values = form(1, f'master_kelas.php?special_delete={class_id}', 'hapus_custom')
request(1, 'master_kelas.php', values)
check(sql(f'SELECT COUNT(*) FROM master_kelas_data WHERE id={class_id}') == '0', 'Confirmed deletion removes an unused special class')

for unit in [0, 1, 2, 3]:
    for path in ['dashboard.php', 'master_kelas.php', 'master_spp.php', 'master_daftar_ulang.php', 'master_biaya_lain.php', 'siswa/daftar.php', 'tabungan/riwayat.php']:
        status, body, _ = request(unit, path)
        check(status == 200 and b'<html' in body.lower(), f'Unit {unit} {path} renders after migration')
    _, body, _ = request(unit, 'dashboard.php')
    check(b'Total Penerimaan' not in body and b'Total bersih' not in body and b'Pembayaran + tabungan' not in body, f'Unit {unit} dashboard removes combined amount')
    _, body, _ = request(unit, 'tabungan/riwayat.php?all_periods=1'); tree = dom.fromstring(body)
    links = tree.xpath('//div[contains(@class,"sw-row-print")]/a')
    check(bool(links) and all(a.get('href', '').startswith('cetak_buku.php?') for a in links), f'Unit {unit} history book buttons target full books')
    for index, link in enumerate(links[:2]):
        status, book, _ = request(unit, 'tabungan/' + link.get('href'))
        check(status == 200 and b'<title>Cetak Buku Tabungan</title>' in book, f'Unit {unit} book link {index+1} opens a full account book')

for key in ['single_draft', 'multi_draft']:
    status, pdf, _ = request(1, 'laporan/surat_orang_tua_pdf.php?draft=' + f[key])
    check(status == 200 and pdf.startswith(b'%PDF-'), key + ' PDF available')
    (qa/'artifacts'/ (key+'.pdf')).write_bytes(pdf)
    status, _, _ = request(2, 'laporan/surat_orang_tua_pdf.php?draft=' + f[key])
    check(status == 400, key + ' cannot be read by another session/unit')
(qa/'http-results.json').write_text(json.dumps({'checks': len(checks), 'passed': checks}, indent=2))
print('OK:', len(checks), 'HTTP/data checks', flush=True)
