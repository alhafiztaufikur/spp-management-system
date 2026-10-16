"""Read generated QA PDFs; never connects to a database or printer."""
import os
import re
from pathlib import Path

import fitz

root = Path(os.environ["SPP_OPERATIONS_QA"]).resolve()
assert not root.is_relative_to(Path.cwd().resolve())
checks = pages = 0
files = list((root / "print").glob("*.pdf")) + list((root / "exports").glob("[0-3]-*.pdf")) + list((root / "exports").glob("general-*.pdf"))
for file in files:
    document = fitz.open(file)
    receipt = file.name.startswith("receipt-") or file.name == "general-1.pdf"
    book = file.name.startswith("book-")
    expected = (612, 396) if receipt else (419.53, 595.28) if book else None
    for page in document:
        pages += 1
        size = (page.rect.width, page.rect.height)
        if expected:
            assert all(abs(a - b) < 1 for a, b in zip(size, expected)), file.name
        else:
            assert min(abs(size[0] - 595.28) + abs(size[1] - 841.89), abs(size[1] - 595.28) + abs(size[0] - 841.89)) < 2, file.name
        text = page.get_text()
        assert "\ufffd" not in text, file.name
        for word in page.get_text("words"):
            assert word[0] >= -1 and word[1] >= -1 and word[2] <= size[0] + 1 and word[3] <= size[1] + 1, (file.name, page.number, word[4])
        checks += 1
    if receipt and "receipt-" in file.name:
        text = "\n".join(p.get_text() for p in document)
        if file.stem.endswith("normal"):
            assert len(document) == 1
            assert "445.000" in text and "0000123456" in text
        if file.stem.endswith("annual"):
            assert len(document) == 12
        if file.stem.endswith("long"):
            assert len(document) > 1
            for i in range(1, 36):
                assert re.search(r"nomor\s+" + str(i) + r"\b", text), (file.name, i)
            assert "JUMLAH TOTAL" in document[-1].get_text()
            assert all("0000123456" in p.get_text() for p in document), file.name
            assert all("JUMLAH TOTAL" not in p.get_text() for p in list(document)[:-1]), file.name
        checks += 1
    if file.name in ["letter-parent.pdf", "letter-principal-10.pdf"]:
        assert len(document) == 1
        assert "Hormat kami" in document[0].get_text()
        checks += 1
print(f"PASS {checks} PDF checks across {pages} pages: paper size, complete receipt details, repeated identities, final totals, letter closing and text bounds")
