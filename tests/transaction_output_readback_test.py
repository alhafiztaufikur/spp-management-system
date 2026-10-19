"""Inspect private artifacts only; no database, network or physical printer access."""
import os
import struct
import zipfile
from pathlib import Path
import fitz

root = Path(os.environ['SPP_SAVINGS_QA']).resolve()
assert not root.is_relative_to(Path.cwd().resolve())
checks = pages = 0
for file in (root / 'outputs').glob('*.png'):
    if '-page' in file.stem:
        continue
    raw = file.read_bytes()
    assert raw[:8] == b'\x89PNG\r\n\x1a\n'
    assert struct.unpack('>II', raw[16:24]) == (2550, 1650), file
    checks += 2
for file in (root / 'outputs').glob('*.zip'):
    with zipfile.ZipFile(file) as archive:
        assert archive.testzip() is None
        names = archive.namelist()
        assert len(names) > 1 and names == sorted(names)
        if 'annual' in file.stem:
            assert len(names) == 12
        for index, name in enumerate(names, 1):
            assert name.endswith(f'-{index:03}.png')
            raw = archive.read(name)
            assert struct.unpack('>II', raw[16:24]) == (2550, 1650)
            checks += 2
files = list((root / 'outputs').glob('*.pdf')) + list((root / 'exports').glob('*.pdf'))
for file in files:
    document = fitz.open(file)
    text = ''.join(page.get_text() for page in document)
    assert text.strip(), file
    if 'savings' in file.stem or 'payment' in file.stem or 'annual' in file.stem or file.stem.endswith('-1'):
        assert len(document) == (12 if 'annual' in file.stem else 1), file
        assert abs(document[0].rect.width - 612) < 1
        assert abs(document[0].rect.height - 396) < 1
        checks += 3
    if file.stem.endswith('-optional'):
        assert 'opsional' in text.lower() and '455.000' in text, file
        checks += 1
    for page in document:
        pages += 1
        for word in page.get_text('words'):
            assert -1 <= word[0] <= word[2] <= page.rect.width + 1, (file, word)
            assert -1 <= word[1] <= word[3] <= page.rect.height + 1, (file, word)
        checks += 1
for file in (root / 'outputs').glob('*-optional.xlsx'):
    with zipfile.ZipFile(file) as archive:
        assert archive.testzip() is None
        xml = ''.join(archive.read(name).decode() for name in archive.namelist() if name.endswith('.xml'))
        assert 'Pembayaran Opsional' in xml and '455000' in xml
        checks += 2
print(f'PASS {checks} PNG/ZIP/XLSX/PDF checks; {pages} PDF pages read back.')
