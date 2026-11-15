"""Real complete browser code + actual ChunkStorage HTTP/filesystem.
IT persistence/task SQL/session are synthetic fixtures, never company records."""
from pathlib import Path
import sys,os,json,copy,subprocess,tempfile,secrets,time,hashlib,base64,shutil
from types import SimpleNamespace
from urllib.parse import urlsplit
import requests
from playwright.sync_api import sync_playwright
from bs4 import BeautifulSoup
B=Path(__file__).resolve().parents[2];F=B.parent/'MOSH_VANILLA_FRONTEND';OUT=Path(__file__).resolve().parent/'integration-output';OUT.mkdir(exist_ok=True)
ns={'__file__':str(B/'tests/R29/browser_acceptance.py')};exec(compile((B/'tests/R29/browser_acceptance.py').read_text().split('class Handler(')[0],'r29-fixture','exec'),ns)
state,boot,user,manifest,oldhandle=[ns[k] for k in ['state','boot','user','manifest','handle']]
task={'id':'T31','title':'اختبار مرفقات IT','department':'تكنولوجيا المعلومات','status':'in_progress','employeeState':'received','assigneeId':'E1','assignee':'مدير الاختبار','customer':'عميل اختبار','workChat':[],'attachments':[],'_version':1}
state['salesTasks']=[task];state['departmentManagers']['تكنولوجيا المعلومات']='E1'
state['staffProfiles'][0]['department']='تكنولوجيا المعلومات';boot['state']=state
it={'today':'2026-10-19','employees':[dict(id=p['id'],name=p['name'],active=True,department=p['department']) for p in state['staffProfiles']], 'stockAssets':[], 'assets':[], 'accounts':[], 'subscriptions':[], 'alerts':[], 'permissions':{k:True for k in ['create','edit','assign','details','attachments','manageAccounts','viewSecrets','audit']}}
root=tempfile.mkdtemp(prefix='mosh-r31-http-');token=secrets.token_hex(24);env=dict(os.environ,MOSH_QA_ROOT=root,MOSH_QA_TOKEN=token);port=8931
log=open(OUT/'php-http.log','w');proc=subprocess.Popen(['php','-d','post_max_size=1M','-d','upload_max_filesize=1M','-S',f'127.0.0.1:{port}',str(B/'tests/R31/http_router.php')],stdout=log,stderr=subprocess.STDOUT,env=env)
headers={'X-QA-Token':token,'X-QA-Owner':'QA-U1'};base=f'http://127.0.0.1:{port}';checks=[];calls=[];errors=[];idempotency={};drop_next_part=[False];drop_task_response=[False];deny_tasks=[False];part_replays=[0]
def ck(n,v):checks.append(dict(name=n,passed=bool(v)));print(('PASS ' if v else 'FAIL ')+n,flush=True)
for _ in range(50):
 try:
  if requests.get(base+'/health',headers=headers,timeout=1).ok:break
 except Exception:time.sleep(.1)
