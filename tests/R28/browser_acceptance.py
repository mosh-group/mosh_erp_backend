from pathlib import Path
import json,copy,threading,http.server,functools,datetime,sys,time
from urllib.parse import urlsplit,parse_qs
from playwright.sync_api import sync_playwright
from bs4 import BeautifulSoup
from types import SimpleNamespace
B=Path(__file__).resolve().parents[2];F=B.parent/'MOSH_VANILLA_FRONTEND';OUT=Path(__file__).resolve().parent/'output';OUT.mkdir(exist_ok=True)
manifest=json.loads((F/'api-map.json').read_text());defs=json.loads((B/'resources/defaults.json').read_text())
state={'salesOrders':[],'operations18':{'version':1,'audit':[]},'finance21':{'version':1,'audit':[]},'finance22':{'version':1,'chart':[],'baseline':[],'audit':[]},'finance23':{'version':1},'fieldWorkSettingsV30B':{'version':'30B','initialized':True},'fieldWorkV30B':{'version':'30B'}}
def put(obj,path,value):
    keys=path.split('.')
    for key in keys[:-1]:obj=obj.setdefault(key,{})
    obj[keys[-1]]=value
for spec in manifest['resources'].values():put(state,spec['statePath'],{} if spec['mode']=='map' else [])
for key,path in manifest['settings'].items():put(state,path,{})
state['hrSettings']=copy.deepcopy(defs.get('hrSettings',{}));state['hrProSettings']=copy.deepcopy(defs.get('hrProSettings',{}))
state['staffProfiles']=[{'id':'E1','name':'مدير الاختبار','employeeNo':'T001','department':'التصميم','active':True,'employmentStatus':'active','isManager':True,'_version':1},{'id':'E2','name':'موظف الاختبار','employeeNo':'T002','department':'المونتاج','active':True,'employmentStatus':'active','_version':1}]
state['departmentManagers']={'التصوير':'E1','المونتاج':'E1'}
state['customers']=[{'id':'C1','name':'عميل تجريبي أول','_version':1,'marketingProfile':{'socialHub':{'accounts':[{'handle':'old_handle','platform':'instagram','connected':True}],'posts':[{'text':'سجل قديم','status':'published','createdAt':'2026-10-15T10:00:00Z'}]}}},{'id':'C2','name':'عميل تجريبي ثان','_version':1}]
user={'id':'U1','name':'حساب الاختبار','username':'qa','roleId':'app_programmer','employeeId':'E1','department':'التصميم','active':True,'isRoot':True,'mustChange':False,'landing':'dashboard','taskScope':'all','globalOverrides':{},'moduleOverrides':{},'_version':1}
settings={k:{'value':{},'_version':1} for k in manifest['settings']};settings['department-managers']['value']=copy.deepcopy(state['departmentManagers'])
boot={'state':state,'settings':settings,'user':user,'users':[user],'roles':defs['roles'],'security':{'minLength':8,'sessionMinutes':9999},'revision':1,'csrfToken':'qa-csrf','resources':manifest['resources'],'settingPaths':manifest['settings']}
caps={'connect':True,'compose':True,'direct':True,'approve':True,'insights':True,'configure':True}
config={'configured':True,'scheduler':{'active':True,'lastRunAt':'2026-10-17T00:00:00Z'},'appId':'123456789','hasSecret':True,'redirectUri':'https://qa.invalid/api/social/instagram/callback','publicBaseUrl':'https://qa.invalid','graphVersion':'v26.0','_version':1}
accounts=[{'id':'A1','customerId':'C1','instagramId':'123','username':'mosh_qa_only','status':'connected','expiresAt':'2026-12-17T00:00:00Z','_version':1}]
posts=[{'id':'P1','customerId':'C1','accountId':'A1','caption':'نص تجريبي بانتظار الاعتماد','mediaKind':'image','attachmentKey':'TESTJPEG','attachmentName':'test.jpg','mode':'publish','status':'pending_approval','createdAt':'2026-10-17T00:00:00Z','createdBy':'محرر الاختبار','createdById':'U2','submittedById':'U2','history':[],'_version':1}]
requests=[];blocked=[];errors=[];checks=[]
def ok(name,condition):
    checks.append({'name':name,'passed':bool(condition)});print('CHECK',name,condition,flush=True)
    if not condition:print('FAIL',name)
