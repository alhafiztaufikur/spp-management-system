"""Reopen exported PNG/ZIP and compare each page with an independent PDF rasterizer."""
from pathlib import Path
import json
import subprocess
import sys
import zipfile
from pypdf import PdfReader
from PIL import Image, ImageChops, ImageStat

qa=Path(sys.argv[1]).resolve()
poppler=Path(sys.argv[2]).resolve()
artifacts=qa/'artifacts'; checks=[]
def check(ok,label):
    assert ok,label
    checks.append(label)
    print('PASS',label,flush=True)
for key in ['single_draft','multi_draft']:
    pdf=PdfReader(artifacts/(key+'.pdf'))
    text='\n'.join(page.extract_text() or '' for page in pdf.pages)
    check(('Pesan khusus' in text if key=='single_draft' else 'Pesan penerima pertama.' in text and 'Pesan penerima kedua.' in text),key+': customized text exists in source PDF')
    images=[]
    if key=='single_draft': images=[Image.open(artifacts/(key+'.png'))]
    else:
        with zipfile.ZipFile(artifacts/(key+'.zip')) as z:
            names=sorted(z.namelist())
            check(all(name.endswith('.png') and '/' not in name for name in names),'ZIP contains only ordered PNG page files')
            for name in names:
                import io
                images.append(Image.open(io.BytesIO(z.read(name))))
    check(len(images)==len(pdf.pages),key+': every PDF page exported exactly once')
    subprocess.run([str(poppler),'-r','150','-png',str(artifacts/(key+'.pdf')),str(artifacts/(key+'-reference'))],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.PIPE)
    references=sorted(artifacts.glob(key+'-reference-*.png'))
    for i,(image,reference) in enumerate(zip(images,references)):
        check(abs(image.width-2480)<=2 and abs(image.height-3508)<=2,f'{key} page {i+1}: A4 at 300 DPI')
        check(image.convert('RGB').getpixel((0,0))==(255,255,255),f'{key} page {i+1}: white background')
        independent=Image.open(reference).convert('RGB')
        normalized=image.convert('RGB').resize(independent.size,Image.Resampling.LANCZOS)
        difference=sum(ImageStat.Stat(ImageChops.difference(normalized,independent)).mean)/3
        check(difference<8,f'{key} page {i+1}: PDF image comparison within rasterizer tolerance ({difference:.2f}/255)')
        normalized.save(artifacts/(key+f'-preview-{i+1}.png'))
(qa/'png-readback.json').write_text(json.dumps({'checks':len(checks),'passed':checks},indent=2))
