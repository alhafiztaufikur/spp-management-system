"""Read private PDF/XLSX exports; never connects to the database."""
import json
import os
import re
from pathlib import Path
import xml.etree.ElementTree as ET
import zipfile

import fitz

root = Path(os.environ['SPP_PARTICIPANTS_ROOT'])
expected = json.loads((root / 'expected.json').read_text(encoding='utf-8'))
if (root / '1-selected-ui.pdf').exists() and (root / '1-selected-ui.xlsx').exists():
    expected['1-selected-ui'] = expected['1-selected']
ns = {'s': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
checks = 0
for name, rows in expected.items():
    with zipfile.ZipFile(root / (name + '.xlsx')) as z:
        strings = [''.join(si.itertext()) for si in ET.fromstring(z.read('xl/sharedStrings.xml'))]
        sheet = ET.fromstring(z.read('xl/worksheets/sheet1.xml'))
        data = []
        for row in sheet.findall('.//s:sheetData/s:row', ns):
            cells = {}
            for cell in row.findall('s:c', ns):
                v = cell.findtext('s:v', namespaces=ns)
                if v is not None:
                    cells[''.join(c for c in cell.get('r') if c.isalpha())] = strings[int(v)] if cell.get('t') == 's' else v
            if re.fullmatch(r'\d+(?:\.0+)?', str(cells.get('A', ''))) and 'C' in cells:
                data.append(cells)
        assert len(data) == len(rows), (name, len(data), len(rows))
        for cells, row in zip(data, rows):
            assert cells['C'] == row['nis'] and cells['B'] == row['nama'] and cells['I'] == row['target'], (name, cells, row)
        checks += 1
    with fitz.open(root / (name + '.pdf')) as pdf:
        text = ' '.join(' '.join(p.get_text() for p in pdf).split())
        assert all(word in text for word in ['Daftar Peserta Tahun Ajaran', 'Dibuat:', 'Status Penempatan'])
        assert f'{len(rows)} peserta' in text, (name, len(rows), text)
        for row in rows:
            assert row['nis'] in text and row['nama'] in text, (name, row['nis'])
        for p in pdf:
            assert p.get_fonts()
            for block in p.get_text('blocks'):
                assert 0 <= block[0] < block[2] <= p.rect.width + 1 and 0 <= block[1] < block[3] <= p.rect.height + 1, (name, block[:4])
        checks += 1
print(f'PASS {checks} exported participant PDF/XLSX row, text and bounds checks.')
