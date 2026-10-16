"""Real PHP templates / JS / Chromium; deliberately isolated mock API (not real Bitrix)."""
from pathlib import Path
from urllib.parse import parse_qs, urlparse
import json, re, shutil, os, subprocess, copy
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parent.parent
state={'groups': {1:{'id':1,'name':'Читатели','version':1,'members':{557},'rules':[]},
                  2:{'id':2,'name':'Редакторы <svg onload=alert(1)>','version':1,'members':{557},'rules':[]}},
       'receipts':{},'calls':[],'fail':False,'nextRule':2}
T={'kind':'folder','siteId':1,'pageId':10,'blockId':50,'folderId':110,'rootFolderId':100,'mode':'custom','editable':True,'title':'Маркетинг / Договоры'}
P={'kind':'page','siteId':1,'pageId':10,'blockId':0,'folderId':0,'mode':'local','editable':True,'title':'Маркетинг / Документы'}
SITE={'kind':'site','siteId':1,'pageId':0,'blockId':0,'folderId':0,'mode':'local','editable':True,'title':'Маркетинг','expandable':True}
USER={'id':557,'name':'Тестовый сотрудник','login':'test557','active':True}
def rule(group,edit=False):
    return {'id':1 if group==1 else 2,'group_id':group,'kind':'folder','site_id':1,'page_id':10,'block_id':50,'folder_id':110,
            'root_folder_id':100,'permission_mode':'custom','title':T['title'],'can_view':True,'can_edit':edit,'can_delete':False,'include_children':True,'open_page':True}
state['groups'][1]['rules']=[rule(1)]
def view(g): return {'group':{k:g[k] for k in ('id','name','version')},'members':[USER] if 557 in g['members'] else [],'memberCount':len(g['members']), 'rules':g['rules'],'offset':0,'hasMore':False}
def compact(g,r):return {'groupId':g['id'],'groupName':g['name'],'version':g['version'],'ruleId':r['id'],'title':r['title'],'scope':'direct',
    'caps':{'view':True,'edit':r['can_edit'],'delete':r['can_delete'],'traverse':False},'target':T,
    'flags':{'canView':True,'canEdit':r['can_edit'],'canDelete':r['can_delete'],'includeChildren':r['include_children'],'openPage':r['open_page']}}
def handler(action,d):
    state['calls'].append((action,copy.deepcopy(d)))
    if action=='tree':
        nodes=[SITE] if not d.get('siteId') else [P,dict(T,kind='disk',folderId=100,title='Файлы',expandable=True)] if d.get('kind')=='site' else [dict(T,expandable=False)]
        return {'nodes':nodes}
    if action=='search':return {'users':[USER]}
    if action=='group.list':return {'groups':[dict(id=g['id'],name=g['name'],version=g['version'],rules_count=len(g['rules']),members_count=len(g['members'])) for g in state['groups'].values() if d.get('query','').lower() in g['name'].lower()], 'hasMore':False,'offset':0}
    if action=='group.view':return view(state['groups'][d['groupId']])
    if action=='group.target':return {'target':T}
    if action=='group.history':return {'events':[],'offset':0,'hasMore':False}
    if action=='console.object':return {'target':T,'rules':[compact(g,r) for g in state['groups'].values() for r in g['rules']],
        'total':sum(len(g['rules']) for g in state['groups'].values()),'offset':0,'hasMore':False,
        'legacy':[{'code':'U14','userId':14,'name':'Личный читатель','assignment':{'role':'VIEWER'}}], 'legacyCount':1,'legacyOffset':0,'legacyHasMore':False}
    if action=='console.employee':return {'user':USER,'groups':[dict(id=g['id'],name=g['name'],version=g['version'],rules_count=len(g['rules'])) for g in state['groups'].values() if 557 in g['members']],
        'groupCount':sum(557 in g['members'] for g in state['groups'].values()),'legacy':[],'offset':0,'hasMore':False,'legacyOffset':0,'legacyHasMore':False,'protected':False,'guest':False}
    if action=='console.explain':
        caps=[]
        for key,label,rkey in [('canView','Просмотр папки','can_view'),('canUpload','Загрузка файлов','can_edit'),('canDelete','Удаление','can_delete')]:
            src=[{'type':'group','groupId':g['id'],'ruleId':r['id'],'label':g['name'],'scope':'direct'} for g in state['groups'].values() if 557 in g['members'] for r in g['rules'] if r[rkey]]
            caps.append({'key':key,'label':label,'allowed':bool(src),'sources':src,'limits':[],'note':'' if src else 'Нет действующего источника разрешения'})
        return {'user':USER,'target':T,'capabilities':caps,'warnings':[],'groupRules':[]}
    rid=d['requestId']
    if rid in state['receipts']:return dict(state['receipts'][rid],replayed=True)
    g=state['groups'][d['groupId']]
    if g['version']!=d['version']:return {'_error':'GROUP_VERSION_CONFLICT','_status':409}
    saved=1
    if action=='group.rule.put':
        g['rules']=[dict(rule(g['id']),can_edit=d['canEdit'],can_delete=d['canDelete'],include_children=d['includeChildren'],open_page=d['openPage'])]
    elif action=='group.members.add':saved=0 if 557 in g['members'] else 1;g['members'].add(557)
    elif action=='group.members.remove':g['members'].discard(557)
    elif action=='group.rule.remove':g['rules']=[r for r in g['rules'] if r['id']!=d['ruleId']]
    else:raise AssertionError(action)
    g['version']+=1
    result={'groupId':g['id'],'version':g['version'],'saved':saved,'deleted':False};state['receipts'][rid]=result
    if state['fail']:state['fail']=False;return {'_error':'NETWORK_AFTER_COMMIT','_status':500}
    return result

