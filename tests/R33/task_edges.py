from ui_fixture import *
from playwright.sync_api import sync_playwright
OUT=Path(os.environ.get('MOSH_QA_OUT',str(Path(__file__).resolve().parent/'output'/'task_edges')));OUT.mkdir(parents=True,exist_ok=True)
checks=[]
def ck(n,v,d=None):checks.append({'name':n,'passed':bool(v),'detail':d});print(('PASS 'if v else'FAIL ')+n,flush=True)
with sync_playwright() as pw:
 b=pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox']);c,p,errors=boot_page(b)
 p.evaluate("state.salesTasks=state.salesTasks.filter(t=>t.id!=='T_admin');MoshApi.initialize();goApp('purchases');MoshR29.adminTasks();")
 p.wait_for_selector('#r29TaskList [data-r29=task-original]');p.locator('#r29TaskList [data-r29=task-original]').first.click();p.wait_for_timeout(300)
 ck('Task absent from bootstrap is fetched from its authorized API',any(x['path']=='/tasks/T_admin' for x in calls))
 ck('Fetched task opens in visible task screen',p.locator('#tasksApp').is_visible() and p.locator('#taskEditTitle_T_admin').count()==1)
 result=p.evaluate("async()=>{try{const t=await MoshApi.commitTask('T_admin',()=>null);return t?.id;}catch(e){return e.message;}}")
 ck('Fetched record is accepted into save baseline, not a new duplicate task',result=='T_admin',result)
 ck('Read/open action did not create or update business records',not any(x['method']in['POST','PATCH','PUT','DELETE']and x['path'].split('?')[0]in['/tasks','/tasks/T_admin','/transactions']for x in calls))
 p.locator('#allTasksContainer').get_by_role('button',name='رجوع لمهام الإدارة',exact=True).click();p.wait_for_timeout(200)
 p.evaluate("document.querySelectorAll('dialog[open]').forEach(d=>MoshUI19.close(d.id,true));")
 # Return a distinct response for each approval filter to detect stale data reuse.
 p.evaluate("""()=>{window.qEdgeRequest=MoshApi.request;MoshApi.request=(path,o)=>path.startsWith('/r24/admin/approvals?')?Promise.resolve({items:[{id:'X',title:path.includes('status=all')?'QA_ALL_APPROVALS':'QA_MINE_APPROVALS',status:'pending_admin',source:'disciplinary',requester:'QA',stage:'QA',department:'الإدارة'}]}):qEdgeRequest(path,o);}""")
 p.locator('#r24AdminRoot .r24-tab[data-view=approvals]').click();p.wait_for_timeout(160)
 ck('Approval mine filter displays own response','QA_MINE_APPROVALS'in p.locator('#r24Page').inner_text())
 p.locator('[data-r24-approval=status]').select_option('all');p.wait_for_timeout(180)
 ck('Approval all filter displays its different response','QA_ALL_APPROVALS'in p.locator('#r24Page').inner_text())
 p.locator('[data-r24-approval=status]').select_option('mine');p.wait_for_timeout(180)
 ck('Returning to mine never reuses all-filter results','QA_MINE_APPROVALS'in p.locator('#r24Page').inner_text() and 'QA_ALL_APPROVALS'not in p.locator('#r24Page').inner_text())
 ck('Task edge cases produce no uncaught JS errors',not errors,errors)
 c.close();b.close()
(OUT/'report.json').write_text(json.dumps({'checks':checks,'passed':sum(x['passed']for x in checks),'total':len(checks),'pageErrors':errors,'boundary':'Real frontend record baseline and navigation functions with synthetic API records. No actual SQL/HTTP task save.'},ensure_ascii=False,indent=2));print('RESULT',sum(x['passed']for x in checks),len(checks));raise SystemExit(0 if all(x['passed']for x in checks) else 1)
