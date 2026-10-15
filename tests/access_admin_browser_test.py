# Offline UI regression: no Bitrix endpoint, no production database, no HTTP server.
from pathlib import Path
import re, json, os, shutil
from urllib.parse import parse_qs
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parent.parent
text=(root/'access.php').read_text()
text=text[text.index('<!doctype html>'):]
text=re.sub(r'<\?= json_encode\([\s\S]*?\?>',json.dumps({'basePath':'','sessid':'test','siteId':0}),text)
text=re.sub(r'<\?[\s\S]*?\?>','',text)
rights={1:'OWNER'}; revision=0; calls=[]; requests={}; lost=[False]; lose_next=[False]
def user(i): return {'id':i,'name':f'Сотрудник {i}','login':f'user{i}','email':f'user{i}@example.org','active':True}
def handle(body):
    global revision
    post=parse_qs(body); action=post['action'][0]; p=json.loads(post['payload'][0]);calls.append((action,p))
    if action=='tree':
        data={'nodes':[{'kind':'site','siteId':1,'title':'Транспорт','expandable':True}]} if not p.get('siteId') else {'nodes':[{'kind':'page','siteId':1,'pageId':10,'title':'Документы','expandable':False}]}
    elif action=='view':
        kind=p.get('kind','site'); ids=p.get('userIds') or sorted(rights)[p.get('offset',0):p.get('offset',0)+100]
        target={'kind':kind,'siteId':1,'pageId':10 if kind=='page' else 0,'blockId':0,'folderId':0,'title':'Транспорт'+(' / Документы' if kind=='page' else ''),'editable':True,'mode':'local'}
        rows=[]
        for i in ids:
            assignment={'role':rights.get(i,'INHERIT')}; effective={'role':rights.get(i,'NONE'),'source':'Прямая роль сайта'}
            if kind=='page':assignment.update(diskRole='INHERIT',includeChildren=False);effective['diskRole']='NONE'
            rows.append({'user':user(i),'assignment':assignment,'effective':effective,'protected':i==1})
        data={'target':target,'revision':f'{revision:064x}','rows':rows,'offset':p.get('offset',0),'total':len(rights),'hasMore':len(rights)>p.get('offset',0)+100,'note':'Снятие прямого назначения возвращает наследование.'}
    elif action=='parse': data={'lines':[{'line':i+1,'input':v} for i,v in enumerate(p['text'].splitlines()) if v.strip()]}
    elif action=='resolve':
        data={'rows':[]}
        for r in p['lines']:
            if r['input'].isdigit(): data['rows'].append({**r,'status':'found','user':user(int(r['input'])),'candidates':[],'candidateCount':1})
            else:data['rows'].append({**r,'status':'ambiguous','user':None,'candidates':[user(900),user(901)],'candidateCount':2})
    elif action=='apply':
        if p['requestId'] in requests:
            data={**requests[p['requestId']],'replayed':True}
        else:
            if p['revision']!=f'{revision:064x}':return {'status':409,'json':{'ok':False,'error':'ACCESS_VERSION_CONFLICT'}}
            for r in p['changes']:
                if r['role']=='INHERIT':rights.pop(r['userId'],None)
                else:rights[r['userId']]=r['role']
            revision+=1
            data={'revision':f'{revision:064x}','saved':len(p['changes']),'userIds':[r['userId'] for r in p['changes']],'requestId':p['requestId']}
            requests[p['requestId']]=data
        if lose_next[0]:lose_next[0]=False;lost[0]=True;return {'abort':True}
    elif action=='search':data={'users':[user(950)]}
    else:raise ValueError(action)
    return {'status':200,'json':{'ok':True,'data':data}}
with sync_playwright() as p:
    browser=p.chromium.launch(headless=True,executable_path=os.getenv('CHROMIUM_PATH') or shutil.which('chromium'),args=['--no-sandbox'])
    page=browser.new_page(viewport={'width':1440,'height':1000},device_scale_factor=1)
    failures=[];page.on('pageerror',lambda e:failures.append(str(e)))
    page.set_content(re.sub(r'<link[^>]+>|<script[^>]+src=[^>]+></script>', '', text))
    page.expose_function('mockServer',handle)
    page.add_script_tag(content="window.fetch = async (url, opts) => {const r=await window.mockServer(opts.body.toString());if(r.abort)throw new Error('Lost reply');return {ok:r.status<400,status:r.status,json:async()=>r.json};};")
    page.add_style_tag(content=(root/'assets/admin/access.css').read_text())
    page.add_script_tag(content=(root/'assets/admin/access.js').read_text())
    page.get_by_role('button',name='Транспорт',exact=False).last.click()
    page.locator('#sba-add summary').click()
    page.locator('#sba-paste').fill('\n'.join(str(i) for i in range(2,502)))
    page.wait_for_function("document.getElementById('sba-resolve-status').textContent.includes('500') && !document.getElementById('sba-save').disabled")
    assert page.locator('#sba-rows tr').count()==501
    page.locator('#sba-bulk-role').select_option('EDITOR')
    page.locator('#sba-save').click()
    page.wait_for_function("document.getElementById('sba-message').textContent.includes('Сохранено назначений: 500')")
    assert len([a for a,_ in calls if a=='apply'])==5
    assert all(rights[i]=='EDITOR' for i in range(2,502))
    assert rights[1]=='OWNER'
    print('PASS browser: 500 pasted users, five resolve packets, five save packets, owner preserved')
    page.locator('#sba-paste').fill('900\nОднофамилец\n900')
    page.wait_for_function("document.getElementById('sba-resolve-status').textContent.includes('уникальных сотрудников: 1')")
    assert page.locator('#sba-unresolved select').count()==1
    page.locator('#sba-unresolved select').select_option('901')
    page.wait_for_function("document.getElementById('sba-count').textContent.includes('изменений: 2.')")
    lose_next[0]=True
    page.locator('#sba-save').click()
    page.wait_for_function("document.getElementById('sba-message').textContent.includes('Нет ответа сервера')")
    page.locator('#sba-save').click()
    page.wait_for_function("document.getElementById('sba-message').textContent.includes('Сохранено назначений: 2')")
    ap=[v for a,v in calls if a=='apply'];assert ap[-1]['requestId']==ap[-2]['requestId']
    print('PASS browser: duplicate collapse, manual ambiguous choice, lost-response replay uses identical requestId')
    assert not failures,failures
    browser.close()
