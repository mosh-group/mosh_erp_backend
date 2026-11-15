from pathlib import Path
import sys, json,copy,threading,http.server,functools
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright
B=Path(__file__).resolve().parents[2]; F=B.parent/'MOSH_VANILLA_FRONTEND'; OUT=Path(__file__).resolve().parent/'output'; OUT.mkdir(exist_ok=True)
source=(B/'tests/R29/browser_acceptance.py').read_text().split('class Handler(')[0]
namespace={'__file__':str(B/'tests/R29/browser_acceptance.py')}
exec(compile(source,'r29_mock_fixture','exec'), namespace)
state,boot,user,manifest,handle=[namespace[k] for k in ['state','boot','user','manifest','handle']]
# Synthetic old no-work session: never load company data in this browser harness.
state['workEvidence']={'id:E1':{'mode':'active','noWork':True,'noWorkAt':'2026-10-14T09:00:00+03:00','startedAt':'2026-10-14T08:00:00+03:00','_version':1}}
class Handler(http.server.SimpleHTTPRequestHandler):
 def log_message(self,*args):pass
server=http.server.ThreadingHTTPServer(('127.0.0.1',8873),functools.partial(Handler,directory=str(F)))
threading.Thread(target=server.serve_forever,daemon=True).start()
with sync_playwright() as pw:
 browser=pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
 context=browser.new_context(viewport={'width':1440,'height':1000},locale='ar',timezone_id='Asia/Damascus')
 def route(r):
  if r.request.url.split('?')[0].endswith('/config.js'):
   return r.fulfill(content_type='text/javascript',body=(F/'config.js').read_text()+"\nwindow.API_BASE_URL='http://127.0.0.1:8873/api';")
  return handle(r)
 context.route('**/*',route)
 page=context.new_page();errors=[];page.on('pageerror',lambda e: errors.append(str(e)));page.on('dialog',lambda d:d.accept());page.set_default_timeout(7000)
 from bs4 import BeautifulSoup
 from types import SimpleNamespace
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
 page.set_content(str(soup),wait_until='domcontentloaded',timeout=10000)
 page.evaluate("""() => {
   for(const key of ['localStorage','sessionStorage']){const m=new Map();Object.defineProperty(window,key,{value:{getItem:k=>m.get(k)||null,setItem:(k,v)=>m.set(k,String(v)),removeItem:k=>m.delete(k),clear:()=>m.clear(),key:i=>[...m.keys()][i]||null,get length(){return m.size;}}});}
   window.API_BASE_URL='https://qa.invalid/api';
 }""")
 page.add_script_tag(content=(F/'js/api-client.js').read_text())
 page.evaluate("""data => { MoshApi.request=(path,options={})=>window.qaRequest(path,options);MoshApi.status={authenticated:true,setupRequired:false};MoshApi.initialState=data.state;MoshApi.user=data.user;MoshApi.resources=data.resources;MoshApi.settingPaths=data.settingPaths;MoshApi.settings=data.settings;MoshApi.auth={version:2,users:data.users,roles:data.roles,security:data.security,audit:[]}; }""",copy.deepcopy(boot))
 for script in manifest['scripts']:page.add_script_tag(content=(F/script.split('?')[0]).read_text())
 page.evaluate("MoshApi.initialize();document.dispatchEvent(new CustomEvent('mosh:r19-ready'));MoshBoot9.finish();")
 page.wait_for_timeout(1500)
 checks=[]
 def check(name,condition):
  checks.append({'name':name,'passed':bool(condition)});print(('PASS 'if condition else'FAIL ')+name,flush=True)
 def wait():page.wait_for_timeout(350)
 def go(key):page.evaluate('(key)=>goApp(key)',key);wait()
 def close_dialogs():page.evaluate("document.querySelectorAll('dialog[open]').forEach(d=>MoshUI19.close(d.id,true))")
 try:
  check('Global sidebar has no loose R29/purchasing buttons',page.locator('#appSidebar .nav>button').count()==0)
  check('Sidebar department label is administrative services',page.locator('#appSidebar a[onclick*=logistics] .nav-label').inner_text()=='قسم الخدمات الإدارية')
  check('Sidebar tooltip uses renamed department',page.locator('#appSidebar a[onclick*=logistics]').get_attribute('title')=='قسم الخدمات الإدارية')
  check('App release is R31, not overwritten by older modules',page.locator('#appLogo .app-version').inner_text()=='R31')
  check('Document title identifies R31',page.title().endswith('R31'))
  before_presence=page.evaluate('JSON.stringify(state.workEvidence)')
  check('Old no-work session remains a warning, not a guessed counter',page.locator('#wd50TimeWarning').is_visible() and page.locator('#wd50StatusTimer').inner_text()=='—')
  page.screenshot(timeout=30000,path=str(OUT/'r30-dashboard-dark.png'),full_page=True)
  go('marketing')
  check('Customer tracking is nested in Marketing rail',page.locator('#marketingExecV33 .mk33-rail-nav #r29ClientNav').count()==1)
  check('Customer tracking has an icon and styled component button',page.locator('#r29ClientNav svg').count()==1 and page.locator('#r29ClientNav').evaluate('(e)=>getComputedStyle(e).display')=='flex')
  page.locator('#r29ClientNav').click();page.wait_for_selector('#r29Customers tbody tr')
  check('Marketing entry opens actual customer workflow via API fixture',page.locator('#r29Customers [data-r29=customer-work]').count()==2)
  page.locator('#r29Customers [data-r29=customer-work]').first.click();page.wait_for_selector('#r29TaskList [data-r29=task-read]')
  check('Customer tracking still presents read-only task action',page.locator('#r29TaskList [data-r29=task-original]').count()==0)
  close_dialogs();go('hr')
  check('Advanced files is nested in HR rail',page.locator('#hrProNav #r29TeamNav').count()==1)
  page.locator('#r29TeamNav').click();page.wait_for_selector('#r29HR [data-r29-tab]')
  check('HR entry opens the full nine-tab workflow',page.locator('#r29HR [data-r29-tab]').count()==9)
  page.locator('#r29HR [data-r29=profile-edit]').click();page.wait_for_selector('#r29ProfileEdit [name=department]')
  opt=page.locator('#r29ProfileEdit [name=department] option[value="اللوجستيات"]')
  check('Department form preserves canonical value and shows alias',opt.count()==1 and opt.inner_text()=='الخدمات الإدارية')
  close_dialogs();go('purchases')
  check('Purchasing inbox belongs to its existing section rail',page.locator('#o18Rail_purchases #r24PurchasingInbox').count()==1)
  check('Purchasing inbox absent from top actions',page.locator('.top-actions #r24PurchasingInbox').count()==0)
  page.locator('#r24PurchasingInbox').click();page.wait_for_selector('#r24PurchaseShade:not([hidden])')
  check('Purchasing inbox still uses the original queue workflow',page.locator('#r24p-title').inner_text()=='طلبات وموافقات الشراء')
  page.locator('#r24PurchaseShade [data-r24p=close]').first.click();go('logistics')
  check('Service department rail and heading are renamed',page.locator('#o18Rail_logistics .o18-rail-title b').inner_text()=='الخدمات الإدارية' and 'لوحة الخدمات الإدارية' in page.locator('#logisticsApp').inner_text())
  check('Logistics application key and request types remain intact',page.locator('#logisticsApp').count()==1 and page.locator('#logisticsApp [data-value="logistics|requests"]').count()>0)
  go('admin');page.locator('#r24AdminRoot [data-r24=tools]').click();wait()
  check('Admin tools retain customer tracking and purchasing access',page.locator('#r24ToolsMenu [data-r29=customers]').is_visible() and page.locator('#r30PurchasingInbox_admin').is_visible())
  check('Admin tasks is not duplicated in tools dropdown',page.locator('#r24AdminRoot [data-r29=admin-tasks]').count()==1)
  page.locator('#r24ToolsMenu [data-r29=customers]').click();page.wait_for_selector('#r29Customers tbody tr');close_dialogs()
  go('design');page.locator('#r23ManagerTools_design .r23-manager-tools-toggle').click();wait()
  team=page.locator('#r23ManagerTools_design .r23-manager-tool').filter(has_text='تقييمات وملفات فريقي')
  check('Manager tools retain team-file access',team.count()==1 and team.is_visible())
  team.click();page.wait_for_selector('#r29HR [data-r29-tab]');close_dialogs()
  go('employeePortal');page.evaluate("MoshEmployeePortal20.openSection('requests')");wait()
  check('Own requests retains purchase inbox for an eligible user',page.locator('#employeePortalRequests [data-r30-purchase-inbox]').count()==1)
  go('marketing')
  settings_before=page.evaluate('JSON.stringify(state.systemSettings)')
  dark_tokens=page.evaluate("({bg:getComputedStyle(document.documentElement).getPropertyValue('--bg'),text:getComputedStyle(document.documentElement).getPropertyValue('--text')})")
  page.evaluate(r"""() => {
    window.r30Contrast=(selector) => {
      const el=document.querySelector(selector);if(!el)return 0;
      const rgb=s=>{const m=s.match(/[\d.]+/g)||[];return m.slice(0,3).map(Number)};
      const luminance=c=>c.map(x=>{x/=255;return x<=.04045?x/12.92:((x+.055)/1.055)**2.4}).reduce((s,x,i)=>s+x*[.2126,.7152,.0722][i],0);
      let bg='rgba(0, 0, 0, 0)',p=el;while(p&&(!bg||bg==='transparent'||bg==='rgba(0, 0, 0, 0)')){bg=getComputedStyle(p).backgroundColor;p=p.parentElement;}
      const a=luminance(rgb(getComputedStyle(el).color)),b=luminance(rgb(bg));return (Math.max(a,b)+.05)/(Math.min(a,b)+.05);
    };
  }""")
  for theme in ['dark','light']:
   page.evaluate('(mode)=>MoshTheme21.apply(mode)',theme);wait()
   check('Marketing entry uses readable '+theme+' theme',page.evaluate("r30Contrast('#r29ClientNav') >= 4.5"))
   page.screenshot(timeout=30000,path=str(OUT/('r30-marketing-'+theme+'.png')),full_page=True)
  check('Light theme updates root tokens and top-action contrast',page.evaluate("getComputedStyle(document.documentElement).getPropertyValue('--bg').trim()==='#f4f2ed' && r30Contrast('#o18QuickNew .o18-btn')>=4.5"))
  go('hr')
  check('Light HR rail and dashboard labels have readable contrast',page.evaluate("r30Contrast('#r29TeamNav')>=4.5 && r30Contrast('#hrProNav .h13-rail-title')>=4.5 && r30Contrast('.db9-kpi>span')>=4.5 && r30Contrast('.c19-title')>=4.5"))
  check('Light HR layout surface stays light after navigation',page.locator('#hrApp .h13-layout').evaluate('(e)=>getComputedStyle(e).backgroundColor')=='rgb(255, 255, 255)')
  page.screenshot(timeout=30000,path=str(OUT/'r30-hr-light.png'),full_page=True)
  page.locator('#r29TeamNav').click();page.wait_for_selector('#r29HR [data-r29-tab]')
  page.set_viewport_size({'width':430,'height':932});wait()
  check('HR dialog fits mobile viewport',page.locator('#r29HR').evaluate('(e)=>{const r=e.getBoundingClientRect();return r.left>=-1&&r.right<=innerWidth+1}') and page.locator('#r29HR .r29').evaluate('(e)=>e.scrollWidth<=e.clientWidth+2'))
  page.screenshot(timeout=30000,path=str(OUT/'r30-hr-mobile.png'),full_page=True);close_dialogs()
  page.set_viewport_size({'width':1440,'height':1000});page.evaluate("MoshTheme21.apply('dark')");go('dashboard')
  check('Theme toggle preserves saved settings and restores dark tokens',settings_before==page.evaluate('JSON.stringify(state.systemSettings)') and dark_tokens==page.evaluate("({bg:getComputedStyle(document.documentElement).getPropertyValue('--bg'),text:getComputedStyle(document.documentElement).getPropertyValue('--text')})"))
  for _ in range(3):
   for key in ['marketing','hr','logistics','purchases','dashboard']:go(key)
   page.evaluate("document.dispatchEvent(new CustomEvent('mosh:refreshed'))");wait()
  check('Repeated navigation/refresh does not recreate global loose buttons',page.locator('#appSidebar .nav>button').count()==0)
  check('Exactly one customer and HR section entry after repeated refresh',page.locator('#r29ClientNav').count()==1 and page.locator('#r29TeamNav').count()==1)
  check('Sidebar order retains existing departments with IT inserted',page.locator('#appSidebar .nav>a').evaluate_all(r"els=>{const keys=els.map(e=>(e.getAttribute('onclick')||'').match(/goApp\('([^']+)'/)?.[1]);const i=keys.indexOf('promotion');return keys[i+1]==='it'&&keys[i+2]==='logistics'&&keys[i+3]==='purchases'}"))
  check('No-work session was not reset or rewritten by UI repair',page.evaluate('JSON.stringify(state.workEvidence)')==before_presence)
  check('Old no-work session remains reviewable after navigation',page.locator('#wd50TimeWarning').is_visible())
  check('No uncaught script exceptions during root workflow',len(errors)==0)
  page.evaluate("""() => {
   MoshApi.user={id:'LIMITED',name:'موظف اختبار محدود',roleId:'role_employee',employeeId:'E2',department:'المونتاج',active:true,isRoot:false,moduleOverrides:{marketing:{view:false},admin:{view:false},hr:{view:false}},globalOverrides:{}};
   MoshUI19.setCapabilities({role:'employee',capabilities:{}});MoshR29.syncNavigation();MoshPurchasing24.sync();
  }""");wait()
  check('Limited employee does not get Marketing/HR admin entry',page.locator('#r29ClientNav').count()==0 and page.locator('#r29TeamNav').count()==0)
  check('Unassigned limited employee does not get purchase inbox',page.locator('[data-r30-purchase-inbox]').count()==0)
  page.evaluate("""() => {
   window.r30BaseRequest=MoshApi.request;
   MoshApi.user={...MoshApi.user,id:'LIMITED_APPROVER'};
   MoshApi.request=(p,o)=>p==='/r24/purchasing/queue'?Promise.resolve({items:[{id:'QA_ONLY_REQUEST',title:'طلب اختبار معزول',stage:'quotation_pending',approverId:'LIMITED_APPROVER',ownerId:'OTHER_TEST_EMPLOYEE'}]}):r30BaseRequest(p,o);
   MoshPurchasing24.sync();
   goApp('employeePortal');MoshEmployeePortal20.openSection('requests');
  }""");wait()
  check('Assigned approver outside Purchasing retains own-request inbox',page.locator('#employeePortalRequests [data-r30-purchase-inbox]').is_visible())
  page.locator('#employeePortalRequests [data-r30-purchase-inbox]').click();page.wait_for_selector('#r24PurchaseShade:not([hidden])')
  check('Assigned approver can open original review action',page.locator('#r24PurchaseShade [data-r24p=decision]').count()==1)
  page.locator('#r24PurchaseShade [data-r24p=close]').first.click()
  page.evaluate('() => {MoshApi.request=r30BaseRequest;}')
  page.evaluate("""() => {
   window.r30SavedRequest=MoshApi.request;MoshApi.request=(p,o)=>p==='/r24/purchasing/queue'?new Promise(resolve=>window.r30DelayedQueue=resolve):r30SavedRequest(p,o);
   MoshPurchasing24.openQueue();
  }""")
  page.wait_for_function('typeof r30DelayedQueue === "function"')
  page.evaluate("MoshApi.user=null;document.dispatchEvent(new CustomEvent('mosh:logout-complete'));r30DelayedQueue({items:[{id:'LATE',title:'late private result'}]});")
  page.wait_for_timeout(600)
  check('Late queue result cannot reopen private data after logout',not page.locator('#r24PurchaseShade').is_visible())
  check('Logout removes private HR/CRM dialogs and section entries',page.locator('dialog[id^=r29],#r29ClientNav,#r29TeamNav,[data-r30-purchase-inbox]').count()==0)
  check('All new UI workflows finished without uncaught errors',len(errors)==0)
 except Exception as e:
  checks.append({'name':'Harness completion','passed':False,'error':str(e)});print('FAIL HARNESS',str(e),flush=True)
  page.screenshot(timeout=30000,path=str(OUT/'failure.png'),full_page=True)
 report={'boundary':'Actual source HTML/CSS/scripts in Chromium memory with synthetic API. Browser navigation is blocked by environment policy; no real HTTP/Laravel/company server/Meta exercised.', 'checks':checks,'passed':sum(c['passed'] for c in checks),'total':len(checks),'pageErrors':errors,'externalRequestsBlocked':len(namespace['blocked'])}
 (OUT/'browser-navigation.json').write_text(json.dumps(report,ensure_ascii=False,indent=2));print(json.dumps(report,ensure_ascii=False,indent=2),flush=True)
 browser.close()
server.shutdown()
sys.exit(0 if report['passed']==report['total'] else 1)
