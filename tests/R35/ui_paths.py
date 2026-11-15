"""Real user clicks on the new menu; no force-clicking hidden legacy rails."""
def grouped_click(page,selector,section=None):
    page.wait_for_timeout(150)
    loc=page.locator(selector).first
    if loc.is_visible():
        loc.click();return
    data=page.evaluate("""([sel,k])=>{const e=document.querySelector(sel),key=k||document.querySelector('.view.active')?.id.replace(/App$/,'');const gs=MoshGrouped35.groups(key);for(let i=0;i<gs.length;i++){const j=gs[i].rows.indexOf(e);if(j>=0)return {key,i,j};}return null;}""",[selector,section])
    if not data:raise AssertionError('No permitted original link for '+selector)
    page.locator('[data-r35-menu="'+data['key']+'"]').click()
    page.locator('.pv-menu-floating:not(.pv-menu-sub) .pv-menu-group').nth(data['i']).click()
    page.locator('.pv-menu-sub .pv-menu-leaf').nth(data['j']).click()
    page.wait_for_timeout(100)
