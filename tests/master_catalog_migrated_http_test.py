"""Real master CRUD endpoints on the owned migrated clone only."""
import json
from pathlib import Path
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from lxml import html

qa = Path(sys.argv[1]).resolve()
f = json.loads((qa / 'fixture.json').read_text())
assert f['base'] == 'http://127.0.0.1:58461' and f['port'] == 58460
mysql = ['C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe', '--no-defaults', '--host=127.0.0.1', '--port=58460', '--user=root', '--batch', '--skip-column-names', f['database']]
sql_unit = 1
def sql(query): return subprocess.check_output(mysql + ['--execute=SET @app_unit_id=' + str(sql_unit) + '; ' + query]).decode().strip()
assert 'master_audit_20261021' in sql('SELECT @@datadir')
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs): return None
opener = urllib.request.build_opener(NoRedirect())
def request(unit, path, values=None):
    data = urllib.parse.urlencode(values).encode() if values is not None else None
    req = urllib.request.Request(f['base'] + '/' + path, data=data, headers={'Cookie': 'PHPSESSID=' + f['sessions'][unit]})
    try: response = opener.open(req, timeout=120)
    except urllib.error.HTTPError as error: response = error
    with response: body = response.read(); status = response.status
    assert not any(x in body for x in [b'Fatal error:', b'Warning:', b'Uncaught ']), body[-1000:]
    return status, body
def form(unit, path, action):
    status, body = request(unit, path); assert status == 200
    for node in html.fromstring(body).xpath('//form'):
        if node.xpath('.//input[@name="aksi" and @value="' + action + '"]'):
            return {n.get('name'): n.get('value', '') for n in node.xpath('.//input[@name]') if n.get('type') not in ['checkbox', 'submit', 'button']}
    raise AssertionError('Missing form: ' + action)
checks = []
def check(ok, label):
    assert ok, label
    checks.append(label); print('PASS', label, flush=True)
check(json.loads(request(1, 'tests/browser_clone_identity.php')[1])['database'] == f['database'], 'HTTP master tests use the owned migrated clone')
for unit in [1, 2, 3]:
    sql_unit = unit
    name = 'QA Catalog ' + str(time.time_ns())
    values = form(unit, 'master_biaya_lain.php', 'tambah')
    values.update(nama=name, nominal='12.345,67', is_active='1', jenis_pembayaran='berulang')
    request(unit, 'master_biaya_lain.php', values)
    mid = sql(f"SELECT id FROM master_biaya_lain_data WHERE unit_id={unit} AND nama='{name}'")
    check(bool(mid), f'Unit {unit} optional catalog creation')
    request(unit, 'master_biaya_lain.php', values)
    check(sql(f"SELECT COUNT(*) FROM master_biaya_lain_data WHERE unit_id={unit} AND nama='{name}'") == '1', f'Unit {unit} duplicate catalog is rejected')
    sid = f['units'][str(unit)]['student_id']
    nis = f['units'][str(unit)]['nis']
    # Native payment linked to a migrated-shape bill; snapshots intentionally differ from the next master name/rate.
    sql(f"INSERT INTO tagihan_biaya_lain_data(unit_id,master_biaya_lain_id,no_induk,nama_snapshot,nominal_tagihan,status,created_by) VALUES({unit},{mid},'{nis}','{name}',12345.67,'open',{f['actor']})")
    bill = sql('SELECT MAX(id) FROM tagihan_biaya_lain_data')
    sql(f"INSERT INTO bayar_data(unit_id,NO_INDUK,KELAS,TGL_BYR,total_jumlah,sistem_pembayaran) VALUES({unit},'{nis}','0',NOW(),1000,'Tunai')")
    payment = sql('SELECT MAX(id) FROM bayar_data')
    sql(f"INSERT INTO bayar_biaya_lain_data(unit_id,bayar_id,master_biaya_lain_id,tagihan_biaya_lain_id,nama_biaya_snapshot,nominal_snapshot) VALUES({unit},{payment},{mid},{bill},'{name}',1000)")
    before_bill = sql('SELECT nama_snapshot,nominal_tagihan,status FROM tagihan_biaya_lain_data WHERE id=' + bill)
    before_payment = sql('SELECT nama_biaya_snapshot,nominal_snapshot FROM bayar_biaya_lain_data WHERE bayar_id=' + payment)
    values.update(aksi='update', id=mid, nama=name + ' Edited', nominal='25.000,13')
    values.pop('is_active')
    request(unit, 'master_biaya_lain.php', values)
    check(sql('SELECT CONCAT(nominal,\':\',is_active) FROM master_biaya_lain_data WHERE id=' + mid) == '25000.13:0', f'Unit {unit} used catalog can be renamed, repriced and archived')
    check(sql('SELECT nama_snapshot,nominal_tagihan,status FROM tagihan_biaya_lain_data WHERE id=' + bill) == before_bill and sql('SELECT nama_biaya_snapshot,nominal_snapshot FROM bayar_biaya_lain_data WHERE bayar_id=' + payment) == before_payment, f'Unit {unit} catalog edit preserves paid and legacy-bill snapshots')
    values['aksi'] = 'hapus'; request(unit, 'master_biaya_lain.php', values)
    check(sql('SELECT COUNT(*) FROM master_biaya_lain_data WHERE id=' + mid) == '1', f'Unit {unit} deleting a used catalog is rejected')
    other = unit % 3 + 1
    foreign_values = form(other, 'master_biaya_lain.php', 'tambah')
    foreign_values.update(aksi='update', id=mid, nama='FORBIDDEN CROSS UNIT', nominal='1', jenis_pembayaran='berulang')
    request(other, 'master_biaya_lain.php', foreign_values)
    check(sql('SELECT nama FROM master_biaya_lain_data WHERE id=' + mid) == name + ' Edited', f'Unit {unit} catalog cannot be changed from another unit')
    # Empty master deletion remains available.
    values = form(unit, 'master_biaya_lain.php', 'tambah'); empty_name = name + ' Empty'
    values.update(nama=empty_name, nominal='1000', jenis_pembayaran='berulang', is_active='1')
    request(unit, 'master_biaya_lain.php', values)
    empty = sql(f"SELECT id FROM master_biaya_lain_data WHERE unit_id={unit} AND nama='{empty_name}'")
    values.update(aksi='hapus', id=empty); request(unit, 'master_biaya_lain.php', values)
    check(sql('SELECT COUNT(*) FROM master_biaya_lain_data WHERE id=' + empty) == '0', f'Unit {unit} deleting an unused catalog succeeds')
    values = form(unit, 'master_kelas.php', 'tambah_custom')
    alumni = sql(f'SELECT master_kelas_id FROM siswa_data WHERE id={sid}')
    values.update(aksi='hapus_custom', id=alumni, confirm_delete='1')
    request(unit, 'master_kelas.php', values)
    check(sql('SELECT COUNT(*) FROM master_kelas_data WHERE id=' + alumni) == '1', f'Unit {unit} Alumni backend deletion remains protected')
(qa / 'catalog-http-results.json').write_text(json.dumps({'checks': len(checks), 'passed': checks}, indent=2))
