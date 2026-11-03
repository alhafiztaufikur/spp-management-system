"""Inspect real PDF pages and native Excel PDF output in the private QA folder."""
import json
import os
import re
from pathlib import Path
import fitz

root = Path(os.environ['SPP_BROWSER_ARTIFACT_ROOT'])
assert 'pagination_desktop_' in str(root)
results = []
for path in sorted((root / 'final').glob('*.pdf')):
    native = path.stem.endswith('-excel')
    with fitz.open(path) as pdf:
        assert len(pdf), path
        content = '\n'.join(page.get_text() for page in pdf)
        assert not re.search(r'Rp\s+\d|Status siswa:|Total kewajiban hanya mencakup|Unit:? SD|#{4}', content), path
        for i, page in enumerate(pdf):
            assert len(page.get_text().split()) > 2, (path, 'blank page', i)
            assert abs(min(page.rect.width, page.rect.height) - 595.28) < 1, (path, 'A4')
            numbers = [b for b in page.get_text('blocks') if re.search(r'Halaman\s+\d+', b[4])]
            assert len(numbers) == 1, (path, i, 'duplicate/missing page number', numbers)
            assert re.search(r'Halaman\s+' + str(i + 1) + r'\b', numbers[0][4]), (path, i, 'page order')
            if not native:
                rules = [item for drawing in page.get_drawings() for item in drawing['items'] if item[0] == 'l' and abs(item[1].y - (page.rect.height - 34)) < .5 and abs(item[1].y - item[2].y) < .1]
                assert len(rules) == 1, (path, i, 'footer rule')
                assert numbers[0][1] - rules[0][1].y >= 4, (path, i, 'footer clearance')
            lines = {}
            for word in page.get_text('words'):
                assert word[0] >= 20 and word[2] <= page.rect.width - 18, (path, i, 'clipped text', word)
                lines.setdefault(round(word[3], 1), []).append(word)
            for words in lines.values():
                words.sort(key=lambda word: word[0])
                for left, right in zip(words, words[1:]):
                    assert left[2] <= right[0] + .4, (path, i, 'overlap', left, right)
            if i in {0, len(pdf) // 2, len(pdf) - 1}:
                page.get_pixmap(matrix=fitz.Matrix(1.5, 1.5)).save(root / (path.stem + '-page' + str(i + 1) + '.png'))
        results.append({'file': path.name, 'pages': len(pdf), 'A4': True, 'footer': True, 'textFits': True})
        if path.stem == 'fixture-large':
            expected = json.loads((root / 'fixture-expected.json').read_text(encoding='utf-8'))
            positions = [content.find(row['nis']) for row in expected['rows']]
            assert -1 not in positions and positions == sorted(set(positions)), 'Rows missing, duplicated or reordered across fragments'
            assert '-125.000,25' in content and '123.456.789.012,25' in content and re.search(r'Belum\s+diketahui', content), 'Lost sign, precision or unknown state'
            assert content.count('Subtotal seluruh hasil filter') == 1, 'Subtotal must occur once'
(root / 'pdf-results.json').write_text(json.dumps(results, indent=2), encoding='utf-8')
print('PASS', len(results), 'PDFs,', sum(item['pages'] for item in results), 'pages: A4, clean context/currency, sequential footer, clearance, no horizontal overlap.')
