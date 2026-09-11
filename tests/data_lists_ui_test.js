// Real list UI in a small DOM/network adapter; this does not replace browser QA.
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
class Node {
  constructor(tag){this.tagName=tag;this.children=[];this.listeners={};this.attributes={};this.dataset={};this.value='';this.textContent='';this.isConnected=true;this.classList={toggle(){},add(){},remove(){}};}
  setAttribute(k,v){this.attributes[k]=String(v);if(k==='value')this.value=String(v);if(k==='name')this.name=v;if(k.startsWith('data-'))this.dataset[k.slice(5).replace(/-([a-z])/g,(_,c)=>c.toUpperCase())]=String(v);}
  removeAttribute(k){delete this.attributes[k];}
  appendChild(n){this.children.push(n);n.parent=this;return n;}
  append(...nodes){nodes.forEach(n=>this.appendChild(n));}
  replaceChildren(...nodes){this.children=[];this.append(...nodes);}
  add(n){this.appendChild(n);}
  before(n){this.parent?.appendChild(n);}
  get selectedOptions(){return this.children.filter(n=>n.value===String(this.value));}
  addEventListener(name,fn){(this.listeners[name]||=[]).push(fn);}
  async emit(name,event={preventDefault(){}}){for(const fn of this.listeners[name]||[])await fn(event);}
  remove(){this.isConnected=false;this.removed=true;}
  showModal(){this.open=true;}
  close(){this.open=false;this.emit('close');}
  focus(){} setCustomValidity(v){this.validity=v;} reportValidity(){}
  querySelector(selector){return walk(this).find(n=>selector.startsWith('[name=')?n.name===selector.slice(6,-1):n.tagName===selector)||null;}
}
function walk(n){return n.children.flatMap(c=>[c,...walk(c)]);}
const document={body:new Node('body'),readyState:'loading',listeners:{},createElement:t=>new Node(t),createTextNode:text=>Object.assign(new Node('#text'),{textContent:text}),querySelectorAll:()=>[],addEventListener(name,fn){(this.listeners[name]||=[]).push(fn);},dispatchEvent(e){(this.listeners[e.type]||[]).forEach(fn=>fn(e));}};
const calls=[],env={document,window:{confirm:()=>true},URL,setTimeout,clearTimeout,CustomEvent:class{constructor(type,opts){this.type=type;this.detail=opts.detail;}},fetch:async(url,opts)=>{const input=JSON.parse(opts.body);calls.push(input);return env.reply(input);}};
let definition={id:1,title:'Заявки',version:1,canEdit:true,fields:[{id:'title',label:'Название',type:'text',required:true},{id:'url',label:'Документ',type:'url',required:false}]};
const success=data=>({ok:true,status:200,json:async()=>({ok:true,data})});
env.reply=async()=>success({list:definition,items:[],page:1,pages:1,total:0});
let source=fs.readFileSync('assets/public/lists.js','utf8').replace('window.SBDataLists={','window.TestListView=ListView;window.SBDataLists={');vm.runInNewContext(source,env);
const service=env.window.SBDataLists;
const tests=[];function test(name,fn){tests.push([name,fn]);}
function fixture(){let root=new Node('section');root.dataset.listContext=JSON.stringify({siteId:1,pageId:10,blockId:100,sessid:'session',view:{listId:1}});let view=new env.window.TestListView(root);return {root,view};}
test('safe document URLs and plain text values',()=>{for(const url of ['javascript:alert(1)','//evil.test','https://user:pass@example.com','/\\evil.test'])assert.equal(service.safeUrl(url),false);assert.equal(service.safeUrl('/local/sitebuilder/s/test/'),true);assert.equal(service.display({type:'checkbox'},false),'Нет');assert.equal(service.display({type:'date'},'2028-02-29'),'29.02.2028');});
test('readers get table with no editing controls and unsafe URLs never become links',async()=>{definition={...definition,canEdit:false};env.reply=async()=>success({list:definition,items:[{id:1,values:{title:'<img onerror=alert(1)>',url:'javascript:alert(1)'},version:1}],total:1,page:1,pages:1});const {view}=fixture();await view.load();assert.equal(view.add.hidden,true);assert.equal(view.settings.hidden,true);assert.equal(walk(view.table).filter(n=>n.tagName==='a').length,0);assert.ok(walk(view.table).some(n=>n.textContent==='<img onerror=alert(1)>'));});
test('failed refresh clears stale data and edit actions',async()=>{definition={...definition,canEdit:true};env.reply=async()=>success({list:definition,items:[],total:0,page:1,pages:1});const {view}=fixture();await view.load();assert.equal(view.add.hidden,false);env.reply=async()=>({ok:false,status:403,json:async()=>({ok:false,message:'Нет доступа'})});await view.load();assert.equal(view.add.hidden,true);assert.equal(view.table.children.length,0);assert.equal(view.status.textContent,'Нет доступа');});
test('slow response cannot replace newest search results',async()=>{const {view}=fixture();let resolve;env.reply=()=>new Promise(r=>{resolve=r;});const slow=view.load();env.reply=async()=>success({list:definition,items:[],total:0,page:1,pages:1});await view.load();resolve(success({list:{...definition,title:'Old'},items:[],total:0,page:1,pages:1}));await slow;assert.equal(view.list.title,definition.title);});
test('record conflict keeps entered draft and schema version',async()=>{env.reply=async()=>success({list:definition,items:[],total:0,page:1,pages:1});const {view}=fixture();await view.load();view.edit({id:3,version:7,values:{title:'Before',url:''}});const dialog=document.body.children.at(-1),form=dialog.querySelector('form');form.querySelector('input').value='My unsaved draft';env.reply=async()=>({ok:false,status:409,json:async()=>({ok:false,message:'Данные изменились'})});await form.emit('submit');assert.equal(dialog.open,true);assert.equal(form.querySelector('input').value,'My unsaved draft');const sent=calls.at(-1);assert.equal(sent.version,7);assert.equal(sent.schemaVersion,1);assert.equal(sent.values.title,'My unsaved draft');assert.ok(walk(form).some(n=>n.textContent==='Данные изменились'));});
test('retrying create uses the same request key',async()=>{env.reply=async()=>success({list:definition,items:[],total:0,page:1,pages:1});const {view}=fixture();await view.load();view.edit(null);const form=document.body.children.at(-1).querySelector('form');form.querySelector('input').value='New';env.reply=async()=>{throw new Error('Connection lost');};await form.emit('submit');let first=calls.at(-1);await form.emit('submit');assert.equal(calls.at(-1).requestKey,first.requestKey);assert.match(first.requestKey,/^[a-zA-Z0-9_-]{16,64}$/);});
test('schema editor preserves stable field IDs',async()=>{let result;service.openSchema({siteId:1,pageId:10,blockId:100,sessid:'session'},definition,list=>{result=list;});const dialog=document.body.children.at(-1),form=dialog.querySelector('form');form.querySelector('[name=label]').value='Новое название';env.reply=async()=>success({list:definition});await form.emit('submit');const sent=calls.findLast(c=>c.action==='schema');assert.equal(sent.fields[0].id,'title');assert.equal(sent.fields[0].label,'Новое название');assert.equal(result.id,1);assert.equal(dialog.open,false);});
test('editor selects existing dataset and saves display settings',async()=>{
  const host=new Node('div');document.getElementById=id=>id==='unknownBlockForm'?host:null;const block={id:100,pageId:10,type:'list',content:{listId:1,title:'Реестр',sortBy:'title',filters:{title:'abc'}},props:{sectionId:4}};
  Object.assign(env,{state:{currentPageId:10},siteId:1,BASE_PATH:'/local/sitebuilder',getSessid:()=> 'session',getCurrentBlock:()=>block,Option:class extends Node{constructor(label,value){super('option');this.textContent=label;this.value=String(value);}}});Object.assign(env.window,{hideAllBlockTypeForms(){},fillVisualBlockForm(){},collectVisualBlockData(){}});
  env.reply=async()=>success({lists:[definition]});vm.runInNewContext(fs.readFileSync('assets/admin/editor/53-data-lists.js','utf8'),env);env.window.fillVisualBlockForm(block);await new Promise(r=>setTimeout(r,0));const data=env.window.collectVisualBlockData(block);assert.equal(data.content.listId,1);assert.equal(data.content.title,'Реестр');assert.equal(data.content.sortBy,'title');assert.equal(data.content.filters.title,'abc');assert.equal(data.props.sectionId,4);
});
(async()=>{for(const [name,fn] of tests){await fn();console.log('PASS '+name);}console.log(tests.length+' UI checks passed');})().catch(e=>{console.error(e);process.exitCode=1;});
