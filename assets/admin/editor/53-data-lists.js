(function () {
    'use strict';
    if (!window.SBDataLists || typeof state === 'undefined') return;
    var service=window.SBDataLists, originalHide=window.hideAllBlockTypeForms,
        originalFill=window.fillVisualBlockForm, originalCollect=window.collectVisualBlockData;
    var host=document.getElementById('unknownBlockForm'), form=document.createElement('div');
    form.id='listBlockForm'; form.className='sb-block-type-form sb-hidden';
    host.before(form);
    function field(label,control) {
        var box=document.createElement('label'); box.className='sb-field';
        var caption=document.createElement('span');caption.textContent=label;box.append(caption,control);form.append(box);return control;
    }
    function input(label) {var el=document.createElement('input');el.className='sb-input';return field(label,el);}
    function select(label) {var el=document.createElement('select');el.className='sb-select';return field(label,el);}
    function options(el,fields,empty) {
        el.replaceChildren(new Option(empty,''));fields.forEach(function(f){el.add(new Option(f.label,f.id));});
    }
    function button(label,run) {var el=document.createElement('button');el.type='button';el.className='sb-btn sb-btn-light sb-btn-small';el.textContent=label;el.onclick=run;form.append(el);return el;}
    var source=select('Список'), title=input('Заголовок блока (необязательно)'); title.maxLength=160;
    var create=button('Создать список',function(){var ctx=context();service.openSchema(ctx,null,function(list){if(sameBlock(ctx)){catalog[list.id]=list;source.add(new Option(list.title,list.id));source.value=list.id;setFields({});note('Список создан. Сохраните блок, чтобы вывести его на странице.');}});});
    var edit=button('Настроить поля',async function(){var ctx=context();try{var data=await service.api(ctx,'configure',{listId:Number(source.value)});if(sameBlock(ctx))service.openSchema(ctx,data.list,function(list){if(sameBlock(ctx)){catalog[list.id]=list;source.selectedOptions[0].textContent=list.title;setFields(draft());note('Настройки списка сохранены.');}});}catch(e){note(e.message);}});
    var sort=select('Сортировка'), direction=select('Направление сортировки'), group=select('Группировать по');
    direction.add(new Option('По возрастанию','asc')); direction.add(new Option('По убыванию','desc'));
    var filter=select('Постоянный фильтр блока'), filterValue=input('Значение фильтра');
    var hint=document.createElement('p');hint.className='sb-block-form-note';hint.textContent='Записи наследуют права исходной страницы списка. Фильтры меняют отображение, но не права доступа. Для ссылки на этот же список добавьте ещё один блок «Список».';form.append(hint);
    var status=document.createElement('p');status.className='sb-block-form-note';status.setAttribute('role','status');form.append(status);
    var catalog={}, active=null, generation=0, savedFilters={};
    function note(text){status.textContent=text||'';}
    function context(){return {siteId:siteId,pageId:Number(active.pageId||state.currentPageId),blockId:Number(active.id),basePath:BASE_PATH,sessid:getSessid()};}
    function sameBlock(ctx){return active&&Number(active.id)===ctx.blockId&&getCurrentBlock()&&Number(getCurrentBlock().id)===ctx.blockId;}
    function draft(){return {sortBy:sort.value,sortDir:direction.value,groupBy:group.value,filters:currentFilters()};}
    function currentFilters(){var result=Object.assign({},savedFilters);if(filter.dataset.previous)delete result[filter.dataset.previous];if(filter.value&&filterValue.value!=='')result[filter.value]=filterValue.value;return result;}
    function setFields(content){
        var list=catalog[source.value],fields=list?list.fields:[];
        options(sort,fields,'По дате добавления');options(group,fields,'Без группировки');options(filter,fields,'Без фильтра');
        sort.value=content.sortBy||'';direction.value=content.sortDir||'asc';group.value=content.groupBy||'';
        savedFilters=Object.assign({},content.filters||{});var key=Object.keys(savedFilters)[0]||'';
        filter.value=key;filter.dataset.previous=key;filterValue.value=savedFilters[key]||'';filterValue.disabled=!filter.value;
        filterValue.placeholder='Текст; для флажка: true или false';edit.disabled=!list||!list.canEdit;
    }
    source.addEventListener('change',function(){setFields({});note('Сохраните блок, чтобы применить выбранный список.');});
    filter.addEventListener('change',function(){filterValue.value='';filterValue.disabled=!filter.value;});
    window.hideAllBlockTypeForms=function(){originalHide.apply(this,arguments);form.classList.add('sb-hidden');};
    window.fillVisualBlockForm=function(block){
        if(!block||block.type!=='list')return originalFill.apply(this,arguments);
        window.hideAllBlockTypeForms();form.classList.remove('sb-hidden');active=block;
        var content=block.content||{},ctx=context(),current=++generation;
        title.value=content.title||'';catalog={};source.replaceChildren(new Option('Загрузка…',String(content.listId||'')));source.disabled=true;edit.disabled=true;create.disabled=true;setFields(content);note('Загрузка списков…');
        service.api(ctx,'catalog').then(function(data){
            if(current!==generation||!sameBlock(ctx))return;
            source.replaceChildren(new Option('Выберите список',''));
            data.lists.forEach(function(list){catalog[list.id]=list;source.add(new Option(list.title,list.id));});
            if(content.listId&&!catalog[content.listId])source.add(new Option('Список недоступен (#'+Number(content.listId)+')',String(content.listId)));
            source.value=content.listId||'';source.disabled=false;create.disabled=false;setFields(content);note('Выберите существующий список или создайте новый.');
        }).catch(function(e){if(current===generation&&sameBlock(ctx))note(e.message);});
    };
    window.collectVisualBlockData=function(block){
        if(!block||block.type!=='list')return originalCollect.apply(this,arguments);
        if(source.disabled){alert('Дождитесь загрузки настроек списка.');return null;}
        return {content:Object.assign({},block.content||{},draft(),{listId:Number(source.value),title:title.value.trim()}),props:block.props||{}};
    };
})();
