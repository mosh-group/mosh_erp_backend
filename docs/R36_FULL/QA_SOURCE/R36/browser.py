"""R36 real UI scripts with isolated API fixtures. Never authenticates to production."""
from pathlib import Path
import os,sys,json,copy
B=Path(__file__).resolve().parents[2];P=B.parent;os.environ['MOSH_PROJECT']=str(P);sys.path.insert(0,str(B/'tests/R33'))
import ui_fixture as x
from playwright.sync_api import sync_playwright
O=Path(os.environ.get('MOSH_QA_OUT',str(B/'tests/R36/output')));O.mkdir(parents=True,exist_ok=True)
checks=[];calls=[];base=x.request
x.state['customers'][0].update(nameAr='رولانا',nameEn='Rollana')
# Retain original canonical name to prove bilingual labels don't break task associations.
x.state['salesTasks'][0]['workChat']=[]
for t in x.state['salesTasks']:
 t['workChat']=[{'id':'M1','text':'النص الأصلي للاختبار','byId':x.user['id'],'by':'مدير الاختبار','time':'2026-10-28T10:00:00Z'},{'id':'M2','system':True,'text':'تم استلام المهمة','byId':x.user['id']},{'id':'M3','text':'رسالة أخرى','byId':'OTHER'}]
 x.ns['mocktasks']=x.state['salesTasks']
def req(path,opts=None):
 opts=opts or {};calls.append({'path':path,'method':opts.get('method','GET'),'body':copy.deepcopy(opts.get('body'))})
 if path=='/ui/branding':return {'mode':'text','image':None,'_version':0}
 if path.startswith('/r34/chat?'):
  t=next((t for t in x.state['salesTasks']if str(t['id'])==path.split('taskId=')[-1]),{})
  return {'task':copy.deepcopy(t),'messagePermissions':[{'id':'M1','canModify':True},{'id':'M2','canModify':False},{'id':'M3','canModify':False}],'canCancel':False}
 return base(path,opts)
x.request=req

def ck(n,v,d=None):checks.append({'name':n,'passed':bool(v),'detail':d});print(('PASS 'if v else'FAIL ')+n,flush=True)
def attempt(n,f):
 try:f()
 except Exception as e:ck(n,False,str(e))
