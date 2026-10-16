"""Offline stage-2 UI regression. API responses are fixtures, not live Bitrix."""
from pathlib import Path
import json, os, re, shutil
from urllib.parse import parse_qs
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parent.parent
text=(root/'access_legacy.php').read_text();text=text[text.index('<!doctype html>'):]
text=re.sub(r'<\?= json_encode\([\s\S]*?\?>',json.dumps({'basePath':'','sessid':'test','siteId':0}),text)
text=re.sub(r'<\?[\s\S]*?\?>','',text)
text=re.sub(r'<link[^>]+>|<script[^>]+src=[^>]+></script>','',text)

def scenario(kind, editable=True, migration=True):
    state={'role':'INHERIT' if kind=='page' else 'CUSTOM','diskRole':'EDITOR','includeChildren':False,'revision':0,'fail':False,'calls':[]}
    target={'kind':kind,'siteId':1,'pageId':10,'blockId':50 if kind=='folder' else 0,'folderId':100 if kind=='folder' else 0,
            'title':'Объект','mode':'bitrix_disk' if kind=='folder' else 'local','editable':editable,'pageDenyAvailable':migration}
    def handle(body):
        post=parse_qs(body);action=post['action'][0];payload=json.loads(post['payload'][0])
        if action=='tree':data={'nodes':[{**target,'expandable':False}]}
        elif action=='view':
            assignment={'role':state['role']}
            if kind=='page':assignment.update(diskRole=state['diskRole'],includeChildren=state['includeChildren'])
            data={'target':target,'revision':f"{state['revision']:064x}",'rows':[{'user':{'id':12,'name':'Сотрудник 12','active':True,'login':'user12'},
                  'assignment':assignment,'effective':{'role':'VIEWER','diskRole':'EDITOR','source':'Исходный доступ'},'protected':False}],
                  'offset':0,'total':1,'hasMore':False,'note':'Проверка интерфейса'}
        elif action=='apply':
            state['calls'].append(payload)
            if state['fail']:
                state['fail']=False
                return {'status':500,'json':{'ok':False,'error':'NATIVE_PACKET_FAILED','details':{'folderId':100,'phase':'commit','users':[{'userId':12,'name':'Сотрудник 12','groups':[2,7],'error':'NATIVE_PACKET_COMMIT'}]}}}
            state.update(payload['changes'][0]);state['revision']+=1
            data={'saved':1,'revision':f"{state['revision']:064x}",'userIds':[12],'requestId':payload['requestId']}
        else:raise AssertionError(action)
        return {'status':200,'json':{'ok':True,'data':data}}
    return state,handle

with sync_playwright() as p:
    browser=p.chromium.launch(headless=True,executable_path=os.getenv('CHROMIUM_PATH') or shutil.which('chromium'),args=['--no-sandbox'])
    for kind,editable,migration in [('page',True,True),('page',True,False),('folder',True,True),('folder',False,True)]:
        state,handler=scenario(kind,editable,migration)
        page=browser.new_page(viewport={'width':1440,'height':1000});failures=[]
        page.on('pageerror',lambda e:failures.append(str(e)));page.set_content(text)
        page.expose_function('mockServer',handler)
        page.add_script_tag(content="window.fetch=async(url,opts)=>{const r=await window.mockServer(opts.body.toString());return {ok:r.status<400,status:r.status,json:async()=>r.json};};")
        page.add_style_tag(content=(root/'assets/admin/access.css').read_text());page.add_script_tag(content=(root/'assets/admin/access.js').read_text())
        page.get_by_role('button',name='Объект',exact=False).last.click();page.wait_for_selector('#sba-rows tr')
        role=page.get_by_label('Право: Сотрудник 12',exact=True)
        if kind=='page':
            assert role.locator('option[value="DENY"]').count()==int(migration)
            assert page.get_by_label('Диск страницы: Сотрудник 12',exact=True).locator('option[value="DENY"]').count()==0
            if migration:
                role.select_option('DENY')
                assert page.get_by_text('Право Диска сохраняется, но не действует, пока страница запрещена', exact=True).count()==1
                page.locator('#sba-save').click()
                page.wait_for_function("document.getElementById('sba-message').textContent.includes('Сохранено назначений: 1')")
                assert state['calls'][0]['changes'][0]['role']=='DENY'
                assert state['calls'][0]['changes'][0]['diskRole']=='EDITOR'
        elif editable:
            assert role.input_value()=='CUSTOM'
            assert role.locator('option[value="CUSTOM"]').is_disabled()
            assert page.locator('#sba-save').is_disabled()
            for value in ['ADD','FULL','DENY','INHERIT']:assert role.locator(f'option[value="{value}"]').count()==1
            role.select_option('FULL');state['fail']=True
            page.locator('#sba-save').click()
            page.wait_for_function("document.getElementById('sba-message').textContent.includes('Этап: commit')")
            assert 'группы Битрикс: 2, 7' in page.locator('#sba-message').inner_text()
            page.locator('#sba-save').click()
            page.wait_for_function("document.getElementById('sba-message').textContent.includes('Сохранено назначений: 1')")
            assert state['calls'][-1]['requestId']==state['calls'][-2]['requestId']
        else:
            assert role.count()==0
            assert 'Нестандартное правило' in page.locator('#sba-rows').inner_text()
            assert page.locator('#sba-save').is_disabled()
        assert not failures,failures
        page.close()
    browser.close()
print('PASS browser stage 2: page denial/schema gate, retained disk grant, native levels/custom rules/read-only, detailed failure and same-ID retry')
