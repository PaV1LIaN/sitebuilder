SiteBuilder init: Загрузка сайта site.get
60-events.js?v=17:581 SiteBuilder init: Загрузка страниц page.list
60-events.js?v=17:581 SiteBuilder init: Загрузка секций и блоков pageSection.list / block.list
60-events.js?v=17:581 SiteBuilder init: Загрузка прав доступа access.list
60-events.js?v=17:618 SiteBuilder editor initialized successfully
core.js:6364 BX.debug:  
(3) ['status', 500, {…}]
0
: 
"status"
1
: 
500
2
: 
async
: 
true
cache
: 
true
data
: 
"action=page.create&sessid=37dd0f1d1c546192387e63f5b1f2b98d&siteId=14&title=%D0%A2%D0%B5%D1%81%D1%82%D0%BE%D0%B2%D0%B0%D1%8F&slug=test&parentId=0"
dataType
: 
"json"
emulateOnload
: 
false
headers
: 
false
lsForce
: 
false
lsTimeout
: 
30
method
: 
"POST"
onfailure
: 
ƒ (err)
onsuccess
: 
ƒ (res)
preparePost
: 
true
processData
: 
true
scriptsRunFirst
: 
false
skipAuthCheck
: 
false
start
: 
true
timeout
: 
60
url
: 
"/local/sitebuilder/api/index.php"
xhr
: 
null
[[Prototype]]
: 
Object
length
: 
3
[[Prototype]]
: 
Array(0)
core.js:6371 console.trace
debug	@	core.js:6371
value	@	core.js:7718
onCustomEvent	@	core.js:11135
(anonymous)	@	core.js:15741
XMLHttpRequest.send		
(anonymous)	@	core.js:15764
(anonymous)	@	00-core.js?v=20.1:131
api	@	00-core.js?v=20.1:130
createPage	@	20-pages.js?v=20:314

core.js:15764  POST https://portal24.itsnn.ru/local/sitebuilder/api/index.php 500 (Internal Server Error)
(anonymous) @ core.js:15764
(anonymous) @ 00-core.js?v=20.1:131
api @ 00-core.js?v=20.1:130
createPage @ 20-pages.js?v=20:314
20-pages.js?v=20:343 Uncaught (in promise) status