probe=requests.post(base+'/probe-multipart',headers=headers,files={'file':('qa.jpg',b'x'*(5*1024*1024),'image/jpeg')},timeout=10).json()
ck('Real PHP 1 MB post limit rejects whole 5 MB multipart',not probe['data']['files'])
def request(path,opts=None):
 global task
 opts=opts or {};method=opts.get('method','GET');v=opts.get('body') or {};calls.append((method,path,copy.deepcopy(v)))
 if path.startswith('/files-upload/'):
  r=requests.request(method,base+path,json=v,headers=headers,timeout=20);z=r.json()
  if drop_next_part[0] and '/parts/' in path and r.ok:drop_next_part[0]=False;part_replays[0]+=1;return {'error':'QA lost part response','network':True,'status':0}
  return {'value':z.get('data')} if r.ok else {'error':z.get('error','QA error'),'status':r.status_code}
 if path=='/it/overview':return {'value':copy.deepcopy(it)}
 if path.startswith('/it/records/'):
  parts=path.strip('/').split('/');kind=parts[2]
  if method=='GET':return {'value':copy.deepcopy(it[kind])}
  key=v['requestId']
  if key in idempotency:return {'value':copy.deepcopy(idempotency[key])}
  if len(parts)==3:
   prior=next((x for x in it[kind] if x['id']==v['id']),None)
   if int(v['_version'])!=int((prior or {}).get('_version',0)):return {'error':'تغير السجل','status':409}
   result={**v,'_version':v['_version']+1,'history':(prior or {}).get('history',[])+[{'action':'update' if prior else 'create','by':'QA','at':'2026-10-19','reason':v.get('notes','')}], 'hasSecret':False}
   result.pop('requestId',None)
   if prior:it[kind].remove(prior)
   it[kind].append(result)
  else:
   result=next(x for x in it[kind] if x['id']==parts[3]);result['_version']+=1
   if v['action']=='archive':result['status']='archived'
   elif v['action']=='restore':result['status']='available' if kind=='assets' else 'active'
   elif v['action']=='renew':result['expiresOn']=v['expiresOn'];result['renewals']=result.get('renewals',[])+[v]
   elif v['action']=='maintenance':result['maintenance']=result.get('maintenance',[])+[v];result['status']='maintenance' if v['status']=='open' else 'available'
  idempotency[key]=copy.deepcopy(result);return {'value':copy.deepcopy(result)}
 if path=='/tasks/T31':
  if method=='GET':return {'value':copy.deepcopy(task)}
  if deny_tasks[0]:return {'error':'QA permission denied','status':403}
  key=opts.get('headers',{}).get('Idempotency-Key','')
  if key in idempotency:return {'value':copy.deepcopy(idempotency[key])}
  if v['_version']!=task['_version']:return {'error':'QA stale task','status':409}
  task.update(v);task['_version']+=1;idempotency[key]=copy.deepcopy(task)
  if drop_task_response[0]:drop_task_response[0]=False;return {'error':'QA lost task response','network':True,'status':0}
  return {'value':copy.deepcopy(task)}
 if path.startswith('/files/'):
  return {'value':{}}
 result={}
 req=SimpleNamespace(url='https://qa.invalid/api'+path,method=method,post_data=json.dumps(v),post_data_json=v,headers={'content-type':'application/json'})
 oldhandle(SimpleNamespace(request=req,fulfill=lambda **kw:result.update(json.loads(kw['body']))))
 return {'value':result.get('data')}
