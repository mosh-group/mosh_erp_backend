"""Permission-aware presentation, keyboard and stale-response checks, synthetic authenticated API."""
from pathlib import Path
import os,sys,copy,json
B=Path(__file__).resolve().parents[2];os.environ['MOSH_PROJECT']=str(B.parent);sys.path.insert(0,str(B/'tests/R33'))
import ui_fixture as x
from playwright.sync_api import sync_playwright
O=Path(os.environ.get('MOSH_QA_OUT',str(B/'tests/R35/evidence/roles')));O.mkdir(parents=True,exist_ok=True)
checks=[]
def ck(n,v,d=None):checks.append({'name':n,'passed':bool(v),'detail':d});print(('PASS 'if v else'FAIL ')+n,flush=True)
base=x.request
x.request=lambda path,opts=None:{'mode':'text','image':None,'_version':0}if path=='/ui/branding'else base(path,opts)
with sync_playwright()as pw:
 b=pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox']);ctx,p,errs=x.boot_page(b);p.set_default_timeout(6000)
 def wait(n=250):p.wait_for_timeout(n)
 def hr():p.evaluate("goApp('hr');document.querySelector('#hrProNav [data-hr-view=employees]')?.click()");wait(350)
 try:
  hr()
  prior=p.locator('[data-mp-status=active] b').inner_text()
  p.evaluate("()=>{const e=state.staffProfiles.find(p=>staffEmploymentStatus(p)==='active');window.qStatusEmployee=e;window.qSavedStatus=e.employmentStatus;delete e.employmentStatus;e.active=true;MoshVisualV2.decorate();}")
  wait();ck('Legacy active status is counted through canonical helper',p.locator('[data-mp-status=active] b').inner_text()==prior)
  p.evaluate("()=>{qStatusEmployee.employmentStatus=qSavedStatus;MoshVisualV2.decorate();}")
  hr();p.locator('[data-r35-menu=hr]').click();wait()
  ck('Menu focuses first group on keyboard open',p.evaluate("document.activeElement.classList.contains('pv-menu-group')"))
  p.keyboard.press('End');ck('End reaches last menu group',p.evaluate("document.activeElement===Array.from(document.querySelectorAll('.pv-menu-group')).at(-1)"))
  p.keyboard.press('Home');p.keyboard.press('ArrowLeft');wait();ck('Left arrow opens submenu and focuses leaf',p.evaluate("document.activeElement.classList.contains('pv-menu-leaf')"))
  p.keyboard.press('Escape');ck('First ESC closes only child menu',p.locator('.pv-menu-sub').is_hidden()and p.locator('.pv-menu-floating:not(.pv-menu-sub)').is_visible())
  p.keyboard.press('Escape');ck('Second ESC closes group menu and restores trigger focus',p.locator('.pv-menu-floating:not(.pv-menu-sub)').is_hidden()and p.locator('[data-r35-menu=hr]').evaluate('e=>e===document.activeElement'))
  p.locator('[data-r35-menu=hr]').click();p.locator('.pv-menu-group').first.click();wait()
  p.evaluate("()=>{const g=MoshGrouped35.groups('hr')[0];window.qOldTarget=g.rows[0];qOldTarget.disabled=true;window.qDidClick=0;qOldTarget.addEventListener('click',()=>qDidClick++);}")
  p.locator('.pv-menu-sub .pv-menu-leaf').first.click();wait();ck('Stale menu cannot execute a now-disabled action',p.evaluate('qDidClick===0'));p.evaluate('qOldTarget.disabled=false')
  hr();p.locator('.mp-peek').first.click();wait();ck('Drawer visible before refresh',p.locator('#mpEmployeePreview').is_visible());p.evaluate("document.dispatchEvent(new CustomEvent('mosh:refreshed'))");wait();ck('Refresh invalidates displayed employee copy',p.locator('#mpEmployeePreview').is_hidden())
  hr();p.locator('[data-r35-menu=hr]').click();p.evaluate("goApp('marketing')");wait();ck('Leaving section closes old grouped menu',p.locator('.pv-menu-floating:not(.pv-menu-sub)').is_hidden())
  p.evaluate("()=>{window.qOldRequest=MoshApi.request;window.qBrandResolve=null;MoshApi.request=(s,o)=>s==='/ui/branding'?new Promise(resolve=>qBrandResolve=resolve):qOldRequest(s,o);MoshBranding35.load(true);}");wait()
  p.evaluate("document.dispatchEvent(new CustomEvent('mosh:logout-complete'))");p.evaluate("qBrandResolve({mode:'image',image:'data:image/png;base64,aW52YWxpZA==',_version:88})");wait();ck('Late branding response cannot reapply after logout',p.locator('#appLogo img').count()==0);p.evaluate('()=>{MoshApi.request=qOldRequest;}')
  ck('Root race/keyboard flow has no uncaught JS errors',not errs,errs)
 finally:ctx.close()
 limited={**copy.deepcopy(x.user),'id':'QA_HR_READER','name':'موظف قراءة موارد بشرية','roleId':'role_manager','active':True,'isRoot':False,'employeeId':'E_hr','department':'الموارد البشرية','taskScope':'department','landing':'dashboard','moduleOverrides':{'hr':{'view':True,'dashboard':True,'list':True,'details':True,'running':True,'create':False,'edit':False,'delete':False,'assign':False,'approvals':False,'print':False,'report':False},'it':{'view':False},'admin':{'view':False}},'globalOverrides':{'systemCustomization':False,'appearanceSettings':False,'manageUsers':False,'managePermissions':False}}
 x.boot['user']=limited;x.boot['users']=[limited];x.ns['user']=limited
 ctx,p,errs=x.boot_page(b);p.set_default_timeout(6000)
 try:
  hr();ck('Limited HR view loads without new permission grants',p.locator('#hrApp').is_visible()and p.evaluate("!MoshAuth37.canModule('hr','edit')&&!MoshAuth37.canModule('hr','delete')"))
  p.locator('[data-r35-menu=hr]').click();wait();ck('Read-only user can open menu groups',p.locator('.pv-menu-group').count()>0)
  permitted=p.evaluate("()=>MoshGrouped35.sources('hr').every(e=>!e.classList.contains('mosh-auth-hidden')&&!e.disabled)");ck('Grouped entries only use allowed original controls',permitted)
  p.keyboard.press('Escape');p.locator('.mp-peek').first.click();wait();ck('Authorized read-only profile preview is available',p.locator('#mpEmployeePreview').is_visible())
  p.keyboard.press('Escape');p.locator('.mp-more').first.click();wait();ck('Write actions do not reappear in overflow',not p.locator('.mp-action-menu button').filter(has_text='تعديل').count()and not p.locator('.mp-action-menu button').filter(has_text='حذف').count());p.keyboard.press('Escape')
  p.evaluate('MoshBranding35.open()');wait();ck('Unprivileged user cannot open branding editor',not p.locator('#r35BrandForm').count())
  old=p.evaluate("document.querySelector('.view.active').id");p.evaluate("goApp('it')");wait();ck('Grouped UI does not grant IT access',p.evaluate("document.querySelector('.view.active').id")==old)
  p.evaluate("MoshGrouped35.open('it',document.createElement('button'))");wait();ck('Direct menu call also checks department permission',p.locator('.pv-menu-floating:not(.pv-menu-sub)').is_hidden())
  ck('Limited role has no JS errors',not errs,errs)
 finally:ctx.close();b.close()
(O/'roles.json').write_text(json.dumps({'checks':checks,'passed':sum(c['passed']for c in checks),'total':len(checks),'boundary':'Actual frontend with synthetic user grants. Backend authorization is unchanged except tested branding endpoint.'},ensure_ascii=False,indent=2))
raise SystemExit(0 if all(c['passed']for c in checks)else 1)
