"""Read private closing exports; never connects to the database."""
import json
import os
from pathlib import Path
import re
import xml.etree.ElementTree as ET
import zipfile

import fitz

root = Path(os.environ['SPP_HISTORY_ARTIFACTS'])
expected = json.loads((root / 'cash-expected.json').read_text())
ns = {'s': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
checks = 0
labels = {'Tabungan Masuk': 'total_masuk', 'Tabungan Keluar': 'total_keluar', 'Mutasi Bersih': 'mutasi_bersih'}
counts = {'Transaksi Masuk': 'count_masuk', 'Transaksi Keluar': 'count_keluar', 'Total Transaksi': 'count_total'}
for unit, totals in expected.items():
    with fitz.open(root / f'cash-{unit}.pdf') as pdf:
        text = ' '.join(' '.join(page.get_text() for page in pdf).split())
        assert len(pdf) == 1
        for label, key in labels.items():
            amount = f"{totals[key]:,.0f}".replace(',', '.')
            assert re.search(re.escape(label) + r'\s+Rp\s+' + re.escape(amount) + r'(?![\d.])', text, re.I), (unit, label, text)
            checks += 1
        assert all(page.get_fonts() for page in pdf)
        for label, key in counts.items():
            assert re.search(re.escape(label) + r'\s+' + str(totals[key]) + r'\b', text), (unit, label, text)
            checks += 1
    with zipfile.ZipFile(root / f'cash-{unit}.xlsx') as archive:
        strings = [''.join(si.itertext()) for si in ET.fromstring(archive.read('xl/sharedStrings.xml'))]
        rows = ET.fromstring(archive.read('xl/worksheets/sheet1.xml')).findall('.//s:sheetData/s:row', ns)
        values = []
        for row in rows:
            cells = []
            for cell in row.findall('s:c', ns):
                value = cell.findtext('s:v', namespaces=ns)
                if value is not None:
                    cells.append(strings[int(value)] if cell.get('t') == 's' else float(value))
            values.append(cells)
        for label, key in {**labels, **counts}.items():
            assert any(label in row and totals[key] in row for row in values), (unit, label, values)
            checks += 1
print(f'PASS {checks} PDF text and XLSX numeric closing total checks.')
