"""Read-only menu/load measurements against explicitly identified private clones."""
import argparse, concurrent.futures, json, math, statistics, time, urllib.request, urllib.parse
from pathlib import Path

parser = argparse.ArgumentParser()
parser.add_argument('root', type=Path)
parser.add_argument('mode', choices=['matrix', 'primary', 'five-users', 'session'])
parser.add_argument('--config', default='load-config.json')
parser.add_argument('--samples', type=int)
parser.add_argument('--resume', action='store_true')
args = parser.parse_args()
root = args.root.resolve()
assert root.name.startswith('menu_speed_') and 'www' not in root.parts
config = json.loads((root / args.config).read_text(encoding='utf-8-sig'))
manifest = json.loads(Path(config['manifest']).read_text(encoding='utf-8-sig'))
assert manifest['isolated_test'] and manifest['port'] != 3306
assert manifest['server_uuid'] != manifest['source_server_uuid']
ports = config['ports']
assert len(ports) == 5 and len(set(ports)) == 5 and all(1024 < p < 65536 for p in ports)
sessions = json.loads((root / 'sessions.json').read_text())
for port in ports:
    identity = json.load(urllib.request.urlopen(f'http://127.0.0.1:{port}/tests/browser_clone_identity.php', timeout=15))
    assert identity['server_uuid'] == manifest['server_uuid'] and identity['port'] == manifest['port']

routes = ['dashboard.php', 'pembayaran/form.php', 'pembayaran/lihat.php',
          'pembayaran/riwayat_daftar_ulang.php', 'otorisasi_transaksi.php', 'siswa/daftar.php',
          'master_kelas.php', 'master_spp.php', 'master_biaya_lain.php', 'master_daftar_ulang.php',
          'tabungan/masuk.php', 'tabungan/keluar.php', 'tabungan/riwayat.php', 'tabungan/cetak.php',
          'laporan/index.php', 'laporan/global.php', 'laporan/surat_laporan.php',
          'role_management.php', 'backup_restore.php', 'laporan/surat_orang_tua.php']
templates = ['status', 'penerimaan', 'spp-tahunan', 'per-item', 'riwayat-tagihan',
             'tabungan-siswa', 'saldo-tabungan', 'setoran', 'kas-tabungan', 'tunggakan-siswa']
reports = ['laporan/template.php?template=' + t + '&tahun_ajaran=2026%2F2027&tanggal_awal=2026-10-01&tanggal_akhir=2026-10-27'
           for t in templates]

def request(port, path, unit=1, actor=0):
    req = urllib.request.Request(f'http://127.0.0.1:{port}/' + path,
                                 headers={'Cookie': 'PHPSESSID=' + sessions[unit][actor]})
    start = time.perf_counter()
    with urllib.request.urlopen(req, timeout=240) as response:
        body = response.read()
        assert not urllib.parse.urlsplit(response.geturl()).path.endswith('/login.php'), 'Audit session expired: ' + path
        assert response.status == 200 and b'Fatal error' not in body and b'Uncaught ' not in body, path
        assert b'alert-error report-template-alert' not in body, path
        return {'ms': round((time.perf_counter() - start) * 1000, 2),
                'php_ms': float(response.headers.get('X-Audit-Ms', 0)),
                'sql_ms': float(response.headers.get('X-Audit-Sql-Ms', 0)),
                'queries': int(response.headers.get('X-Audit-Queries', 0)), 'bytes': len(body)}

def summarize(rows):
    values = sorted(row['ms'] for row in rows)
    return {'n': len(rows), 'first_ms': rows[0]['ms'], 'median_ms': round(statistics.median(values), 2),
            'p95_ms': values[math.ceil(.95 * len(values)) - 1], 'samples': rows}

result = {'mode': args.mode, 'config': config, 'measurements': {}}
output = root / (config.get('label', 'current') + '-' + args.mode + '.json')
if args.resume and output.exists():
    saved = json.loads(output.read_text())
    assert saved['mode'] == args.mode and saved['config'] == config
    result = saved
def record(key, rows):
    result['measurements'][key] = summarize(rows)
    output.write_text(json.dumps(result, indent=2))
    print(key, 'median', result['measurements'][key]['median_ms'], 'p95', result['measurements'][key]['p95_ms'], flush=True)

if args.mode in ['matrix', 'primary']:
    selected = routes + reports if args.mode == 'matrix' else [
        'master_spp.php', 'master_daftar_ulang.php', 'pembayaran/riwayat_daftar_ulang.php',
        'laporan/global.php', reports[0], reports[4], reports[5], 'pembayaran/status_spp.php?no_induk=' + config['student_nis'] + '&bulan=10&tahun=2026&details=1']
    count = args.samples or (3 if args.mode == 'matrix' else 30)
    for unit in ([1, 2, 3, 0] if args.mode == 'matrix' else [1, 0]):
        for path in selected:
            if unit == 0 and path.startswith('pembayaran/status_spp.php'):
                continue
            key = str(unit) + '/' + path
            if args.resume and key in result['measurements']:
                continue
            record(key, [request(ports[0], path, unit) for _ in range(count)])
elif args.mode == 'five-users':
    count = args.samples or 30
    work = [(reports[0], 1), ('master_daftar_ulang.php', 2), ('pembayaran/form.php', 3),
            ('laporan/global.php', 0), ('tabungan/cetak.php', 1)]
    rows = {str(unit) + '/' + path: [] for path, unit in work}
    with concurrent.futures.ThreadPoolExecutor(max_workers=5) as pool:
        for iteration in range(count):
            futures = [pool.submit(request, ports[i], path, unit, i) for i, (path, unit) in enumerate(work)]
            for (path, unit), future in zip(work, futures):
                rows[str(unit) + '/' + path].append(future.result())
            print('Five-session round', iteration + 1, flush=True)
    for key, values in rows.items():
        record(key, values)
else:
    # Two independent PHP workers prove session contention rather than the
    # built-in HTTP server's own single-request queue.
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        heavy, navigation = [], []
        for _ in range(args.samples or 30):
            pending = pool.submit(request, ports[0], reports[4], 1, 0)
            time.sleep(.1)
            navigation.append(request(ports[1], 'laporan/global.php', 1, 0))
            heavy.append(pending.result())
        record('same-session/catalogue-during-report', navigation)
        record('same-session/report', heavy)
