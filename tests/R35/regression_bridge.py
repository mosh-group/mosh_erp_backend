"""Run originals with explicit approved DOM-path changes. No assertions removed.
Only release labels, moved-menu clicks, date-widget selector, and disclosure opening are adapted.
"""
import sys
from pathlib import Path
p=Path(sys.argv[1]);sys.path.insert(0,str(p.parent))
from ui_paths import grouped_click
s=p.read_text()
for old in ["=='R33'","=='R34'","=='R31.1'"]:s=s.replace(old,"=='R35'")
s=s.replace(".endswith('R31.1')",".endswith('R35')")
s=s.replace("page.locator(f'#it31Tabs [data-id={tab}]').click()","grouped_click(page,f'#it31Tabs [data-id={tab}]','it')")
s=s.replace("p.locator('#it31Tabs [data-id=subscriptions]').click()","grouped_click(p,'#it31Tabs [data-id=subscriptions]','it')")
s=s.replace("p.locator('#'+k+'App '+sel).filter(visible=True).first.click()","grouped_click(p,'#'+k+'App '+sel,k)")
for sel,k in [('#r29ClientNav','marketing'),('#r29TeamNav','hr'),('#r24PurchasingInbox','purchases')]:
 s=s.replace("page.locator('"+sel+"').click()","grouped_click(page,'"+sel+"','"+k+"')")
if p.name=='browser_features.py':
 s=s.replace("page.evaluate(\"MoshDeadline34.mount()\");wait();inp=", "page.locator('.pv-task-info>summary').click();page.evaluate(\"MoshDeadline34.mount()\");wait();inp=")
 s=s.replace("inp.locator('xpath=following-sibling::*[1]').locator('select')", "page.locator('.r34-deadline[data-r35-for=taskEditDueDate_T_design] select')")
 s=s.replace('old.nextElementSibling.remove();',"document.querySelector('.r34-deadline[data-r35-for=taskEditDueDate_T_design]').remove();")
exec(compile(s,str(p),'exec'),{'__file__':str(p),'__name__':'__main__','grouped_click':grouped_click})
