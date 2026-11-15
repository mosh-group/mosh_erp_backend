"""R35 original frontend + authenticated synthetic API; no production records/network."""
from pathlib import Path
import os,sys,json,copy,base64,time
B=Path(__file__).resolve().parents[2];ROOT=B.parent;os.environ['MOSH_PROJECT']=str(ROOT)
sys.path.insert(0,str(B/'tests/R33'))
import ui_fixture as x
from playwright.sync_api import sync_playwright
O=Path(os.environ.get('MOSH_QA_OUT',str(B/'tests/R35/evidence')));O.mkdir(parents=True,exist_ok=True)
checks=[];api_calls=[]
brand={'mode':'text','image':None,'_version':0};base=x.request
PNG='data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jG4kAAAAASUVORK5CYII='
# Diverse statuses in isolated data only.
x.state['staffProfiles'][1]['employmentStatus']='inactive';x.state['staffProfiles'][1]['active']=False
x.state['staffProfiles'][2]['employmentStatus']='draft';x.state['staffProfiles'][2]['active']=False
for p in x.state['staffProfiles']:
 p.setdefault('employmentStatus','active');p.setdefault('active',True)
for t in x.state['salesTasks']:
 t.setdefault('dueDate','2026-11-02');t['description']='وصف اختبار محفوظ على الخادم التجريبي.';t['executionNotes']='ملاحظة اختبار لاستخدام الملف الأصلي.'

def req(path,opts=None):
 opts=opts or {};api_calls.append({'path':path,'method':opts.get('method','GET'),'body':copy.deepcopy(opts.get('body'))})
 if path=='/ui/branding':
  if opts.get('method')=='PUT':
   v=opts['body'];brand.update(mode=v['mode'],image=None if v.get('remove')else v.get('image')or brand['image'],_version=brand['_version']+1)
  return copy.deepcopy(brand)
 if path.startswith('/r34/deadline'):
  return {'baseDate':'2026-10-22','dueDate':'2026-10-25','mode':'workdays','days':2,'monthMeaning':'شهر تقويمي'}
 if path.startswith('/r34/chat?'):
  tid=path.split('taskId=')[-1];t=next((v for v in x.state['salesTasks']if v['id']==tid),None)
  return {'task':copy.deepcopy(t),'messagePermissions':[],'canCancel':False}
 return base(path,opts)
x.request=req

def ck(name,value,detail=None):
 checks.append({'name':name,'passed':bool(value),**({'detail':detail} if detail is not None else {})});print(('PASS 'if value else'FAIL ')+name,flush=True)

def attempt(name,fn):
 try:fn()
 except Exception as e:ck(name,False,str(e))

