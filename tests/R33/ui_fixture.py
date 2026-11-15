from pathlib import Path
import json,copy,os
from types import SimpleNamespace
from bs4 import BeautifulSoup
from urllib.parse import urlsplit
ROOT=Path(os.environ.get('MOSH_PROJECT',str(Path(__file__).resolve().parents[3])))
B=ROOT/'MOSH_LARAVEL_BACKEND'; F=ROOT/'MOSH_VANILLA_FRONTEND'
ns={'__file__':str(B/'tests/R29/browser_acceptance.py')}
exec(compile((B/'tests/R29/browser_acceptance.py').read_text().split('class Handler(')[0],'fixture','exec'),ns)
state,boot,user,manifest,oldhandle=[ns[k] for k in ['state','boot','user','manifest','handle']]
depts={'admin':'الإدارة','hr':'الموارد البشرية','sales':'المبيعات','marketing':'التسويق','design':'التصميم','photo':'التصوير','editing':'المونتاج','promotion':'الترويج','logistics':'اللوجستيات','purchases':'المشتريات','warehouses':'المستودعات','accounting':'المحاسبة','platform':'MOSH MEDIA','it':'تكنولوجيا المعلومات'}
state['staffProfiles'] += [dict(state['staffProfiles'][1],id='E_'+k,name='موظف اختبار '+v,employeeNo='QA-'+k,department=v) for k,v in depts.items()]
state['salesTasks']=[{'id':'T_'+k,'title':'مهمة اختبار '+v,'customer':'عميل تجريبي أول','customerId':'C1','department':v,'assigneeId':'E_'+k,'assignee':'موظف اختبار '+v,'status':'in_progress','employeeState':'received','isUrgent':False,'_version':1,'workChat':[],'attachments':[],'comments':[],'history':[]} for k,v in depts.items()]
state['departmentManagers']={v:'E1' for v in depts.values()};ns['mocktasks']=state['salesTasks'];boot['state']=state;boot['settings']['department-managers']['value']=copy.deepcopy(state['departmentManagers'])
calls=[]
it={'today':'2026-10-20','employees':copy.deepcopy(state['staffProfiles']),'stockAssets':[],'assets':[],'accounts':[],'subscriptions':[],'alerts':[],'permissions':{k:True for k in ['create','edit','assign','details','attachments','manageAccounts','viewSecrets','audit']}}
admin={'totals':{'working':0,'break':0,'no_work':0,'untracked':0},'pendingApprovals':0,'newReplies':0,'employees':[dict(id=p['id'],name=p['name'],department=p['department'],status='off',jobTitle=p.get('jobTitle','')) for p in state['staffProfiles']],'sourceNote':'بيانات اختبار معزولة'}
def request(path,options=None):
 opts=options or {};calls.append({'path':path,'method':opts.get('method','GET'),'body':copy.deepcopy(opts.get('body'))})
 p=path.split('?')[0]
 if p=='/r24/admin/overview':return copy.deepcopy(admin)
 if p.startswith('/r24/admin/'):
  if p.endswith('/report'):return {'rows':[],'columns':[],'filters':opts.get('body',{}),'totals':{}}
  return {'items':[],'employees':copy.deepcopy(state['staffProfiles']),'departments':list(depts.values())}
 if p=='/r29/admin/tasks':return {'tasks':[copy.deepcopy(t) for t in state['salesTasks'] if t['department']=='الإدارة']}
 if p=='/it/overview':return copy.deepcopy(it)
 if p.startswith('/it/records/'):return []
 if p.startswith('/tasks/') and opts.get('method','GET')=='GET':
  return copy.deepcopy(next((t for t in state['salesTasks'] if t['id']==p.split('/')[2]),{}))
 if p=='/r24/purchasing/inbox':return {'items':[],'visible':True,'canRequest':True,'canApprove':True,'pending':0}
 result={}
 def fulfill(**kwargs):result.update(json.loads(kwargs['body']))
 o=SimpleNamespace(url='https://qa.invalid/api'+path,method=opts.get('method','GET'),post_data=json.dumps(opts.get('body')) if opts.get('body') is not None else None,post_data_json=opts.get('body') or {},headers={'content-type':'application/json'})
 oldhandle(SimpleNamespace(request=o,fulfill=fulfill))
 return result.get('data')
def boot_page(browser):
 ctx=browser.new_context(viewport={'width':1440,'height':1000},locale='ar',timezone_id='Asia/Damascus');ctx.route('**/*',lambda r:r.abort())
 page=ctx.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)));page.on('dialog',lambda d:d.accept());page.set_default_timeout(5000)
 soup=BeautifulSoup((F/'index.html').read_text(),'html.parser')
 for script in soup.find_all('script',src=True):script.decompose()
 for link in soup.find_all('link',rel='stylesheet'):
  p=F/link.get('href','').split('?')[0]
  if p.exists():
   css=soup.new_tag('style');css.string=p.read_text();link.replace_with(css)
  else:link.decompose()
 page.expose_function('qaRequest',request)
 page.set_content(str(soup),wait_until='domcontentloaded')
 page.evaluate("""() => { for(const key of ['localStorage','sessionStorage']){const m=new Map();Object.defineProperty(window,key,{value:{getItem:k=>m.get(k)||null,setItem:(k,v)=>m.set(k,String(v)),removeItem:k=>m.delete(k),clear:()=>m.clear(),key:i=>[...m.keys()][i]||null,get length(){return m.size;}}});}window.API_BASE_URL='https://qa.invalid/api'; }""")
 page.add_script_tag(content=(F/'js/api-client.js').read_text())
 page.evaluate("""data=>{MoshApi.request=(path,options={})=>window.qaRequest(path,options);MoshApi.status={authenticated:true,setupRequired:false};MoshApi.initialState=data.state;MoshApi.user=data.user;MoshApi.resources=data.resources;MoshApi.settingPaths=data.settingPaths;MoshApi.settings=data.settings;MoshApi.auth={version:2,users:data.users,roles:data.roles,security:data.security,audit:[]};} """,copy.deepcopy(boot))
 for script in manifest['scripts']:page.add_script_tag(content=(F/script.split('?')[0]).read_text())
 page.evaluate("MoshApi.initialize();document.dispatchEvent(new CustomEvent('mosh:r19-ready'));MoshBoot9.finish();")
 page.wait_for_timeout(1500)
 return ctx,page,errors
