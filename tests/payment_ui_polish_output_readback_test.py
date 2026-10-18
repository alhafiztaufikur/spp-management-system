"""Read private export artifacts; never connects to a school database."""
import os
import struct
import zipfile
from pathlib import Path

import fitz

root = Path(os.environ["SPP_POLISH_ROOT"])
checks = 0
for engine in ["chrome", "msedge"]:
    for unit in [1, 2, 3]:
        for kind in ["native", "browser"]:
            file = root / "outputs" / f"{kind}-{engine}-{unit}.pdf"
            with fitz.open(file) as pdf:
                assert len(pdf) == 1, (file, len(pdf))
                text = pdf[0].get_text()
                assert all(s in text for s in ["Sisa PSB", "Sisa DU", "Buku Paket", "135.000", "Jam Bayar:", "SUPER ADMIN"]), file
                assert "Sisa Komite" not in text and "terkini" not in text, file
                assert pdf[0].get_fonts(), file
                assert abs(pdf[0].rect.width - 612) < 1 and abs(pdf[0].rect.height - 396) < 1, file
                for block in pdf[0].get_text("blocks"):
                    assert block[0] >= 0 and block[1] >= 0 and block[2] <= 613 and block[3] <= 397, (file, block[:4])
                checks += 1
    with fitz.open(root / "outputs" / f"{engine}-annual.pdf") as pdf:
        assert len(pdf) == 12 and all("JUMLAH TOTAL" in p.get_text() for p in pdf)
    assert struct.unpack(">II", (root / "outputs" / f"{engine}-receipt.png").read_bytes()[16:24]) == (2550, 1650)
    with zipfile.ZipFile(root / "outputs" / f"{engine}-annual.zip") as archive:
        assert len(archive.namelist()) == 12
        for name in archive.namelist():
            assert struct.unpack(">II", archive.read(name)[16:24]) == (2550, 1650)
    checks += 3

for name, pages in [("normal", 1), ("annual", 12), ("long", None), ("no-nis", 1), ("long-words", None)]:
    with fitz.open(root / "receipt-fixtures" / f"receipt-{name}.pdf") as pdf:
        assert len(pdf) == pages if pages else len(pdf) >= 1
        text = " ".join(" ".join(page.get_text() for page in pdf).split())
        assert "Sisa Komite" not in text
        if name == "no-nis":
            assert "ADMIN" in text and "NIS Diknas" not in text
        if name == "long-words":
            assert "rupiah" in text and "Sistem Pembayaran:" in text and "PETUGAS UJI" in text
        if name == "long":
            for number in range(1, 36):
                assert f"Biaya tambahan nomor {number} dengan" in text, number
        for page in pdf:
            for block in page.get_text("blocks"):
                assert block[0] >= 0 and block[1] >= 0 and block[2] <= 613 and block[3] <= 397, (name, block[:4])
    checks += 1

print(f"PASS {checks} document readback checks.")