with sync_playwright()as pw:
 b=pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox']);ctx,p,errors=x.boot_page(b);p.set_default_timeout(5000)
 def wait(ms=180):p.wait_for_timeout(ms)
 def go(k):p.evaluate('(k)=>{setDirtyState(false);goApp(k)}',k);wait(380)
 ck('Actual R36 frontend loaded',p.evaluate("MOSH_UI_RELEASE==='R36'&&!window.__MOSH_LOCAL_PREVIEW__"))
 for k in ['hr','sales','marketing','it','accounting','purchases','logistics']:
  def menu(k=k):
   go(k);p.mouse.move(0,0);wait(300);trigger=p.locator(f'[data-r35-menu={k}]');tools=p.locator(f'#r23ManagerTools_{k} .r23-manager-tools-toggle')
   ck(k+': dropdown visible after manager tools',trigger.is_visible() and tools.is_visible() and trigger.bounding_box()['x']+trigger.bounding_box()['width']<=tools.bounding_box()['x']+2)
   ck(k+': identical manager/section control metrics',p.evaluate("k=>{let a=document.querySelector('[data-r35-menu='+k+']'),b=document.querySelector('#r23ManagerTools_'+k+' .r23-manager-tools-toggle');return ['height','fontSize','fontWeight','padding','backgroundColor','borderRadius'].every(x=>getComputedStyle(a)[x]===getComputedStyle(b)[x]);}",k))
   trigger.click();wait();ck(k+': dropdown groups open',p.locator('.pv-menu-floating:not(.pv-menu-sub)').is_visible());p.keyboard.press('Escape')
  attempt(k+' menu flow',menu)
 def social():
  nav=p.locator('.sidebar .nav a');keys=nav.evaluate_all("es=>es.map(e=>(e.getAttribute('onclick')||'').match(/goApp\\('([^']+)'/)?.[1]).filter(Boolean)")
  ck('Social department directly follows marketing',keys.index('social')==keys.index('marketing')+1 and keys.index('design')==keys.index('social')+1)
  go('marketing');ck('Marketing social action moved not duplicated',p.locator('#marketingSocialHubTab').count()==0)
  go('social');ck('New department has own task tab',p.locator('#m15Tabs_social [data-mode=running]').is_visible())
  p.locator('#socialHubTab').click();wait(600)
  ck('Existing social hub mounted once under new department',p.locator('#socialApp #marketingSocialHubContent').is_visible() and p.locator('#marketingSocialHubContent').count()==1)
  ck('Original Instagram record appears, not reset', '@mosh_qa_only' in p.locator('#marketingSocialHubContent').inner_text())
  p.screenshot(path=str(O/'social.png'),full_page=True)
  go('social');ck('Department click resets social dashboard',p.locator('#marketingSocialHubContent').is_hidden()and p.locator('#socialAppContent').is_visible())
 attempt('Social department lifecycle',social)
 def names():
  go('marketing');p.evaluate("MoshMarketingV33.openDashboard();MoshMarketingV33.searchCustomers('rOLLaNA')");wait(350)
  ck('English search finds one bilingual customer',p.locator('.mk33-table tbody tr').count()==1 and 'رولانا' in p.locator('.mk33-table tbody tr').inner_text())
  ck('Both names displayed beside each other','Rollana' in p.locator('.mk33-table tbody tr').inner_text())
  p.evaluate("MoshMarketingV33.searchCustomers('رولا')");wait();ck('Arabic partial search returns same record',p.locator('.mk33-table tbody tr').get_attribute('data-id')=='C1')
  p.evaluate("MoshMarketingCustomer25.open()");wait();ck('Create dialog has Arabic and English inputs',p.locator('#r25CustomerForm [name=nameAr]').count()==1 and p.locator('#r25CustomerForm [name=nameEn]').get_attribute('dir')=='ltr')
  p.locator('#r25CustomerForm [name=nameAr]').fill('شركة تجربة العربية');p.locator('#r25CustomerForm [name=nameEn]').fill('QA English Co');p.screenshot(path=str(O/'customer-form.png'),full_page=True)
  p.evaluate('MoshMarketingCustomer25.close(true)');wait()
  go('sales');p.evaluate("salesList('customergroup')");wait();ck('Sales options retain canonical value with bilingual label',p.evaluate("customerOptions('').includes('رولانا — Rollana')&&customerOptions('').includes('value=\"عميل تجريبي أول\"')"))
  ck('Alias selection resolves same ID without mutating canonical name',p.evaluate("getCatalogCustomerByName('Rollana').id==='C1'&&getCatalogCustomerByName('رولانا').name==='عميل تجريبي أول'"))
  p.evaluate("renderEditCustomerForm('C1')");wait();ck('Edit preserves both persisted aliases',p.locator('#newCustomerName').input_value()=='رولانا' and p.locator('#newCustomerNameEn').input_value()=='Rollana')
 attempt('Bilingual customer UI',names)
 def chat():
  go('tasks');p.evaluate("openTaskDetails('T_design')");wait(600)
  for act,title in [('choose','إرفاق ملفات'),('voice','تسجيل صوت')]:
   btn=p.locator(f'[data-chat34-task=T_design] [data-chat34={act}]');ck(act+': original action uses accessible SVG icon',btn.is_visible()and btn.inner_text()==''and btn.get_attribute('aria-label')==title and btn.locator('svg').count()==1)
  ck('Only authorized editable message exposes overflow',p.locator('.r36-message-more').count()==1)
  ck('Edit/delete hidden until overflow click',not p.locator('[data-chat34=edit]').first.is_visible()and not p.locator('[data-chat34=delete]').first.is_visible())
  p.locator('.r36-message-more').click();wait();ck('Overflow menu opens and edit/delete available',p.locator('[data-chat34=edit]').is_visible()and p.locator('[data-chat34=delete]').is_visible())
  p.screenshot(path=str(O/'chat-menu.png'),full_page=True);p.keyboard.press('Escape');wait();ck('ESC closes only message options',p.locator('.r36-message-options').is_hidden()and p.locator('[data-chat34-task=T_design]').is_visible())
  before=len([c for c in calls if 'files-upload' in c['path']]);p.locator('[data-chat34-task=T_design] input[type=file]').set_input_files({'name':'large-design.psd','mimeType':'application/octet-stream','buffer':b'0'*(26*1024*1024)},timeout=30000);wait(500)
  ck('26MiB file passes UI staging without upload or send',p.evaluate("MoshChat34.inspect('T_design').files===1")and before==len([c for c in calls if 'files-upload' in c['path']]))
  ck('Creation and return share size safety without 25MB cap',p.evaluate("validateTaskAttachmentFile({name:'design.psd',size:300*1024*1024})===''"))
  p.locator('[data-chat34=remove]').click();wait();ck('Removing unsent file creates no chat message',p.evaluate("MoshChat34.inspect('T_design').files===0&&state.salesTasks.find(t=>t.id==='T_design').workChat.length===3"))
 attempt('Chat controls and staging',chat)
 def exit_guard():
  p.evaluate("window.qExit36=0;setDirtyState(true);void MoshR14Polish.requestUnsavedExit(()=>qExit36++);");p.wait_for_selector('#r14UnsavedDialog[open]')
  p.locator('#r14UnsavedDialog [data-r14=cancel]').click();wait();ck('Unsaved dialog Cancel does not recursively reopen',p.locator('#r14UnsavedDialog').evaluate('e=>!e.open') and p.evaluate('qExit36===0'))
  p.evaluate("void MoshR14Polish.requestUnsavedExit(()=>qExit36++);");p.keyboard.press('Escape');wait();ck('ESC cancels unsaved navigation without freezing',p.locator('#r14UnsavedDialog').evaluate('e=>!e.open'))
  p.evaluate("void MoshR14Polish.requestUnsavedExit(()=>qExit36++);");p.locator('#r14UnsavedDialog [data-r14=discard]').click();wait();ck('Discard exits once without writing rejected data',p.evaluate('qExit36===1')and p.locator('#r14UnsavedDialog').evaluate('e=>!e.open'))
 attempt('Unsaved changes exit guard',exit_guard)
 ck('No unhandled JS error',not errors,errors);b.close()
(O/'results.json').write_text(json.dumps({'checks':checks,'passed':sum(c['passed']for c in checks),'total':len(checks),'errors':errors,'boundary':'Original HTML/CSS/JS in Chromium, synthetic authorized API; not live Laravel or production.'},ensure_ascii=False,indent=2))
raise SystemExit(0 if all(c['passed']for c in checks)else 1)
