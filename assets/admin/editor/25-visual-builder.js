/* =========================================================
   VISUAL BUILDER 2.0
   Editor status, responsive preview, inspector tabs and
   searchable component library.
   ========================================================= */

var SB_BLOCK_TYPES = {
    heading: {title: 'Заголовок', icon: 'H', category: 'basic'},
    text: {title: 'Текст', icon: '¶', category: 'basic'},
    button: {title: 'Кнопка', icon: '↗', category: 'basic'},
    image: {title: 'Изображение', icon: '▧', category: 'content'},
    hero: {title: 'Первый экран', icon: '★', category: 'marketing'},
    cards: {title: 'Карточки', icon: '▦', category: 'marketing'},
    quote: {title: 'Цитата', icon: '“', category: 'content'},
    stats: {title: 'Показатели', icon: '№', category: 'data'},
    table: {title: 'Таблица', icon: '▤', category: 'data'},
    divider: {title: 'Разделитель', icon: '—', category: 'basic'},
    spacer: {title: 'Отступ', icon: '↕', category: 'basic'},
    disk: {title: 'Битрикс.Диск', icon: '◫', category: 'advanced'},
    html: {title: 'HTML', icon: '</>', category: 'advanced'}
};

function blockTypeMeta(type) {
    type = String(type || 'text');
    return SB_BLOCK_TYPES[type] || {title: type || 'Блок', icon: '◆', category: 'advanced'};
}

function setEditorStatus(status, text) {
    var node = document.getElementById('editorSaveStatus');
    if (!node) return;

    status = status || 'ready';
    node.setAttribute('data-state', status);

    var textNode = node.querySelector('.sb-editor-status__text');
    if (textNode) {
        textNode.textContent = text || (status === 'working' ? 'Выполняется…' : status === 'error' ? 'Ошибка' : 'Готово');
    }
}

function showEditorToast(message, type, timeout) {
    var stack = document.getElementById('editorToastStack');
    if (!stack || !message) return;

    var toast = document.createElement('div');
    toast.className = 'sb-toast sb-toast--' + (type || 'success');
    toast.innerHTML = '<span class="sb-toast__mark">' + (type === 'error' ? '!' : type === 'info' ? 'i' : '✓') + '</span>'
        + '<span class="sb-toast__text">' + escapeHtml(message) + '</span>';

    stack.appendChild(toast);
    window.setTimeout(function () {
        toast.classList.add('is-visible');
    }, 10);

    window.setTimeout(function () {
        toast.classList.remove('is-visible');
        window.setTimeout(function () {
            if (toast.parentNode) toast.parentNode.removeChild(toast);
        }, 180);
    }, Number(timeout || 2600));
}

function setInspectorTab(tab) {
    tab = String(tab || 'page');

    var available = Array.prototype.slice.call(document.querySelectorAll('[data-inspector-panel="' + tab + '"]'))
        .some(function (panel) { return !panel.hidden; });

    if (!available) {
        tab = 'page';
    }

    if (state) {
        state.inspectorTab = tab;
    }

    document.querySelectorAll('[data-inspector-tab]').forEach(function (button) {
        button.classList.toggle('is-active', button.getAttribute('data-inspector-tab') === tab);
        button.setAttribute('aria-selected', button.getAttribute('data-inspector-tab') === tab ? 'true' : 'false');
    });

    document.querySelectorAll('[data-inspector-panel]').forEach(function (panel) {
        var active = panel.getAttribute('data-inspector-panel') === tab && !panel.hidden;
        panel.classList.toggle('is-active', active);
    });
}