def page_html(view,initial):
    # Render the same PHP partials used by access.php, not a hand-drawn test page.
    code='define("SB_ACCESS_CONSOLE_VIEW",true);$base="/local/sitebuilder";$view='+json.dumps(view)+';$h=fn(string $s):string=>htmlspecialchars($s,ENT_QUOTES,"UTF-8");require '+json.dumps(str(root/'views/access'/('groups.php' if view=='groups' else 'console.php')))+';'
    body=subprocess.check_output(['php','-r',code]).decode()
    cid='sbg-config' if view=='groups' else 'uc-config'
    config={'basePath':'/local/sitebuilder','sessid':'test','view':view,'initial':initial}
    css=(root/'assets/admin/groups.css').read_text()+(root/'assets/admin/access-console.css').read_text()
    js=(root/'assets/admin'/('groups.js' if view=='groups' else 'access-console.js')).read_text()
    return '<!doctype html><html><head><style>'+css+'</style></head><body class="sbg-body">'+body+f'<script id="{cid}" type="application/json">'+json.dumps(config)+'</script><script>'+js+'</script></body></html>'

with sync_playwright() as pw:
    b=pw.chromium.launch(executable_path=os.getenv('CHROMIUM_PATH') or shutil.which('chromium'),headless=True,args=['--no-sandbox'])
    page=b.new_page(viewport={'width':1440,'height':1000})
    errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
    def mock_server(body):
        post=parse_qs(body);result=handler(post['action'][0],json.loads(post['payload'][0]))
        bad=result.pop('_error',None);status=result.pop('_status',200)
        return {'status':status,'body':{'ok':False,'error':bad} if bad else {'ok':True,'data':result}}
    page.expose_function('mockServer',mock_server)
    def load_url(address):
        u=urlparse(address);q=parse_qs(u.query);v=q.get('view',['objects'])[0]
        initial={k:int(x[0]) for k,x in q.items() if k.endswith('Id')};initial['kind']=q.get('kind',['site'])[0]
        html=page_html(v,initial)
        # No network navigation: this environment blocks browser URL navigation by policy.
        html=re.sub(r'<script>([\s\S]*?)</script>', '', html)
        page.set_content(html)
        page.add_script_tag(content="window.history.replaceState=(a,b,url)=>{window.__testUrl=url}; window.fetch=async(url,opts)=>{const r=await window.mockServer(opts.body.toString());return {ok:r.status<400,status:r.status,json:async()=>r.body};};")
        page.add_script_tag(content=(root/'assets/admin'/('groups.js' if v=='groups' else 'access-console.js')).read_text())
    base='http://127.0.0.1:8973/local/sitebuilder/access.php'
    target='&kind=folder&siteId=1&pageId=10&blockId=50&folderId=110'
    load_url(base+'?view=objects'+target);page.wait_for_selector('#uc-object-rules .uc-row')
    assert page.locator('.sbg-nav a').all_text_contents()[:3]==['Объекты','Группы','Сотрудники']
    page.locator('#uc-open-rule').click();page.locator('#uc-group-search').fill('Редакторы')
    page.locator('#uc-group-results button').first.click();page.wait_for_function('!document.getElementById("uc-edit").disabled && !document.getElementById("uc-flags").disabled')
    page.locator('#uc-edit').check();assert not page.locator('#uc-delete').is_checked()
    # An accidental navigation cannot silently discard a changed grant.
    page.locator('.sbg-nav a').filter(has_text='Сотрудники').click()
    assert page.locator('#uc-message').is_visible() and 'Завершите' in page.locator('#uc-message').inner_text()
    state['fail']=True;page.locator('#uc-save-rule').click();page.wait_for_selector('#uc-retry-panel',state='visible')
    page.locator('#uc-retry').click();page.wait_for_function('document.getElementById("uc-message").textContent.includes("Запись подтверждена")')
    puts=[d for a,d in state['calls'] if a=='group.rule.put'];assert len(puts)==2 and puts[0]==puts[1]
    assert state['groups'][2]['rules'][0]['can_edit'] and not state['groups'][2]['rules'][0]['can_delete']
    page.locator('#uc-explain-search').fill('557');page.locator('#uc-explain-results button').first.click()
    page.wait_for_selector('.uc-capabilities')
    upload=page.locator('.uc-capabilities tr').filter(has_text='Загрузка файлов')
    assert 'Разрешено' in upload.inner_text() and 'Редакторы' in upload.inner_text()
    delete=page.locator('.uc-capabilities tr').filter(has_text='Удаление');assert 'Не разрешено' in delete.inner_text()
    assert page.locator('#uc-object-rules svg').count()==0
    state['groups'][2]['name']='Редакторы отдела'
    page.locator('#uc-reload').click();page.wait_for_function('document.getElementById("uc-message").textContent.includes("Данные перечитаны")')
    page.evaluate('scrollTo(0,0)');page.screenshot(path='/mnt/data/sitebuilder-unified-objects.png',full_page=True)
    # Deep link opens the very same saved rule, not an unrelated copy.
    href=upload.locator('a').get_attribute('href');load_url('http://127.0.0.1:8973'+href)
    page.wait_for_function('document.getElementById("sbg-rule-panel").open && !document.getElementById("sbg-flags").disabled')
    assert page.locator('#sbg-edit').is_checked() and not page.locator('#sbg-can-delete').is_checked()
    page.locator('#sbg-tab-members').click();href=page.locator('#sbg-members a').get_attribute('href')
    load_url('http://127.0.0.1:8973'+href+target);page.wait_for_selector('#uc-memberships .uc-row')
    assert page.locator('#uc-memberships .uc-row').count()==2
    page.locator('#uc-memberships .uc-row').filter(has_text='Редакторы').get_by_role('button').click()
    page.wait_for_function('document.getElementById("uc-message").textContent.includes("исключён")')
    assert 557 not in state['groups'][2]['members']
    assert 'Не разрешено' in page.locator('.uc-capabilities tr').filter(has_text='Загрузка файлов').inner_text()
    assert 'Разрешено' in page.locator('.uc-capabilities tr').filter(has_text='Просмотр папки').inner_text()
    page.locator('#uc-work details').filter(has_text='Добавить сотрудника в группу').first.locator('summary').click()
    page.locator('#uc-member-group-search').fill('Редакторы');page.locator('#uc-member-group-results button').first.click()
    assert 'всем 1 назначениям' in page.locator('#uc-member-group-results').inner_text()
    state['groups'][2]['version']+=1
    page.get_by_role('button',name='Включить сотрудника в группу',exact=True).click()
    page.wait_for_function('document.getElementById("uc-message").textContent.includes("другим администратором")')
    assert 557 not in state['groups'][2]['members']
    page.locator('#uc-member-group-search').fill('Редактор');page.locator('#uc-member-group-results button').first.click()
    page.get_by_role('button',name='Включить сотрудника в группу',exact=True).click()
    page.wait_for_function('document.getElementById("uc-message").textContent.includes("включён")')
    assert 557 in state['groups'][2]['members']
    assert not any(a=='apply' for a,d in state['calls'])
    page.evaluate('scrollTo(0,0)');page.screenshot(path='/mnt/data/sitebuilder-unified-employee.png',full_page=True)
    page.set_viewport_size({'width':390,'height':844});page.screenshot(path='/mnt/data/sitebuilder-unified-mobile.png',full_page=True)
    assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1')
    assert not errors,errors
    b.close()
print('PASS unified console: one rule across views, employee links, exact source links, membership revoke/add, conflict, no personal writes, replay, XSS, mobile')