def handle(route):
    req=route.request;u=urlsplit(req.url);p=u.path
    if '/api/' not in p:
        if u.hostname in ['127.0.0.1','localhost']:route.continue_()
        else:blocked.append(req.url);route.abort()
        return
    path=p.split('/api',1)[1];data=req.post_data_json if req.post_data and 'json' in req.headers.get('content-type','') else {};requests.append((req.method,path,data))
    result={};status=200
    if path=='/auth/status':result={'authenticated':True,'setupRequired':False,'csrfToken':'qa-csrf'}
    elif path=='/bootstrap':result=copy.deepcopy(boot)
    elif path=='/auth/me':result={**user,'role':defs['roles'][0]}
    elif path=='/social/instagram/configuration':result=config
    elif path=='/social/instagram':
        cid=parse_qs(u.query).get('customerId',[''])[0];result={'accounts':[a for a in accounts if a['customerId']==cid],'posts':[a for a in posts if a['customerId']==cid],'capabilities':caps,'configuration':config}
    elif path=='/social/posts' and req.method=='POST':
        status=201;st='draft' if data['mode']=='draft' else ('pending_approval' if not caps['direct'] else 'reconciling')
        result={**data,'id':'P'+str(len(posts)+1),'status':st,'createdAt':'2026-10-17T00:00:00Z','createdBy':user['name'],'createdById':user['id'],'_version':1,'history':[]};posts.append(result)
    elif path.endswith('/decision'):
        row=next(x for x in posts if x['id']==path.split('/')[3]);row['status']={'approve':'reconciling','reject':'rejected','cancel':'cancelled'}[data['action']];row['_version']+=1;result=row
    elif path.endswith('/check'):result=next(x for x in posts if x['id']==path.split('/')[3])
    elif path.endswith('/insights'):result={'fetchedAt':'2026-10-17T00:00:00Z','metrics':{'views':1234,'reach':567},'unavailable':{'total_interactions':'غير متاح للاختبار'}}
    elif path=='/transactions':result={'changes':[]}
    elif path=='/hr/department-managers':
        dep=data['department'];state['departmentManagers'][dep]=data['managerId'];boot['state']['departmentManagers']=copy.deepcopy(state['departmentManagers']);settings['department-managers']['value']=copy.deepcopy(state['departmentManagers']);result={'changes':[{'resource':'settings/department-managers','id':'department-managers','data':{'value':state['departmentManagers'],'_version':2}}]}
    elif path=='/workday':result={'date':'2026-10-17','segments':[],'summary':{},'current':None}
    elif path.endswith('/capabilities'):result={'role':'admin','capabilities':{}}
    elif req.method=='GET':result=[]
    route.fulfill(status=status,content_type='application/json',body=json.dumps({'success':True,'data':result,'meta':{'total':0,'page':1,'lastPage':1}}),headers={'Access-Control-Allow-Origin':'http://127.0.0.1:8773','Access-Control-Allow-Credentials':'true'})
class Handler(http.server.SimpleHTTPRequestHandler):
    def log_message(self,*args):pass
