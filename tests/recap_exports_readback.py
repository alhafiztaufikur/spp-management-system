"""Read real documents back; never posts or writes application data."""
from pathlib import Path
from decimal import Decimal
import fitz,json,re,subprocess,sys,time,urllib.request,urllib.parse,zipfile,xml.etree.ElementTree as ET
root=Path(sys.argv[1]).resolve();assert root.name.startswith('recap_refinements_')
fixture=json.loads((root/'fixture.json').read_text(encoding='utf-8'));models=json.loads((root/'export-models.json').read_text(encoding='utf-8'));base='http://127.0.0.1:58512/'
ns={'s':'http://schemas.openxmlformats.org/spreadsheetml/2006/main'};results=[]
def fetch(path,unit,name,extension):
 sessions=fixture['sessions']['super_admin'];session=sessions[int(unit)] if isinstance(sessions,list) else sessions[str(unit)]
 request=urllib.request.Request(base+path,headers={'Cookie':'PHPSESSID='+session});started=time.perf_counter()
 with urllib.request.urlopen(request,timeout=240) as response:
  data=response.read();assert response.status==200
 assert data.startswith(b'PK' if extension=='xlsx' else b'%PDF-'),name
 file=root/'artifacts'/(name+'.'+extension);file.write_bytes(data);results.append({'document':name+'.'+extension,'ms':round((time.perf_counter()-started)*1000,2),'bytes':len(data)});return file
def workbook(file,sheet=2):
 with zipfile.ZipFile(file) as z:
  shared=[]
  if 'xl/sharedStrings.xml' in z.namelist():shared=[''.join(e.itertext()) for e in ET.fromstring(z.read('xl/sharedStrings.xml')).findall('s:si',ns)]
  def rows(sheet):
   tree=ET.fromstring(z.read('xl/worksheets/sheet'+str(sheet)+'.xml'));out=[]
   for row in tree.findall('.//s:sheetData/s:row',ns):
    values={}
    for cell in row.findall('s:c',ns):
     letters=re.match('[A-Z]+',cell.attrib['r'])[0];col=0
     for char in letters:col=col*26+ord(char)-64
     value=cell.find('s:v',ns);value=value.text if value is not None else ''
     if cell.attrib.get('t')=='s':value=shared[int(value)]
     elif cell.attrib.get('t')=='inlineStr':value=''.join(cell.find('s:is',ns).itertext())
     values[col-1]=value
    out.append(values)
   return out
  return rows(sheet),shared,z.read('xl/styles.xml').decode()

def verify_sections(rows,sections,name):
 width=max(3,max(len(section['columns']) for section in sections))
 if not any(section['detail'] for section in sections):width=max(6,width)
 cursor=0;verified=0
 for section in sections:
  while cursor<len(rows) and rows[cursor].get(0)!=section['title']:cursor+=1
  assert cursor<len(rows),(name,section['title']);cursor+=1
  columns=section['columns'];positions=[int(i*width/len(columns)) for i in range(len(columns))]
  # The report title also occurs in the cover heading; locate the actual column row.
  while cursor<len(rows) and [rows[cursor].get(pos) for pos in positions]!=[column['label'] for column in columns]:cursor+=1
  assert cursor<len(rows),(name,'column headers')
  assert [rows[cursor].get(pos) for pos in positions]==[column['label'] for column in columns],section['title'];cursor+=1
  for expected in section['rows']:
   actual=rows[cursor]
   for column,pos in zip(columns,positions):
    value=expected.get(column['key']);read=actual.get(pos,'')
    if column['type'] in ('money','money_optional','number','count'):
     if value is None:assert read=='\u2014'
     else:assert Decimal(str(read))==Decimal(str(value)),(name,section['title'],column['key'])
    elif column['type'] not in ('datetime','date'):
     if isinstance(value,dict):value=str(value.get('text',''))+('\n'+str(value['sub']) if value.get('sub') else '')
     assert ('\u2014' if value is None else str(value))==str(read or ''),(section['title'],column['key'],repr(value),repr(read))
    elif expected.get('time_precision')=='none':assert read=='Tanggal dan jam tidak tercatat'
    elif value:assert Decimal(str(read))>0,'Known dates remain numeric Excel dates'
   cursor+=1;verified+=1
  if not section['rows']:cursor+=1
  for total in section['totals']:
   while cursor<len(rows) and rows[cursor].get(0)!=total['label']:cursor+=1
   assert cursor<len(rows),(name,total['label'])
   numbers=[v for c,v in rows[cursor].items() if c>0 and v!=''];assert len(numbers)==1
   assert Decimal(str(numbers[0]))==Decimal(str(total['value'])),(name,total['label']);cursor+=1
 assert verified==sum(len(s['rows']) for s in sections)
 return verified
