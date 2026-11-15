"""Native boot/fetch; simulated auth HTTP replies served by Playwright, no live Laravel."""
from pathlib import Path
import os,sys,json,copy,mimetypes,time
B=Path(__file__).resolve().parents[2];os.environ['MOSH_PROJECT']=str(B.parent);sys.path.insert(0,str(B/'tests/R33'))
import ui_fixture as x
from bs4 import BeautifulSoup
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright
O=Path(os.environ.get('MOSH_QA_OUT',str(B/'tests/R36/output/startup')));O.mkdir(parents=True,exist_ok=True)
checks=[];seen=[];errors=[];blocked=[];held=[];login=[]
def ck(n,v,d=None):checks.append({'name':n,'passed':bool(v),'detail':d});print(('PASS 'if v else'FAIL ')+n,flush=True)
H={'Access-Control-Allow-Origin':'null','Access-Control-Allow-Credentials':'true','Access-Control-Allow-Headers':'*','Access-Control-Allow-Methods':'GET,POST,OPTIONS','Date':'Fri, 30 Oct 2026 10:30:00 GMT'}
def reply(route,data,code=200):route.fulfill(status=code,headers=H,content_type='application/json',body=json.dumps({'success':code<400,'data':data,**({'message':'بيانات الدخول غير صحيحة'}if code>=400 else{})}))
def serve(route):
 r=route.request;u=urlsplit(r.url);seen.append({'path':u.path,'url':r.url,'method':r.method})
 if u.hostname!='qa.invalid':blocked.append(r.url);return route.abort()
 if r.method=='OPTIONS':return route.fulfill(status=204,headers=H)
 if u.path=='/api/auth/status':held.append(route);return
 if u.path=='/api/auth/login':
  login.append({'headers':dict(r.headers),'body':json.loads(r.post_data)})
  return reply(route,{'csrfToken':'new-test-csrf'},401 if len(login)==1 else 200)
 if u.path.startswith('/api/'):
  path=u.path[4:]+(('?'+u.query)if u.query else'')
  if path=='/bootstrap':return reply(route,copy.deepcopy(x.boot))
  if path=='/ui/branding':return reply(route,{'mode':'text','image':None,'_version':0})
  return reply(route,x.request(path,{'method':r.method,'body':json.loads(r.post_data)if r.post_data else None}))
 if u.path=='/config.js':return route.fulfill(headers=H,content_type='text/javascript',body="window.API_BASE_URL='https://qa.invalid/api';")
 f=(x.F/u.path.lstrip('/')).resolve()
 if not f.is_relative_to(x.F.resolve())or not f.is_file():return route.fulfill(status=404,headers=H,body='not found')
 route.fulfill(headers=H,content_type=mimetypes.guess_type(f.name)[0]or'application/octet-stream',body=f.read_bytes())
with sync_playwright()as pw:
 b=pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox']);ctx=b.new_context(viewport={'width':1366,'height':900});ctx.route('**/*',serve);p=ctx.new_page();p.on('pageerror',lambda e:errors.append(str(e)));p.on('dialog',lambda d:d.accept());p.set_default_timeout(12000)
 soup=BeautifulSoup((x.F/'index.html').read_text(),'html.parser');base=soup.new_tag('base',href='https://qa.invalid/');soup.head.insert(0,base)
 shim=soup.new_tag('script');shim.string="for(const key of ['localStorage','sessionStorage']){const m=new Map();Object.defineProperty(window,key,{value:{getItem:k=>m.get(k)||null,setItem:(k,v)=>m.set(k,String(v)),removeItem:k=>m.delete(k),clear:()=>m.clear(),key:i=>[...m.keys()][i]||null,get length(){return m.size;}}});}";soup.head.insert(1,shim)
 try:
  begin=time.monotonic();p.set_content(str(soup),wait_until='domcontentloaded');p.wait_for_selector('#entry36Username',state='visible');visible=round((time.monotonic()-begin)*1000)
  p.wait_for_timeout(200);ck('Login visible while auth response is still held',len(held)==1 and p.locator('#entry36Password').is_visible(),{'fixture_ms_to_visible':visible})
  ck('No department manifest, scripts or bootstrap before session confirmation',not any(r['path']=='/api-map.json'or r['path']=='/api/bootstrap'or r['path'].startswith('/js/02-')for r in seen))
  ck('Submit disabled while session/CSRF unavailable',p.locator('#entry36Submit').is_disabled())
  reply(held.pop(),{'authenticated':False,'setupRequired':False,'csrfToken':'test-csrf'});p.wait_for_function("!document.querySelector('#entry36Submit').disabled")
  ck('Unauthenticated session still does not load department scripts',not any(r['path']=='/api-map.json'for r in seen))
  p.screenshot(path=str(O/'login.png'));p.locator('#entry36Username').fill('synthetic_user');p.locator('#entry36Password').fill('not-real-password');p.locator('#entry36Submit').click();p.wait_for_timeout(300)
  ck('Rejected password leaves interactive login and clears password',p.locator('#entry36Submit').is_enabled()and p.locator('#entry36Password').input_value()==''and p.locator('#entry36Status').get_attribute('role')=='alert')
  ck('CSRF carried on actual login POST',login[0]['headers'].get('x-csrf-token')=='test-csrf'and login[0]['body']['username']=='synthetic_user')
  ck('Wrong password did not bootstrap application',not any(r['path']=='/api/bootstrap'for r in seen))
  p.locator('#entry36Password').fill('another-test-only');p.locator('#entry36Submit').click();p.wait_for_function("window.MoshGrouped35&&window.MoshApi?.user&&!document.documentElement.classList.contains('mosh-booting')",timeout=30000);p.wait_for_timeout(450)
  ck('Successful login loads real app scripts then removes entry overlay',p.locator('#moshEntry36').count()==0 and p.locator('#dashboardApp').is_visible())
  ck('Each manifest script loaded exactly once',all(sum(r['url'].split('qa.invalid/')[-1]==s for r in seen)==1 for s in x.manifest['scripts']))
  ck('Post-authentication styles are activated',p.locator('link[data-r36-deferred-style]').evaluate_all("es=>es.every(e=>e.media==='all'&&!!e.sheet)"))
  ck('Performance marks emitted',p.evaluate("performance.getEntriesByName('mosh36-login-visible').length===1&&performance.getEntriesByName('mosh36-app-ready').length===1"))
  ck('No unhandled JS errors or production request',not errors and not blocked,{'errors':errors,'blocked':blocked})
 except Exception as e:ck('Startup sequence completed',False,str(e))
 finally:ctx.close();b.close()
(O/'results.json').write_text(json.dumps({'checks':checks,'passed':sum(c['passed']for c in checks),'total':len(checks),'boundary':'Browser routed synthetic auth/bootstrap. Timing is local fixture only, not production latency.'},ensure_ascii=False,indent=2))
raise SystemExit(0 if all(c['passed']for c in checks)else 1)
