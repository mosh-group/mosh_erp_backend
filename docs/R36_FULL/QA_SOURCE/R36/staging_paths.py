"""Validate actual creation/return controls, not only shared file validator."""
from pathlib import Path
import os,sys,json,copy
B=Path(__file__).resolve().parents[2];os.environ['MOSH_PROJECT']=str(B.parent);sys.path.insert(0,str(B/'tests/R33'))
import ui_fixture as x
from playwright.sync_api import sync_playwright
O=Path(os.environ.get('MOSH_QA_OUT',str(B/'tests/R36/output/staging')));O.mkdir(parents=True,exist_ok=True);large=O/'qa-large.psd'
with large.open('wb')as f:f.truncate(26*1024*1024)
checks=[]
def ck(n,v,d=None):checks.append({'name':n,'passed':bool(v),'detail':d});print(('PASS 'if v else'FAIL ')+n,flush=True)
base=x.request;x.request=lambda path,opts=None:{'mode':'text','image':None,'_version':0}if path=='/ui/branding'else base(path,opts)
with sync_playwright()as pw:
 b=pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox']);ctx,p,err=x.boot_page(b);p.set_default_timeout(12000)
 try:
  p.evaluate('openStandaloneTaskModal()');p.wait_for_timeout(200);before=len(x.calls)
  p.locator('#standaloneAttachmentInput').set_input_files(str(large));p.wait_for_timeout(300)
  ck('Creation picker accepts actual 26MiB file',p.evaluate("standalonePendingAttachments.length===1&&standalonePendingAttachments[0].size===26*1024*1024"))
  ck('Creation selection is staged without network upload',not any('files-upload'in c['path']for c in x.calls[before:]))
  p.locator('#standaloneAttachmentsList button').click();p.wait_for_timeout(150);ck('Unsent creation attachment can be removed',p.evaluate('standalonePendingAttachments.length===0'))
  p.evaluate('setDirtyState(false);cancelStandaloneTask();goApp(\'tasks\');state.salesTasks.find(t=>t.id===\'T_design\').employeeState=\'submitted\';MoshTaskInbox49.requestReturn(\'T_design\')');p.wait_for_timeout(250)
  p.locator('#mr49ReturnFiles').set_input_files(str(large));p.wait_for_timeout(350)
  ck('Return-for-revision picker accepts same 26MiB file',p.locator('#mr49ReturnFileCount').inner_text()=='1 / 5' and 'qa-large.psd'in p.locator('#mr49ReturnFileList').inner_text())
  ck('No 25MB help text remains in return dialog','25 MB لكل ملف'not in p.locator('#mr49ReturnDialog').inner_text())
  ck('No false upload error or upload before confirming return',p.locator('#mr49ReturnError').is_hidden()and not any('files-upload'in c['path']for c in x.calls[before:]))
  p.locator('[data-mr49-return-remove]').click();ck('Removing selected return file leaves empty draft',p.locator('#mr49ReturnFileCount').inner_text()=='0 / 5')
  p.locator('#mr49ReturnFiles').set_input_files({'name':'unsafe.php','mimeType':'text/plain','buffer':b'<?php test;'});p.wait_for_timeout(120)
  ck('Dangerous extensions remain blocked',p.locator('#mr49ReturnFileCount').inner_text()=='0 / 5'and p.locator('#mr49ReturnError').is_visible())
  ck('No uncaught script error in original pickers',not err,err)
 except Exception as e:ck('Actual picker flow completed',False,str(e))
 finally:ctx.close();b.close();large.unlink(missing_ok=True)
(O/'results.json').write_text(json.dumps({'checks':checks,'passed':sum(c['passed']for c in checks),'total':len(checks),'boundary':'Original browser file pickers with actual local file bytes; no real HTTP upload in this suite. Large HTTP test separate.'},ensure_ascii=False,indent=2))
raise SystemExit(0 if all(c['passed']for c in checks)else 1)
