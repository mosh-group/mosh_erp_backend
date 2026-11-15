"""R35 isolated QA runner. Requires Python + Playwright/Chromium, PHP and Node.
Never runs PHPUnit, migrations or any production database-changing command.
Legacy suites use simulated APIs; only the large-file test uses local HTTP/filesystem.
Reports go outside the project by default. Some legacy suites still create test-output screenshots.
"""
from pathlib import Path
import os,sys,subprocess,concurrent.futures,json,tempfile,time
B=Path(__file__).resolve().parents[2];F=B.parent/'MOSH_VANILLA_FRONTEND'
O=Path(os.environ.get('MOSH_QA_OUT',tempfile.mkdtemp(prefix='mosh-r35-qa-')));O.mkdir(parents=True,exist_ok=True)
suites=[]
for name,file in [('r35_interface','browser.py'),('r35_roles_races','roles_races.py')]:suites.append((name,[sys.executable,str(B/'tests/R35'/file)]))
suites.append(('r35_branding',['php',str(B/'tests/R35/branding.php')]))
for name,file in [('navigation','R33/acceptance_navigation.py'),('boot_transport','R33/boot_transport.py'),('subviews','R33/subview_sweep.py'),('task_edges','R33/task_edges.py'),('legacy_navigation','R31/rebuild_regression_navigation.py'),('legacy_hr','R31/rebuild_regression_r29.py'),('r34_features','R34/browser_features.py')]:suites.append((name,[sys.executable,str(B/'tests/R35/regression_bridge.py'),str(B/'tests'/file)]))
for file in ['R34/domain.php','R34/facebook.php','R31/chunks.php','R31/security.php','R28/acceptance.php','R29/domain.php','R29/security_payroll.php','R29/instagram_regression.php']:suites.append((file.replace('/','_').replace('.php',''),['php','-d','auto_prepend_file='+str(B/'tests/R34/php_autoload.php'),str(B/'tests'/file)]))
suites += [('node_frontend',['node','--test',*[str(p)for p in sorted((F/'tests').glob('*.test.cjs'))]]),('node_r29',['node','--test',str(B/'tests/R29/model.test.cjs')]),('node_upload',['node','--test',str(B/'tests/R31/upload-model.test.cjs')]),('node_hash',['node','--test',str(B/'tests/R34/hash.test.cjs')])]
if '--large-files' in sys.argv:suites.append(('large_http',[sys.executable,str(B/'tests/R34/large_http.py')]))
results=[]
def run(item):
 name,args=item;t=time.monotonic();env=dict(os.environ,MOSH_PROJECT=str(B.parent),MOSH_QA_OUT=str(O/name))
 try:
  r=subprocess.run(args,cwd=B,env=env,capture_output=True,text=True,timeout=300);rc=r.returncode;log=r.stdout+'\n'+r.stderr
 except subprocess.TimeoutExpired as e:rc=-1;log=str(e.stdout)+'\nTIMEOUT'
 (O/(name+'.log')).write_text(log);result={'suite':name,'returncode':rc,'seconds':round(time.monotonic()-t,1)};print(result,flush=True);return result
with concurrent.futures.ThreadPoolExecutor(max_workers=3)as pool:
 for result in pool.map(run,suites):results.append(result);(O/'summary.json').write_text(json.dumps(results,indent=2))
print('QA results:',O)
raise SystemExit(0 if all(x['returncode']==0 for x in results)else 1)
