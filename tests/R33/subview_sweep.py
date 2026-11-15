from ui_fixture import *
from playwright.sync_api import sync_playwright
OUT=Path(os.environ.get('MOSH_QA_OUT',str(Path(__file__).resolve().parent/'output'/Path(__file__).stem)));OUT.mkdir(parents=True,exist_ok=True)
checks=[]
def ck(n,v,d=None):checks.append({'name':n,'passed':bool(v),'detail':d});print(('PASS 'if v else'FAIL ')+n,flush=True)
with sync_playwright() as pw:
 b=pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox']);ctx,p,errors=boot_page(b)
 def wait(n=130):p.wait_for_timeout(n)
 def go(k):p.evaluate('(k)=>goApp(k)',k);wait()
 def close():p.evaluate("document.querySelectorAll('dialog[open]').forEach(d=>MoshUI19.close(d.id,true));document.getElementById('r24Backdrop')?.setAttribute('hidden','');MoshUI15?.closeModal?.();hrProCloseModal?.();MoshManagerApprovals28?.close?.();")
 def click_test(k,sel,label):
  start=len(errors)
  try:
   close();go(k);p.locator('#'+k+'App '+sel).filter(visible=True).first.click();wait(160)
   ck(k+': '+label+' opens without uncaught script error',len(errors)==start and len(p.locator('#'+k+'App').inner_text())>30,errors[start:])
  except Exception as e:ck(k+': '+label,False,str(e))
 for k in [x for x in depts if x not in ['admin','it','platform']]:
  start=len(errors)
  try:
   close();go(k);p.locator('#m15Tabs_'+k).get_by_role('button',name='اعتماد المدير',exact=True).click();wait(180)
   ck(k+': existing manager approval opens',p.locator('#'+k+'App').is_visible() and len(errors)==start,errors[start:]);go(k)
  except Exception as e:ck(k+': existing manager approval opens',False,str(e))
 # Actual navigation menu click paths. They do not submit business changes.
 for v in ['employees','requests','attendance','wisdom','recruitment','performance','reports']:
  click_test('hr',f'[data-hr-view="{v}"]','HR '+v)
 for v in ['quotes','orders','cancelledquotes','servicecontracts','customergroup','servicelist','reports15','settings15']:
  click_test('sales',f'[data-m15="sales"][data-value="{v}"]',v)
 for k,vs in {'purchases':['requests','orders','suppliers','reports'], 'logistics':['requests','schedule','reservations'], 'warehouses':['scan','inbound','warehouses','stock','customers','custody']}.items():
  for v in vs:click_test(k,f'[data-o18="nav"][data-value="{k}|{v}"]',v)
 for v in ['customers22','vendors22','vendorStatement23','employees23','statements22','invoices','vendors','payroll23','hours23','employees','expenses','cash','trial22','income23','balance23','journal22','reconcile22','chart22','print23','packages','billing','settings']:
  click_test('accounting',f'[data-f21="nav"][data-value="{v}"]',v)
 for v in ['employees','approvals','purchases','reports','decisions','alerts','archive']:
  start=len(errors)
  try:
   close();go('admin')
   sel=f'#r24AdminRoot [data-r24=go][data-view={v}]'
   if not p.locator(sel).first.is_visible():p.locator('#r24AdminRoot [data-r24=tools]').click()
   p.locator(sel).first.click();wait(250)
   ck('admin: '+v+' opens with current data fixture',p.locator('#r24Page').is_visible() and len(errors)==start,errors[start:])
  except Exception as e:ck('admin: '+v,False,str(e))
 # Failure must be recoverable, not silently shown as valid zero figures.
 close();go('design');p.evaluate("""()=>{document.dispatchEvent(new CustomEvent('mosh:refreshed'));window.qOld=MoshApi.request;window.qFail=true;MoshApi.request=(path,o)=>path==='/r24/admin/overview'&&qFail?Promise.reject(new Error('QA_NETWORK_FAILURE')):qOld(path,o);goApp('admin');}""");wait(250)
 ck('Admin network failure is shown with retry, not successful empty data','QA_NETWORK_FAILURE' in p.locator('#r24Page').inner_text() and p.locator('#r24Page [data-r24=refresh]').count()==1)
 p.evaluate('qFail=false');p.locator('#r24Page [data-r24=refresh]').click();wait(250)
 ck('Admin retry recovers the dashboard',p.locator('#r24Page .r24-metric').count()>0 and 'QA_NETWORK_FAILURE' not in p.locator('#r24Page').inner_text())
 p.evaluate('()=>{MoshApi.request=qOld;}')
 # A failed old list read must not clobber a newer successful task list.
 close();p.evaluate('MoshR29.openCustomers()');wait(220)
 p.evaluate("""()=>{window.qTaskOld=MoshApi.request;window.qOldRead=null;MoshApi.request=(path,o)=>path==='/r29/customers/C1/work'?new Promise((resolve,reject)=>qOldRead={resolve,reject}):qTaskOld(path,o);}""");p.locator('#r29Customers [data-r29=customer-work][data-id=C1]').click();wait()
 p.evaluate('MoshR29.adminTasks()');wait(220);p.evaluate("qOldRead.reject(new Error('QA_STALE_LIST_ERROR'))");wait(200)
 ck('Stale failed customer list cannot replace newer Administration task list',p.locator('#r29TaskList [data-r29=task-original]').count()>0 and 'QA_STALE_LIST_ERROR' not in p.locator('#r29TaskList').inner_text());close()
 p.evaluate('()=>{MoshApi.request=qTaskOld;}')
 ck('Entire submenu sweep has no uncaught JavaScript errors',not errors,errors)
 (OUT/'report.json').write_text(json.dumps({'passed':sum(c['passed'] for c in checks),'total':len(checks),'checks':checks,'pageErrors':errors,'boundary':'Navigation/renderer smoke tests on synthetic data; no claim of financial posting, SQL persistence, approval transport, or genuine backend operations.'},ensure_ascii=False,indent=2));ctx.close();b.close()
print('RESULT',sum(c['passed'] for c in checks),len(checks),flush=True);raise SystemExit(0 if all(c['passed'] for c in checks) else 1)
