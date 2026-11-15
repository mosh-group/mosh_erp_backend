import sys,os
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'R33'))
from ui_fixture import *
from playwright.sync_api import sync_playwright
with sync_playwright() as pw:
 b=pw.chromium.launch(executable_path='/usr/bin/chromium',headless=True,args=['--no-sandbox'])
 ctx,p,e=boot_page(b);print('BOOT',e,flush=True)
 for expr in ["goApp('admin')","goApp('design')","document.dispatchEvent(new CustomEvent('mosh:refreshed'))","document.dispatchEvent(new CustomEvent('mosh:logout-complete'))","MoshApi.user={...MoshApi.user,id:'NEW'}","goApp('admin')"]:
  print('START',expr,flush=True);p.evaluate('()=>{'+expr+'}');print('EVALDONE',flush=True);p.wait_for_timeout(250);print('DONE',e,flush=True)
 b.close()
