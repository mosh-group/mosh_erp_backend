"""Actual complete frontend with synthetic API. No company records or real Meta calls."""
from pathlib import Path
import sys,os,json,copy,datetime,hashlib,base64
from urllib.parse import urlsplit,parse_qs
B=Path(__file__).resolve().parents[2];sys.path.insert(0,str(B/'tests/R33'));import ui_fixture as x
from playwright.sync_api import sync_playwright
O=Path(os.environ.get('MOSH_QA_OUT','/mnt/data/r34_work/evidence/r34-browser'));O.mkdir(parents=True,exist_ok=True)
checks=[];calls=[];uploads={};idem={};lost=[True];seq=[0];cfg={'enabled':False,'generalManagerId':'','executiveId':'','reportsTo':{},'routes':{'hr':'direct','purchases':'direct','damage':'chain'},'_version':0,'canConfigure':True,'selfEmployeeId':'E1','isExecutive':False,'people':[{'id':i,'name':'QA '+i,'department':'الإدارة','hasAccount':True}for i in ['E1','E2','E3']],'departmentManagers':{'التصميم':'E1','التسويق':'E2'}}
requests=[];fbposts=[];cancelled=[];fbaccount={'id':'FB1','name':'QA Facebook page','status':'connected','pageId':'12345','customerId':'C1','_version':1}
fb={'accounts':[fbaccount],'posts':fbposts,'configuration':{'configured':True,'scheduler':{'active':True}},'capabilities':{k:True for k in ['compose','direct','schedule','approve','connect','insights','configure']}}
base_request=x.request
now='2026-10-22T13:00:00Z'
def ck(n,v,detail=None):checks.append({'name':n,'passed':bool(v),**({'detail':detail}if detail is not None else{})});print(('PASS 'if v else'FAIL ')+n,flush=True)
def task(id):return next(t for t in x.state['salesTasks']if str(t['id'])==str(id))
def req(path,opts=None):
 opts=opts or {};m=opts.get('method','GET');v=opts.get('body')or{};p=path.split('?')[0];q=parse_qs(urlsplit(path).query);calls.append({'path':path,'method':m,'body':copy.deepcopy(v)})
 def ok(z):return {'value':copy.deepcopy(z)}
 def err(text,status=409,network=False):return {'error':text,'status':status,'network':network}
 if p=='/r34/hierarchy':
  if m=='PUT':
   if not isinstance(v.get('enabled'),bool)or not v.get('generalManagerId')or not v.get('executiveId'):return err('Missing form data',422)
   cfg.update(v);cfg['_version']+=1
  return ok(cfg)
 if p=='/r34/deadline':return ok({'baseDate':'2026-10-22','dueDate':'2026-10-25','mode':q['mode'][0],'days':int(q.get('days',['0'])[0])or None,'monthMeaning':'شهر تقويمي إلى التاريخ المقابل؛ الجمعة إلى السبت'})
 if p=='/r34/chat':
  t=task(q['taskId'][0]);return ok({'task':t,'messagePermissions':[{'id':a['id'],'canModify':not a.get('deletedAt')}for a in t.get('workChat',[])],'canCancel':t['status']=='in_progress'})
 if p in ['/r34/chat/send','/r34/chat/change']:
  key=v['requestId'];t=task(v['taskId'])
  if key in idem:return ok(idem[key])
  if p.endswith('/send'):
   if v.get('text'):seq[0]+=1;t.setdefault('workChat',[]).append({'id':'MSG'+str(seq[0]),'kind':'text','text':v['text'],'byId':x.user['id'],'by':'QA current user','time':now})
   for f in v.get('files',[]):
    rec=next(a for a in uploads.values()if a['key']==f);seq[0]+=1;t['workChat'].append({'id':'FILE'+str(seq[0]),'kind':'media','name':rec['name'],'size':rec['size'],'attachmentKey':f,'byId':x.user['id'],'by':'QA current user','time':now});t.setdefault('attachments',[]).append({k:rec[k]for k in ['key','name','size']})
  else:
   msg=next(a for a in t['workChat']if a['id']==v['messageId'])
   if v.get('messageRevision')!=msg.get('revision',0):return err('Version changed')
   if v['action']=='edit':msg.setdefault('revisions',[]).append({'text':msg['text'],'replacedAt':now,'replacedBy':'QA editor'});msg.update(text=v['text'],editedAt=now,editedBy='QA editor')
   else:msg.update(deletedAt=now,deletedBy='QA manager',deletedById='QA-MANAGER',deletionReason=v.get('reason',''))
   msg['revision']=msg.get('revision',0)+1
  t['_version']+=1;idem[key]=copy.deepcopy(t)
  if p.endswith('/send')and lost[0]:lost[0]=False;return err('Lost response after actual fixture save',0,True)
  return ok(t)
 if p=='/files-upload/start':
  id=hashlib.sha256(v['key'].encode()).hexdigest();uploads.setdefault(id,{**v,'id':id,'chunkSize':262144,'parts':(v['size']+262143)//262144,'received':[],'bytes':{},'complete':False});a=uploads[id];return ok({k:a[k]for k in ['id','chunkSize','parts','received','complete']})
 if p.startswith('/files-upload/'):
  a=uploads[p.split('/')[2]]
  if '/parts/' in p:i=int(p.split('/')[-1]);a['bytes'][i]=base64.b64decode(v['base64']);a['received']=list(a['bytes']);return ok({'index':i})
  if p.endswith('/complete'):
   raw=b''.join(a['bytes'][i]for i in range(a['parts']))
   if len(raw)!=a['size']or hashlib.sha256(raw).hexdigest()!=a['sha256']:return err('Mismatch',422)
   a['complete']=True;return ok({'key':a['key'],'name':a['name'],'size':a['size']})
 if p=='/r34/cancellations':
  if m=='POST':
   if not v.get('reason'):return err('Reason missing',422)
   t=task(v['taskId']);requests.append({'id':'CAN1','taskId':t['id'],'taskTitle':t['title'],'department':t['department'],'customer':t['customer'],'reason':v['reason'],'requestedBy':'QA manager','status':'pending','expiresAt':'2030-01-01T00:00:00Z','attemptsRemaining':3,'canConfirm':True,'canReadCode':False,'_version':1})
   return ok(requests[-1])
  return ok({'items':requests,'pendingCount':0})
 if p=='/r34/approvals':return ok({'hr':[],'damage':[],'canRequestDamage':True,'stockOptions':{'warehouses':[{'id':'W1','name':'QA Stock'}],'items':[{'id':'I1','name':'QA Item'}]},'pendingCount':0})
 if p=='/r34/cancelled-tasks':return ok({'items':cancelled})
 if p.endswith('/confirm')and p.startswith('/r34/cancellations'):
  if v.get('code')!='03456':return err('Wrong code',422)
  r=requests[0];r['status']='approved';r['canConfirm']=False;t=task(r['taskId']);t['status']='cancelled';t['_version']+=1;t['cancellation']={'executedBy':'QA manager','at':now,'reason':r['reason']};cancelled.append(copy.deepcopy(t));return ok({'task':t,'request':r})
 if p.startswith('/r29/customers/')and p.endswith('/archive'):
  c=next(c for c in x.state['customers']if c['id']==p.split('/')[3]);c['active']=not v['archive'];c['_version']=c.get('_version',1)+1;c.setdefault('archiveHistory',[]).append({'by':'QA','reason':v['reason'],'at':now});return ok(c)
 if p.startswith('/customers/')and m=='GET':return ok(next(c for c in x.state['customers']if c['id']==p.split('/')[2]))
 if p=='/social/facebook':return ok(fb)
 if p=='/social/facebook/configuration':
  if m=='PUT' and (not v.get('appId') or not v.get('redirectUri') or not v.get('publicBaseUrl')):return err('Missing configuration form data',422)
  return ok({'appId':'12345','redirectUri':'https://qa.invalid/api/social/facebook/callback','publicBaseUrl':'https://qa.invalid','graphVersion':'v26.0','hasSecret':True,'_version':1})
 if p=='/social/facebook/selections':return ok({'selections':[{'id':'a'*64,'_version':1,'pages':[{'pageId':'12345','name':'QA selected page'}]}]})
 if p=='/social/facebook/select-page':return ok(fbaccount)
 if p=='/social/facebook/posts':
  if not v.get('caption')or not v.get('accountId'):return err('Missing Facebook form data',422)
  a={**v,'id':'FBPOST'+str(len(fbposts)+1),'_version':1,'createdBy':'QA','createdAt':now,'status':'draft'if v['mode']=='draft'else'scheduled'if v['mode']=='schedule'else'published'};fbposts.append(a);return ok(a)
 if p.startswith('/social/facebook/posts/')and p.endswith('/decision'):
  a=next(a for a in fbposts if a['id']==p.split('/')[-2]);action=v['action'];a['status']={'approve':'queued','return':'returned','reject':'rejected_final','cancel':'cancelled'}[action];a['_version']+=1;return ok(a)
 if '/insights' in p and p.startswith('/social/facebook/'):return ok({'days':7,'metrics':[{'key':'views','label':'مشاهدات','available':False}]})
 try:return ok(base_request(path,opts))
 except Exception as e:return err(str(e),500)
x.request=lambda path,opts=None:req(path,opts).get('value')
with sync_playwright()as pw:
 browser=pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox']);ctx,page,errors=x.boot_page(browser);page.expose_function('qa34Request',req)
 page.evaluate("()=>{MoshApi.request=async(p,o={})=>{const r=await qa34Request(p,o);if(r.error){const e=new Error(r.error);e.status=r.status;e.network=r.network;throw e;}return r.value;};}")
 page.set_default_timeout(10000)
 def wait(n=250):page.wait_for_timeout(n)
 def close():page.evaluate("document.querySelectorAll('dialog[open]').forEach(d=>MoshUI19.close(d.id,true));")
 try:
  ck('Full app uses R34 release identity',page.locator('#appLogo .app-version').inner_text()=='R34')
  page.evaluate("goApp('marketing')");wait();ck('Marketing main customer table has serial column','م' in page.locator('#marketingExecV33 thead').inner_text())
  ck('Current and previous customer tabs are in actual marketing workspace',page.locator('#marketingExecV33').get_by_role('button',name='عملاء سابقين',exact=True).count()==1)
  first=page.locator('#marketingExecV33 [data-archive34]').first;cid=first.get_attribute('data-archive34');first.click();wait();page.locator('#r34ArchiveCustomer [name=reason]').fill('QA archive customer');page.locator('#r34ArchiveCustomer button[type=submit]').click();wait(500)
  ck('Customer archive form sends reason before controls disable',any(c['path'].endswith('/archive')and c['body'].get('reason')=='QA archive customer'for c in calls))
  page.locator('#marketingExecV33').get_by_role('button',name='عملاء سابقين',exact=True).click();wait();ck('Archived customer visible in previous tab',page.locator(f'#marketingExecV33 [data-archive34="{cid}"]').count()==1)
  page.locator(f'#marketingExecV33 [data-archive34="{cid}"]').click();wait();page.locator('#r34ArchiveCustomer [name=reason]').fill('QA reactivate same customer');page.locator('#r34ArchiveCustomer button[type=submit]').click();wait(450);ck('Reactivation retains original customer ID',next(c for c in x.state['customers']if c['id']==cid)['active']is True)
  page.evaluate('MoshGovernance34.openHierarchy()');page.wait_for_selector('#r34Hierarchy');page.locator('#r34Hierarchy [name=generalManagerId]').select_option('E3');page.locator('#r34Hierarchy [name=executiveId]').select_option('E2');page.locator('#r34Hierarchy [data-parent34=E1]').select_option('E2');page.locator('#r34Hierarchy [data-parent34=E2]').select_option('E3');page.locator('#r34Hierarchy [name=enabled]').check();page.locator('#r34Hierarchy button[type=submit]').click();wait(500)
  ck('Hierarchy form transmits configured people and relations',cfg['enabled']and cfg['reportsTo']=={'E1':'E2','E2':'E3'}and cfg['executiveId']=='E2')
  close();page.evaluate("goApp('tasks');openTaskDetails('T_design')");wait(600)
  ck('Task has new staged chat composer',page.locator('[data-chat34-task="T_design"]').count()==1)
  before=sum(c['path'].startswith('/files-upload')for c in calls)
  page.locator('[data-chat34-task="T_design"] input[type=file]').set_input_files([{'name':'design.psd','mimeType':'application/octet-stream','buffer':b'QA original bytes'*500},{'name':'preview.jpg','mimeType':'image/jpeg','buffer':b'qa jpeg bytes'}]);wait()
  ck('Selecting files does not upload or send',sum(c['path'].startswith('/files-upload')for c in calls)==before and page.evaluate("MoshChat34.inspect('T_design').files")==2)
  page.locator('[data-chat34-task="T_design"] [data-chat34=remove]').last.click();ck('Unsent remove leaves no chat tombstone',page.evaluate("MoshChat34.inspect('T_design').files")==1 and not task('T_design').get('workChat'))
  page.locator('[data-chat34-task="T_design"] [data-text34]').fill('Original message to correct');page.locator('[data-chat34-task="T_design"] [data-chat34=send]').click();wait(900)
  ck('Explicit send uploads selected file and handles lost reply',len(uploads)==1 and len(task('T_design')['workChat'])==2 and page.evaluate("MoshChat34.inspect('T_design').pending"))
  page.locator('[data-chat34-task="T_design"] [data-chat34=send]').click();wait(700)
  ck('Retry confirms same send without duplicates',len(task('T_design')['workChat'])==2 and not page.evaluate("MoshChat34.inspect('T_design').pending"))
  ck('Successful send clears staged files',page.evaluate("MoshChat34.inspect('T_design').files")==0)
  page.locator('[data-chat34-task="T_design"] [data-chat34=edit]').first.click();page.locator('#r34ChatChange [name=text]').fill('Corrected text');page.locator('#r34ChatChange button[type=submit]').click();wait(500)
  ck('Edited message shows struck original and new text',page.locator('[data-chat34-task="T_design"]').get_by_text('Corrected text',exact=True).count()==1 and 'Original message' in page.locator('[data-chat34-task="T_design"] s').first.inner_text())
  page.locator('[data-chat34-task="T_design"] [data-chat34=delete]').first.click();page.locator('#r34ChatChange [name=reason]').fill('Wrong message');page.locator('#r34ChatChange button[type=submit]').click();wait(500)
  ck('Deleted text remains struck and names actual deleter','QA manager' in page.locator('[data-chat34-task="T_design"]').inner_text()and page.locator('[data-chat34-task="T_design"] s').count()>=2)
  page.locator('[data-chat34-task="T_design"] [data-chat34=delete]').first.click();page.locator('#r34ChatChange [name=reason]').fill('Wrong file');page.locator('#r34ChatChange button[type=submit]').click();wait(500)
  ck('Deleted file card stays visible and download removed','design.psd' in page.locator('[data-chat34-task="T_design"]').inner_text() and page.locator('[data-chat34-task="T_design"] [data-chat34=download]').count()==0)
  page.screenshot(path=str(O/'chat-audit.png'),full_page=False)
  # Date enhancement on actual task edit date.
  page.evaluate("MoshDeadline34.mount()");wait();inp=page.locator('#taskEditDueDate_T_design');ck('Existing task editor gets duration choices',inp.locator('xpath=following-sibling::*[1]').locator('select').count()==1)
  inp.locator('xpath=following-sibling::*[1]').locator('select').select_option('2');wait();ck('Relative date preview supplied by server',inp.input_value()=='2026-10-25'and page.evaluate("MoshDeadline34.ruleFor('taskEditDueDate_T_design').days")==2)
  restored=page.evaluate("()=>{const old=document.querySelector('#taskEditDueDate_T_design'),fresh=old.cloneNode(true);fresh.value=old.value;old.nextElementSibling.remove();old.replaceWith(fresh);MoshDeadline34.mount();const prior={dueDate:fresh.value,dueRule:{mode:'workdays',days:2,baseDate:'2026-10-22',setById:'QA'}};return {mode:fresh.nextElementSibling.querySelector('select').value,rule:MoshDeadline34.ruleFor(fresh.id,prior),ready:MoshDeadline34.ready(fresh.id)};}")
  ck('Reopened editor discards transient selection but preserves saved deadline origin',restored['mode']=='manual'and restored['rule']['setById']=='QA'and restored['ready'])
  page.locator('[data-chat34-task="T_design"] [data-chat34=cancel-task]').click();page.locator('#r34CancelRequest [name=reason]').fill('QA incorrect task');page.locator('#r34CancelRequest button[type=submit]').click();wait(600)
  ck('Cancellation request sends reason and does not cancel immediately',len(requests)==1 and task('T_design')['status']=='in_progress')
  ck('Requester inbox never exposes executive code',page.locator('#r34Inbox [data-r34=cancel-code]').count()==0)
  page.locator('#r34Inbox [data-r34=cancel-confirm]').click();page.locator('#r34CancelConfirm [name=code]').fill('03456');page.locator('#r34CancelConfirm button[type=submit]').click();wait(550)
  ck('Confirmed cancellation keeps original chat record',task('T_design')['status']=='cancelled'and len(task('T_design')['workChat'])==2)
  close();page.evaluate('MoshGovernance34.cancelled()');wait(400);ck('Cancelled tasks archive renders retained task','مهمة اختبار التصميم' in page.locator('#r34Cancelled').inner_text());close()
  page.evaluate("goApp('marketing');openMoshSocialHub('C1')");wait(500)
  ck('Existing Instagram view provides platform switch',page.locator('[data-platform34=facebook]').count()==1)
  page.locator('[data-platform34=facebook]').click();wait(500);ck('Facebook page shown in separate official workspace','QA Facebook page' in page.locator('#marketingSocialHubContent').inner_text())
  page.screenshot(path=str(O/'facebook.png'))
  print('FB BUTTONS',page.locator('#marketingSocialHubContent button').all_text_contents(),flush=True)
  page.locator('[data-fb34=config]').click();page.wait_for_selector('#r34FacebookConfig');ck('Facebook configuration does not return stored app secret',page.locator('#r34FacebookConfig [name=appSecret]').input_value()=='')
  page.locator('#r34FacebookConfig [name=appId]').fill('987654');page.locator('#r34FacebookConfig button[type=submit]').click();wait(450)
  ck('Facebook configuration form transmits only entered settings',any(c['path']=='/social/facebook/configuration'and c['method']=='PUT'and c['body']['appId']=='987654'and c['body']['appSecret']==''for c in calls))
  page.locator('[data-fb34=choose-page]').click();page.wait_for_selector('#r34FacebookChoose');page.locator('#r34FacebookChoose button[type=submit]').click();wait(450)
  ck('Official candidate selection sends exact chosen page',any(c['path']=='/social/facebook/select-page'and c['body']['pageId']=='12345'and c['body']['selectionId']=='a'*64 for c in calls))
  # Explicit draft, immediate publish, and scheduled request use the same shared form but distinct modes.
  for mode in ['draft','publish','schedule']:
   page.locator('[data-fb34=compose]').click();page.wait_for_selector('#r34FacebookCompose');page.locator('#r34FacebookCompose [name=accountId]').select_option('FB1');page.locator('#r34FacebookCompose [name=caption]').fill('QA Facebook '+mode);page.locator('#r34FacebookCompose [name=mode]').select_option(mode)
   if mode=='schedule':page.locator('#r34FacebookCompose [name=scheduledAt]').fill('2030-10-28T14:00')
   page.locator('#r34FacebookCompose button[type=submit]').click();wait(450)
   ck('Facebook '+mode+' submits explicit page and mode',any(c['path']=='/social/facebook/posts'and c['body']['mode']==mode and c['body']['accountId']=='FB1'and c['body']['caption']=='QA Facebook '+mode for c in calls))
  page.locator('[data-fb34=compose]').click();page.wait_for_selector('#r34FacebookCompose');page.locator('#r34FacebookCompose [name=accountId]').select_option('FB1');page.locator('#r34FacebookCompose [name=caption]').fill('QA photo draft');page.locator('#r34FacebookCompose [name=mediaKind]').select_option('image');before_upload=len(uploads)
  page.locator('#r34FacebookCompose [name=media]').set_input_files({'name':'page-image.png','mimeType':'image/png','buffer':base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aZJ8AAAAASUVORK5CYII=')});wait();ck('Choosing Facebook media alone does not upload',len(uploads)==before_upload)
  page.locator('#r34FacebookCompose button[type=submit]').click();wait(700);ck('Facebook file uploads only on explicit save and is linked to draft',len(uploads)==before_upload+1 and fbposts[-1]['attachmentKey'].startswith('fb/')and fbposts[-1]['status']=='draft')
  page.locator('[data-fb34=insights]').click();page.wait_for_selector('#r34FacebookInsights');wait(250);ck('Unavailable Facebook insight is not invented as zero','غير متاح' in page.locator('#r34FacebookInsights').inner_text()and page.locator('#r34FacebookInsights strong').count()==0);close()
  fbposts.append({'id':'FBPENDING','_version':1,'accountId':'FB1','caption':'QA pending approval','status':'pending_approval'});page.locator('[data-fb34=refresh]').click();wait(450);page.locator('[data-fb34=return][data-id=FBPENDING]').click();page.locator('#r34FacebookDecision [name=note]').fill('QA correction requested');page.locator('#r34FacebookDecision button[type=submit]').click();wait(450)
  ck('Facebook return for correction remains editable',fbposts[-1]['status']=='returned'and page.locator('[data-fb34=edit][data-id=FBPENDING]').count()==1)
  fbposts[-1]['status']='pending_approval';fbposts[-1]['_version']+=1;page.locator('[data-fb34=refresh]').click();wait(400);page.locator('[data-fb34=reject][data-id=FBPENDING]').click();page.locator('#r34FacebookDecision [name=note]').fill('QA final rejection');page.locator('#r34FacebookDecision button[type=submit]').click();wait(450)
  ck('Final rejected Facebook request has no edit or cancel control',fbposts[-1]['status']=='rejected_final'and page.locator('[data-fb34=edit][data-id=FBPENDING]').count()==0 and page.locator('[data-fb34=cancel][data-id=FBPENDING]').count()==0)
  page.locator('[data-platform34=instagram]').click();wait(450);ck('Switching back preserves existing Instagram workspace',page.locator('#marketingSocialHubContent').is_visible()and'Instagram' in page.locator('#marketingSocialHubContent').inner_text()and page.locator('[data-fb34=compose]').count()==0);page.locator('[data-platform34=facebook]').click();wait(450)

  ck('Future platforms not presented as connected','لاحقاً' in page.locator('#marketingSocialHubContent').inner_text()or'TikTok' in page.locator('#marketingSocialHubContent').inner_text())
  for theme in ['light','dark']:
   page.evaluate('(t)=>MoshTheme21.apply(t)',theme);wait();ck('Social workspace remains in '+theme,page.locator('#marketingSocialHubContent').is_visible());page.screenshot(path=str(O/f'facebook-{theme}.png'))
  page.set_viewport_size({'width':430,'height':932});wait(400);ck('Facebook workspace fits narrow viewport',page.evaluate("()=>{const e=document.querySelector('#marketingSocialHubContent');return e.scrollWidth<=e.clientWidth+2;}"));page.screenshot(path=str(O/'facebook-mobile.png'));page.set_viewport_size({'width':1440,'height':1000});wait()
  ck('No uncaught JavaScript errors',not errors,errors)
 except Exception as e:
  ck('Completed new frontend acceptance',False,str(e));page.screenshot(path=str(O/'failure.png'));print(str(e),flush=True)
 finally:
  (O/'results.json').write_text(json.dumps({'passed':sum(c['passed']for c in checks),'total':len(checks),'checks':checks,'errors':errors,'boundary':'Actual complete HTML/CSS/JS with synthetic records/API. No Laravel SQL/Meta.'},ensure_ascii=False,indent=2));(O/'calls.json').write_text(json.dumps(calls,ensure_ascii=False,indent=2));browser.close()
raise SystemExit(0 if all(c['passed']for c in checks)else 1)
