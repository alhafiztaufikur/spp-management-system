"""Real-data HTTP and document readback; requires the private audit HTTP config."""
import io
import json
from decimal import Decimal
from pathlib import Path
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
import zipfile

root = Path(sys.argv[1]).resolve()
assert any(name in str(root) for name in ["import_audit_20261020","ui_time_audit_20261021"])
config = json.loads((root / "http.json").read_text())
assert config["base"] == "http://127.0.0.1:58361"
results, failures = [], []


def request(unit, route, expected=200):
    started = time.perf_counter()
    session = config["sessions"][str(unit)] if isinstance(config["sessions"], dict) else config["sessions"][unit]
    req = urllib.request.Request(config["base"] + "/" + route, headers={"Cookie": "PHPSESSID=" + session})
    try:
        response = urllib.request.urlopen(req, timeout=240)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        body, code, headers, url = response.read(), response.status, dict(response.headers), response.url
    ms = round((time.perf_counter() - started) * 1000)
    results.append({"unit": unit, "route": route, "status": code, "ms": ms, "bytes": len(body), "timing": headers.get("Server-Timing")})
    assert code == expected, (code, route, body[:500])
    assert not any(marker in body for marker in [b"Fatal error:", b"Warning:", b"Parse error:", b"Uncaught "]), (route, body[-1000:])
    assert "login.php" not in url, "Unexpected login redirect"
    if route.startswith("laporan/template.php"):
        assert len(body)>1000 and b"Gagal memuat laporan" not in body, "Report model failed behind an HTTP 200 response"
    return body


def check(label, action):
    try:
        action()
        print("PASS", label, flush=True)
    except Exception as error:
        failures.append({"case": label, "error": str(error)})
        print("FAIL", label, str(error)[:300], flush=True)
    (root / "http-results.json").write_text(json.dumps({"results": results, "failures": failures}, indent=2))


pages = ["pembayaran/form.php", "pembayaran/lihat.php?tanggal_awal=2022-01-01&tanggal_akhir=2026-12-31",
         "pembayaran/riwayat_daftar_ulang.php", "siswa/daftar.php", "master_kelas.php", "master_spp.php",
         "master_biaya_lain.php", "master_daftar_ulang.php", "tabungan/masuk.php", "tabungan/keluar.php",
         "tabungan/riwayat.php?all_periods=1", "tabungan/cetak.php", "laporan/index.php", "laporan/global.php",
         "laporan/surat_laporan.php", "laporan/surat_orang_tua.php"]
for unit in [1, 2, 3, 0]:
    for route in pages:
        check(f"Unit {unit} {route}", lambda u=unit, r=route: request(u, r))
    for template in ["status", "penerimaan", "spp-tahunan", "per-item", "tabungan-siswa", "saldo-tabungan", "riwayat-tagihan", "setoran", "kas-tabungan", "tunggakan-siswa"]:
        route = "laporan/template.php?" + urllib.parse.urlencode({"template": template, "unit": "all" if unit == 0 else unit, "tanggal_awal": "2026-10-16", "tanggal_akhir": "2026-10-16", "tahun_ajaran": "2026/2027"})
        check(f"Unit {unit} template {template}", lambda u=unit, r=route: request(u, r))


fixture = json.loads((root / "http-fixture.json").read_text())
adam = fixture["adam"]
def student_history():
    data=json.loads(request(1, "pembayaran/history_siswa.php?" + urllib.parse.urlencode({"no_induk": adam["NO_INDUK"], "student_id": adam["id"], "unit_id": 1, "scope": "all"})))
    assert data['ok'] and len(data['rows'])==54
check("Adam JSON history contains 54 payments",student_history)


def archive_references(unit):
    import csv
    coverage=json.loads((root / 'reference-coverage.json').read_text())[str(unit)]
    route='legacy_history.php?'+urllib.parse.urlencode({'id':coverage['batch_id'],'kind':'all','format':'csv'})
    records=list(csv.reader(io.StringIO(request(unit,route).decode('utf-8-sig'))))[1:]
    assert len(records)==coverage['total']
    with (root/f'artifacts/source-references-{unit}.csv').open(encoding='utf-8',newline='') as source:
        assert records==list(csv.reader(source))[1:], 'Indexed archive differs from the exhaustive source readback'
    negatives=sum(r[1]=='bayar_du' and Decimal(str(json.loads(r[4]).get('jumlah',0)))<0 for r in records)
    zero=sum(r[1] in ['transaksi_m','transaksi_k'] and all(Decimal(str(json.loads(r[4]).get(k) or 0))==0 for k in ['MASUK','KELUAR']) for r in records)
    assert negatives==[0,3,8,3][unit] and zero==[0,3,2,2][unit]
    request(2 if unit==1 else 1,route,expected=409)
for unit in [1,2,3]:
    check(f'Unit {unit} restored source references and access isolation',lambda u=unit:archive_references(u))


def read_xlsx(data):
    assert data.startswith(b"PK")
    with zipfile.ZipFile(io.BytesIO(data)) as archive:
        ns = {"s": "http://schemas.openxmlformats.org/spreadsheetml/2006/main"}
        shared = []
        if "xl/sharedStrings.xml" in archive.namelist():
            shared = ["".join(s.itertext()) for s in ET.fromstring(archive.read("xl/sharedStrings.xml")).findall("s:si", ns)]
        rows = []
        for row in ET.fromstring(archive.read("xl/worksheets/sheet1.xml")).findall("s:sheetData/s:row", ns):
            values = []
            for cell in row.findall("s:c", ns):
                text = cell.findtext("s:v", "", ns)
                if cell.attrib.get("t") == "s": text = shared[int(text)]
                elif cell.attrib.get("t") == "inlineStr": text = "".join(cell.find("s:is", ns).itertext())
                values.append(text)
            rows.append(values)
        return rows


def payment_export():
    data = request(0, "pembayaran/export_history.php?format=xlsx&tanggal_awal=2022-01-01&tanggal_akhir=2026-12-31")
    (root / "artifacts/payments-2022-2026.xlsx").write_bytes(data)
    rows = read_xlsx(data)
    assert len(rows) - 1 == 49678, len(rows)
    assert sum(Decimal(row[5]) for row in rows[1:]) == Decimal(fixture["payment_total_2022_2026"])


check("Full 2022–2026 payment Excel: count and exact amount", payment_export)
for unit in [1, 2, 3]:
    for route, name in [("pembayaran/riwayat_daftar_ulang.php?output=excel", "registration"),
                        ("tabungan/export_history.php?format=xlsx&tanggal_awal=2026-10-01&tanggal_akhir=2026-10-20", "savings")]:
        def export(u=unit, r=route, n=name):
            data = request(u, r)
            (root / f"artifacts/{n}-{u}.xlsx").write_bytes(data)
            assert len(read_xlsx(data)) > 1
        check(f"Unit {unit} {name} Excel readback", export)
    def receipt(u=unit):
        import fitz
        record = fixture["units"][str(u)]
        route = "laporan/cetak_struk.php?" + urllib.parse.urlencode({"id": -1000000000-int(record["payment_id"]), "output": "pdf"})
        data = request(u, route)
        (root / f"artifacts/payment-receipt-{u}.pdf").write_bytes(data)
        with fitz.open(stream=data, filetype="pdf") as document:
            text = "".join(page.get_text() for page in document)
            assert record["payment_name"].split()[0].casefold() in text.casefold()
    check(f"Unit {unit} payment PDF readback", receipt)

print(json.dumps({"requests": len(results), "failures": failures}, indent=2), flush=True)
sys.exit(bool(failures))
