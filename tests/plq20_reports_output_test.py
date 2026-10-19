"""Extract actual PDF text/coordinates and compare with read-only SQL/Excel models."""
import collections
import json
import os
from pathlib import Path
import re
import zipfile
import xml.etree.ElementTree as ET
import fitz

root = Path(os.environ['SPP_QA_DIR'])
checks = 0
def check(ok, label):
    global checks
    if not ok:
        raise AssertionError(label)
    checks += 1
def compact(text):
    return re.sub(r'\s+', '', text)
def spans(page):
    return [s for b in page.get_text('dict')['blocks'] if 'lines' in b for l in b['lines'] for s in l['spans'] if s['text'].strip()]
def money(value):
    return 'Rp'+format(round(float(value)), ',').replace(',', '.')
def fulltext(doc):
    return '\n'.join(page.get_text() for page in doc)

production = fitz.open(root/'plq-full.pdf')
guide = fitz.open(root/'plq-guide.pdf')
check(len(production) == 3, 'All 57 entries across three spreads')
for i, page in enumerate(production):
    check(abs(page.rect.width-148*72/25.4)<.01 and abs(page.rect.height-210*72/25.4)<.01, 'Exact A5 physical size')
    check(not page.get_drawings(), 'Production has no grid, border or other artwork')
    check('Tabungan' not in page.get_text() and 'Paraf' not in page.get_text(), 'Production has no table headings')
    actual=[(s['text'],tuple(round(x,2) for x in s['bbox'])) for s in spans(page)]
    expected=[(s['text'],tuple(round(x,2) for x in s['bbox'])) for s in spans(guide[i]) if re.fullmatch(r'[\d.,/]+',s['text'])]
    check(actual == expected, 'Guide and production share exact text positions')
    for s in spans(page):
        check(s['bbox'][0]>=3*72/25.4 and s['bbox'][2]<=145*72/25.4+.1 and s['bbox'][3]<=201*72/25.4, 'Text stays in printer margins')
        check(s['bbox'][3]<=100*72/25.4 or s['bbox'][1]>=109.7*72/25.4, 'Text avoids fold margins')
partial=fitz.open(root/'plq-partial.pdf')
check(re.findall(r'^\d+$', partial[0].get_text(), re.M)==['15'], 'Partial printing retains original number')
check('15.000' in partial[0].get_text(), 'Partial printing retains existing running balance')
zero=fitz.open(root/'plq-zero.pdf')
check('100,25' in fulltext(zero) and re.search(r'^0$', fulltext(zero), re.M), 'Fractional amount and zero balance preserved')
check(len(fitz.open(root/'plq-calibration.pdf')[0].get_drawings())>0, 'Calibration contains guide markers')
long=fitz.open(root/'authorization-long.pdf');text=compact(fulltext(long))
check(all(marker in text for marker in ['PENUTUP-ALASAN','AKHIR-SEBELUM','AKHIR-SESUDAH']), 'Long audit snapshots and notes are complete')
check('secret_username' not in text, 'Audit username absent')
recap=fitz.open(root/'recap-350.pdf');text=compact(fulltext(recap))
for i in range(1,351):
    check(text.count(f'Siswa{i:04d}')==1, 'Each large report row appears once')
check(text.count('Rp12.345')==350 and text.count('Rp4.320.750')==2, 'Large report values and totals retained')

