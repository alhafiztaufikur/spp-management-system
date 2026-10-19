"""Read exported artifacts only; no database mutations."""
import os, struct, zipfile
from pathlib import Path
import fitz
root=Path(os.environ["SPP_SAVINGS_QA"])/"outputs"
checks=0
for engine in ["chrome","msedge"]:
 for unit in [1,2,3]:
  for kind in ["native","browser"]:
   file=root/f"{kind}-{engine}-{unit}.pdf"
   with fitz.open(file) as pdf:
    assert len(pdf)==1, (file,len(pdf))
    text=pdf[0].get_text()
    assert all(word in text for word in ["Uang Daftar Ulang","Buku Paket","135.000","Sistem Pembayaran:","Jam Bayar:"]),file
    assert "terkini" not in text,file
    assert len(pdf[0].get_fonts())>0,file
    assert abs(pdf[0].rect.width-612)<1 and abs(pdf[0].rect.height-396)<1,file
    for block in pdf[0].get_text("blocks"):
     assert block[0]>=0 and block[1]>=0 and block[2]<=613 and block[3]<=397,(file,block[:4])
    checks+=1
 with fitz.open(root/f"{engine}-annual.pdf") as pdf:
  assert len(pdf)==12
  assert all("JUMLAH TOTAL" in p.get_text() for p in pdf)
  checks+=1
 assert struct.unpack(">II",(root/f"{engine}-receipt.png").read_bytes()[16:24])==(2550,1650)
 with zipfile.ZipFile(root/f"{engine}-annual.zip") as z:
  assert len(z.namelist())==12
  for name in z.namelist():assert struct.unpack(">II",z.read(name)[16:24])==(2550,1650)
  checks+=1
for name,count in [("normal",1),("annual",12),("long",None)]:
 with fitz.open(root/f"receipt-{name}.pdf") as pdf:
  assert len(pdf)==count if count else len(pdf)>1
  if name=="long":
   text=" ".join(" ".join(p.get_text() for p in pdf).split())
   for i in range(1,36):assert f"Biaya tambahan nomor {i} dengan" in text,i
  checks+=1
result=f"PASS: {checks} exported document checks; all normal slips one page, annual 12 pages, PNG 2550x1650, long items retained."
(root.parent/"output-readback-result.txt").write_text(result)
print(result)
