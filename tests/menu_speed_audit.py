"""Read-only HTTP timing matrix against an explicitly selected audit clone server."""
import json
import os
from pathlib import Path
import statistics
import sys
import time
import urllib.request

root = Path(sys.argv[1]).resolve()
assert root.name.startswith('menu_speed_')
label = sys.argv[2]
assert label in ('baseline', 'after')
extended = len(sys.argv) > 3 and sys.argv[3] == 'extended'
suffix = '-extended-timings.json' if extended else '-timings.json'
config = json.loads((root / 'config.json').read_text())
assert config['database'].startswith('db_spp_audit_')
sessions = json.loads((root / 'sessions.json').read_text())
base = 'http://127.0.0.1:' + str(config[label + '_port'])
routes = [
    'dashboard.php', 'pembayaran/form.php', 'pembayaran/lihat.php',
    'pembayaran/riwayat_daftar_ulang.php', 'siswa/daftar.php', 'master_kelas.php',
    'master_spp.php', 'master_biaya_lain.php', 'master_daftar_ulang.php',
    'tabungan/masuk.php', 'tabungan/keluar.php', 'tabungan/riwayat.php',
    'tabungan/cetak.php', 'laporan/index.php', 'laporan/global.php',
    'laporan/surat_laporan.php',
    'laporan/template.php?template=tabungan-siswa',
    'pembayaran/lihat.php?page=2', 'tabungan/riwayat.php?page=2',
    'tabungan/masuk.php?q=Ahmad',
    'laporan/index.php?jenis_laporan%5B%5D=%2A&urut=nama',
]
if extended:
    routes = ['otorisasi_transaksi.php'] + [
        'laporan/template.php?template=' + template
        for template in ['status', 'penerimaan', 'spp-tahunan', 'per-item',
                         'saldo-tabungan', 'riwayat-tagihan', 'tunggakan-siswa',
                         'setoran', 'kas-tabungan']
    ]
results = []
for route in routes:
    samples = []
    for repeat in range(3):
        req = urllib.request.Request(base + '/' + route, headers={'Cookie': 'PHPSESSID=' + sessions['1']})
        started = time.perf_counter()
        with urllib.request.urlopen(req, timeout=120) as response:
            body = response.read()
            assert response.status == 200 and 'login.php' not in response.url, response.url
            assert not any(value in body for value in (b'Fatal error', b'Warning:', b'Uncaught')), body[-1000:]
            samples.append({'ms': round((time.perf_counter() - started) * 1000, 2),
                            'server_ms': float(response.headers.get('X-Audit-Ms', 0)),
                            'sql_ms': float(response.headers.get('X-Audit-Sql-Ms', 0)),
                            'queries': int(response.headers.get('X-Audit-Queries', 0)), 'bytes': len(body)})
    result = {'route': route, 'samples': samples, 'median_ms': statistics.median(s['ms'] for s in samples)}
    results.append(result)
    (root / (label + suffix)).write_text(json.dumps(results, indent=2))
    print(json.dumps({'route': route, 'median_ms': result['median_ms'], 'bytes': samples[-1]['bytes'], 'queries': samples[-1]['queries']}), flush=True)
(root / (label + suffix)).write_text(json.dumps(results, indent=2))
