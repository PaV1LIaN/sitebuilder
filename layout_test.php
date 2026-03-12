<?php
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('DisableEventsCheck', true);

require $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php';

global $USER, $APPLICATION;

if (!$USER->IsAuthorized()) {
    LocalRedirect('/auth/');
}

$siteId = (int)($_GET['siteId'] ?? 0);
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Layout test</title>
  <?php $APPLICATION->ShowHead(); ?>
  <script src="/bitrix/js/main/core/core.js"></script>
</head>
<body>
  <h2>Layout test</h2>
  <div>siteId: <?= (int)$siteId ?></div>

  <button onclick="testGet()">layout.get</button>
  <button onclick="testCreate()">layout.block.create(header text)</button>
  <button onclick="testList()">layout.block.list(header)</button>

  <pre id="out" style="margin-top:20px;background:#f5f5f5;padding:12px;border:1px solid #ddd;"></pre>

<script>
function out(x){
  document.getElementById('out').textContent =
    typeof x === 'string' ? x : JSON.stringify(x, null, 2);
}

function api(action, data) {
  return new Promise((resolve, reject) => {
    BX.ajax({
      url: '/local/sitebuilder/api.php',
      method: 'POST',
      dataType: 'json',
      data: Object.assign({ action, sessid: BX.bitrix_sessid() }, data || {}),
      onsuccess: resolve,
      onfailure: reject
    });
  });
}

async function testGet() {
  try {
    const r = await api('layout.get', { siteId: <?= (int)$siteId ?> });
    out(r);
  } catch (e) {
    out('layout.get error');
  }
}

async function testCreate() {
  try {
    const r = await api('layout.block.create', {
      siteId: <?= (int)$siteId ?>,
      zone: 'header',
      type: 'text',
      text: 'Тестовый header блок'
    });
    out(r);
  } catch (e) {
    out('layout.block.create error');
  }
}

async function testList() {
  try {
    const r = await api('layout.block.list', {
      siteId: <?= (int)$siteId ?>,
      zone: 'header'
    });
    out(r);
  } catch (e) {
    out('layout.block.list error');
  }
}
</script>
</body>
</html>