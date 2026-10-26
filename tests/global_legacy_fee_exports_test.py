"""Read-only fee recap/real PDF/XLSX checks on the explicitly isolated HTTP clone."""
from pathlib import Path
from decimal import Decimal
from io import BytesIO
import json
import sys
import urllib.parse
import urllib.request

from lxml import html
from pypdf import PdfReader
from openpyxl import load_workbook

qa = Path(sys.argv[1]).resolve()
fixture = json.loads((qa / 'fixture.json').read_text())
manifest = json.loads((qa / 'isolated-instance.json').read_text())
assert fixture['base'] == 'http://127.0.0.1:58471' and fixture['port'] == manifest['port'] == 58470
assert manifest['isolated_test'] and manifest['server_uuid'] != manifest['source_server_uuid']
checks = []


def check(ok, label):
    assert ok, label
    checks.append(label)


def get(path, params=None, unit=1):
    url = fixture['base'] + '/' + path
    if params is not None:
        url += '?' + urllib.parse.urlencode(params, doseq=True)
    sessions = fixture['sessions']
    session = sessions[unit] if isinstance(sessions, list) else sessions[str(unit)]
    request = urllib.request.Request(url, headers={'Cookie': 'PHPSESSID=' + session})
    with urllib.request.urlopen(request, timeout=180) as response:
        body = response.read()
        check(response.status == 200, path + ' returns 200')
    check(not any(marker in body[:20000] for marker in [b'Fatal error:', b'Uncaught ', b'Gagal memuat laporan']), path + ' loads successfully')
    return body


identity = json.loads(get('tests/browser_clone_identity.php'))
assert identity['server_uuid'] == manifest['server_uuid'] and identity['port'] == 58470
cases = [
    ('exact-date', 'per-item', '2026-10-02', '2026-10-02', '252601095', 'Ada Pembayaran', True),
    ('name-search', 'per-item', '2026-10-01', '2026-10-26', 'Aqila Areta Azzahra', 'Ada Pembayaran', True),
    ('outside-date', 'per-item', '2026-10-26', '2026-10-26', '252601095', 'Ada Pembayaran di Luar Periode', False),
    ('status', 'status', '2026-10-01', '2026-10-26', '252601095', 'Ada Pembayaran', True),
    ('billing-history', 'riwayat-tagihan', '', '', '252601095', 'Ada Pembayaran', True),
]
for unit in [1, 0]:
    for stem, template, start, end, query, status, paid in cases:
        params = dict(template=template, unit=str(unit) if unit else 'all', kategori='biaya_lain:20',
                      komponen_tagihan='biaya_lain:20', q=query, siswa_status='all',
                      tanggal_awal=start, tanggal_akhir=end, tahun_ajaran='2026/2027')
        page = get('laporan/template.php', params, unit)
        tree = html.fromstring(page)
        check('Aqila Areta Azzahra' in tree.text_content(), stem + ' search finds the student')
        if template != 'riwayat-tagihan':
            rows = tree.xpath('//tr[td//*[contains(text(),"252601095")]]')
            check(bool(rows) and status in rows[0].text_content(), stem + ' existing status cell shows proven payment evidence')
        check(len(tree.xpath('//a[contains(@class,"report-export-btn")]')) == 3, stem + ' retains the existing three export actions')
        for fmt in ['pdf', 'excel']:
            data = get('laporan/export_global.php', params | dict(format=fmt, download='1'), unit)
            artifact = qa / 'artifacts' / f'fee-{unit}-{stem}.{ "pdf" if fmt == "pdf" else "xlsx" }'
            artifact.write_bytes(data)
            if fmt == 'pdf':
                check(data.startswith(b'%PDF-'), stem + ' exports a real PDF')
                text = ' '.join((page.extract_text() or '') for page in PdfReader(BytesIO(data)).pages)
                check('Aqila Areta Azzahra' in text, stem + ' PDF retains student identity')
                check(('450.000' in text) == paid, stem + ' PDF respects the selected payment period')
                if template != 'riwayat-tagihan':
                    check(status in ' '.join(text.split()), stem + ' PDF uses the same evidence status')
            else:
                check(data.startswith(b'PK'), stem + ' exports a real XLSX')
                book = load_workbook(BytesIO(data), data_only=True)
                values = [cell.value for sheet in book for row in sheet for cell in row if cell.value is not None]
                check('252601095' in [str(value) for value in values], stem + ' XLSX retains student identity')
                amounts = [Decimal(str(value)) for value in values if isinstance(value, (int, float))]
                has_amount = Decimal('450000') in amounts or any('Dibayar Rp 450.000' in str(value) for value in values)
                check(has_amount == paid, stem + ' XLSX respects the selected payment period')
                if template != 'riwayat-tagihan':
                    check(status in values, stem + ' XLSX uses the same evidence status')
                book.close()

params = dict(template='per-item', unit='1', kategori='biaya_lain:20', q='252601095',
              tanggal_awal='2026-10-26', tanggal_akhir='2026-10-26', status=['ada_pembayaran'])
check('Aqila Areta Azzahra' in html.fromstring(get('laporan/template.php', params)).text_content(), 'Existing payment filter finds outside-period evidence')
(qa / 'fee-export-results.json').write_text(json.dumps({'checks': len(checks), 'passed': checks}, indent=2))
print(f'PASS {len(checks)} fee UI/PDF/XLSX checks; all requests are GET.')
