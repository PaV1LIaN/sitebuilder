"""Real UI in Chromium; API fixtures, never production Bitrix. No credentials needed."""
from pathlib import Path
from urllib.parse import parse_qs
import json, re, shutil, os
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parent.parent
html='<html><body class="sbg-body">'+(root/'views/access/groups.php').read_text()+'<script id="sbg-config" type="application/json">{}</script></body></html>'
html=re.sub(r'<script id="sbg-config"[^>]*>[\s\S]*?</script>', '<script id="sbg-config" type="application/json">'+json.dumps({'basePath':'','sessid':'test'})+'</script>',html)
html=re.sub(r'<\?[\s\S]*?\?>','',html)
html=re.sub(r'<link[^>]*>|<script[^>]+src=[^>]+></script>','',html)
state={'groups':{},'next':1,'rules':1,'calls':[],'receipts':{},'fail':False}
def profile(i):return {'id':i,'name':f'Сотрудник {i}','login':f'user{i}','active':True}
def target(t):return dict({'kind':'site','siteId':1,'pageId':0,'blockId':0,'folderId':0,'rootFolderId':100,'mode':'local','editable':True,'title':'Тестовый сайт'},**t)
def group_view(g,offset=0):return {'group':{k:g[k] for k in ('id','name','version')},'members':[profile(i) for i in sorted(g['members'])[offset:offset+100]],'memberCount':len(g['members']),'offset':offset,'hasMore':offset+100<len(g['members']),'rules':list(g['rules'].values())}
def handler(body):
    post=parse_qs(body); a=post['action'][0]; d=json.loads(post['payload'][0]); state['calls'].append((a,d))
    if a=='group.list':data={'groups':[dict(id=g['id'],name=g['name'],version=g['version'],members_count=len(g['members']),rules_count=len(g['rules'])) for g in state['groups'].values() if d.get('query','').lower() in g['name'].lower()], 'hasMore':False,'offset':0}
    elif a=='tree':
        if not d.get('siteId'):nodes=[target({'expandable':True})]
        elif d.get('kind')=='disk':nodes=[target({'kind':'folder','pageId':10,'blockId':50,'folderId':110,'title':'Договоры','mode':'custom','expandable':False})]
        else:nodes=[target({'kind':'page','pageId':10,'title':'Документы','expandable':False}),target({'kind':'disk','pageId':10,'blockId':50,'folderId':100,'title':'Файлы отдела','mode':'custom','expandable':True})]
        data={'nodes':nodes}
    elif a=='group.target':data={'target':target(d['target'])}
    elif a=='group.view':data=group_view(state['groups'][d['groupId']],d.get('offset',0))
    elif a=='group.history':data={'events':[],'hasMore':False,'offset':0}
    elif a=='parse':data={'lines':[{'line':n+1,'input':v} for n,v in enumerate(d['text'].splitlines()) if v.strip()]}
    elif a=='resolve':
        rows=[]
        for row in d['lines']:
            v=row['input']; i=int(v) if v.isdigit() else None
            rows.append(dict(row,user=profile(i) if i else None,status='found' if i else ('ambiguous' if v=='Иванов Иван Иванович' else 'not_found'),candidates=[profile(778),profile(779)] if v=='Иванов Иван Иванович' else []))
        data={'rows':rows}
    else:
        rid=d['requestId']
        if rid in state['receipts']:return {'status':200,'json':{'ok':True,'data':dict(state['receipts'][rid],replayed=True)}}
        saved=1;deleted=False
        if a=='group.create':
            i=state['next'];state['next']+=1;g={'id':i,'name':d['name'],'version':1,'members':set(),'rules':{}};state['groups'][i]=g
        else:
            g=state['groups'][d['groupId']]
            assert d['version']==g['version'],(d,g)
            g['version']+=1
            if a=='group.rename':g['name']=d['name']
            elif a=='group.members.add':new=set(d['userIds'])-g['members'];g['members'].update(new);saved=len(new)
            elif a=='group.members.remove':saved=len(g['members'].intersection(d['userIds']));g['members'].difference_update(d['userIds'])
            elif a=='group.rule.put':
                t=d['target'];i=state['rules'];state['rules']+=1
                g['rules'][i]={'id':i,'kind':t['kind'],'site_id':t['siteId'],'page_id':t['pageId'],'block_id':t['blockId'],'folder_id':t['folderId'],
                    'permission_mode':t['mode'],'title':t['title'],'can_view':True,'can_edit':d['canEdit'],'can_delete':d['canDelete'],'open_page':d['openPage'],'include_children':d['includeChildren']}
            elif a=='group.rule.remove':g['rules'].pop(d['ruleId'],None)
            elif a=='group.delete':state['groups'].pop(g['id']);deleted=True
            else:raise AssertionError(a)
        data={'groupId':g['id'],'version':g['version'],'saved':saved,'deleted':deleted};state['receipts'][rid]=data
        if state['fail']:state['fail']=False;return {'status':500,'json':{'ok':False,'error':'NETWORK_AFTER_COMMIT'}}
    return {'status':200,'json':{'ok':True,'data':data}}