try:
 with sync_playwright() as pw:
  browser=pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox']);context=browser.new_context(viewport={'width':1440,'height':1000},locale='ar',timezone_id='Asia/Damascus');context.route('**/*',lambda r:r.abort());page=context.new_page();page.set_default_timeout(12000);page.on('pageerror',lambda e:errors.append(str(e)));page.on('dialog',lambda d:d.accept())
  soup=BeautifulSoup((F/'index.html').read_text(),'html.parser')
  for s in soup.find_all('script',src=True):s.decompose()
  for l in soup.find_all('link',rel='stylesheet'):
   p=F/l.get('href','').split('?')[0]
   if p.exists():s=soup.new_tag('style');s.string=p.read_text();l.replace_with(s)
   else:l.decompose()
  page.expose_function('qaRequest',request);page.set_content(str(soup),wait_until='domcontentloaded',timeout=15000)
  page.evaluate("""() => {for(const key of ['localStorage','sessionStorage']){const m=new Map();Object.defineProperty(window,key,{value:{getItem:k=>m.get(k)||null,setItem:(k,v)=>m.set(k,String(v)),removeItem:k=>m.delete(k),clear:()=>m.clear(),key:i=>[...m.keys()][i]||null,get length(){return m.size;}}});}window.API_BASE_URL='https://qa.invalid/api';}""")
  page.add_script_tag(content=(F/'js/api-client.js').read_text());page.evaluate("""d=>{MoshApi.request=async(p,o={})=>{const r=await qaRequest(p,o);if(r.error){const e=new Error(r.error);e.status=r.status;e.network=r.network;throw e;}return r.value;};MoshApi.status={authenticated:true,setupRequired:false};MoshApi.initialState=d.state;MoshApi.user=d.user;MoshApi.resources=d.resources;MoshApi.settingPaths=d.settingPaths;MoshApi.settings=d.settings;MoshApi.auth={version:2,users:d.users,roles:d.roles,security:d.security,audit:[]};}""",copy.deepcopy(boot))
  for s in manifest['scripts']:page.add_script_tag(content=(F/s.split('?')[0]).read_text())
  page.evaluate("MoshApi.initialize();document.dispatchEvent(new CustomEvent('mosh:r19-ready'));MoshBoot9.finish();");page.wait_for_timeout(800)
  def close():page.evaluate("document.querySelectorAll('dialog[open]').forEach(d=>MoshUI19.close(d.id,true))")
  def go(tab):page.locator(f'#it31Tabs [data-id="{tab}"]').click();page.wait_for_timeout(150)
  try:
   page.locator('#it31Nav').click();page.wait_for_selector('#itApp.active #it31Tabs button')
   ck('Real styled IT department navigation opens',page.locator('#itApp.active').count()==1 and page.locator('#it31Nav svg').count()==1)
   ck('IT department offers five native tabs',page.locator('#it31Tabs button').count()==5)
   ck('IT is in real employee/task department options',page.evaluate("salesTaskDepartments.includes('تكنولوجيا المعلومات') && !!PRODUCTIVE_DEPARTMENTS.it"))
   ck('Two independent vault permission labels in user panel source','viewITSecrets' in (F/'js/43-mosh-auth-v37-js.js').read_text() and 'manageITAccounts' in (F/'js/43-mosh-auth-v37-js.js').read_text())
   page.screenshot(path=str(OUT/'it-dashboard.png'),timeout=30000)
   go('assets');page.locator('#it31Body [data-it31=new]').click();page.locator('#it31Edit [name=name]').fill('حاسوب اختبار');page.locator('#it31Edit [name=code]').fill('QA-001');page.locator('#it31Edit [name=serial]').fill('TEST-SERIAL-31');page.locator('#it31Edit button[type=submit]').click();page.wait_for_selector('#it31Edit',state='detached');page.wait_for_timeout(200)
   ck('Equipment create receives confirmed server ID/version',len(it['assets'])==1 and it['assets'][0]['_version']==1)
   ck('Equipment form sends idempotency request ID',any(v.get('requestId','').startswith('it31-') for m,p,v in calls if p=='/it/records/assets'))
   page.locator('#it31Body [data-it31=edit]').first.click();page.locator('#it31Edit [name=employeeId]').select_option('E2');page.locator('#it31Edit [name=status]').select_option('assigned');page.locator('#it31Edit button[type=submit]').click();page.wait_for_selector('#it31Edit',state='detached');ck('Equipment assignment form sends employee and expected version',it['assets'][0]['employeeId']=='E2' and it['assets'][0]['_version']==2)
   page.locator('#it31Body [data-it31=details]').first.click();page.wait_for_selector('#it31Detail[open]');ck('Device details open with history',page.locator('#it31Detail').get_by_text('سجل الإجراءات',exact=True).count()==1)
   close();go('accounts');page.locator('#it31Body [data-it31=new]').click();page.locator('#it31Edit [name=name]').fill('إيميل شركة — اختبار');page.locator('#it31Edit [name=service]').fill('بريد الشركة');page.locator('#it31Edit [name=username]').fill('qa@example.invalid')
   ck('Password storage fields disabled on HTTP/nonsecure page',page.locator('#it31Edit [name=vaultPassword]').is_disabled())
   ck('Editing metadata never pre-fills passwords',page.locator('#it31Edit [name=vaultPassword]').input_value()=='')
   page.locator('#it31Edit button[type=submit]').click();page.wait_for_selector('#it31Edit',state='detached');ck('Company account metadata saves without credentials',len(it['accounts'])==1 and 'secret' not in it['accounts'][0])
   go('subscriptions');page.locator('#it31Body [data-it31=new]').click();page.locator('#it31Edit [name=name]').fill('ترخيص تصميم — اختبار');page.locator('#it31Edit [name=service]').fill('خدمة تجريبية');page.locator('#it31Edit [name=expiresOn]').fill('2026-11-01');page.locator('#it31Edit [name=amount]').fill('25.50');page.locator('#it31Edit button[type=submit]').click();page.wait_for_selector('#it31Edit',state='detached');ck('Subscription forms persist billing/reminder/account data',len(it['subscriptions'])==1 and it['subscriptions'][0]['reminderDays']==14)
   page.locator('#it31Body [data-it31=details]').first.click();page.locator('#it31Detail [data-it31=renew]').click();page.locator('#it31Action [name=reason]').fill('تجديد اختبار فقط');page.locator('#it31Action [name=expiresOn]').fill('2027-11-01');page.locator('#it31Action button[type=submit]').click();page.wait_for_selector('#it31Action',state='detached');ck('Renewal action distinct from metadata edit',it['subscriptions'][0]['expiresOn']=='2027-11-01' and len(it['subscriptions'][0]['renewals'])==1)
   close();go('accounts');page.locator('#it31Body [data-it31=archive]').click();page.locator('#it31Action [name=reason]').fill('أرشفة سجل اختبار');page.locator('#it31Action button[type=submit]').click();page.wait_for_selector('#it31Action',state='detached');page.locator('#it31Body [data-it31=archive-list]').click();ck('Archive keeps account and history',len(it['accounts'])==1 and it['accounts'][0]['status']=='archived');page.locator('#it31Body [data-it31=restore]').click();page.locator('#it31Action [name=reason]').fill('إعادة تفعيل نفس السجل');page.locator('#it31Action button[type=submit]').click();page.wait_for_selector('#it31Action',state='detached');ck('Restore reuses same account ID',len(it['accounts'])==1 and it['accounts'][0]['status']=='active')
   go('tasks');ck('IT uses existing task workspace and manager mapping',page.locator('#itTaskList').get_by_text('اختبار مرفقات IT',exact=True).count()>0 and page.evaluate("getDepartmentManager('تكنولوجيا المعلومات')?.id==='E1'"))
   # Actual chat uploader, authentic JS hashing/parts/commit. Synthetic task service.
   page.evaluate("""() => {const d=document.createElement('div');d.id='qa-chat';d.className='task-running-chat';d.innerHTML='<textarea id="taskChatInput_T31"></textarea>';document.body.appendChild(d);window.qaProgress=[];document.addEventListener('mosh:upload-progress',e=>qaProgress.push(e.detail));}""")
   drop_next_part[0]=True;drop_task_response[0]=True
   sizes=[1024,256*1024+19,5*1024*1024]
   page.evaluate("""async sizes => {window.qaFiles=sizes.map((n,i)=>{const b=new Uint8Array(n);for(let k=0;k<n;k++)b[k]=(k+i)%251;return new File([b],'QA_'+i+'.JPG',{type:'image/jpeg',lastModified:1000+i});});await addTaskChatMedia('T31',qaFiles);}""",sizes)
   ck('Multiple varied-size JPGs saved to real filesystem',len(list((Path(root)/'complete').glob('*.json')))==3)
   ck('Task gets exactly three confirmed file links/chat records',len(task['attachments'])==3 and len(task['workChat'])==3)
   ck('5 MB SHA256 matches all original browser bytes',all(json.loads(p.read_text())['sha256']==hashlib.sha256(bytes((k+i)%251 for k in range(sizes[i]))).hexdigest() for p in (Path(root)/'complete').glob('*.json') for i in range(3) if json.loads(p.read_text())['name']==f'QA_{i}.JPG'))
   ck('Lost chunk reply retried without corrupting payload',part_replays[0]==1 and len(task['attachments'])==3)
   ck('Lost task reply reuses idempotency and creates no duplicate messages',len(task['workChat'])==3 and len([v for m,p,v in calls if p=='/tasks/T31' and m=='PATCH'])==2)
   ck('Upload progress reaches server-confirmed completion',page.evaluate("qaProgress.filter(x=>x.percent===100).length") ==3)
   await_reload=page.evaluate("(async()=>{await MoshApi.refresh();return state.salesTasks.find(x=>x.id==='T31').attachments.length;})()")
   ck('Confirmed attachments remain after actual frontend refresh',await_reload==3)
   # Denied task save must not imply attachment published in chat; retry then succeeds.
   deny_tasks[0]=True;page.evaluate("(async()=>{await addTaskChatMedia('T31',[new File(['QA retry'],'RETRY.txt',{type:'text/plain',lastModified:999})]);})()")
   ck('Denied task commit never displays phantom success',len(task['attachments'])==3 and page.locator('#qa-chat [data-upload31].error').count()==1)
   deny_tasks[0]=False;page.locator('#qa-chat [data-upload31] button').click();page.wait_for_function("state.salesTasks.find(x=>x.id==='T31').attachments.length===4")
   ck('Retry attaches the already-uploaded file once',len(task['attachments'])==4 and len(list((Path(root)/'complete').glob('*.json')))==4)
   invalid=page.evaluate("(async()=>{try{await MoshApi.upload({key:'BAD',name:'bad.php',blob:new Blob(['bad'])});return '';}catch(e){return e.message;}})()")
   ck('Forbidden extensions rejected by actual backend chunk rules',bool(invalid))
   huge=page.evaluate("(async()=>{try{await MoshApi.upload({key:'BIG',name:'huge.zip',blob:new Blob([new Uint8Array(25*1024*1024+1)])});return '';}catch(e){return e.message;}})()")
   ck('Client blocks files above configured 25 MB',bool(huge))
   # Native uploader owner boundary cannot read another session.
   raw=b'QA owner';s=requests.post(base+'/files-upload/start',headers=headers,json={'key':'QA-OWNER','name':'owner.txt','kind':'test','size':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}).json()['data']
   other=requests.put(base+'/files-upload/'+s['id']+'/parts/0',headers={**headers,'X-QA-Owner':'QA-U2'},json={'base64':base64.b64encode(raw).decode()})
   ck('Chunk session cannot be used by another test owner',other.status_code==422)
   page.evaluate("document.querySelector('#qa-chat').remove();goApp('it');");page.wait_for_timeout(300);go('assets');page.evaluate("MoshTheme21.apply('light')");page.screenshot(path=str(OUT/'it-equipment-light.png'),timeout=30000)
   page.set_viewport_size({'width':390,'height':844});page.wait_for_timeout(200);ck('IT workspace avoids horizontal page overflow on mobile',page.locator('#itApp').evaluate('(e)=>e.scrollWidth<=e.clientWidth+2'))
   it['permissions'].update(manageAccounts=False,viewSecrets=False);page.evaluate("MoshIT31.load()");page.wait_for_timeout(200);go('accounts');ck('Account write controls hidden without independent grant',page.locator('#it31Body [data-it31=new]').count()==0 and page.locator('#it31Body [data-it31=edit]').count()==0)
   page.evaluate("MoshApi.user=null;document.dispatchEvent(new CustomEvent('mosh:logout-complete'));");page.wait_for_timeout(300);ck('Logout clears IT data and pending uploads',page.locator('dialog[id^=it31],[data-upload31]').count()==0 and page.locator('#it31Body').inner_text()=='')
   ck('Actual new scripts have no uncaught browser exceptions',not errors)
  except Exception as e:
   ck('Harness completed',False);checks[-1]['error']=str(e);print('EXCEPTION',e,flush=True)
   try:page.screenshot(path=str(OUT/'failure.png'),timeout=30000)
   except Exception:pass
  browser.close()
finally:
 proc.terminate();proc.wait(timeout=10);log.close();shutil.rmtree(root,ignore_errors=True)
report={'checks':checks,'passed':sum(x['passed'] for x in checks),'total':len(checks),'pageErrors':errors,'httpBoundary':'Actual PHP CLI server at loopback, 1 MB POST limit and production ChunkStorage; not Laravel/SQL/session/CSRF/FileController. Browser full HTML/JS/CSS in memory, proxy to real test HTTP for binary protocol; IT/task records explicitly mocked.','calls':len(calls)};(OUT/'report.json').write_text(json.dumps(report,ensure_ascii=False,indent=2));print(json.dumps(report,ensure_ascii=False,indent=2));sys.exit(0 if report['passed']==report['total'] else 1)
