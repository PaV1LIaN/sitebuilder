/* =========================================================
   CONTENT TOOLS / STAGE 18
   Media library, rich text editor, ready-made sections and
   mobile editor controls.
   ========================================================= */
(function () {
    'use strict';

    if (!window.SB_EDITOR_CONFIG || typeof state === 'undefined') {
        return;
    }

    var mediaModal = document.getElementById('mediaLibraryModal');
    var mediaGrid = document.getElementById('mediaLibraryGrid');
    var mediaStatus = document.getElementById('mediaLibraryStatus');
    var mediaSearch = document.getElementById('mediaLibrarySearch');
    var mediaUpload = document.getElementById('mediaLibraryUpload');
    var mediaTargetElement = null;
    var mediaFiles = [];
    var mediaLoading = false;
    var richEditor = document.getElementById('textRichEditor');
    var richSource = document.getElementById('textTextInput');
    var richToolbar = document.getElementById('textRichToolbar');
    var syncingRichEditor = false;
    var richSelectionRange = null;

    function ctEscape(value) {
        return typeof escapeHtml === 'function'
            ? escapeHtml(value)
            : String(value == null ? '' : value).replace(/[&<>"']/g, function (char) {
                return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[char];
            });
    }

    function ctBytes(value) {
        value = Math.max(0, Number(value || 0));
        if (value < 1024) return value + ' Б';
        if (value < 1024 * 1024) return (value / 1024).toFixed(1) + ' КБ';
        return (value / (1024 * 1024)).toFixed(1) + ' МБ';
    }

    function ctSafeMediaUrl(value) {
        value = String(value || '').trim();

        if (!value) {
            return '';
        }

        try {
            var parsed = new URL(value, window.location.origin);

            if (parsed.origin !== window.location.origin) {
                return '';
            }

            var fileId = 0;

            if (/\/media_preview\.php$/i.test(parsed.pathname)) {
                fileId = Number(parsed.searchParams.get('fileId') || 0);
            } else if (/\/bitrix\/tools\/disk\/downloadFile\.php$/i.test(parsed.pathname)) {
                fileId = Number(parsed.searchParams.get('objectId') || 0);
            }

            return fileId > 0
                ? '/bitrix/tools/disk/downloadFile.php?objectId=' + fileId
                : '';
        } catch (error) {
            return '';
        }
    }

    /* -----------------------------------------------------
       Rich text editor
       ----------------------------------------------------- */

    function sanitizeRichHtml(html) {
        var template = document.createElement('template');
        template.innerHTML = String(html || '');
        var allowed = {
            P: true, BR: true, H2: true, H3: true, H4: true, H5: true, H6: true,
            STRONG: true, B: true, EM: true, I: true, U: true, S: true,
            UL: true, OL: true, LI: true, A: true, BLOCKQUOTE: true,
            CODE: true, PRE: true, SPAN: true
        };
        var drop = {SCRIPT: true, STYLE: true, IFRAME: true, OBJECT: true, EMBED: true, SVG: true, MATH: true};

        Array.prototype.slice.call(template.content.querySelectorAll('*')).forEach(function (node) {
            if (drop[node.tagName]) {
                node.remove();
                return;
            }

            if (!allowed[node.tagName]) {
                var fragment = document.createDocumentFragment();
                while (node.firstChild) fragment.appendChild(node.firstChild);
                node.replaceWith(fragment);
                return;
            }

            Array.prototype.slice.call(node.attributes || []).forEach(function (attribute) {
                var name = String(attribute.name || '').toLowerCase();
                if (node.tagName === 'A' && ['href', 'target', 'rel'].indexOf(name) !== -1) return;
                node.removeAttribute(attribute.name);
            });

            if (node.tagName === 'A') {
                var href = String(node.getAttribute('href') || '').trim();
                var safe = href.charAt(0) === '/' || href.charAt(0) === '#' || href.charAt(0) === '?'
                    || /^(https?:|mailto:|tel:)/i.test(href);
                if (!safe || href.indexOf('//') === 0) node.removeAttribute('href');
                if (node.getAttribute('target') === '_blank') {
                    node.setAttribute('rel', 'noopener noreferrer');
                } else {
                    node.removeAttribute('target');
                    node.removeAttribute('rel');
                }
            }
        });

        return template.innerHTML;
    }

    function richEditorToSource() {
        if (!richEditor || !richSource || syncingRichEditor) return;
        syncingRichEditor = true;
        richSource.value = sanitizeRichHtml(richEditor.innerHTML);
        richSource.dispatchEvent(new Event('input', {bubbles: true}));
        syncingRichEditor = false;
    }

    function richSourceToEditor(force) {
        if (!richEditor || !richSource || syncingRichEditor) return;
        var block = typeof getCurrentBlock === 'function' ? getCurrentBlock() : null;
        var visible = block && String(block.type || '') === 'text';
        if (!visible && !force) return;
        var html = sanitizeRichHtml(richSource.value || '');
        if (force || richEditor.innerHTML !== html) {
            syncingRichEditor = true;
            richEditor.innerHTML = html;
            syncingRichEditor = false;
        }
    }

    function rememberRichSelection() {
        if (!richEditor || !window.getSelection) return;
        var selection = window.getSelection();
        if (!selection || selection.rangeCount < 1) return;
        var range = selection.getRangeAt(0);
        var common = range.commonAncestorContainer;
        var element = common.nodeType === 1 ? common : common.parentElement;
        if (element && richEditor.contains(element)) {
            richSelectionRange = range.cloneRange();
        }
    }

    function restoreRichSelection() {
        if (!richSelectionRange || !window.getSelection) return;
        var selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(richSelectionRange);
    }

    function runRichCommand(command, value) {
        if (!richEditor) return;
        richEditor.focus();
        restoreRichSelection();

        if (command === 'createLink') {
            var href = window.prompt('Адрес ссылки', 'https://');
            if (href === null) return;
            href = String(href || '').trim();
            if (!href || !(href.charAt(0) === '/' || href.charAt(0) === '#' || href.charAt(0) === '?' || /^(https?:|mailto:|tel:)/i.test(href))) {
                if (typeof showEditorToast === 'function') showEditorToast('Некорректная ссылка', 'error');
                return;
            }
            restoreRichSelection();
            document.execCommand('createLink', false, href);
        } else if (command === 'formatBlock') {
            document.execCommand('formatBlock', false, value || 'p');
        } else {
            document.execCommand(command, false, null);
        }

        richEditorToSource();
    }

    function installRichEditor() {
        if (!richEditor || !richSource || !richToolbar) return;

        richEditor.addEventListener('input', function () {
            rememberRichSelection();
            richEditorToSource();
        });
        richEditor.addEventListener('keyup', rememberRichSelection);
        richEditor.addEventListener('mouseup', rememberRichSelection);
        richEditor.addEventListener('focus', rememberRichSelection);
        richEditor.addEventListener('blur', richEditorToSource);
        document.addEventListener('selectionchange', rememberRichSelection);
        richEditor.addEventListener('paste', function (event) {
            event.preventDefault();
            var clipboard = event.clipboardData || window.clipboardData;
            var html = clipboard ? clipboard.getData('text/html') : '';
            var text = clipboard ? clipboard.getData('text/plain') : '';
            var insert = html ? sanitizeRichHtml(html) : ctEscape(text).replace(/\n/g, '<br>');
            document.execCommand('insertHTML', false, insert);
            richEditorToSource();
        });

        richSource.addEventListener('input', function () {
            if (!syncingRichEditor) richSourceToEditor(false);
        });

        richToolbar.addEventListener('pointerdown', function (event) {
            if (event.target.closest('button')) event.preventDefault();
        });
        richToolbar.addEventListener('click', function (event) {
            var button = event.target.closest('[data-rich-command]');
            if (!button || button.tagName === 'SELECT') return;
            runRichCommand(String(button.getAttribute('data-rich-command') || ''));
        });
        richToolbar.addEventListener('change', function (event) {
            var select = event.target.closest('select[data-rich-command]');
            if (!select) return;
            runRichCommand(String(select.getAttribute('data-rich-command') || ''), String(select.value || 'p'));
        });

        var originalFillBlockForm = window.fillBlockForm;
        if (typeof originalFillBlockForm === 'function') {
            window.fillBlockForm = function () {
                var result = originalFillBlockForm.apply(this, arguments);
                window.setTimeout(function () { richSourceToEditor(true); }, 0);
                return result;
            };
        }

        richSourceToEditor(true);
    }

    /* -----------------------------------------------------
       Media library
       ----------------------------------------------------- */

    function mediaSetStatus(text, type) {
        if (!mediaStatus) return;
        mediaStatus.textContent = text || '';
        mediaStatus.className = 'sb-media-library__status' + (type ? ' is-' + type : '');
    }

    function mediaFilteredFiles() {
        var query = mediaSearch ? String(mediaSearch.value || '').trim().toLowerCase() : '';
        return mediaFiles.filter(function (file) {
            return !!file.isImage && (!query || String(file.name || '').toLowerCase().indexOf(query) !== -1);
        });
    }

    function renderMediaFiles() {
        if (!mediaGrid) return;
        var items = mediaFilteredFiles();

        if (!items.length) {
            mediaGrid.innerHTML = '<div class="sb-media-library__empty">Изображений пока нет. Загрузите PNG, JPG, GIF, WebP или SVG.</div>';
            return;
        }

        mediaGrid.innerHTML = items.map(function (file) {
            var previewUrl = ctSafeMediaUrl(file.previewUrl || file.downloadUrl)
                || ('/bitrix/tools/disk/downloadFile.php?objectId=' + Number(file.id || 0));
            return ''
                + '<article class="sb-media-card" data-media-id="' + Number(file.id || 0) + '" data-media-url="' + ctEscape(previewUrl) + '" data-media-name="' + ctEscape(file.name || '') + '">'
                + '  <button class="sb-media-card__choose" type="button" data-media-choose title="Выбрать изображение">'
                + '    <span class="sb-media-card__image"><img src="' + ctEscape(previewUrl) + '" alt="" loading="lazy"></span>'
                + '    <span class="sb-media-card__name">' + ctEscape(file.name || 'Изображение') + '</span>'
                + '    <span class="sb-media-card__meta">' + ctEscape(ctBytes(file.size)) + '</span>'
                + '  </button>'
                + '  <button class="sb-media-card__delete" type="button" data-media-delete title="Удалить">×</button>'
                + '</article>';
        }).join('');
    }

    async function loadMediaFiles() {
        if (mediaLoading) return;
        mediaLoading = true;
        mediaSetStatus('Загружаю изображения…', 'info');
        try {
            var response = await api('file.list', {siteId: siteId});
            var data = typeof apiData === 'function' ? apiData(response) : response;
            mediaFiles = Array.isArray(data.files) ? data.files : [];
            renderMediaFiles();
            mediaSetStatus(mediaFiles.filter(function (file) { return file.isImage; }).length + ' изображений', 'success');
        } catch (error) {
            console.error(error);
            mediaSetStatus('Не удалось загрузить медиатеку', 'error');
            if (mediaGrid) mediaGrid.innerHTML = '<div class="sb-media-library__empty">Ошибка загрузки медиатеки</div>';
        } finally {
            mediaLoading = false;
        }
    }

    function openMediaLibrary(target) {
        if (!mediaModal) return;
        mediaTargetElement = target || null;
        mediaModal.hidden = false;
        document.body.classList.add('sb-modal-open');
        if (mediaSearch) mediaSearch.value = '';
        loadMediaFiles();
    }

    function closeMediaLibrary() {
        if (!mediaModal) return;
        mediaModal.hidden = true;
        mediaTargetElement = null;
        document.body.classList.remove('sb-modal-open');
    }

    function selectMedia(card) {
        if (!card || !mediaTargetElement) return;
        var url = String(card.getAttribute('data-media-url') || '');
        var name = String(card.getAttribute('data-media-name') || '');
        if (!url) return;
        mediaTargetElement.value = url;
        mediaTargetElement.dispatchEvent(new Event('input', {bubbles: true}));
        mediaTargetElement.dispatchEvent(new Event('change', {bubbles: true}));

        if (mediaTargetElement.id === 'imageSrcInput') {
            var alt = document.getElementById('imageAltInput');
            if (alt && !String(alt.value || '').trim()) {
                alt.value = name.replace(/\.[^.]+$/, '').replace(/[-_]+/g, ' ');
                alt.dispatchEvent(new Event('input', {bubbles: true}));
            }
        }
        closeMediaLibrary();
        if (typeof showEditorToast === 'function') showEditorToast('Изображение выбрано', 'success');
    }

    async function uploadMedia(file) {
        if (!file) return;
        var extension = String(file.name || '').split('.').pop().toLowerCase();
        var imageMime = /^image\/(png|jpeg|gif|webp|svg\+xml)$/i.test(String(file.type || ''));
        var imageExtension = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'].indexOf(extension) !== -1;
        if (!imageMime && !imageExtension) {
            mediaSetStatus('Поддерживаются только изображения PNG, JPG, GIF, WebP и SVG', 'error');
            return;
        }

        var form = new FormData();
        form.append('action', 'file.upload');
        form.append('sessid', getSessid());
        form.append('siteId', String(siteId));
        form.append('file', file, file.name);
        mediaSetStatus('Загружаю «' + file.name + '»…', 'info');

        try {
            var response = await fetch(API_URL, {method: 'POST', body: form, credentials: 'same-origin'});
            var result = await response.json();
            if (!result || !result.ok) throw result || new Error('UPLOAD_FAILED');
            await loadMediaFiles();
            mediaSetStatus('Изображение загружено', 'success');
        } catch (error) {
            console.error(error);
            mediaSetStatus('Не удалось загрузить изображение', 'error');
        } finally {
            if (mediaUpload) mediaUpload.value = '';
        }
    }

    function installMediaButtons(root) {
        root = root || document;
        root.querySelectorAll('input[data-section-field="backgroundImage"], input[data-card-field="imageSrc"]').forEach(function (input) {
            if (input.getAttribute('data-media-enhanced') === '1') return;
            input.setAttribute('data-media-enhanced', '1');
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'sb-btn sb-btn-light sb-btn-small sb-dynamic-media-button';
            button.textContent = 'Медиатека';
            button.addEventListener('click', function () { openMediaLibrary(input); });
            input.insertAdjacentElement('afterend', button);
        });
    }

    function installMediaLibrary() {
        document.addEventListener('click', function (event) {
            var opener = event.target.closest('[data-open-media]');
            if (opener) {
                var target = document.getElementById(String(opener.getAttribute('data-media-target') || ''));
                if (target) openMediaLibrary(target);
                return;
            }

            if (event.target.closest('[data-close-media-library]')) {
                closeMediaLibrary();
                return;
            }

            var choose = event.target.closest('[data-media-choose]');
            if (choose) {
                selectMedia(choose.closest('.sb-media-card'));
                return;
            }

            var remove = event.target.closest('[data-media-delete]');
            if (remove) {
                var card = remove.closest('.sb-media-card');
                var fileId = Number(card && card.getAttribute('data-media-id') || 0);
                if (fileId > 0 && window.confirm('Удалить изображение из медиатеки?')) {
                    remove.disabled = true;
                    api('file.delete', {siteId: siteId, fileId: fileId}).then(loadMediaFiles).catch(function () {
                        mediaSetStatus('Не удалось удалить изображение', 'error');
                        remove.disabled = false;
                    });
                }
            }
        });

        if (mediaSearch) mediaSearch.addEventListener('input', renderMediaFiles);
        if (mediaUpload) mediaUpload.addEventListener('change', function () { uploadMedia(mediaUpload.files && mediaUpload.files[0]); });
        var refresh = document.getElementById('mediaLibraryRefresh');
        if (refresh) refresh.addEventListener('click', loadMediaFiles);

        installMediaButtons(document);
        var observer = new MutationObserver(function (records) {
            records.forEach(function (record) {
                Array.prototype.slice.call(record.addedNodes || []).forEach(function (node) {
                    if (node.nodeType === 1) installMediaButtons(node);
                });
            });
        });
        observer.observe(document.body, {childList: true, subtree: true});
    }

    /* -----------------------------------------------------
       Ready-made sections
       ----------------------------------------------------- */

    function setLibraryView(view) {
        view = view === 'presets' ? 'presets' : 'blocks';
        document.querySelectorAll('[data-library-view]').forEach(function (button) {
            button.classList.toggle('is-active', button.getAttribute('data-library-view') === view);
        });
        document.querySelectorAll('[data-library-pane]').forEach(function (pane) {
            pane.hidden = pane.getAttribute('data-library-pane') !== view;
        });
        var tools = document.querySelector('[data-library-block-tools]');
        if (tools) tools.hidden = view !== 'blocks';
    }

    async function createSectionPreset(presetKey, button) {
        if (!state.currentPageId) {
            if (typeof showEditorToast === 'function') showEditorToast('Сначала выберите страницу', 'error');
            return;
        }
        if (button) button.disabled = true;
        if (typeof setEditorStatus === 'function') setEditorStatus('working', 'Создаю секцию…');

        try {
            var response = await api('pageSection.createPreset', {
                siteId: siteId,
                pageId: state.currentPageId,
                presetKey: presetKey
            });
            var data = typeof apiData === 'function' ? apiData(response) : response;
            if (Array.isArray(data.sections)) state.pageSections = data.sections;
            if (data.section && data.section.id) {
                state.currentSectionId = Number(data.section.id);
                state.currentColumn = 1;
            }
            if (typeof loadPageSections === 'function') await loadPageSections();
            if (typeof loadBlocks === 'function') await loadBlocks();
            if (typeof closeBlockLibrary === 'function') closeBlockLibrary();
            if (typeof setInspectorTab === 'function') setInspectorTab('section');
            if (typeof showEditorToast === 'function') showEditorToast('Готовая секция добавлена', 'success');
        } catch (error) {
            console.error(error);
            if (typeof showEditorToast === 'function') showEditorToast('Не удалось создать готовую секцию', 'error');
        } finally {
            if (button) button.disabled = false;
        }
    }

    function installPresetLibrary() {
        document.querySelectorAll('[data-library-view]').forEach(function (button) {
            button.addEventListener('click', function () {
                setLibraryView(String(button.getAttribute('data-library-view') || 'blocks'));
            });
        });
        document.querySelectorAll('[data-section-preset]').forEach(function (button) {
            button.addEventListener('click', function () {
                createSectionPreset(String(button.getAttribute('data-section-preset') || ''), button);
            });
        });

        var openLibraryButton = document.getElementById('openBlockLibraryBtn');
        if (openLibraryButton) {
            openLibraryButton.addEventListener('click', function () { setLibraryView('blocks'); });
        }

        var originalOpenBlockLibrary = window.openBlockLibrary;
        if (typeof originalOpenBlockLibrary === 'function') {
            window.openBlockLibrary = function () {
                setLibraryView('blocks');
                return originalOpenBlockLibrary.apply(this, arguments);
            };
        }
        setLibraryView('blocks');
    }

    /* -----------------------------------------------------
       Mobile dock
       ----------------------------------------------------- */

    function installMobileDock() {
        var dock = document.getElementById('editorMobileDock');
        if (!dock) return;

        dock.addEventListener('click', function (event) {
            var button = event.target.closest('[data-mobile-editor-action]');
            if (!button) return;
            var action = String(button.getAttribute('data-mobile-editor-action') || '');

            if (action === 'pages') {
                var pagesButton = document.getElementById('togglePagesPanelBtn');
                if (pagesButton) pagesButton.click();
            } else if (action === 'inspector') {
                var inspectorButton = document.getElementById('toggleInspectorPanelBtn');
                if (inspectorButton) inspectorButton.click();
            } else if (action === 'add') {
                if (typeof openBlockLibrary === 'function') openBlockLibrary();
            } else if (action === 'preview') {
                var order = ['mobile', 'tablet', 'desktop'];
                var current = state.previewDevice || 'mobile';
                var next = order[(order.indexOf(current) + 1) % order.length];
                if (typeof setPreviewDevice === 'function') setPreviewDevice(next);
            }
        });
    }

    installRichEditor();
    installMediaLibrary();
    installPresetLibrary();
    installMobileDock();

    window.SBMediaLibrary = {
        open: openMediaLibrary,
        close: closeMediaLibrary,
        reload: loadMediaFiles
    };
})();
