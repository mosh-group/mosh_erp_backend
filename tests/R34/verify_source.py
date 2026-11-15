"""Read-only static and source preservation checks. Does not initialize Laravel or write a database."""
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor
import os,sys,json,hashlib,subprocess,sqlite3,re,urllib.parse
B=Path(__file__).resolve().parents[2];P=B.parent;F=P/'MOSH_VANILLA_FRONTEND';O=Path(os.environ.get('MOSH_QA_OUT','/mnt/data/r34_work/evidence/final-static'));O.mkdir(parents=True,exist_ok=True)
manifest=Path(os.environ.get('MOSH_SOURCE_HASHES','/mnt/data/r34_work/source_manifest.json'));source=json.loads(manifest.read_text());sha=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
checks=[]
def ck(n,v,**kw):checks.append({'name':n,'passed':bool(v),**kw});print(('PASS 'if v else'FAIL ')+n,flush=True)
files=[p for p in P.rglob('*')if p.is_file()];current={str(p.relative_to(P)):sha(p)for p in files}
missing=[k for k in source if k not in current];changed=[k for k,v in source.items()if current.get(k)!=v];added=[k for k in current if k not in source]
def protected(k):
 return k.startswith(('MOSH_LARAVEL_BACKEND/vendor/','MOSH_LARAVEL_BACKEND/storage/','MOSH_LARAVEL_BACKEND/database/'))or Path(k).name in ['.env','.env.example','config.js','composer.json','composer.lock']
protected_keys=[k for k in source if protected(k)];bad=[k for k in protected_keys if current.get(k)!=source[k]]
ck('All R33 source files retained',not missing,count=len(source),missing=missing);ck('Protected source data/settings/libraries byte-identical',not bad,count=len(protected_keys),differences=bad)
php=[p for p in B.rglob('*.php')if not any(x in p.parts for x in ['vendor','storage','bootstrap','database'])]+[B/'bootstrap/app.php',B/'config/mosh.php'];php=list(dict.fromkeys(php))
js=[p for p in (F/'js').glob('*.js')]
def lint(row):cmd,p=row;r=subprocess.run(cmd+[str(p)],capture_output=True,text=True);return {'file':str(p.relative_to(P)),'ok':r.returncode==0,'output':(r.stdout+r.stderr)if r.returncode else''}
with ThreadPoolExecutor(max_workers=5)as pool:out=list(pool.map(lint,[(['php','-l'],p)for p in php]+[(['node','--check'],p)for p in js]))
ck('PHP syntax',all(a['ok']for a in out[:len(php)]),count=len(php));ck('JavaScript syntax',all(a['ok']for a in out[len(php):]),count=len(js))
html=(F/'index.html').read_text();inline=[]
for attrs,code in re.findall(r'<script\b([^>]*)>([\s\S]*?)</script>',html,re.I):
 if 'src='in attrs or not code.strip()or 'application/json'in attrs:continue
 r=subprocess.run(['node','--check','-'],input=code,text=True,capture_output=True);inline.append({'ok':r.returncode==0,'error':r.stderr})
ck('Index inline scripts syntax',all(a['ok']for a in inline),count=len(inline))
api=json.loads((F/'api-map.json').read_text());paths=api.get('scripts',[]);ck('Manifest scripts unique',len(paths)==len(set(paths)),count=len(paths));refs=list(paths)
refs+=re.findall(r'(?:src|href)=["\']([^"\']+)["\']',html)
local=[x for x in refs if not x.startswith(('http:','https:','data:','#','//'))];bad_refs=[x for x in local if not(F/urllib.parse.urlsplit(x).path.lstrip('/')).is_file()]
ck('All local entrypoint assets exist',not bad_refs,count=len(local),missing=bad_refs)
for p in B.rglob('*.sqlite'):
 con=sqlite3.connect(p.resolve().as_uri()+'?mode=ro',uri=True);result=con.execute('PRAGMA quick_check').fetchall();con.close();ck('SQLite read-only integrity '+str(p.relative_to(B)),result==[('ok',)])
# Explicitly record actual boot blocker. Never ignore/change vendor's platform check.
r=subprocess.run(['php','artisan','--version'],cwd=B,capture_output=True,text=True);(O/'laravel_boot.txt').write_text(r.stdout+r.stderr)
report={'checks':checks,'passed':sum(a['passed']for a in checks),'total':len(checks),'phpFiles':len(php),'jsFiles':len(js),'syntaxResults':out,'inline':inline,'sourceFiles':len(source),'sourceMissing':missing,'sourceChanged':changed,'added':added,'protectedFiles':len(protected_keys),'protectedChanged':bad,'laravelBoot':{'returncode':r.returncode,'completed':r.returncode==0,'evidence':'laravel_boot.txt'},'sourceArchiveSha256':'6c7566cd313aeb856f82270c801e2dd02fdfc27686236537d0bf43e6c1c83cba'}
(O/'verification.json').write_text(json.dumps(report,ensure_ascii=False,indent=2));(O/'current_hashes.json').write_text(json.dumps(current,indent=2));print('Laravel bootstrap return code',r.returncode,flush=True)
raise SystemExit(0 if all(a['passed']for a in checks) else 1)
