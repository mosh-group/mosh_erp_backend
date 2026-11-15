#!/usr/bin/env python3
"""Run focused release/regression suites in a TEMPORARY complete copy.
Does NOT bootstrap Laravel or connect to the configured business API/database.
Requirements: Python, playwright+Chromium, bs4, requests, PHP CLI, Node.
Run only from a trusted local copy; never expose test files on a public web root.
"""
from pathlib import Path
import argparse,os,subprocess,json,tempfile,shutil,time,sys
p=argparse.ArgumentParser();p.add_argument('--source',type=Path,default=Path(__file__).resolve().parents[3]);p.add_argument('--output',type=Path,required=True);args=p.parse_args()
source=args.source.resolve();output=args.output.resolve();output.mkdir(parents=True,exist_ok=True)
if source==output or source in output.parents:
 sys.exit('Choose an output folder outside the original project tree.')
for name in ['MOSH_LARAVEL_BACKEND','MOSH_VANILLA_FRONTEND']:
 if not (source/name).is_dir():sys.exit('Missing complete project directory: '+name)
results=[]
with tempfile.TemporaryDirectory(prefix='mosh-r33-isolated-') as tmp:
 root=Path(tmp)
 for name in ['MOSH_LARAVEL_BACKEND','MOSH_VANILLA_FRONTEND']:shutil.copytree(source/name,root/name)
 backend=root/'MOSH_LARAVEL_BACKEND';frontend=root/'MOSH_VANILLA_FRONTEND'
 for name in ['rebuild_regression_navigation.py','rebuild_regression_r29.py']:
  path=backend/'tests/R31'/name
  s=path.read_text().replace("=='R31.1'","=='R33'").replace(".endswith('R31.1')",".endswith('R33')");path.write_text(s)
 suites=[('navigation', [sys.executable,'tests/R33/acceptance_navigation.py'],backend),('boot_transport',[sys.executable,'tests/R33/boot_transport.py'],backend),('subviews',[sys.executable,'tests/R33/subview_sweep.py'],backend),('task_edges',[sys.executable,'tests/R33/task_edges.py'],backend),
 ('php_upload_chunks',['php','tests/R31/chunks.php'],backend),('php_it_security',['php','tests/R31/security.php'],backend),('php_r28_acceptance',['php','tests/R28/acceptance.php'],backend),('php_r29_domain',['php','tests/R29/domain.php'],backend),('php_r29_security_payroll',['php','tests/R29/security_payroll.php'],backend),('php_instagram_regression',['php','tests/R29/instagram_regression.php'],backend),
 ('node_frontend',['node','--test',*[str(x) for x in sorted((frontend/'tests').glob('*.test.cjs'))]],frontend),('node_r29_model',['node','--test','tests/R29/model.test.cjs'],backend),('node_upload_model',['node','--test','tests/R31/upload-model.test.cjs'],backend),
 ('browser_it_upload',[sys.executable,'tests/R31/browser_it_upload.py'],backend),('browser_navigation_legacy',[sys.executable,'tests/R31/rebuild_regression_navigation.py'],backend),('browser_hr_customers_legacy',[sys.executable,'tests/R31/rebuild_regression_r29.py'],backend)]
 for name,command,cwd in suites:
  started=time.monotonic();env=dict(os.environ,MOSH_PROJECT=str(root),MOSH_QA_OUT=str(output/name))
  try:
   r=subprocess.run(command,cwd=cwd,env=env,capture_output=True,text=True,timeout=180);code=r.returncode;log=r.stdout+'\n'+r.stderr
  except (OSError,subprocess.TimeoutExpired) as error:code=-1;log=str(error)
  (output/(name+'.log')).write_text(log,encoding='utf-8');results.append({'suite':name,'returncode':code,'seconds':round(time.monotonic()-started,2)})
  (output/'suites.json').write_text(json.dumps(results,indent=2));print(name,code,flush=True)
 # Preserve synthetic screenshots/logs only; no DB, environment or vendor exports from tests.
 for name,rel in [('upload-http','tests/R31/integration-output')]:
  src=backend/rel
  if src.is_dir():shutil.copytree(src,output/name,dirs_exist_ok=True)
raise SystemExit(0 if all(r['returncode']==0 for r in results) else 1)
