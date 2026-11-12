"""Check page geometry, intact headers, output-specific labels and summary placement."""
import os,json,re,pathlib,fitz
root=pathlib.Path(os.environ['SPP_UI_OUTPUT']).resolve()
assert 'backups/spp-management-system' in root.as_posix() and (root/'model-reconciliation.json').exists()
results=[]
models=json.loads((root/'model-reconciliation.json').read_text())
for name in ['riwayat-tagihan','penerimaan','spp-tahunan','per-item','status','tabungan-siswa','saldo-tabungan']:
 for suffix in ['', '-excel']:
  path=root/(name+suffix+'.pdf')
  if not path.exists():
   assert suffix,(path,'Missing required report PDF')
   continue
  doc=fitz.open(path);text='\n'.join(p.get_text()for p in doc)
  assert len(doc)>0 and not re.search(r'Fatal error|Warning:|#{4}',text),(path,'Invalid output')
  if name!='status' or suffix=='':assert not re.search(r'seluruh hasil filter',text,re.I),(path,'Unwanted subtotal label')
  if name=='penerimaan':assert not re.search(r'Biaya Lain|Total Penerimaan',text,re.I),(path,'Receipt labels')
  if name=='spp-tahunan':assert not re.search(r'Belum\s+Diketahui|Belum\s+Bayar|Lunas',text,re.I),(path,'Monthly status labels')
  if name=='tabungan-siswa':assert not re.search(r'Penyesuaian|Keterangan|Legacy\s*[·.]',text,re.I),(path,'Hidden savings fields')
  if name=='riwayat-tagihan':
   grand=re.search(r'Total Tunggakan Keseluruhan[^\n]*',text);assert grand and 'belum diketahui' not in grand[0],(path,'Grand total')
  if name in ['penerimaan','spp-tahunan','per-item','tabungan-siswa','saldo-tabungan']:
   pages=[i for i,p in enumerate(doc)if 'Total Rupiah' in p.get_text()];assert len(pages)==1,(path,'Summary title')
   page=doc[pages[0]]
   title=page.search_for('Total Rupiah')[0]
   numeric=[w for w in page.get_text('words')if w[1]>=title.y1-.5 and w[3]<page.rect.height-40 and w[0]>=title.x0-1 and re.fullmatch(r'-?\d[\d.,]*',w[4])]
   right=max((w[2]for w in numeric),default=0)
   amounts=[w for w in numeric if abs(w[2]-right)<2]
   prior=[w for w in page.get_text('words')if w[3]<title.y0 and re.fullmatch(r'-?\d[\d.,]*',w[4])]
   table_right=max((w[2]for w in prior),default=right)
   assert right>=table_right-3 and right-title.x0<=275,(path,'Summary alignment/width',title,right,table_right)
   expected=next(m['totals']for m in models if m['unit']==1 and m['template']==name)
   expected=[t for t in expected if not(name=='tabungan-siswa'and t.get('key')=='penyesuaian')]
   assert len(amounts)>=len(expected),(path,'Summary split across pages',len(amounts),len(expected))
   if not suffix:
    spans=[s for b in page.get_text('dict')['blocks']for l in b.get('lines',[])for s in l['spans']if s['text']=='Total Rupiah'];assert all(9<=s['size']<=10.1 for s in spans),(path,'Summary font size',spans)
  for i,page in enumerate(doc):
   assert abs(min(page.rect.width,page.rect.height)-595.28)<1,(path,'A4')
   assert len(page.get_text().split())>2,(path,'Blank page',i)
   for w in page.get_text('words'):assert w[0]>=15 and w[2]<=page.rect.width-14,(path,'Clipped text',i,w)
   for phrase in ['Total Tagihan','Total Bayar','Saldo Berjalan','Tercakup PSB']:
    for box in page.search_for(phrase):
     # A wrapped phrase yields multiple rectangles whose text does not contain both words.
     content=page.get_textbox(fitz.Rect(box.x0-.5,box.y0-.5,box.x1+.5,box.y1+.5))
     assert phrase.casefold() in content.replace('\n',' ').casefold(),(path,'Wrapped phrase',phrase,content)
  doc[-1].get_pixmap(matrix=fitz.Matrix(1.2,1.2)).save(root/(name+suffix+'-last.png'))
  results.append({'report':name+suffix,'pages':len(doc),'A4':True,'summary':True,'textFits':True})
(root/'presentation-pdf-results.json').write_text(json.dumps(results,indent=2))
print('PASS',len(results),'PDFs,',sum(r['pages']for r in results),'pages: labels, full headers, A4, summary position and intact text.')
