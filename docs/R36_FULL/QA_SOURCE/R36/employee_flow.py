"""Actual employee UI/transport code; server documents simulated, no production DB."""
from pathlib import Path
import os,sys,json,copy,time
B=Path(__file__).resolve().parents[2];os.environ['MOSH_PROJECT']=str(B.parent);sys.path.insert(0,str(B/'tests/R33'))
import ui_fixture as x
from playwright.sync_api import sync_playwright
O=Path(os.environ.get('MOSH_QA_OUT',str(B/'tests/R36/output/employee')));O.mkdir(parents=True,exist_ok=True)
checks=[];calls=[]
def ck(n,v,d=None):checks.append({'name':n,'passed':bool(v),'detail':d});print(('PASS 'if v else'FAIL ')+n,flush=True)
limited={**copy.deepcopy(x.user),'id':'EMP36','name':'موظف اختبار التسويق','employeeId':'E_marketing','department':'التسويق','roleId':'role_employee','active':True,'isRoot':False,'mustChange':False,'landing':'running','taskScope':'mine','moduleOverrides':{'marketing':{'view':True,'running':True,'list':True,'details':True,'status':True,'attachments':True,'edit':False,'delete':False},'admin':{'view':False}},'globalOverrides':{'createTasks':False}}
x.boot['user']=limited;x.boot['users']=[limited];x.ns['user']=limited
x.state['salesTasks']=[copy.deepcopy(next(t for t in x.state['salesTasks']if t['id']=='T_marketing'))]
t=x.state['salesTasks'][0];t.update(employeeState='working',employeeId='E_OLD',workTiming={'totalMs':255000,'activeStartedAt':0,'sessions':[{'startAt':'2026-10-30T10:00:00Z','endAt':'2026-10-30T10:04:15Z','ms':255000}]})
x.ns['mocktasks']=x.state['salesTasks'];base=x.request

def req(path,options=None):
 options=options or {};method=options.get('method','GET');calls.append((path,method,copy.deepcopy(options.get('body'))))
 if path=='/ui/branding':return {'mode':'text','image':None,'_version':0}
 if path.split('?')[0]=='/tasks':return {'data':copy.deepcopy(x.state['salesTasks']),'meta':{'last_page':1,'current_page':1}}
 if path=='/transactions'and method=='POST':
  changes=[]
  for op in options['body']['operations']:
   if op['resource']=='tasks':
    assert op['id']=='T_marketing'and op['action']=='update'and op['version']==t['_version']
    t.update(op['data']);t['_version']+=1;t['workTiming']['activeStartedAt']=int(time.time()*1000)if t['employeeState']=='working'else 0
    changes.append({'resource':'tasks','id':t['id'],'data':copy.deepcopy(t)})
   elif op['resource']=='work-presence':
    changes.append({'resource':'work-presence','id':op['id'],'data':{**op['data'],'id':op['id'],'_version':op.get('version',0)+1}})
  return {'changes':changes,'transactionId':'QA_TX_'+str(len(calls))}
 if path=='/tasks/T_marketing'and method=='PATCH':
  patch=options['body'];assert patch['_version']==t['_version'];old=t['_version'];t.update({k:v for k,v in patch.items()if k!='_version'});t['_version']=old+1
  # Fixture confirms pause; actual elapsed server-clock rules tested separately in PHP.
  t['workTiming']['activeStartedAt']=int(time.time()*1000) if t['employeeState']=='working' else 0
  return copy.deepcopy(t)
 return base(path,options)
