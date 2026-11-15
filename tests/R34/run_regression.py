from pathlib import Path
import os,sys,subprocess,concurrent.futures,json,time
B=Path(__file__).resolve().parents[2];F=B.parent/'MOSH_VANILLA_FRONTEND';O=Path(os.environ.get('MOSH_QA_OUT','/mnt/data/r34_work/evidence/regression'));O.mkdir(parents=True,exist_ok=True)
wrapper="import sys;from pathlib import Path;p=Path(sys.argv[1]);sys.path.insert(0,str(p.parent));s=p.read_text().replace(\"=='R33'\",\"=='R34'\").replace(\"=='R31.1'\",\"=='R34'\").replace(\".endswith('R31.1')\",\".endswith('R34')\");exec(compile(s,str(p),'exec'),{'__file__':str(p),'__name__':'__main__'})"
auto=B/'tests/R34/php_autoload.php';auto.write_text("<?php spl_autoload_register(function($c){if(str_starts_with($c,'App\\\\Services\\\\R34\\\\')||str_starts_with($c,'App\\\\Services\\\\Social\\\\')){$p=dirname(__DIR__,2).'/app/'.str_replace('\\\\','/',substr($c,4)).'.php';if(is_file($p))require_once $p;}});")
suites=[]
for n,p in [('navigation','R33/acceptance_navigation.py'),('boot_transport','R33/boot_transport.py'),('subviews','R33/subview_sweep.py'),('task_edges','R33/task_edges.py'),('legacy_navigation','R31/rebuild_regression_navigation.py'),('legacy_hr','R31/rebuild_regression_r29.py')]:suites.append((n,[sys.executable,'-c',wrapper,str(B/'tests'/p)],B))
for p in ['R31/chunks.php','R31/security.php','R28/acceptance.php','R29/domain.php','R29/security_payroll.php','R29/instagram_regression.php']:suites.append((p.replace('/','_').replace('.php',''),['php','-d','auto_prepend_file='+str(auto),str(B/'tests'/p)],B))
suites += [('node_frontend',['node','--test',*[str(x)for x in sorted((F/'tests').glob('*.test.cjs'))]],F),('node_r29',['node','--test',str(B/'tests/R29/model.test.cjs')],B),('node_upload',['node','--test',str(B/'tests/R31/upload-model.test.cjs')],B)]
results=[]
def run(row):
 n,c,cwd=row;t=time.monotonic();env=dict(os.environ,MOSH_PROJECT=str(B.parent),MOSH_QA_OUT=str(O/n))
 try:
  r=subprocess.run(c,cwd=cwd,env=env,capture_output=True,text=True,timeout=180);code=r.returncode;text=r.stdout+'\n'+r.stderr
 except subprocess.TimeoutExpired as e:code=-1;text=(e.stdout or b'').decode(errors='replace')+'\nTIMEOUT'
 (O/(n+'.log')).write_text(text);v={'suite':n,'returncode':code,'seconds':round(time.monotonic()-t,1)};print(v,flush=True);return v
with concurrent.futures.ThreadPoolExecutor(max_workers=3)as pool:
 for v in pool.map(run,suites):results.append(v);(O/'suites.json').write_text(json.dumps(results,indent=2))
raise SystemExit(0 if all(x['returncode']==0 for x in results)else 1)