with sync_playwright()as pw:
 b=pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox']);ctx,p,errors=x.boot_page(b);p.set_default_timeout(7000)
 def wait(ms=220):p.wait_for_timeout(ms)
 def go(k):
  p.evaluate('(k)=>goApp(k)',k);wait(160)
  if p.locator('#r14UnsavedDialog[open]').count():p.locator('#r14UnsavedDialog [data-r14=discard]').click();wait(200)
  wait(170)
 def hr():go('hr');p.evaluate("document.querySelector('#hrProNav [data-hr-view=employees]')?.click()");wait(400)
 def fold():
  if p.locator('.pv-task-info').count()and not p.locator('.pv-task-info').first.get_attribute('open'):p.locator('.pv-task-info>summary').first.click();wait(150)
 def open_menu(k):
  p.locator(f'[data-r35-menu="{k}"]').click();wait(100)
 def close_dialogs():p.evaluate("document.querySelectorAll('dialog[open]').forEach(d=>MoshUI19.close(d.id,true));MoshGrouped35.close();")
 ck('R35 source loaded, original API available',p.evaluate("!!MoshApi.commitTask&&!window.__MOSH_LOCAL_PREVIEW__&&MOSH_UI_RELEASE==='R35'"))
 ck('No preview banners or suppressed submit handler',p.locator('#mosh-preview-banner').count()==0)
 ck('No new glass layer',p.evaluate("getComputedStyle(document.querySelector('.pv-menu-floating')).backdropFilter==='none'"))
 for k in ['hr','sales','marketing','it','accounting','purchases','logistics']:
  def menu_test(k=k):
   go(k);ck(k+': one active section',p.evaluate("k=>document.querySelectorAll('.view.active').length===1&&document.getElementById(k+'App').classList.contains('active')",k))
   ck(k+': grouped menu installed',p.locator(f'[data-r35-menu={k}]').is_visible())
   open_menu(k);ck(k+': accessible groups available',p.locator('.pv-menu-floating:not(.pv-menu-sub) .pv-menu-group').count()>0)
   button=p.locator('.pv-menu-floating:not(.pv-menu-sub) .pv-menu-group').first;button.click();wait(80)
   ck(k+': submenu shows original links',p.locator('.pv-menu-sub .pv-menu-leaf').count()>0)
   p.locator('.pv-menu-sub .pv-menu-leaf').first.click();wait(330)
   ck(k+': leaf closes menu and retains section',p.locator('.pv-menu-floating:not(.pv-menu-sub)').is_hidden() and p.locator('#'+k+'App').is_visible())
   go(k);ck(k+': re-entry dashboard not last leaf',p.evaluate("k=> k==='it'?document.querySelector('#it31Tabs [data-id=dashboard]')?.getAttribute('aria-current')==='page':document.querySelector('#m15Tabs_'+k+' [data-mode=distribution]')?.classList.contains('active')",k))
  attempt(k+' menu sequence',menu_test)
 hr()
 def hr_test():
  rows=p.locator('#h13EmployeeRows tbody tr:has([data-h13=profile])');total=rows.count();ck('HR table retains authorized original records',total>4)
  ck('Display serial is distinct from original employee IDs',p.locator('#h13EmployeeRows .mv-serial').count()==total+1)
  ck('Table rows approximately 44px',abs(rows.first.bounding_box()['height']-44)<3,rows.first.bounding_box()['height'])
  p.locator('.mp-peek').first.click();wait();ck('Employee quick view opens without leaving table',p.locator('#mpEmployeePreview').is_visible()and rows.count()==total)
  p.screenshot(path=str(O/'hr-quick-view.png'),full_page=True)
  p.keyboard.press('Escape');ck('Escape closes quick view',p.locator('#mpEmployeePreview').is_hidden())
  p.locator('#h13EmployeeSearch').fill('المبيعات');wait(450);ck('Search uses original employee filter',rows.count()==1)
  p.locator('.mp-filter-chip').first.click();wait(350);ck('Removing search chip restores list',rows.count()==total)
  p.locator('[data-mp-status=draft]').click();wait(330);ck('Draft employees remain reachable',rows.count()==1)
  p.locator('[data-mp-status=all]').click();wait(250)
  p.locator('.mp-sort').click();wait(100);ck('Name sort marks ascending',p.locator('#h13EmployeeRows th[aria-sort=ascending]').count()==1)
  vals=p.locator('#h13EmployeeRows tbody .mv-serial').all_text_contents();ck('Serial updates after sorting',vals==list(map(str,range(1,len(vals)+1))))
  p.locator('[data-mp-density=comfortable]').click();wait();ck('Comfortable density without font shrinking',p.evaluate("document.documentElement.dataset.mpDensity==='comfortable'"));p.locator('[data-mp-density=compact]').click()
  # Test clicking an existing profile action through the actual actions menu.
  p.locator('.mp-more').first.click();wait();ck('Original row actions available in overflow',p.locator('.mp-action-menu button').count()>0)
  profile=p.locator('.mp-action-menu button').filter(has_text='الملف');
  if profile.count():profile.first.click();wait();ck('Original employee profile opens from new actions',not p.locator('.mp-action-menu').is_visible())
  else:p.keyboard.press('Escape')
  hr();open_menu('hr');p.locator('.pv-menu-group').nth(1).hover();wait();p.screenshot(path=str(O/'hr-menu.png'),full_page=True);p.keyboard.press('Escape');p.keyboard.press('Escape')
 attempt('HR interaction',hr_test)
 def task_test():
  go('tasks');p.evaluate("openTaskDetails('T_design')");wait(500)
  ck('Task defaults collapsed with only title/arrow',p.locator('.pv-task-info').count()==1 and not p.locator('.pv-task-info').get_attribute('open'))
  ck('Chat controls are outside disclosure',p.evaluate("!document.querySelector('.pv-task-info .task-running-chat')&&!!document.querySelector('.task-running-chat')"))
  p.screenshot(path=str(O/'task-collapsed.png'),full_page=True)
  title=p.locator('.pv-task-title').bounding_box();arrow=p.locator('.pv-task-chevron').bounding_box();ck('Arrow immediately left of task name',0<=title['x']-(arrow['x']+arrow['width'])<=16)
  p.locator('.pv-task-info>summary').click();wait(300)
  ck('Task data controls preserved by ID',p.locator('#taskEditTitle_T_design').input_value()=='مهمة اختبار التصميم')
  p.locator('#taskEditTitle_T_design').fill('تعديل اسم المهمة للاختبار');wait();ck('Title editing still works',p.locator('.pv-task-title').inner_text()=='تعديل اسم المهمة للاختبار')
  p.locator('.pv-task-info .r34-deadline select').select_option('2');wait(450);ck('Deadline still calls authoritative backend',any(c['path'].startswith('/r34/deadline')for c in api_calls))
  ck('Returned deadline remains in original input',p.locator('#taskEditDueDate_T_design').input_value()=='2026-10-25')
  ck('Details use three aligned columns',p.locator('.pv-task-inner .field-grid').evaluate("e=>getComputedStyle(e).gridTemplateColumns.split(' ').length") ==3)
  chat=p.locator('.task-running-chat').first.bounding_box();ck('Chat uses 75 percent viewport height',abs(chat['height']-p.viewport_size['height']*.75)<4)
  p.screenshot(path=str(O/'task-expanded.png'),full_page=True)
  before=len([c for c in api_calls if c['path'].startswith('/files-upload')]);p.locator('[data-chat34-task=T_design] input[type=file]').set_input_files({'name':'qa-design.psd','mimeType':'application/octet-stream','buffer':b'not a live customer file'});wait(300)
  ck('Selecting an attachment does not send or upload it',before==len([c for c in api_calls if c['path'].startswith('/files-upload')]))
  p.locator('.pv-task-info>summary').click();wait(100);ck('Closing details does not remove staged chat',p.locator('[data-chat34-task=T_design]').is_visible())
 attempt('Task interactions',task_test)
 def brand_test():
  p.evaluate('MoshBranding35.open()');p.wait_for_selector('#r35BrandForm');p.locator('#r35BrandForm [name=logo]').set_input_files({'name':'qa-logo.png','mimeType':'image/png','buffer':base64.b64decode(PNG.split(',')[1])});wait(200)
  ck('Logo preview is shown before saving',p.locator('.r35-brand-preview img').count()==1)
  ck('Selecting a logo does not save immediately',brand['_version']==0)
  p.locator('#r35BrandForm button[type=submit]').click();wait(500)
  ck('Logo writes to authenticated endpoint',brand['mode']=='image'and brand['_version']==1)
  ck('Saved logo image and independent version displayed',p.locator('#appLogo img').count()==1 and p.locator('#appLogo .app-version').inner_text()=='R35')
  p.evaluate('applySystemSettings()');wait();ck('Legacy settings render cannot replace saved logo',p.locator('#appLogo img').count()==1)
  p.evaluate('MoshBranding35.open()');p.wait_for_selector('#r35BrandForm');p.locator('[data-brand-remove]').click();p.locator('#r35BrandForm button[type=submit]').click();wait(450);ck('Remove logo returns to original text, not data deletion',p.locator('#appLogo img').count()==0 and 'MOSH' in p.locator('#appLogo').inner_text())
 attempt('Logo interactions',brand_test)
 def viewport_test():
  for width in [1280,900,390]:
   close_dialogs();p.set_viewport_size({'width':width,'height':900});hr();open_menu('hr');p.locator('.pv-menu-group').first.click();wait(200)
   ck(str(width)+': nested menu remains on screen',p.locator('.pv-menu-sub').evaluate("e=>{let r=e.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth+1&&r.top>=0&&r.bottom<=innerHeight+1}"))
   ck(str(width)+': no document horizontal overflow',p.evaluate('document.documentElement.scrollWidth<=innerWidth+2'))
   p.keyboard.press('Escape');p.keyboard.press('Escape')
   p.screenshot(path=str(O/f'width-{width}.png'),full_page=True)
  p.set_viewport_size({'width':1440,'height':1000});hr();p.evaluate("document.documentElement.dataset.moshTheme='light';applySystemSettings()");wait(250);ck('Light theme remains readable palette',p.evaluate("getComputedStyle(document.body).color!=='rgb(245, 245, 245)'"));p.screenshot(path=str(O/'light.png'),full_page=True)
 attempt('Responsive / light',viewport_test)
 ck('No unexpected JavaScript errors in tested paths',not errors,errors)
 (O/'results.json').write_text(json.dumps({'checks':checks,'errors':errors,'calls':len(api_calls),'boundary':'Original browser UI with synthetic authenticated API; not Laravel HTTP/SQL.'},ensure_ascii=False,indent=2))
 b.close()
raise SystemExit(0 if all(c['passed'] for c in checks) else 1)