x.request=req
with sync_playwright()as pw:
 b=pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox']);ctx,p,errs=x.boot_page(b);p.set_default_timeout(5000)
 try:
  p.evaluate("goApp('marketing')");p.wait_for_timeout(500)
  ck('Task owned by canonical assignee despite stale employeeId',p.evaluate("MoshEmployeeTasks41.isMine(state.salesTasks[0])"))
  ck('Former assignee ID does not override another current assignee',p.evaluate("!MoshEmployeeTasks41.isMine({...state.salesTasks[0],assigneeId:'OTHER',employeeId:'E_marketing'})"))
  ck('Unassigned explicit task is not taken by matching name',p.evaluate("!MoshEmployeeTasks41.isMine({...state.salesTasks[0],assigneeId:'',assignee:'موظف اختبار التسويق'})"))
  ck('Mine list initially includes working task',p.locator('[data-auth38-open=T_marketing]').is_visible())
  ok=p.evaluate("async()=>await MoshTaskInbox49.perform('pause','T_marketing')");p.wait_for_timeout(450)
  ck('Actual pause action confirmed',ok and t['employeeState']=='paused',{'status':t['employeeState'],'error':p.evaluate("MoshTaskInbox49.error('T_marketing')"),'calls':calls[-2:]})
  ck('Pause preserves original ID/assignee/255 seconds',t['id']=='T_marketing'and t['assigneeId']=='E_marketing'and t['workTiming']['totalMs']==255000)
  p.evaluate("goApp('dashboard');goApp('marketing')");p.wait_for_timeout(700)
  ck('Paused task remains in employee list after navigating back',p.locator('[data-auth38-open=T_marketing]').is_visible())
  ck('Paused status label visible','متوقفة مؤقتاً'in p.locator('[data-auth38-host=marketing]').inner_text())
  p.evaluate("async()=>await MoshApi.refreshTasks()");p.wait_for_timeout(400)
  ck('Task refresh retains paused record',p.evaluate("MoshEmployeeTasks41.mine().some(t=>t.id==='T_marketing'&&t.employeeState==='paused')"))
  p.locator('[data-auth38-open=T_marketing]').click();p.wait_for_timeout(2300)
  # The read-only task may receive the approved collapsed details layout.
  # Verify the actual employee panel survives, not visibility of a folded input.
  panel=p.locator('#emp47TaskDialog')
  ck('Task panel remains open across periodic mine redraw',panel.is_visible() and p.locator('#emp47TaskTitle').inner_text()=='مهمة اختبار التسويق' and panel.locator('[data-emp48-action="start"]').count()>0)
  panel.evaluate('e=>e.dataset.qaR36Identity="same-panel"')
  p.wait_for_timeout(2300)
  ck('Same task panel survives another periodic redraw',p.locator('#emp47TaskDialog').is_visible()and p.locator('#emp47TaskDialog').get_attribute('data-qa-r36-identity')=='same-panel' and p.locator('#emp47TaskTitle').inner_text()=='مهمة اختبار التسويق')
  ck('Resume available without a new receipt',p.evaluate("MoshTaskInbox49.actionHtml(state.salesTasks[0]).includes('data-emp48-action=\"start\"')"))
  p.screenshot(path=str(O/'employee-paused.png'),full_page=True)
  ck('No task recreation, reassignment or employee mutation',all(op['action']=='update' and op['resource'] in ['tasks','work-presence'] and not any(k in op['data'] for k in ['assigneeId','employeeId']) for path,m,body in calls if path=='/transactions' for op in body['operations']))
  ck('Employee UI has no unhandled errors',not errs,errs)
 except Exception as e:ck('Employee flow completed',False,str(e))
 finally:ctx.close()
 # A fresh browser/login built from confirmed server state, rather than preserving client cache.
 ctx,p,errs=x.boot_page(b)
 try:
  p.evaluate("goApp('marketing')");p.wait_for_timeout(350)
  ck('Fresh login keeps paused task visible',p.locator('[data-auth38-open=T_marketing]').is_visible())
  ck('Fresh login never starts paused clock',t['employeeState']=='paused'and t['workTiming']['activeStartedAt']==0)
  ok=p.evaluate("async()=>await MoshTaskInbox49.perform('start','T_marketing')");p.wait_for_timeout(250)
  ck('Resume operates on same task without recreating it',ok and t['employeeState']=='working'and len(x.state['salesTasks'])==1 and t['workTiming']['totalMs']==255000)
  ck('No unhandled error after fresh login and resume',not errs,errs)
 except Exception as e:ck('Fresh login flow completed',False,str(e))
 finally:ctx.close()
 # Root full-snapshot safety check, original api-client operations and save error handling.
 x.boot['user']=x.user;x.boot['users']=[x.user];x.ns['user']=x.user;ctx,p,errs=x.boot_page(b)
 try:
  before=len(calls);original=p.evaluate('state.staffProfiles.length')
  r=p.evaluate("async()=>{state.staffProfiles.pop();try{await MoshApi.save();return 'unexpected success'}catch(e){return e.code||e.message}}")
  ck('Omitted employee aborts whole save before delete reaches server',r=='EMPLOYEE_DATA_GUARD'and not any(path=='/transactions'and m=='POST'for path,m,_ in calls[before:]),r)
  ck('Error restores original employee projection',p.evaluate('state.staffProfiles.length')==original)
 except Exception as e:ck('Employee preservation flow completed',False,str(e))
 finally:ctx.close();b.close()
(O/'results.json').write_text(json.dumps({'checks':checks,'total':len(checks),'passed':sum(c['passed']for c in checks),'boundary':'Real UI/transport to synthetic documents, not Laravel HTTP/SQL; clock tested separately.'},ensure_ascii=False,indent=2))
raise SystemExit(0 if all(c['passed']for c in checks)else 1)
