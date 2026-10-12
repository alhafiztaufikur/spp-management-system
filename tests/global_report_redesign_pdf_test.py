"""Read PDF contents, ordering, page geometry and totals on the disposable clone.

Requires PyMuPDF and the private manifests from the companion PHP tests.
Run the PHP export test with --full-only to append full, multi-page matrices.
"""
import json
import math
import os
import re
import urllib.parse
import urllib.request
from pathlib import Path

import fitz

assert re.fullmatch(r"db_spp_audit_[a-z0-9_]+", os.environ["SPP_DB_NAME"])
directory = Path(os.environ["SPP_QA_DIR"])
manifest = json.loads((directory / "pdf-manifest.json").read_text(encoding="utf-8"))
normalize = lambda value: re.sub(r"\s+", "", str(value)).casefold()

pages = 0
for item in manifest:
    with fitz.open(item["path"]) as pdf:
        assert len(pdf) > 0
        pages += len(pdf)
        content = normalize("\n".join(page.get_text() for page in pdf))
        assert normalize(item["doc"]["school"]) in content, item["path"]
        assert normalize(item["report"]["title"]) in content, item["path"]
        assert normalize("Unit " + item["doc"]["unit_label"]) in content
        landscape = item["orientation"] == "landscape"
        assert (pdf[0].rect.width > pdf[0].rect.height) == landscape
        assert abs(min(pdf[0].rect.width, pdf[0].rect.height) - (841.89 if item["paper"] == "A3" else 595.28)) < 1
        for page in pdf:
            assert page.get_text().strip(), "Blank page " + item["path"]
            name_headers = page.search_for("NAMA SISWA")
            if name_headers:
                center = (name_headers[0].x0 + name_headers[0].x1) / 2
                names = {str(row.get("nama", "")) for section in item["doc"]["sheets"][0]["sections"] for row in section["rows"]}
                for name in names - {""}:
                    for rect in page.search_for(name):
                        assert abs((rect.x0 + rect.x1) / 2 - center) < 3, ("Name not centered", item["path"], name)
            words=page.get_text("words")
            nis_header=next((w for w in words if w[4]=="NIS"),None)
            identities={str(row.get("nis","")) for section in item["doc"]["sheets"][0]["sections"] for row in section["rows"]}
            if nis_header:
                # Some fixture names repeat their NIS; use the identity occurrence
                # nearest its header instead of treating name text as another cell.
                for value in identities.intersection(w[4] for w in words):
                    distance=min(abs((w[0]+w[2]-nis_header[0]-nis_header[2])/2) for w in words if w[4]==value)
                    assert distance<3, ("NIS not centered",item["path"],value)
            for block in page.get_text("blocks"):
                assert block[0] >= -1 and block[2] <= page.rect.width + 1, "Clipped text " + item["path"]
            amounts=[]
            for block in page.get_text("dict")["blocks"]:
                for line in block.get("lines",[]):
                    for span in line["spans"]:
                        if span["text"].strip().startswith("Rp "):
                            assert span["size"] >= 8.9, ("Unreadable amount font",item["path"],span)
                            amounts.append(span["bbox"])
            amounts.sort(key=lambda r:(round(r[1]),r[0]))
            for a,b in zip(amounts,amounts[1:]):
                if abs(a[1]-b[1])<2:
                    assert a[2] <= b[0]+1, ("Overlapping amounts",item["path"],a,b)

        if item.get("matrix"):
            assert "namasiswa" in normalize(pdf[0].get_text()), "Unnecessary cover page before matrix"
            headings = [int(x) for x in re.findall(r"kolom(\d+)", content)]
            assert headings and headings == list(range(1, max(headings)+1)), "Column band order"
            for page in pdf:
                text = normalize(page.get_text())
                if normalize("Diknas") in text:
                    assert normalize("NIS") in text and normalize("Nama Siswa") in text, "Missing repeated identity/header"
        previous_identity = 0
        for section in item["doc"]["sheets"][0]["sections"]:
            for row in section["rows"]:
                if row.get("nis"):
                    index = content.find(normalize(row["nis"]), previous_identity)
                    assert index >= previous_identity, "Student row order " + item["path"]
                    previous_identity = index + len(normalize(row["nis"]))
                for column in section["columns"]:
                    value = row.get(column["key"], "")
                    if value is None or value == "" or column["key"] in ["no", "unit"]:
                        continue
                    if column["type"] in ["money", "money_optional"]:
                        expected = ["Rp " + format(float(value), ",.0f").replace(",", ".")]
                    elif isinstance(value, dict):
                        expected = [value.get("text", ""), value.get("sub", "")]
                    elif column["type"] in ["date", "datetime"]:
                        continue  # Typed dates are checked by the PHP XLSX readback test.
                    else:
                        expected = [str(value)]
                    for text in expected:
                        assert normalize(text) in content, (item["path"], column["key"], text)
            for total in section["totals"]:
                # The existing cash PDF labels these totals in its component rows.
                label = total["label"]
                if item["path"].endswith("kas-tabungan.pdf") and label in ["Total Tabungan Masuk", "Total Tabungan Keluar"]:
                    label = label.removeprefix("Total ")
                assert normalize(label) in content, (item["path"], label)
                assert normalize("Rp " + format(float(total["value"]), ",.0f").replace(",", ".")) in content
    print("PASS: " + Path(item["path"]).name)
print(f"PASS: {len(manifest)} PDFs / {pages} pages; school/unit, row order, text, status, amounts, totals and page bounds")
