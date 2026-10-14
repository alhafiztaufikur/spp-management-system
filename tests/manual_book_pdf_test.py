"""Verify the shipped PDF against the editable HTML (requires PyMuPDF)."""
from pathlib import Path
from html.parser import HTMLParser
import re
import fitz

root = Path(__file__).resolve().parents[1]
source = root/'documentation/manual_penggunaan_sistemspp.html'
class Manual(HTMLParser):
    def __init__(self):
        super().__init__(); self.headings={};self.pages={};self.current=None;self.toc=None;self.images=[];self.ids=[]
    def handle_starttag(self,tag,attrs):
        a=dict(attrs)
        if 'id' in a:self.ids.append(a['id'])
        if tag=='h1' and 'data-manual-heading' in a:self.current=a['data-manual-heading'];self.headings[self.current]=''
        if tag=='span' and 'data-toc' in a:self.toc=a['data-toc']
        if tag=='img':self.images.append(a['src']);assert a.get('alt')
    def handle_data(self,data):
        if self.current:self.headings[self.current]+=data
        if self.toc:self.pages[self.toc]=int(data.strip())
    def handle_endtag(self,tag):
        if tag=='h1':self.current=None
        if tag=='span':self.toc=None
m=Manual();html=source.read_text(encoding='utf-8');m.feed(html)
assert len(m.headings)==len(m.pages)==46
assert len(m.ids)==len(set(m.ids))
assert len(m.images)==26
assert all((source.parent/i).is_file() for i in m.images)
assert not re.search(r'Titipan SPP|mode Advanced',html)
assert not re.search(r'password\s*[:=]\s*\S+',html,re.I)
d=fitz.open(source.with_suffix('.pdf'))
normal=lambda s:' '.join(s.split())
for key,title in m.headings.items():
    page=m.pages[key]
    assert 1<=page<=len(d),(key,page)
    assert normal(title) in normal(d[page-1].get_text()),(key,title,page,'Daftar isi berbeda')
image_placements=0
for index,p in enumerate(d):
    assert abs(p.rect.width-595.28)<1 and abs(p.rect.height-841.89)<1
    assert len(p.get_text().split())>12,('Halaman kosong',index+1)
    assert f'{index+1} / {len(d)}' in p.get_text(),('Nomor halaman',index+1)
    image_placements+=len(p.get_image_info())
    assert not any(link.get('uri') for link in p.get_links()),('Tautan lokal pada PDF portabel',index+1)
    assert '\ufffd' not in p.get_text(),('Karakter tidak terbaca',index+1)
    for word in p.get_text('words'):
        assert word[0]>=40 and word[2]<=555 and word[1]>=36 and word[3]<=825,('Teks terpotong',index+1,word)
assert image_placements==26,('Gambar hilang/terduplikasi',image_placements)
all_text=normal(' '.join(p.get_text() for p in d))
for term in ['PULIHKAN DATABASE','IMPOR LEGACY','Backup pengaman','Terapkan ke Penerima Lain','Hapus Akun','Rekap Kas Tabungan Harian','Menempatkan siswa PSB']:
    assert term in all_text,('Isi PDF hilang',term)
print(f'PASS: PDF A4 {len(d)} halaman; 46 tujuan daftar isi sesuai; 26 gambar; seluruh teks/nomor halaman dalam batas.')
