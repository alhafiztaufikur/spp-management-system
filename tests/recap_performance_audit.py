"""Read-only paired navigation and section latency on explicitly owned audit servers."""
from pathlib import Path
import json, math, statistics, sys, time, urllib.parse, urllib.request

root=Path(sys.argv[1]).resolve()
assert root.name.startswith('recap_refinements_')
config=json.loads((root/'config.json').read_text())
assert config['database'].startswith('db_spp_audit_')
fixture=json.loads((root/'fixture.json').read_text())
sessions=fixture['sessions']['super_admin']
def request(port,path,unit=1):
    session=sessions[unit] if isinstance(sessions,list) else sessions[str(unit)]
    req=urllib.request.Request(f'http://127.0.0.1:{port}/'+path,headers={'Cookie':'PHPSESSID='+session})
    start=time.perf_counter()
    with urllib.request.urlopen(req,timeout=90) as response:
        body=response.read()
        assert response.status==200 and b'Fatal error' not in body
        return {'http_ms':round((time.perf_counter()-start)*1000,2),'php_ms':float(response.headers['X-Audit-Ms']),
                'sql_ms':float(response.headers['X-Audit-Sql-Ms']),'queries':int(response.headers['X-Audit-Queries']),'bytes':len(body)}
def summarize(samples):
    values=sorted(sample['http_ms'] for sample in samples)
    return {'n':len(samples),'first_ms':samples[0]['http_ms'],'median_ms':round(statistics.median(values),2),
            'p95_ms':values[math.ceil(.95*len(values))-1],'samples':samples}
query={'jenis_laporan[]':'*','tanggal_awal':'2026-10-01','tanggal_akhir':'2026-10-21','urut':'nama','per_page':'10'}
report='laporan/index.php?'+urllib.parse.urlencode(query)
routes=[report,'master_daftar_ulang.php','master_biaya_lain.php','master_kelas.php?show_participants=1&source_level=1',
        'tabungan/masuk.php','tabungan/riwayat.php','tabungan/cetak.php',
        *['laporan/template.php?template='+template+'&tanggal_awal=2026-10-01&tanggal_akhir=2026-10-21' for template in ['setoran','kas-tabungan','tabungan-siswa','penerimaan']]]
results={'navigation':{},'sections':{}}
for path in routes:
    samples={'baseline':[],'after':[]}
    for _ in range(5):
        for label,port in [('baseline',config['baseline_port']),('after',config['port'])]:samples[label].append(request(port,path))
    results['navigation'][path]={label:summarize(rows) for label,rows in samples.items()}
    print('navigation',path.split('?')[0],*[label+':'+str(summarize(rows)['median_ms']) for label,rows in samples.items()],flush=True)
for unit in [1,0]:
    for section in ['semua','belum_spp','belum_komite','belum_du','belum_biaya_lain']:
        params=query|{'fragment':'section','section':section,'section_page['+section+']':'2'}
        path='laporan/index.php?'+urllib.parse.urlencode(params)
        samples=[request(config['port'],path,unit) for _ in range(20)]
        results['sections'][str(unit)+'/'+section]=summarize(samples)
        print('section',unit,section,'median',statistics.median(s['http_ms'] for s in samples),'p95',results['sections'][str(unit)+'/'+section]['p95_ms'],flush=True)
(root/'performance-final.json').write_text(json.dumps(results,indent=2))