for name,model in models.items():
 query=urllib.parse.urlencode({'jenis_laporan[]':'*','unit':'all' if model['unit']==0 else 'active','tanggal_awal':'2026-10-01','tanggal_akhir':'2026-10-21','urut':'nama','include_undated':'1' if model['undated'] else '0'})
 file=fetch('laporan/export_excel.php?'+query+'&download=1',model['unit'],name,'xlsx');rows,shared,styles=workbook(file);verified=verify_sections(rows,model['sections'],name)
 pdf=fetch('laporan/export_pdf.php?'+query+'&output=pdf',model['unit'],name,'pdf')
 text=subprocess.check_output([r'C:\laragon\bin\git\mingw64\bin\pdftotext.exe','-enc','UTF-8','-layout',str(pdf),'-'],text=True,encoding='utf-8');(root/'artifacts'/(name+'-pdf.txt')).write_text(text,encoding='utf-8');flat=' '.join(text.split())
 for section in model['sections']:assert section['title'] in flat
 for nis in {str(r['NO_INDUK']) for s in model['sections'] for r in s['rows']}:assert nis in text
 # Extraction in content order preserves a wrapped cell's words without mixing adjacent columns.
 with fitz.open(pdf) as document:content=' '.join(' '.join(page.get_text() for page in document).split())
 if model['undated']:assert content.count('Tanggal dan jam tidak tercatat')==sum(r.get('time_precision')=='none' for s in model['sections'] for r in s['rows'])
 assert 'Jam tidak tercatat' in flat,'Savings dates must not invent midnight clocks'
 print(name,'PASS',verified,'payment/debt rows and all five sections',flush=True)
# Four changed Global filters keep the existing document endpoints and data model.
for template in ['setoran','kas-tabungan','penerimaan','tabungan-siswa']:
 query=urllib.parse.urlencode({'template':template,'unit':'1','tanggal_awal':'2026-10-01','tanggal_akhir':'2026-10-21','kategori':'semua' if template=='penerimaan' else 'spp'})
 file=fetch('laporan/export_global.php?'+query+'&format=excel&download=1',1,'global-'+template,'xlsx')
 sections=json.loads((root/'export-global-models.json').read_text(encoding='utf-8'))[template];verified=verify_sections(workbook(file,1)[0],sections,template)
 pdf=fetch('laporan/export_global.php?'+query+'&format=pdf&download=1',1,'global-'+template,'pdf')
 with fitz.open(pdf) as document:content=' '.join(' '.join(page.get_text() for page in document).split());assert len(document)>0
 for section in sections:
  for total in section['totals']:
   amount=Decimal(str(total['value'])).quantize(Decimal('0.01'));whole,fraction=f'{abs(amount):.2f}'.split('.');printed=('-' if amount<0 else '')+f'{int(whole):,}'.replace(',','.')+(','+fraction if fraction!='00' else '')
   assert printed in content,(template,total['label'],'numeric PDF subtotal')
  for row in section['rows']:
   if row.get('nis'):assert str(row['nis']) in content,(template,'PDF identity')
 print(template,'PASS',verified,'rows, numeric subtotals, PDF',flush=True)
(root/'export-results.json').write_text(json.dumps({'ok':True,'documents':results},indent=2));print('PASS document download/readback matrix',len(results))