server=http.server.ThreadingHTTPServer(('127.0.0.1',8773),functools.partial(Handler,directory=str(F)));threading.Thread(target=server.serve_forever,daemon=True).start()
with sync_playwright() as pw:
    browser=pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox']);context=browser.new_context(viewport={'width':1440,'height':1000},locale='ar',timezone_id='Asia/Damascus');context.route('**/*',handle)
    page=context.new_page();page.set_default_timeout(5000);page.on('pageerror',lambda e:errors.append(str(e)));page.on('dialog',lambda d:d.accept())
    try:
        # Network navigation is disallowed by the installed Chromium policy. Render source in memory;
        # no browser policy is changed and no real server/Meta request is made.
        soup=BeautifulSoup((F/'index.html').read_text(),'html.parser')
        for script in soup.find_all('script',src=True):script.decompose()
        for link in soup.find_all('link',rel='stylesheet'):
            path=F/link.get('href','').split('?')[0]
            if path.exists():
                css=soup.new_tag('style');css.string=path.read_text();link.replace_with(css)
            else:link.decompose()
        def in_memory_request(path,options):
            result={}
            def fulfill(**kwargs):result.update(json.loads(kwargs['body']))
            opts=options or {};request=SimpleNamespace(url='https://qa.invalid/api'+path,method=opts.get('method','GET'),post_data=json.dumps(opts.get('body')) if opts.get('body') is not None else None,post_data_json=opts.get('body') or {},headers={'content-type':'application/json'})
            handle(SimpleNamespace(request=request,fulfill=fulfill))
            return result.get('data')
        page.expose_function('qaRequest',in_memory_request)
        print('set-content',flush=True);page.set_content(str(soup),wait_until='domcontentloaded',timeout=10000);print('set-content-done',flush=True)
        page.evaluate('''() => {
            for(const key of ['localStorage','sessionStorage']){const m=new Map();Object.defineProperty(window,key,{value:{getItem:k=>m.get(k)||null,setItem:(k,v)=>m.set(k,String(v)),removeItem:k=>m.delete(k),clear:()=>m.clear(),key:i=>[...m.keys()][i]||null,get length(){return m.size;}}});}
            window.API_BASE_URL='https://qa.invalid/api';
        }''')
        page.add_script_tag(content=(F/'config.js').read_text())
        page.add_script_tag(content=(F/'js/api-client.js').read_text())
        page.evaluate('''data => { MoshApi.request=(path,options={})=>window.qaRequest(path,options);MoshApi.status={authenticated:true,setupRequired:false};MoshApi.initialState=data.state;MoshApi.user=data.user;MoshApi.resources=data.resources;MoshApi.settingPaths=data.settingPaths;MoshApi.settings=data.settings;MoshApi.auth={version:2,users:data.users,roles:data.roles,security:data.security,audit:[]}; }''',copy.deepcopy(boot))
        for script in manifest['scripts']:page.add_script_tag(content=(F/script.split('?')[0]).read_text());print('loaded',script,flush=True)
        page.evaluate("MoshApi.initialize();document.dispatchEvent(new CustomEvent('mosh:r19-ready'));MoshBoot9.finish();")
        page.wait_for_timeout(500)
        page.wait_for_function("window.MoshInstagram28 && window.MoshAuth37",timeout=15000)
        ok('Actual full frontend loads R28',True)
        page.evaluate("goApp('marketing');openMoshSocialHub('C1')")
        page.wait_for_selector('#ig28Customer',timeout=8000);page.wait_for_selector('[data-ig28="compose"]');page.wait_for_timeout(300)
        ok('CRM and social panels are not stacked',page.locator('#marketingExecV33').evaluate('(e)=>getComputedStyle(e).display')=='none')
        ok('Verified account visible',page.get_by_text('@mosh_qa_only',exact=False).count()>0)
        ok('Legacy history not counted as official published',page.get_by_text('منشور بتأكيد Instagram',exact=True).count()==0)
        ok('Manager map retains both departments',page.evaluate("getManagedDepartmentsForStaff('E1').length===2"))
        before=page.evaluate('JSON.stringify(state.departmentManagers)');page.evaluate("getDepartmentManager('التصوير');getDepartmentManager('المونتاج');getDepartmentManager('المبيعات')");ok('Manager getter does not mutate assignments',page.evaluate('JSON.stringify(state.departmentManagers)')==before)
        page.evaluate("goApp('hr');MoshHR13.go('employees','managers')")
        page.wait_for_selector('[id="hrDeptManager_التصميم"]',state='attached')
        page.evaluate("document.querySelectorAll('#hrApp details').forEach(d=>d.open=true);document.getElementById('hrDeptManager_التصميم').value='E1';window.qaManagerDone=assignDepartmentManagerFromHR('التصميم');void 0")
        page.locator('#m14Confirm [data-answer=yes]').click()
        page.wait_for_function("state.departmentManagers['التصميم']==='E1'")
        ok('Manager assignment uses dedicated server endpoint',any(path=='/hr/department-managers' for _,path,_ in requests))
        ok('Assigning third department preserves employment and both existing departments',page.evaluate("getManagedDepartmentsForStaff('E1').length===3&&getStaffProfileById('E1').department==='التصميم'"))
        page.evaluate('MoshApi.refresh()');page.wait_for_function("state.departmentManagers['التصميم']==='E1'");page.wait_for_timeout(100)
        ok('All three assignments survive actual frontend refresh with synthetic server data',page.evaluate("getManagedDepartmentsForStaff('E1').length===3"))
        ok('Scoped manager can access both additional departments',page.evaluate("(()=>{const u={id:'LIMITED',roleId:'role_manager',employeeId:'E1',department:'التصميم',active:true,moduleOverrides:{},globalOverrides:{}};return MoshAuth37.canModule('photo','view',u)&&MoshAuth37.canModule('editing','view',u);})()"))
        ok('Explicit module denial overrides added manager scope',page.evaluate("!MoshAuth37.canModule('editing','view',{roleId:'role_manager',employeeId:'E1',department:'التصميم',active:true,moduleOverrides:{editing:{view:false}}})"))
        page.evaluate("goApp('marketing');openMoshSocialHub('C1')");page.wait_for_selector('[data-ig28=compose]')
        page.screenshot(path=str(OUT/'r28-social-dark.png'),full_page=True)
        page.locator('[data-ig28="compose"]').click();page.locator('#ig28Caption').fill('مسودة اختبار — لا اتصال خارجي')
        page.mouse.click(5,5);ok('Backdrop click does not close composer',page.locator('#ig28Compose').evaluate('(d)=>d.open'))
        page.locator('[data-save-mode="draft"]').click();page.wait_for_function("!document.getElementById('ig28Compose').open",timeout=5000)
        ok('Draft saved with server response',any(p['status']=='draft' for p in posts))
        ok('Draft has idempotency key',any(d.get('requestId') for m,p,d in requests if p=='/social/posts'))
        page.locator('[data-ig28="approve"]').click();page.locator('[data-ig28="confirm-decision"]').click();page.wait_for_function("!document.getElementById('ig28Decision').open")
        ok('Ambiguous server response is not shown as published',page.get_by_text('جارٍ التحقق من النشر',exact=True).count()>0 and page.get_by_text('منشور بتأكيد Instagram',exact=True).count()==0)
        page.locator('[data-ig28="insights"]').click();page.wait_for_selector('#ig28Metrics .ig28-kpis');ok('Unavailable metric displays dash', '—' in page.locator('#ig28Metrics').inner_text());page.evaluate("MoshUI19.close('ig28Insights',true)")
        page.locator('[data-ig28="config"]').click();page.wait_for_selector('#ig28Secret');ok('App secret never returned to browser',page.locator('#ig28Secret').input_value()=='');page.evaluate("MoshUI19.close('ig28Config',true)")
        caps['direct']=False;caps['approve']=False;caps['configure']=False
        page.evaluate('MoshInstagram28.refresh()');page.wait_for_selector('[data-ig28="compose"]');page.wait_for_timeout(100)
        page.locator('[data-ig28="compose"]').click();page.locator('#ig28Caption').fill('يحتاج موافقة');page.locator('[data-save-mode="publish"]').click();ok('Text-only publish is blocked client-side','اختر حساباً' in page.locator('[data-ig28-error]:visible').inner_text() or 'أرفق' in page.locator('[data-ig28-error]:visible').inner_text());page.evaluate("MoshUI19.close('ig28Compose',true)")
        ok('Approval controls hidden without grant',page.locator('[data-ig28="approve"]').count()==0)
        page.select_option('#ig28Customer','C2');page.wait_for_function("!document.querySelector('[data-ig28=compose]') || document.querySelector('#ig28Customer')?.value==='C2'");page.wait_for_timeout(300)
        ok('Switching customer clears other customer data',page.locator('#marketingSocialHubContent').get_by_text('@mosh_qa_only',exact=False).count()==0)
        page.evaluate("MoshTheme21.apply('light');openMoshSocialHub('C1')");page.wait_for_timeout(500);page.screenshot(path=str(OUT/'r28-social-light.png'),full_page=True)
        page.set_viewport_size({'width':430,'height':932});page.wait_for_timeout(150);page.screenshot(path=str(OUT/'r28-social-mobile.png'),full_page=True)
        ok('Social content has no horizontal overflow at mobile width',page.evaluate("(()=>{const e=document.querySelector('.ig28');return !e||e.scrollWidth<=e.clientWidth+2})()"))
        page.locator('[data-ig28=back]').click();ok('Marketing navigation restored',page.locator('#marketingExecV33').evaluate('(e)=>getComputedStyle(e).display')!='none' and page.locator('#marketingSocialHubContent').evaluate('(e)=>getComputedStyle(e).display')=='none')
        page.evaluate("document.dispatchEvent(new CustomEvent('mosh:logout-complete'))");ok('Logout clears retained social dialogs',page.locator('.ig28-dialog').count()==0)
        ok('No Meta publish/connect endpoint invoked during tests',not any(p=='/social/instagram/connect' for _,p,_ in requests))
    except Exception as e:
        checks.append({'name':'Browser harness completion','passed':False,'error':str(e)});page.screenshot(path=str(OUT/'browser-failure.png'),full_page=True);print('ERROR',e)
    browser.close()
server.shutdown()
report={'checks':checks,'pageErrors':errors,'networkCalls':len(requests),'externalRequestsBlocked':len(blocked),'passed':sum(x['passed'] for x in checks),'total':len(checks),'testBoundary':'Actual complete frontend scripts/styles rendered in memory (Chromium navigation blocked), synthetic API adapter; no real HTTP transport, production server or Meta calls.'}
(OUT/'browser-results.json').write_text(json.dumps(report,ensure_ascii=False,indent=2));print(json.dumps(report,ensure_ascii=False,indent=2))
