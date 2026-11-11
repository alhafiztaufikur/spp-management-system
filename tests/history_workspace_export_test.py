"""Compare complete Excel contents, PDF text and printable receipts on loopback restores."""
import io
import json
import os
import re
import urllib.request
import xml.etree.ElementTree as ET
import zipfile
from html.parser import HTMLParser
from pathlib import Path

from pypdf import PdfReader

audit = Path(os.environ['SPP_HISTORY_AUDIT_ROOT'])
sessions = json.loads((audit / 'sessions.json').read_text())
bases = {'before': 'http://127.0.0.1:8131', 'after': 'http://127.0.0.1:8132'}


class Text(HTMLParser):
    def __init__(self):
        super().__init__()
        self.skip = 0
        self.parts = []

    def handle_starttag(self, tag, attrs):
        if tag in ('script', 'style'):
            self.skip += 1

    def handle_endtag(self, tag):
        if tag in ('script', 'style'):
            self.skip -= 1

    def handle_data(self, value):
        if not self.skip:
            self.parts.append(value)


def content(body, kind):
    if kind == 'xlsx':
        with zipfile.ZipFile(io.BytesIO(body)) as book:
            names = sorted(n for n in book.namelist() if re.match(r'xl/(worksheets/[^/]+|sharedStrings|styles|workbook)\.xml$', n))
            return {n: ET.canonicalize(book.read(n).decode('utf-8')) for n in names}
    if kind == 'pdf':
        return [p.extract_text() for p in PdfReader(io.BytesIO(body)).pages]
    text = Text()
    text.feed(body.decode('utf-8'))
    return ' '.join(' '.join(text.parts).split())


checks = []
for role in ('super_admin', 'admin'):
    for unit in (1, 2, 3, 0):
        payment = json.loads((audit / 'before' / f'{role}_{unit}_payment_5.json').read_text())['model']['history']['rows'][0]
        routes = [
            ('du-xlsx', '/pembayaran/riwayat_daftar_ulang.php?output=excel', 'xlsx'),
            ('payment-xlsx', '/pembayaran/export_history.php?format=xlsx&tanggal_awal=2026-10-01&tanggal_akhir=2026-10-22', 'xlsx'),
            ('payment-pdf', '/pembayaran/export_history.php?format=pdf&tanggal_awal=2026-10-01&tanggal_akhir=2026-10-22', 'pdf'),
            ('receipt', f"/laporan/cetak_struk.php?id={payment['id']}&unit_id={payment['unit_id']}", 'html'),
            ('receipt-pdf', f"/laporan/cetak_struk.php?id={payment['id']}&unit_id={payment['unit_id']}&output=pdf", 'pdf'),
        ]
        for label, route, kind in routes:
            expected = None
            for variant, base in bases.items():
                request = urllib.request.Request(base + route, headers={'Cookie': 'PHPSESSID=' + sessions[f'{role}-{unit}']})
                with urllib.request.urlopen(request, timeout=180) as response:
                    assert response.status == 200
                    body = response.read()
                (audit / variant / f'export-{role}-{unit}-{label}.{kind}').write_bytes(body)
                actual = content(body, kind)
                if variant == 'before':
                    expected = actual
                else:
                    assert actual == expected, f'{role}/{unit}/{label}: exported financial content changed'
            checks.append(f'{role}/{unit}/{label}')
        print(f'PASS exports {role} unit {unit}', flush=True)
(audit / 'exports-result.json').write_text(json.dumps({'passed': True, 'checks': checks}, indent=2))
print(f'PASS {len(checks)} Excel, PDF and receipt comparisons')
