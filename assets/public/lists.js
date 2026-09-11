(function () {
    'use strict';
    var TYPES = {text:'Текст',textarea:'Многострочный текст',number:'Число',date:'Дата',choice:'Выбор значения',checkbox:'Да / нет',person:'Сотрудник',url:'Ссылка / документ'};
    function node(tag, attrs, children) {
        var n = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (key) {
            if (key === 'text') n.textContent = attrs[key];
            else if (key === 'class') n.className = attrs[key];
            else n.setAttribute(key, attrs[key]);
        });
        (children || []).forEach(function (child) { n.appendChild(child); });
        return n;
    }
    function button(text, run, primary) {
        var b = node('button', {type:'button',text:text,class:primary?'sb-list-primary':''});
        b.addEventListener('click', run); return b;
    }
    function option(value, label) { return node('option', {value:value,text:label}); }
    function select(values, value, label) {
        var n = node('select', {'aria-label':label});
        values.forEach(function (pair) { n.appendChild(option(pair[0], pair[1])); });
        n.value = value || ''; return n;
    }
    function message(n, text, error) { n.textContent = text || ''; n.classList.toggle('is-error', !!error); }
    async function api(context, action, data) {
        var response = await fetch((context.basePath || '/local/sitebuilder') + '/lists_api.php', {
            method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'},
            body:JSON.stringify(Object.assign({}, data || {}, {siteId:context.siteId,pageId:context.pageId,blockId:context.blockId,sessid:context.sessid,action:action}))
        });
        var json;
        try { json = await response.json(); } catch (e) { throw new Error('Не удалось получить ответ. Обновите страницу и повторите попытку.'); }
        if (!response.ok || !json.ok) { var error = new Error(json.message || 'Не удалось выполнить действие.'); error.status=response.status; throw error; }
        return json.data;
    }
    function notify(id) { document.dispatchEvent(new CustomEvent('sb-list-changed',{detail:{id:id}})); }
    function modal(title) {
        var dialog = node('dialog',{class:'sb-list-dialog','aria-label':title});
        dialog.appendChild(node('h2',{text:title}));
        dialog.addEventListener('close',function(){dialog.remove();});
        document.body.appendChild(dialog); dialog.showModal(); return dialog;
    }
    function fieldLabel(label, control) { return node('label',{class:'sb-list-field'},[node('span',{text:label}),control]); }
    function key() { return 'k_' + (window.crypto && window.crypto.randomUUID ? window.crypto.randomUUID().replace(/-/g,'') : Date.now().toString(36)+Math.random().toString(36).slice(2)+Math.random().toString(36).slice(2)); }
    function display(field, value) {
        if (value === null || value === undefined || value === '') return '—';
        if (field.type === 'checkbox') return value ? 'Да' : 'Нет';
        if (field.type === 'person') return value.name || 'Не выбран';
        if (field.type === 'date' && /^\d{4}-\d{2}-\d{2}$/.test(value)) return value.split('-').reverse().join('.');
        return String(value);
    }
    function safeUrl(value) {
        if (typeof value !== 'string' || /[\x00-\x20\\]/.test(value) || value.indexOf('//') === 0) return false;
        if (value.charAt(0) === '/') return true;
        try { var u=new URL(value); return ['http:','https:'].indexOf(u.protocol)>=0 && !u.username && !u.password; } catch(e) { return false; }
    }

    function openSchema(context, definition, saved) {
        var editing = !!definition;
        var fields = editing ? JSON.parse(JSON.stringify(definition.fields)) : [{id:'title',label:'Название',type:'text',required:true,options:[]}];
        var dialog = modal(editing ? 'Настройка списка' : 'Создать список');
        var form = node('form'), title = node('input',{type:'text',required:'',maxlength:160,value:editing?definition.title:'Новый список'});
        var cards = node('div'), status = node('p',{class:'sb-list-message',role:'status'});
        form.appendChild(fieldLabel('Название списка',title));
        form.appendChild(node('p',{class:'sb-list-inspector-note',text:editing?'Изменения полей применятся ко всем блокам этого списка. Заполненные поля защищены от удаления и смены типа.':'Доступ к записям будет определяться правами этой страницы. Этот список можно использовать в других блоках сайта.'}));
        form.appendChild(cards);
        function collect() {
            return Array.from(cards.children).map(function(card){
                return {id:card.dataset.fieldId,label:card.querySelector('[name=label]').value.trim(),type:card.querySelector('[name=type]').value,
                    required:card.querySelector('[name=required]').checked,options:card.querySelector('[name=options]').value.split('\n').map(function(v){return v.trim();}).filter(Boolean)};
            });
        }
        function render() {
            cards.replaceChildren();
            fields.forEach(function(field,index){
                var card=node('div',{class:'sb-list-field-card','data-field-id':field.id});
                var label=node('input',{name:'label',type:'text',value:field.label,required:'',maxlength:120,'aria-label':'Название поля'});
                var type=select(Object.keys(TYPES).map(function(k){return [k,TYPES[k]];}),field.type,'Тип поля'); type.name='type';
                var required=node('input',{type:'checkbox',name:'required'}); required.checked=!!field.required;
                var options=node('textarea',{name:'options',placeholder:'Варианты, каждый с новой строки','aria-label':'Варианты выбора'}); options.value=(field.options||[]).join('\n'); options.hidden=field.type!=='choice';
                type.addEventListener('change',function(){options.hidden=type.value!=='choice';});
                var controls=node('div',{class:'sb-list-field-row'},[label,type,node('label',{},[required,document.createTextNode(' Обязательное')])]);
                controls.appendChild(button('↑',function(){fields=collect();if(index>0){var prev=fields[index-1];fields[index-1]=fields[index];fields[index]=prev;render();}}));
                controls.appendChild(button('↓',function(){fields=collect();if(index<fields.length-1){var next=fields[index+1];fields[index+1]=fields[index];fields[index]=next;render();}}));
                controls.appendChild(button('Удалить поле',function(){fields=collect();fields.splice(index,1);render();}));
                card.append(controls,options); cards.appendChild(card);
            });
        }
        render();
        form.appendChild(button('+ Поле',function(){fields=collect();if(fields.length>=50){message(status,'Допустимо не более 50 полей.',true);return;}fields.push({id:'f_'+key(),label:'Новое поле',type:'text',required:false,options:[]});render();}));
        var save=node('button',{type:'submit',class:'sb-list-primary',text:editing?'Сохранить настройки списка':'Создать список'});
        form.append(status,node('footer',{},[button('Отмена',function(){dialog.close();}),save])); dialog.appendChild(form);
        form.addEventListener('submit',async function(event){
            event.preventDefault(); save.disabled=true; message(status,'Сохранение…');
            try {
                var data=await api(context,editing?'schema':'create',{listId:editing?definition.id:0,version:editing?definition.version:0,title:title.value.trim(),fields:collect()});
                saved(data.list); notify(data.list.id); dialog.close();
            } catch(e){message(status,e.message,true);} finally{save.disabled=false;}
        });
        title.focus();
    }

    function ListView(root) {
        this.root=root; this.context=JSON.parse(root.dataset.listContext); this.view=this.context.view||{};
        this.page=1;this.request=0;this.list=null;this.items=[];this.deleted=false;
        this.build();this.load();
        var self=this;
        document.addEventListener('sb-list-changed',function(event){if(self.root.isConnected&&self.list&&event.detail.id===self.list.id)self.load();});
    }
    ListView.prototype.build=function(){
        var self=this; this.root.replaceChildren();
        this.title=node('h2',{text:this.view.title||'Список'});
        this.add=button('+ Запись',function(){self.edit(null);},true);this.add.hidden=true;
        this.settings=button('Поля списка',function(){openSchema(self.context,self.list,function(list){self.list=list;});});this.settings.hidden=true;
        this.trash=button('Удалённые',function(){self.deleted=!self.deleted;self.page=1;self.load();});this.trash.hidden=true;
        this.root.appendChild(node('div',{class:'sb-list-head'},[this.title,node('div',{class:'sb-list-actions'},[this.add,this.settings,this.trash,button('Обновить',function(){self.load();})])]));
        this.search=node('input',{type:'search',placeholder:'Поиск по записям','aria-label':'Поиск по записям'});
        this.sort=select([['','Сначала новые']],'','Сортировка');this.dir=select([['asc','По возрастанию'],['desc','По убыванию']],this.view.sortDir||'asc','Направление сортировки');
        this.group=select([['','Без группировки']],'','Группировка');
        this.filter=select([['','Все поля']],'','Поле фильтра'); this.filterValue=node('input',{type:'text',placeholder:'Значение фильтра','aria-label':'Значение фильтра'});
        this.filterHost=node('span',{},[this.filterValue]);
        this.root.appendChild(node('div',{class:'sb-list-toolbar'},[this.search,this.sort,this.dir,this.group]));
        this.root.appendChild(node('div',{class:'sb-list-toolbar'},[this.filter,this.filterHost,button('Применить фильтр',function(){self.page=1;self.load();}),button('Сбросить',function(){self.filter.value='';self.changeFilter();self.search.value='';self.page=1;self.load();})]));
        var timer;this.search.addEventListener('input',function(){clearTimeout(timer);timer=setTimeout(function(){self.page=1;self.load();},350);});
        [this.sort,this.dir,this.group].forEach(function(n){n.addEventListener('change',function(){self.page=1;self.load();});});
        this.filter.addEventListener('change',function(){self.changeFilter();});
        this.status=node('p',{class:'sb-list-message',role:'status','aria-live':'polite'});
        this.table=node('div',{class:'sb-list-scroll'});this.pager=node('div',{class:'sb-list-pagination'});
        this.root.append(this.status,this.table,this.pager);
    };
    ListView.prototype.changeFilter=function(){
        var field=this.list&&this.list.fields.find(function(f){return f.id===this.filter.value;},this);
        var n;
        if(field&&field.type==='choice')n=select([['','Все значения']].concat(field.options.map(function(o){return[o,o];})),'','Значение фильтра');
        else if(field&&field.type==='checkbox')n=select([['','Все значения'],['true','Да'],['false','Нет']],'','Значение фильтра');
        else n=node('input',{type:field&&field.type==='date'?'date':'text',placeholder:'Значение фильтра','aria-label':'Значение фильтра'});
        this.filterHost.replaceChildren(n);this.filterValue=n;
    };
    ListView.prototype.fillControls=function(){
        var self=this,signature=JSON.stringify(this.list.fields);
        if(signature===this.signature)return;
        var first=!this.signature;this.signature=signature;
        [[this.sort,'Сначала новые','sortBy'],[this.group,'Без группировки','groupBy'],[this.filter,'Все поля','']].forEach(function(entry){
            var selected=first?(self.view[entry[2]]||''):entry[0].value;
            entry[0].replaceChildren(option('',entry[1]));self.list.fields.forEach(function(f){entry[0].appendChild(option(f.id,f.label));});entry[0].value=selected;
        });
    };
    ListView.prototype.load=async function(){
        var request=++this.request,filters={};if(this.filter.value&&this.filterValue.value!=='')filters[this.filter.value]=this.filterValue.value;
        message(this.status,'Загрузка…');this.root.setAttribute('aria-busy','true');
        try {
            var data=await api(this.context,'records',{page:this.page,query:this.search.value,filters:filters,deleted:this.deleted,
                sortBy:this.signature?this.sort.value:(this.view.sortBy||''),sortDir:this.dir.value,groupBy:this.signature?this.group.value:(this.view.groupBy||'')});
            if(request!==this.request)return;
            this.list=data.list;this.items=data.items;this.page=data.page;this.fillControls();
            this.title.textContent=this.view.title||this.list.title;this.add.hidden=!this.list.canEdit||this.deleted;
            this.settings.hidden=!this.list.canEdit;this.trash.hidden=!this.list.canEdit;this.trash.textContent=this.deleted?'К активным записям':'Удалённые';
            this.renderRows(data.groupBy);this.renderPager(data);message(this.status,data.total?'Записей: '+data.total:(this.deleted?'Удалённых записей нет.':'Записи не найдены.'));
        }catch(e){if(request!==this.request)return;this.table.replaceChildren();this.pager.replaceChildren();this.add.hidden=true;this.settings.hidden=true;this.trash.hidden=true;message(this.status,e.message,true);}
        finally{if(request===this.request)this.root.removeAttribute('aria-busy');}
    };
    ListView.prototype.renderRows=function(group){
        var self=this,table=node('table'),head=node('tr');
        this.list.fields.forEach(function(f){head.appendChild(node('th',{scope:'col',text:f.label}));});
        head.appendChild(node('th',{scope:'col',text:'Действия'}));table.appendChild(node('thead',{},[head]));var body=node('tbody'),last=null;
        this.items.forEach(function(item){
            if(group){var field=self.list.fields.find(function(f){return f.id===group;}),label=display(field,item.values[group]);
                if(label!==last){body.appendChild(node('tr',{class:'sb-list-group'},[node('td',{colspan:self.list.fields.length+1,text:field.label+': '+label})]));last=label;}}
            var row=node('tr');self.list.fields.forEach(function(f){var value=item.values[f.id],cell=node('td');
                if(f.type==='url'&&safeUrl(value))cell.appendChild(node('a',{href:value,text:value,target:'_blank',rel:'noopener noreferrer'}));
                else cell.textContent=display(f,value);row.appendChild(cell);
            });
            var actions=node('td');actions.appendChild(button('Открыть',function(){self.edit(item);}));
            if(self.list.canEdit)actions.appendChild(button(item.deleted?'Восстановить':'Удалить',function(){self.remove(item);}));
            row.appendChild(actions);body.appendChild(row);
        });table.appendChild(body);this.table.replaceChildren(table);
    };
    ListView.prototype.renderPager=function(data){
        var self=this;var prev=button('Назад',function(){self.page--;self.load();});var next=button('Далее',function(){self.page++;self.load();});
        prev.disabled=data.page<=1;next.disabled=data.page>=data.pages;
        this.pager.replaceChildren(prev,node('span',{text:'Страница '+data.page+' из '+data.pages}),next);
    };
    ListView.prototype.remove=async function(item){
        if(!item.deleted&&!window.confirm('Переместить запись в удалённые? Её можно будет восстановить.'))return;
        try{await api(this.context,item.deleted?'restoreRecord':'deleteRecord',{listId:this.list.id,itemId:item.id,version:item.version,schemaVersion:this.list.version});notify(this.list.id);}
        catch(e){message(this.status,e.message,true);}
    };
    ListView.prototype.edit=function(item){
        var self=this,list=this.list,readOnly=!list.canEdit||!!(item&&item.deleted),dialog=modal(item?'Запись списка':'Новая запись'),form=node('form'),inputs={};
        list.fields.forEach(function(field){
            var value=item?item.values[field.id]:null,control;
            if(readOnly){form.appendChild(fieldLabel(field.label,node('div',{text:display(field,value)})));return;}
            if(field.type==='choice')control=select([['','Не выбрано']].concat(field.options.map(function(o){return[o,o];})),value,field.label);
            else if(field.type==='textarea'){control=node('textarea',{maxlength:10000});control.value=value||'';}
            else if(field.type==='checkbox'){control=node('input',{type:'checkbox'});control.checked=value===true;}
            else if(field.type==='person'){
                var box=node('div'),search=node('input',{type:'text',placeholder:'Начните вводить ФИО','aria-label':field.label}),results=node('div',{class:'sb-list-people'});
                search.value=value&&value.name||'';var selected=value||null,token=0,timer;
                search.addEventListener('input',function(){selected=null;search.setCustomValidity('');clearTimeout(timer);var request=++token;
                    timer=setTimeout(async function(){try{var data=await api(self.context,'people',{query:search.value});if(request!==token||!dialog.isConnected)return;
                        results.replaceChildren();data.people.forEach(function(p){results.appendChild(button(p.name,function(){selected=p;search.value=p.name;search.setCustomValidity('');results.replaceChildren();}));});
                        if(search.value.length>=2&&!data.people.length)results.textContent='Сотрудники не найдены.';
                    }catch(e){if(request===token)results.textContent=e.message;}},300);
                });
                box.append(search,results);inputs[field.id]={get:function(){if(search.value&&!selected){search.setCustomValidity('Выберите сотрудника из результатов поиска.');search.reportValidity();throw new Error('Выберите сотрудника в поле «'+field.label+'».');}return selected?selected.id:null;}};
                form.appendChild(fieldLabel(field.label+(field.required?' *':''),box));return;
            }else{control=node('input',{type:field.type==='number'?'number':field.type==='date'?'date':'text',step:'any',maxlength:2000});control.value=value===null||value===undefined?'':value;}
            if(field.required&&field.type!=='checkbox')control.required=true;
            inputs[field.id]={get:function(){return field.type==='checkbox'?control.checked:control.value;}};
            form.appendChild(fieldLabel(field.label+(field.required?' *':''),control));
        });
        var status=node('p',{class:'sb-list-message',role:'status'}),save=node('button',{type:'submit',text:'Сохранить',class:'sb-list-primary'}),footer=node('footer',{},[button('Закрыть',function(){dialog.close();})]);
        if(!readOnly)footer.appendChild(save);form.append(status,footer);dialog.appendChild(form);
        var requestKey=key();
        form.addEventListener('submit',async function(event){event.preventDefault();if(readOnly)return;save.disabled=true;
            try{var values={};Object.keys(inputs).forEach(function(id){values[id]=inputs[id].get();});
                await api(self.context,'saveRecord',{listId:list.id,itemId:item?item.id:0,version:item?item.version:0,schemaVersion:list.version,values:values,requestKey:requestKey});
                notify(list.id);dialog.close();
            }catch(e){message(status,e.message,true);}finally{save.disabled=false;}
        });
    };
    function init(){document.querySelectorAll('[data-list-context]').forEach(function(root){if(!root.__dataList)root.__dataList=new ListView(root);});}
    window.SBDataLists={api:api,openSchema:openSchema,init:init,display:display,safeUrl:safeUrl,types:TYPES};
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