expected=json.loads((root/'output-expected.json').read_text())
ns={'s':'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
for unit, model in expected.items():
    if unit=='selected_reference':continue
    book=fitz.open(root/f'book-http-{unit}-pdf.pdf')
    book_text=compact(fulltext(book))
    check(all(format(row['saldo']/100, ',.0f').replace(',', '.') in book_text for row in model['book']), 'HTTP book includes every running balance')
    pdf=fitz.open(root/f'recap-http-{unit}.pdf');text=compact(fulltext(pdf))
    expected_money=[];expected_nis=[]
    for sheet in model['doc']['sheets']:
        for section in sheet['sections']:
            check(compact(section['title']) in text, 'Every report section present')
            for row in section['rows']:
                for column in section['columns']:
                    value=row.get(column['key'],'')
                    if column['type'] in ('money','money_optional') and value!='':expected_money.append(money(value))
                    if column['key']=='NO_INDUK':expected_nis.append(str(value))
            expected_money.extend(money(t['value']) for t in section['totals'])
    actual_money=re.findall(r'Rp\s*[\d.]+', fulltext(pdf))
    check(collections.Counter(map(compact,actual_money))==collections.Counter(expected_money), 'PDF money cells and subtotals match Excel model exactly')
    actual_nis=re.findall(r'(?<!\d)\d{8,10}(?!\d)',fulltext(pdf))
    # NIS Diknas may use the same numeric shape: compare occurrence count for each NIS.
    for nis,count in collections.Counter(expected_nis).items():
        expected_diknas=sum(str(row.get('NO_induk_diknas',''))==nis for sheet in model['doc']['sheets'] for section in sheet['sections'] for row in section['rows'])
        check(len(re.findall(r'(?<![A-Za-z0-9])'+re.escape(nis)+r'(?![A-Za-z0-9])', fulltext(pdf)))==count+expected_diknas, 'Every SQL detail identity retained in PDF: '+nis)
    with zipfile.ZipFile(root/f'recap-http-{unit}.xlsx') as archive:
        excel_money=[]
        styles=ET.fromstring(archive.read('xl/styles.xml'))
        currency_ids={f.get('numFmtId') for f in styles.findall('s:numFmts/s:numFmt',ns) if 'Rp' in f.get('formatCode','')}
        currency_styles={str(i) for i,f in enumerate(styles.findall('s:cellXfs/s:xf',ns)) if f.get('numFmtId') in currency_ids}
        for name in archive.namelist():
            if re.fullmatch(r'xl/worksheets/sheet\d+\.xml',name):
                xml=ET.fromstring(archive.read(name))
                for cell in xml.findall('.//s:c',ns):
                    value=cell.find('s:v',ns)
                    if value is not None and cell.get('t') not in ('s','str','b','e') and value.text and cell.get('s','0') in currency_styles:
                        excel_money.append(money(value.text))
        check(collections.Counter(excel_money)==collections.Counter(expected_money), 'Actual XLSX and PDF financial cells match exactly')
    audit=fitz.open(root/f'audit-http-{unit}.pdf');audit_text=compact(fulltext(audit))
    check('@' not in audit_text and 'Pencarian:' not in audit_text and 'Filtermencocokkan' not in audit_text, 'HTTP audit removes usernames and unwanted copy')
    for item in model['audit']:
        check(item['reference'] in audit_text, 'Every selected audit transaction retained')
        for event in item['events']:
            check(compact(event['label']) in audit_text and compact(event['name']) in audit_text, 'Audit names and activities retained')
            if event['note']:
                check(compact(event['note']) in audit_text, 'Audit note complete')
            for change in event['changes']:
                check(compact(str(change['before'])) in audit_text and compact(str(change['after'])) in audit_text, 'Both snapshot values complete')
selected=fitz.open(root/'audit-selected.pdf');check(expected['selected_reference'] in compact(fulltext(selected)), 'Scoped selection PDF contains chosen transaction')
long_report=fitz.open(root/'recap-long.pdf');check('AKHIR-REKAP-PANJANG' in compact(fulltext(long_report)), 'Long recap cell complete')
for name in ['authorization-short.pdf','authorization-long.pdf','recap-350.pdf']:
    for page in fitz.open(root/name):
        for s in spans(page):
            check(s['bbox'][0]>=0 and s['bbox'][2]<=page.rect.width+.1 and s['bbox'][3]<=page.rect.height, 'PDF text not clipped outside page')
(root/'output-verification.json').write_text(json.dumps({'checks':checks},indent=2))
print(f'PASS {checks} PDF text, coordinate, ledger and Excel-model checks')
