"""Read private workflow QA artifacts; no database, network or printer writes."""
import os
import struct
import zipfile
import xml.etree.ElementTree as ET
from pathlib import Path
import fitz

root = Path(os.environ['SPP_SAVINGS_QA']).resolve()
assert not root.is_relative_to(Path.cwd().resolve())
checks = pages = 0
files = list(root.glob('*.pdf')) + list((root / 'outputs').glob('*.pdf')) + list((root / 'browser').glob('*.pdf'))
for file in files:
    with fitz.open(file) as doc:
        text = ''.join(p.get_text() for p in doc)
        assert text.strip(), file
        book = 'book' in file.stem
        report = file.stem.startswith('workflow-') and file.stem.rsplit('-', 1)[-1] in ('psb', 'repeat', 'once')
        expected = 4 if file.stem == 'book-long' else 12 if 'annual' in file.stem else 1
        assert len(doc) == expected, file
        checks += 2
        for page in doc:
            pages += 1
            width, height = (419.53, 595.28) if book else (841.89, 595.28) if report else (612, 396)
            assert abs(page.rect.width - width) < 2 and abs(page.rect.height - height) < 2, file
            if not report:
                assert not page.get_images(), file
                assert page.get_fonts(), file
                checks += 2
            for word in page.get_text('words'):
                assert -1 <= word[0] <= word[2] <= page.rect.width + 1, (file, word)
                assert -1 <= word[1] <= word[3] <= page.rect.height + 1, (file, word)
            if book:
                assert all(label in page.get_text() for label in ('No.', 'Tanggal', 'Masuk', 'Keluar', 'Saldo', 'Paraf')), file
                checks += 1
            checks += 2
        if file.stem == 'workflow-combined':
            assert all(value in text for value in ('60.000', '780.000', '970.000', 'Sisa PSB', 'Sisa DU')), file
            checks += 1
        if file.stem == 'workflow-mixed-book':
            assert all(value in text for value in ('01/10/2026', '01/11/2026', '02/11/2026', '105.000')), file
            checks += 1
        if file.stem == 'book-long':
            assert text.count('01/10/2026') == 55 and '54.999.999.945' in text, file
            checks += 1
for file in (root / 'browser').glob('*-export.png'):
    raw = file.read_bytes()
    assert raw[:8] == b'\x89PNG\r\n\x1a\n' and struct.unpack('>II', raw[16:24]) == (2550, 1650), file
    checks += 1
for file in (root / 'outputs').glob('*annual.zip'):
    with zipfile.ZipFile(file) as archive:
        assert archive.testzip() is None and len(archive.namelist()) == 12, file
        for name in archive.namelist():
            assert struct.unpack('>II', archive.read(name)[16:24]) == (2550, 1650), (file, name)
            checks += 1
        checks += 1
ns = {'m': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
for unit in (1, 2, 3):
    for kind in ('psb', 'repeat', 'once'):
        file = root / 'outputs' / f'workflow-{unit}-{kind}.xlsx'
        with zipfile.ZipFile(file) as archive:
            assert archive.testzip() is None, file
            shared = [''.join(x.itertext()) for x in ET.fromstring(archive.read('xl/sharedStrings.xml'))]
            sheet = ET.fromstring(archive.read('xl/worksheets/sheet1.xml'))
            values = {}
            for cell in sheet.findall('.//m:row[@r="12"]/m:c', ns):
                v = cell.find('m:v', ns)
                value = v.text if v is not None else None
                values[cell.get('r')] = shared[int(value)] if cell.get('t') == 's' and value is not None else value
            assert values['D12'] == f'Workflow QA {unit}', file
            expected = {'F12': 800000, 'G12': 0, 'H12': 800000} if kind == 'psb' else {'F12': 0, 'G12': 0, 'H12': 120000 if unit == 1 else 220000, 'I12': 0} if kind == 'repeat' else {'F12': 100000, 'G12': 50000, 'H12': 0, 'I12': 50000}
            assert all(float(values[cell]) == amount for cell, amount in expected.items()), (file, values)
            checks += 3
print(f'PASS {checks} artifact checks; {pages} native PDF pages, 9 XLSX, 9 PNG and 3 annual ZIP files read back.')
