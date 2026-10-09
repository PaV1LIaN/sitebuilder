(function () {
  'use strict';

  var root = document.getElementById('sb-disk-file-open');
  if (!root) return;
  var status = root.querySelector('[data-open-status]');

  function browserUrl(value) {
    if (typeof value !== 'string' || !value.trim()) throw new Error('Не получен адрес документа.');
    var url = new URL(value, window.location.href);
    if (url.protocol !== 'https:' && url.protocol !== 'http:') throw new Error('Некорректный адрес документа.');
    if (url.username || url.password) throw new Error('Некорректный адрес документа.');
    return url.href;
  }

  async function openDocument() {
    try {
      var endpoint = new URL(root.dataset.url, window.location.href);
      if (endpoint.origin !== window.location.origin) throw new Error('Не удалось открыть сервис документов.');
      var response = await fetch(endpoint.href, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body: new URLSearchParams({ SITE_ID: root.dataset.siteId || '', sessid: root.dataset.sessid || '' })
      });
      var data = await response.json();

      if (data && data.authUrl) {
        var authLink = root.querySelector('[data-open-auth]');
        authLink.href = browserUrl(data.authUrl);
        authLink.hidden = false;
        status.textContent = 'Для просмотра файла требуется вход в сервис документов.';
        return;
      }
      if (!response.ok || !data || data.status !== 'success') {
        throw new Error('Не удалось открыть документ. Проверьте права доступа и повторите попытку.');
      }

      // Navigate this tab directly to Office/WOPI. A Disk listing and a
      // second browser tab are not part of the shared-file flow.
      window.location.replace(browserUrl(data.viewUrl));
    } catch (e) {
      status.textContent = e instanceof SyntaxError
        ? 'Сервис документов вернул некорректный ответ. Обновите страницу и повторите попытку.'
        : (e.message || 'Не удалось открыть документ.');
    }
  }

  openDocument();
})();