with sync_playwright() as pw:
    browser=pw.chromium.launch(headless=True,executable_path=os.getenv('CHROMIUM_PATH') or shutil.which('chromium'),args=['--no-sandbox'])
    page=browser.new_page(viewport={'width':1440,'height':1000},device_scale_factor=1)
    errors=[];page.on('pageerror',lambda e:errors.append(str(e)));page.set_content(html)
    page.expose_function('mockServer',handler)
    page.add_script_tag(content="window.fetch=async(url,opts)=>{const r=await window.mockServer(opts.body.toString());return {ok:r.status<400,status:r.status,json:async()=>r.json};};")
    page.add_style_tag(content=(root/'assets/admin/groups.css').read_text());page.add_script_tag(content=(root/'assets/admin/groups.js').read_text())
    page.wait_for_function("!document.getElementById('sbg-new-name').disabled")
    page.locator('#sbg-new-name').fill('Отдел снабжения');page.locator('#sbg-create button').click()
    page.wait_for_function("document.getElementById('sbg-message').textContent.includes('Группа создана')")
    assert page.locator('#sbg-work').is_visible()
    page.locator('#sbg-add-panel summary').click()
    page.locator('#sbg-paste').fill('\n'.join(str(i) for i in range(1000,1500))+'\n1001\nИванов Иван Иванович\nНеизвестный')
    page.locator('#sbg-resolve').click();page.wait_for_function("!document.getElementById('sbg-add').disabled")
    assert '500' in page.locator('#sbg-preview-count').inner_text()
    page.get_by_label('Уточнить строку 502').select_option('778')
    page.locator('#sbg-add').click();page.wait_for_function("document.getElementById('sbg-message').textContent.includes('Добавлено сотрудников: 501')")
    assert len(state['groups'][1]['members'])==501
    calls=[d for a,d in state['calls'] if a=='group.members.add'];assert len(calls)==6;assert all(len(d['userIds'])<=100 for d in calls)
    page.locator('#sbg-tab-rights').click();page.locator('#sbg-rule-panel summary').click();page.locator('#sbg-site').select_option('1')
    page.get_by_role('button',name='Раскрыть Файлы отдела',exact=True).click()
    page.get_by_role('button',name='Договоры',exact=True).click()
    page.locator('#sbg-edit').check();assert not page.locator('#sbg-can-delete').is_checked()
    page.locator('#sbg-save-rule').click();page.wait_for_function("document.getElementById('sbg-message').textContent.includes('Право группы сохранено')")
    rule=state['groups'][1]['rules'][1];assert rule['can_edit'] and not rule['can_delete'] and rule['folder_id']==110
    # Lost commit response: same request ID replay, no duplicate group.
    page.locator('#sbg-new-name').fill('Читатели');state['fail']=True;page.locator('#sbg-create button').click()
    page.wait_for_selector('#sbg-retry-panel',state='visible');page.locator('#sbg-retry').click()
    page.wait_for_function("document.getElementById('sbg-message').textContent.includes('Операция подтверждена')")
    assert len(state['groups'])==2
    creates=[d['requestId'] for a,d in state['calls'] if a=='group.create'];assert creates[-1]==creates[-2]
    # XSS safety in a group title.
    page.locator('#sbg-name').fill('<img src=x onerror=alert(1)>');page.locator('#sbg-rename button').click()
    page.wait_for_function("document.getElementById('sbg-message').textContent.includes('Название группы сохранено')")
    assert page.locator('#sbg-list img').count()==0
    page.on('dialog',lambda d:d.accept());page.locator('#sbg-delete').click()
    page.wait_for_function("document.getElementById('sbg-message').textContent.includes('Группа удалена')")
    assert len(state['groups'])==1 and len(state['groups'][1]['rules'])==1
    page.get_by_role('button',name='Отдел снабжения',exact=False).click();page.locator('#sbg-tab-rights').click()
    page.screenshot(path='/mnt/data/sitebuilder-groups-preview.png',full_page=True)
    page.locator('#sbg-rule-panel summary').click();page.locator('#sbg-site').select_option('1');page.get_by_role('button',name='Раскрыть Файлы отдела',exact=True).click();page.get_by_role('button',name='Договоры',exact=True).click()
    page.screenshot(path='/mnt/data/sitebuilder-groups-rule-preview.png',full_page=True)
    page.set_viewport_size({'width':390,'height':844});page.screenshot(path='/mnt/data/sitebuilder-groups-mobile.png',full_page=True)
    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth+1')
    assert not errors,errors
    browser.close()
print('PASS groups UI: 501 users / 6 packets, homonym selection, independent delete, scoped folder grant, same-ID retry, group deletion, XSS, mobile layout')