/* SiteBuilder page access active panel fix v1 */
function refreshInspectorAccessTab() {
    var tab = document.getElementById('inspectorAccessTab');
    if (!tab) return;

    var groupPanel = document.getElementById('siteGroupPanel');
    var accessPanel = document.getElementById('siteAccessPanel');
    var pageAccessPanel = document.getElementById('pageAccessPanel');
    var visible = !!(
        (groupPanel && !groupPanel.hidden)
        || (accessPanel && !accessPanel.hidden)
        || (pageAccessPanel && !pageAccessPanel.hidden)
    );

    tab.hidden = !visible;

    if (!visible && state && state.inspectorTab === 'access') {
        setInspectorTab('page');
        return;
    }

    /*
     * Панели доступа загружаются асинхронно.
     * Если вкладка «Доступ» уже открыта, новая панель после
     * снятия hidden должна сразу получить is-active.
     */
    if (visible && state && state.inspectorTab === 'access') {
        document
            .querySelectorAll('[data-inspector-panel="access"]')
            .forEach(function (panel) {
                panel.classList.toggle(
                    'is-active',
                    !panel.hidden
                );
            });
    }
}

function setPreviewDevice(device) {
    device = ['desktop', 'tablet', 'mobile'].indexOf(device) !== -1 ? device : 'desktop';

    var viewport = document.getElementById('editorViewport');
    if (!viewport) return;

    viewport.classList.remove('is-desktop', 'is-tablet', 'is-mobile');
    viewport.classList.add('is-' + device);

    document.querySelectorAll('[data-preview-device]').forEach(function (button) {
        button.classList.toggle('is-active', button.getAttribute('data-preview-device') === device);
    });

    if (state) {
        state.previewDevice = device;
    }

    try {
        window.localStorage.setItem('sitebuilder.editor.previewDevice', device);
    } catch (e) {
        // localStorage may be disabled.
    }
}

function openBlockLibrary() {
    if (!state.currentPageId) {
        showEditorToast('Сначала выберите страницу', 'error');
        return;
    }

    var modal = document.getElementById('blockLibraryModal');
    if (!modal) return;

    modal.hidden = false;
    document.body.classList.add('sb-modal-open');

    var search = document.getElementById('blockLibrarySearch');
    if (search) {
        search.value = '';
        filterBlockLibrary();
        window.setTimeout(function () { search.focus(); }, 30);
    }
}

function closeBlockLibrary() {
    var modal = document.getElementById('blockLibraryModal');
    if (!modal) return;

    modal.hidden = true;
    document.body.classList.remove('sb-modal-open');
}

function selectedLibraryCategory() {
    var active = document.querySelector('[data-library-category].is-active');
    return active ? String(active.getAttribute('data-library-category') || 'all') : 'all';
}

function filterBlockLibrary() {
    var search = document.getElementById('blockLibrarySearch');
    var query = search ? String(search.value || '').trim().toLowerCase() : '';
    var category = selectedLibraryCategory();

    document.querySelectorAll('[data-library-block]').forEach(function (card) {
        var haystack = String(card.getAttribute('data-search') || '').toLowerCase();
        var cardCategory = String(card.getAttribute('data-category') || '');
        var visible = (!query || haystack.indexOf(query) !== -1)
            && (category === 'all' || category === cardCategory);

        card.hidden = !visible;
    });
}

function toggleAdvancedJson() {
    var fields = document.getElementById('blockJsonFields');
    var button = document.getElementById('toggleAdvancedJsonBtn');
    if (!fields) return;

    var opened = !fields.classList.contains('is-open');
    fields.classList.toggle('is-open', opened);

    if (button) {
        button.classList.toggle('is-open', opened);
        button.textContent = opened ? 'Скрыть технический JSON' : 'Технический JSON';
    }
}

function updateSpacerOutput() {
    var input = document.getElementById('spacerHeightInput');
    var output = document.getElementById('spacerHeightOutput');
    if (input && output) {
        output.textContent = Number(input.value || 0) + ' px';
    }
}

function updatePublicPageLink() {
    var link = document.getElementById('openPublicPageLink');
    if (!link) return;

    var pageId = Number((state && state.currentPageId) || 0);
    link.href = BASE_PATH + '/public.php?siteId=' + siteId + (pageId > 0 ? '&pageId=' + pageId : '');
}

