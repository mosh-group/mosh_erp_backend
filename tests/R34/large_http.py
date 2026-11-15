"""Actual local PHP HTTP + filesystem transfer, no Laravel authentication/SQL/production writes."""
from pathlib import Path
import os,subprocess,tempfile,secrets,time,requests,json,hashlib,base64,socket
B=Path(__file__).resolve().parents[2];O=Path(os.environ.get('MOSH_QA_OUT','/mnt/data/r34_work/evidence/large-http'));O.mkdir(parents=True,exist_ok=True)
checks=[]
def ck(n,v):checks.append({'name':n,'passed':bool(v)});print(('PASS 'if v else'FAIL ')+n,flush=True)
with tempfile.TemporaryDirectory(prefix='mosh-r34-http-')as root:
 token=secrets.token_hex(24)
 with socket.socket()as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
 env=dict(os.environ,MOSH_QA_ROOT=root,MOSH_QA_TOKEN=token);log=open(O/'php.log','w')
 proc=subprocess.Popen(['php','-d','post_max_size=1M','-d','upload_max_filesize=1M','-S',f'127.0.0.1:{port}',str(B/'tests/R31/http_router.php')],env=env,stdout=log,stderr=subprocess.STDOUT)
 try:
  sess=requests.Session();sess.headers.update({'X-QA-Token':token,'X-QA-Owner':'QA-LARGE'});base=f'http://127.0.0.1:{port}'
  for _ in range(70):
   try:
    if sess.get(base+'/health',timeout=1).ok:break
   except requests.RequestException:time.sleep(.1)
  part=b'MOSH original bytes\x00\xff\x81'+bytes(range(256))*1024;part=(part*((262144//len(part))+1))[:262144]
  for parts in [132,1025]:
   total=parts*262144;sha=hashlib.sha256()
   for _ in range(parts):sha.update(part)
   meta={'key':'QA-large-'+str(total),'name':'original-'+str(total)+'.psd','kind':'task-chat','size':total,'sha256':sha.hexdigest()}
   r=sess.post(base+'/files-upload/start',json=meta,timeout=10);ck('Start accepts '+str(total)+' bytes',r.ok)
   data=r.json()['data'];id=data['id'];payload={'base64':base64.b64encode(part).decode()}
   for i in range(parts):
    r=sess.put(base+f'/files-upload/{id}/parts/{i}',json=payload,timeout=20)
    if not r.ok:raise RuntimeError(f'part {i}: {r.text}')
    if i==1:
     rr=sess.put(base+f'/files-upload/{id}/parts/{i}',json=payload,timeout=20);ck('Identical part response retry safe '+str(total),rr.ok)
    if i==parts//2:
     resume=sess.post(base+'/files-upload/start',json=meta,timeout=10).json()['data'];ck('Resume retains accepted parts '+str(total),len(resume['received'])==i+1)
   r=sess.post(base+f'/files-upload/{id}/complete',json={},timeout=90);ck('Actual HTTP completes '+str(total)+' bytes',r.ok)
   done=r.json()['data'];files=list(Path(root).rglob('*'));match=[p for p in files if p.is_file()and p.stat().st_size==total]
   ck('Original hash preserved on disk '+str(total),bool(match)and all(hashlib.file_digest(open(p,'rb'),'sha256').hexdigest()==meta['sha256'] for p in match))
   again=sess.post(base+f'/files-upload/{id}/complete',json={},timeout=10).json()['data'];ck('Lost complete response retry same object '+str(total),again==done)
   wrong=sess.post(base+f'/files-upload/{id}/complete',json={},headers={'X-QA-Owner':'OTHER'},timeout=10);ck('Another owner cannot access upload '+str(total),not wrong.ok)
   ck('Transient parts removed after finalization '+str(total),not list(Path(root).rglob(id+'/*.part')))
  ck('Transfer exceeded old 999-part route shape',parts>999)
 finally:proc.terminate();proc.wait(timeout=10);log.close()
(O/'results.json').write_text(json.dumps({'passed':sum(x['passed']for x in checks),'total':len(checks),'checks':checks,'boundary':'Real PHP local HTTP and filesystem. QA router, not Laravel session/SQL.'},indent=2))
raise SystemExit(0 if all(x['passed']for x in checks)else 1)
