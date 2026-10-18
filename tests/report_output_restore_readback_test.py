"""Compare actual PDF positions/fonts/text with the original renderer's output."""
import json,os,re
from pathlib import Path
import fitz

root=Path(os.environ['SPP_QA_DIR']);checks=0
def check(ok,label):
 global checks
 if not ok:raise AssertionError(label)
 checks+=1
def normalized(value):return re.sub(r'\b\d{2}/\d{2}/\d{4}\s+\d{2}:\d{2}:\d{2}\s+WIB\b','CREATED_AT',value)
def spans(page):return [s for b in page.get_text('dict')['blocks'] if 'lines' in b for l in b['lines'] for s in l['spans'] if s['text'].strip()]
for original in sorted(root.glob('baseline-*.pdf')):
 current=fitz.open(root/original.name.replace('baseline-','restored-'));baseline=fitz.open(original)
 check(len(current)==len(baseline),'Original page count '+original.name)
 for i,(a,b) in enumerate(zip(baseline,current)):
  check(a.rect==b.rect,'Original paper size '+original.name)
  aa=spans(a);bb=spans(b);check(len(aa)==len(bb),'Original span count '+original.name)
  for x,y in zip(aa,bb):
   check(normalized(x['text'])==normalized(y['text']),'Original text/order '+original.name)
   check(x['font']==y['font'] and x['size']==y['size'] and x['color']==y['color'],'Original font/size/color '+original.name)
   check(all(abs(v-w)<.03 for v,w in zip(x['bbox'],y['bbox'])),'Original coordinates '+original.name)
  check(len(a.get_drawings())==len(b.get_drawings()),'Original table/grid elements '+original.name)
check(len(list(root.glob('baseline-*.pdf')))==27,'All nine Global reports in all three palettes')
(root/'restoration-output.json').write_text(json.dumps({'checks':checks},indent=2));print('PASS',checks,'original PDF text/font/position/page/grid checks')