(function initVisualBuilderUi() {
    var remembered = 'desktop';
    try {
        remembered = window.localStorage.getItem('sitebuilder.editor.previewDevice') || 'desktop';
    } catch (e) {
        remembered = 'desktop';
    }
    setPreviewDevice(remembered);
    setInspectorTab('page');
    updateSpacerOutput();

    document.querySelectorAll('[data-preview-device]').forEach(function (button) {
        button.addEventListener('click', function () {
            setPreviewDevice(String(button.getAttribute('data-preview-device') || 'desktop'));
        });
    });

    document.querySelectorAll('[data-inspector-tab]').forEach(function (button) {
        button.addEventListener('click', function () {
            setInspectorTab(String(button.getAttribute('data-inspector-tab') || 'page'));
        });
    });

    var openButton = document.getElementById('openBlockLibraryBtn');
    if (openButton) openButton.addEventListener('click', openBlockLibrary);

    document.querySelectorAll('[data-close-block-library]').forEach(function (button) {
        button.addEventListener('click', closeBlockLibrary);
    });

    document.querySelectorAll('[data-library-category]').forEach(function (button) {
        button.addEventListener('click', function () {
            document.querySelectorAll('[data-library-category]').forEach(function (item) {
                item.classList.toggle('is-active', item === button);
            });
            filterBlockLibrary();
        });
    });

    var search = document.getElementById('blockLibrarySearch');
    if (search) search.addEventListener('input', filterBlockLibrary);

    document.querySelectorAll('[data-library-block]').forEach(function (button) {
        button.addEventListener('click', async function () {
            var type = String(button.getAttribute('data-library-block') || '');
            if (!type || typeof createBlock !== 'function') return;

            button.disabled = true;
            try {
                await createBlock(type);
                closeBlockLibrary();
                setInspectorTab('block');
                showEditorToast('Блок «' + blockTypeMeta(type).title + '» добавлен', 'success');
            } catch (error) {
                console.error(error);
                showEditorToast('Не удалось добавить блок', 'error');
            } finally {
                button.disabled = false;
            }
        });
    });

    var advancedButton = document.getElementById('toggleAdvancedJsonBtn');
    if (advancedButton) advancedButton.addEventListener('click', toggleAdvancedJson);

    var spacer = document.getElementById('spacerHeightInput');
    if (spacer) spacer.addEventListener('input', updateSpacerOutput);

    var pageSearch = document.getElementById('pageSearchInput');
    if (pageSearch) {
        pageSearch.addEventListener('input', function () {
            state.pageSearch = String(pageSearch.value || '');
            if (typeof renderPages === 'function') renderPages();
        });
    }

    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-page-id]') && typeof setInspectorTab === 'function') {
            window.setTimeout(function () { setInspectorTab('page'); }, 0);
        }
    });

    var accessObserverTargets = [
        document.getElementById('siteGroupPanel'),
        document.getElementById('siteAccessPanel'),
        document.getElementById('pageAccessPanel')
    ].filter(Boolean);
    if (accessObserverTargets.length && typeof MutationObserver === 'function') {
        var accessObserver = new MutationObserver(refreshInspectorAccessTab);
        accessObserverTargets.forEach(function (node) {
            accessObserver.observe(node, {attributes: true, attributeFilter: ['hidden', 'class']});
        });
    }
    refreshInspectorAccessTab();


    document.addEventListener('input', function (event) {
        var target = event.target;
        if (!target || typeof target.matches !== 'function') return;

        if (target.matches(
            '#pageTitleInput, #pageSlugInput, #pageStatusInput, #pageParentInput, '
            + '#blockInspector input, #blockInspector textarea, #blockInspector select, '
            + '[data-section-field]'
        )) {
            setEditorStatus('dirty', 'Есть изменения');
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeBlockLibrary();
            return;
        }

        if ((event.ctrlKey || event.metaKey) && String(event.key).toLowerCase() === 'k') {
            event.preventDefault();
            openBlockLibrary();
            return;
        }

        if ((event.ctrlKey || event.metaKey) && String(event.key).toLowerCase() === 's') {
            event.preventDefault();

            var tab = String(state.inspectorTab || 'page');

            if (tab === 'section' && state.currentSectionId && typeof savePageSection === 'function') {
                savePageSection(state.currentSectionId);
            } else if (tab === 'block' && state.currentBlockId && typeof saveBlock === 'function') {
                saveBlock();
            } else if (state.currentPageId && typeof savePage === 'function') {
                savePage();
            }
        }
    });
})();
