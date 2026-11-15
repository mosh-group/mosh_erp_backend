from ui_fixture import *
from playwright.sync_api import sync_playwright
import time,collections
OUT=Path(os.environ.get('MOSH_QA_OUT',str(Path(__file__).resolve().parent/'output'/Path(__file__).stem)));OUT.mkdir(parents=True,exist_ok=True)
checks=[];page_errors=[]
def ck(name,value,detail=None):
 checks.append({'name':name,'passed':bool(value),**({'detail':detail} if detail is not None else {})});print(('PASS 'if value else'FAIL ')+name,flush=True)
def attempt(name,fn):
 try:fn()
 except Exception as e:ck(name,False,str(e))
with sync_playwright() as pw:
 browser=pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
 ctx,page,errors=boot_page(browser)
 def wait(n=180):page.wait_for_timeout(n)
 def go(k):page.evaluate('(k)=>goApp(k)',k);wait()
 def nav(k):
  e=page.locator(f'#appSidebar a[onclick*="goApp(\'{k}\'"]')
  if e.count() and e.first.is_visible():e.first.click()
  else:page.evaluate('(k)=>goApp(k)',k)
  wait()
 def close():page.evaluate("document.querySelectorAll('dialog[open]').forEach(d=>MoshUI19.close(d.id,true));document.getElementById('r24Backdrop')?.setAttribute('hidden','');")
 ck('Release identity is R33',page.locator('#appLogo .app-version').inner_text()=='R33')
 ck('Original complete frontend boots without JS errors',not errors,errors)
 # Capture the actual Marketing public entrypoint; a navigation must call it only once.
 page.evaluate("""()=>{window.qaMarketingCount=0;const old=MoshMarketingV33.openDashboard;MoshMarketingV33.openDashboard=function(...a){qaMarketingCount++;return old.apply(this,a)};window.qaOldAdmin=0;const d=window.openProductiveDistributionPage;window.openProductiveDistributionPage=function(k,...a){if(k==='admin')qaOldAdmin++;return d.call(this,k,...a)};}""")
 go('marketing');ck('Marketing dashboard is prepared exactly once per section entry',page.evaluate('qaMarketingCount')==1)
 go('admin');ck('Hidden Administration productive dashboard is not prepared',page.evaluate('qaOldAdmin')==0)
 before=sum(c['path']=='/r24/admin/overview' for c in calls)
 for view in ['employees','dashboard','employees','dashboard']:
  page.locator(f'#r24AdminRoot [data-r24=go][data-view={view}]').click();wait()
 after=sum(c['path']=='/r24/admin/overview' for c in calls)
 ck('Dashboard/employee tabs reuse fresh overview without duplicate requests',before==after,{'before':before,'after':after})
 page.locator('#r24AdminRoot [data-r24=refresh]').click();wait()
 ck('Explicit Administration refresh requests current server data',sum(c['path']=='/r24/admin/overview' for c in calls)==after+1)
 page.locator('#r24AdminRoot [data-r24=go][data-view=employees]').click();wait();nav('admin')
 ck('Clicking already-active Administration resets to its dashboard',page.locator('#r24AdminRoot .r24-tab[data-view=dashboard]').get_attribute('aria-selected')=='true')
 # All departmental entries, task menu labels and dashboard re-entry.
 for k,v in depts.items():
  def section(k=k,v=v):
   close();nav(k)
   ck(k+': exactly one active application',page.evaluate("k=>document.querySelectorAll('.view.active').length===1&&document.getElementById(k+'App').classList.contains('active')",k))
   ck(k+': non-empty visible section',len(page.locator('#'+k+'App').inner_text().strip())>10)
   if k not in ['admin','it']:
    b=page.locator(f'#m15Tabs_{k} button[data-mode=running]')
    expected='مهام قسم '+('الخدمات الإدارية' if k=='logistics' else ('المستودعات العامة' if k=='warehouses' else v))
    ck(k+': task navigation label only',b.count()==1 and b.inner_text().strip()==expected)
    b.click();wait(250)
    ck(k+': task action remains running',b.get_attribute('data-mode')=='running' and b.evaluate("e=>e.classList.contains('active')"))
    nav(k);wait(200)
    ck(k+': department entry returns to dashboard',page.locator(f'#m15Tabs_{k} button[data-mode=distribution]').evaluate("e=>e.classList.contains('active')"))
   elif k=='it':
    for tab in ['assets','accounts','subscriptions','tasks']:
     page.locator(f'#it31Tabs [data-id={tab}]').click();wait(120)
     ck('IT: '+tab+' view remains visible after selection',page.locator(f'#it31Tabs [data-id={tab}]').get_attribute('aria-current')=='page')
    nav('it');ck('IT: re-entry resets dashboard',page.locator('#it31Tabs [data-id=dashboard]').get_attribute('aria-current')=='page')
   wait(120)
  attempt('Complete section test: '+k,section)
 # Real clicking of original task management action, not merely checking button existence.
 def admin_task():
  go('purchases');page.evaluate('MoshR29.adminTasks()');page.wait_for_selector('#r29TaskList [data-r29=task-original]')
  page.locator('#r29TaskList [data-r29=task-original]').first.click();wait(300)
  ck('Admin task opens in visible tasks application, not purchases',page.locator('#tasksApp').is_visible() and not page.locator('#purchasesApp').is_visible())
  ck('Admin task opens the exact selected task',page.locator('#taskEditTitle_T_admin').input_value()=='مهمة اختبار الإدارة')
  ck('Task provides explicit return to Administration task list',page.locator('#allTasksContainer').get_by_role('button',name='رجوع لمهام الإدارة',exact=True).count()==1)
  page.locator('#allTasksContainer').get_by_role('button',name='رجوع لمهام الإدارة',exact=True).click();page.wait_for_selector('#r29TaskList [data-r29=task-original]')
  ck('Return opens Administration task list and correct background',page.locator('#adminApp').is_visible() and page.locator('#r29TaskList').is_visible());close()
 attempt('Admin task opening and return completed',admin_task)
 # Marketing legacy controls and old social responses never overlay the normal workspace.
 def social_return():
  go('marketing');page.evaluate("openMoshSocialHub('C1')");wait(220)
  ck('Social view is isolated from Marketing customer workspace',page.locator('#marketingSocialHubContent').is_visible() and not page.locator('#marketingExecV33').is_visible())
  nav('design');nav('marketing');wait(250)
  ck('Returning to Marketing shows only its dashboard',page.locator('#marketingExecV33').is_visible() and not page.locator('#marketingSocialHubContent').is_visible())
  ck('Legacy Marketing root remains hidden',not page.locator('#marketingExecV29').is_visible())
 attempt('Marketing social return completed',social_return)
 # Main marketing subviews, without changing their functionality.
 for view in ['discovery','guide','plan','notes','tasks','dashboard']:
  def mk(view=view):
   page.evaluate('(v)=>MoshMarketingV33.openView(v)',view);wait(120)
   ck('Marketing '+view+': workspace remains present',page.locator('#marketingExecV33').is_visible() and not page.locator('#marketingSocialHubContent').is_visible())
  attempt('Marketing subview '+view,mk)
 # Late response to previous IT section must not rewrite breadcrumb or re-open hidden content.
 page.evaluate("""()=>{window.qaActualRequest=MoshApi.request;window.qaHeld=[];MoshApi.request=(p,o={})=>p==='/it/overview'?new Promise((resolve,reject)=>qaHeld.push({resolve,reject})):qaActualRequest(p,o);goApp('it');goApp('marketing');}""")
 wait();page.evaluate('(d)=>qaHeld[0].resolve(d)',copy.deepcopy(it));wait(300)
 ck('Late IT response does not replace currently selected Marketing section',page.locator('#marketingApp').is_visible() and not page.locator('#itApp').is_visible() and 'التسويق' in page.locator('#crumb').inner_text())
 page.evaluate('()=>{MoshApi.request=qaActualRequest;}')
 # Rapid navigation: run original permission-wrapped route several times in one JS turn.
 for seq in [['admin','it','marketing'],['marketing','admin','design'],['it','photo','admin'],['admin','marketing','it']]:
  page.evaluate('(s)=>s.forEach(k=>goApp(k))',seq);wait(450)
  ck('Rapid navigation keeps last section: '+','.join(seq),page.evaluate('k=>document.querySelectorAll(".view.active").length===1&&document.getElementById(k+"App").classList.contains("active")',seq[-1]))
 # Cached response from previous account must never be put in the new user's DOM.
 def owner_change():
  go('design');page.evaluate("""()=>{document.dispatchEvent(new CustomEvent('mosh:refreshed'));window.qaAdminHold=[];window.qaOriginalRequest=MoshApi.request;MoshApi.request=(p,o={})=>p==='/r24/admin/overview'?new Promise(resolve=>qaAdminHold.push(resolve)):qaOriginalRequest(p,o);goApp('admin');}""");wait()
  ck('Old account overview request is pending for ownership test',page.evaluate('qaAdminHold.length')==1)
  page.evaluate("""()=>{document.dispatchEvent(new CustomEvent('mosh:logout-complete'));MoshApi.user={...MoshApi.user,id:'QA_NEW_ACCOUNT'};goApp('design');goApp('admin');}""");wait()
  ck('New account starts separate overview request',page.evaluate('qaAdminHold.length')==2)
  new=copy.deepcopy(admin);new['sourceNote']='QA_NEW_ACCOUNT_ONLY';old=copy.deepcopy(admin);old['sourceNote']='QA_OLD_PRIVATE_RESPONSE'
  page.evaluate('(d)=>qaAdminHold[1](d)',new);wait();page.evaluate('(d)=>qaAdminHold[0](d)',old);wait()
  ck('Late old-account response cannot overwrite current dashboard', 'QA_NEW_ACCOUNT_ONLY' in page.locator('#r24AdminRoot').inner_text() and 'QA_OLD_PRIVATE_RESPONSE' not in page.locator('#r24AdminRoot').inner_text())
  page.evaluate('()=>{MoshApi.request=qaOriginalRequest;}')
 attempt('Cross-account asynchronous isolation completed',owner_change)
 page.evaluate("document.dispatchEvent(new CustomEvent('mosh:refreshed'))");wait(250)
 # Visual regression snapshots of the actual new full interface (synthetic records only).
 for theme in ['dark','light']:
  page.evaluate('(t)=>MoshTheme21.apply(t)',theme)
  for k in ['admin','marketing','it']:
   go(k);wait(200);page.screenshot(path=str(OUT/f'{k}-{theme}.png'),full_page=False)
   ck(k+': visible in '+theme+' mode',page.locator('#'+k+'App').is_visible())
 page.set_viewport_size({'width':390,'height':844});go('it');wait(180)
 ck('IT mobile content has no horizontal overflow',page.locator('#itApp').evaluate('(e)=>e.scrollWidth<=e.clientWidth+2'))
 page.screenshot(path=str(OUT/'it-mobile.png'));page_errors+=errors;ctx.close()
 # An actual second browser account with the existing employee role.
 limited={**copy.deepcopy(user),'id':'U_LIMITED','username':'qa_limited','name':'موظف اختبار محدود','roleId':'role_employee','isRoot':False,'employeeId':'E_design','department':'التصميم','taskScope':'mine','moduleOverrides':{'admin':{'view':False},'it':{'view':False},'design':{'view':True,'running':True,'dashboard':False}},'globalOverrides':{}}
 boot['user']=limited;boot['users']=[limited];ns['user']=limited
 ctx,page,errors2=boot_page(browser)
 def limited_tests():
  go('design');wait(300)
  ck('Limited employee can enter assigned department',page.locator('#designApp').is_visible())
  ck('Personal-task view stays personal, not renamed as a department-wide grant',page.evaluate("MoshAuth37.taskScope()==='mine'"))
  previous=page.evaluate("document.querySelector('.view.active').id")
  page.evaluate("goApp('admin')");wait()
  ck('Denied Administration navigation does not open the section',page.evaluate("document.querySelector('.view.active').id")==previous and not page.locator('#adminApp').is_visible())
  page.evaluate("goApp('it')");wait()
  ck('Denied IT navigation does not expose the vault or registry',not page.locator('#itApp').is_visible())
  # Rapid department switching must not let queued task-only rendering change the last route.
  page.evaluate("goApp('design');goApp('dashboard')");wait(350)
  ck('Queued personal-task rendering cannot redirect after leaving department',page.locator('#dashboardApp').is_visible() and 'الرئيسية' in page.locator('#crumb').inner_text())
 attempt('Limited-account navigation completed',limited_tests)
 page_errors+=errors2;ck('No uncaught JavaScript errors in full navigation/roles sweep',not page_errors,page_errors)
 ctx.close();browser.close()
report={'checks':checks,'passed':sum(c['passed'] for c in checks),'total':len(checks),'pageErrors':page_errors,'boundary':'Full original HTML/CSS/JavaScript in Chromium memory with explicit API fixtures. Not Laravel session/SQL or production-server testing.'}
(OUT/'report.json').write_text(json.dumps(report,ensure_ascii=False,indent=2));print('RESULT',report['passed'],report['total'],flush=True)
raise SystemExit(0 if report['passed']==report['total'] else 1)
