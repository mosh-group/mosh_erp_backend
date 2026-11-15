"""Full original boot loader + real fetch/transport over browser-routed fixtures.
No Laravel session or network server is represented by these fixtures.
"""
from ui_fixture import *
from playwright.sync_api import sync_playwright
import mimetypes
OUT=Path(os.environ.get('MOSH_QA_OUT',str(Path(__file__).resolve().parent/'output'/Path(__file__).stem)));OUT.mkdir(parents=True,exist_ok=True)
checks=[];errors=[];console=[];seen=[];blocked=[]
def check(n,v,d=None):
 checks.append({'name':n,'passed':bool(v),'detail':d});print(('PASS ' if v else 'FAIL ')+n,flush=True)
CORS={'Access-Control-Allow-Origin':'null','Access-Control-Allow-Credentials':'true','Access-Control-Allow-Headers':'*','Access-Control-Allow-Methods':'GET,POST,PUT,PATCH,DELETE,OPTIONS','Date':'Tue, 20 Oct 2026 10:30:00 GMT'}
def serve(route):
 r=route.request;u=urlsplit(r.url);seen.append({'url':r.url,'method':r.method})
 if u.hostname!='qa.invalid':blocked.append(r.url);return route.abort()
 headers=dict(CORS)
 if r.method=='OPTIONS':return route.fulfill(status=204,headers=headers)
 if u.path.startswith('/api/'):
  path=u.path[4:]+(('?'+u.query) if u.query else '')
  if path=='/auth/status':data={'authenticated':True,'setupRequired':False,'csrfToken':'qa-csrf'}
  elif path=='/bootstrap':data=copy.deepcopy(boot)
  else:
   opts={'method':r.method}
   if r.post_data:
    try:opts['body']=json.loads(r.post_data)
    except:opts['body']={}
   data=request(path,opts)
  return route.fulfill(status=200,headers=headers,content_type='application/json',body=json.dumps({'success':True,'data':data}))
 if u.path=='/config.js':return route.fulfill(headers=headers,content_type='application/javascript',body="window.API_BASE_URL='https://qa.invalid/api';")
 p=(F/u.path.lstrip('/')).resolve()
 if not p.is_relative_to(F.resolve()) or not p.is_file():return route.fulfill(status=404,headers=headers,body='not found')
 return route.fulfill(headers=headers,body=p.read_bytes(),content_type=mimetypes.guess_type(p.name)[0]or'application/octet-stream')
with sync_playwright() as pw:
 b=pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox']);ctx=b.new_context(viewport={'width':1440,'height':1000});ctx.route('**/*',serve)
 p=ctx.new_page();p.on('pageerror',lambda e:errors.append(str(e)));p.on('console',lambda m:console.append(m.text) if m.type=='error' else None);p.on('dialog',lambda d:d.accept());p.set_default_timeout(10000)
 soup=BeautifulSoup((F/'index.html').read_text(),'html.parser')
 base=soup.new_tag('base',href='https://qa.invalid/');soup.head.insert(0,base)
 shim=soup.new_tag('script');shim.string="""for(const key of ['localStorage','sessionStorage']){const m=new Map();Object.defineProperty(window,key,{value:{getItem:k=>m.get(k)||null,setItem:(k,v)=>m.set(k,String(v)),removeItem:k=>m.delete(k),clear:()=>m.clear(),key:i=>[...m.keys()][i]||null,get length(){return m.size;}}});}""";soup.head.insert(1,shim)
 p.set_content(str(soup),wait_until='load');p.wait_for_function("window.MoshNavigation33 && window.MoshAuth37 && window.MoshApi?.user",timeout=20000);p.wait_for_timeout(2000)
 check('Original api-boot loads complete R33 manifest through fetch',bool(p.evaluate('window.MoshNavigation33')))
 check('Original API transport remains unmodified in the browser','fetch(' in p.evaluate('MoshApi.request.toString()'))
 check('Original transport requests auth/status and bootstrap',all(any(x['url'].endswith(y) for x in seen) for y in ['/api/auth/status','/api/bootstrap']))
 check('Every current manifest script loaded once',all(sum(x['url'].split('qa.invalid/')[-1]==s for x in seen)==1 for s in manifest['scripts']))
 check('Boot cover removed and dashboard visible',p.locator('#dashboardApp').is_visible())
 for k in ['admin','marketing','it']:
  p.evaluate('(k)=>goApp(k)',k);p.wait_for_timeout(200)
  if k=='it':p.locator('#it31Tabs [data-id=subscriptions]').click()
  if k=='admin':p.locator('#r24AdminRoot [data-view=employees]').click()
  p.wait_for_timeout(200);p.evaluate('async()=>{await MoshApi.refresh()}');p.wait_for_timeout(400)
  check(k+': real API refresh preserves current visible section',p.locator('#'+k+'App').is_visible())
  if k=='it':check('IT subscriptions survive bootstrap data refresh',p.locator('#it31Tabs [data-id=subscriptions]').get_attribute('aria-current')=='page')
  if k=='admin':check('Administration employees remain selected during data refresh',p.locator('#r24AdminRoot [data-view=employees]').get_attribute('aria-selected')=='true')
  p.evaluate('(k)=>goApp(k)',k);p.wait_for_timeout(250)
  if k=='it':check('Explicit IT department click resets dashboard after refresh',p.locator('#it31Tabs [data-id=dashboard]').get_attribute('aria-current')=='page')
  if k=='admin':check('Explicit Administration click resets dashboard after refresh',p.locator('#r24AdminRoot [data-view=dashboard]').get_attribute('aria-selected')=='true')
 check('No uncaught JavaScript error during actual loader/transport execution',not errors,errors)
 check('No production company or Meta URL was requested',not blocked,blocked)
 p.screenshot(path=str(OUT/'boot-it.png'));ctx.close();b.close()
report={'total':len(checks),'passed':sum(x['passed'] for x in checks),'checks':checks,'pageErrors':errors,'consoleErrors':console,'boundary':'Real original api-client/api-boot and browser fetch, with local file responses and API fixtures routed in Chromium. Not HTTP/Laravel/SQL integration.'}
(OUT/'report.json').write_text(json.dumps(report,ensure_ascii=False,indent=2));print('RESULT',report['passed'],report['total']);raise SystemExit(0 if report['passed']==report['total']else 1)
