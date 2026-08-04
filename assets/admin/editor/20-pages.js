async function loadSite() {
    var res = await api('site.get', {
        siteId: siteId
    });

    state.site = res.site || null;
}

async function loadPages() {
    var res = await api('page.list', {
        siteId: siteId
    });

    state.pages = Array.isArray(res.pages) ? res.pages : [];

    if (!state.currentPageId && state.pages.length) {
        state.currentPageId = Number(state.pages[0].id || 0);
    }

    fillParentOptions();
    renderPages();
    fillPageForm();
    updateCanvasHeader();
}

async function loadBlocks() {
    if (typeof loadPageAccessList === 'function') {
        loadPageAccessList();
    }

    if (!state.currentPageId) {
        state.blocks = [];
        state.currentBlockId = 0;
        state.pageSections = [];
        state.currentSectionId = 0;
        state.currentColumn = 1;
        renderPageSectionsPanel();
        renderBlocks();
        fillBlockForm();
        return;
    }

    await loadPageSections();

    var res = await api('block.list', {
        pageId: state.currentPageId
    });

    state.blocks = Array.isArray(res.blocks) ? res.blocks : [];

    await ensureUnsectionedBlocksAssigned();

    if (state.currentBlockId) {
        var exists = state.blocks.some(function (b) {
            return Number(b.id || 0) === state.currentBlockId;
        });

        if (!exists) {
            state.currentBlockId = 0;
        }
    }

    renderBlocks();
    fillBlockForm();
    updateCanvasHeader();
}

function fillParentOptions() {
    if (!newPageParentId) {
        return;
    }

    var currentValue = String(newPageParentId.value || '0');
    var html = '<option value="0">Без родителя</option>';

    state.pages.forEach(function (page) {
        html += '<option value="' + Number(page.id || 0) + '">' + escapeHtml(page.title || ('Страница #' + page.id)) + '</option>';
    });

    newPageParentId.innerHTML = html;
    newPageParentId.value = currentValue;
}

function fillPageParentEditorOptions() {
    var select = document.getElementById('pageParentInput');
    if (!select) return;

    var currentPageId = Number(state.currentPageId || 0);
    var currentValue = String(select.value || '0');

    var html = '<option value="0">Без родителя</option>';

    state.pages.forEach(function (page) {
        var id = Number(page.id || 0);

        if (id === currentPageId) {
            return;
        }

        html += '<option value="' + id + '">' + escapeHtml(page.title || ('Страница #' + id)) + '</option>';
    });

    select.innerHTML = html;

    if (currentValue && select.querySelector('option[value="' + currentValue + '"]')) {
        select.value = currentValue;
    }
}

function renderPages() {
    if (!pagesList) {
        return;
    }

    if (!state.pages.length) {
        pagesList.innerHTML = '<div class="sb-empty">Страниц пока нет</div>';
        return;
    }

    var query = String(state.pageSearch || '').trim().toLowerCase();
    var visibleIds = {};
    var matchedIds = {};

    if (query) {
        var byId = {};
        state.pages.forEach(function (page) {
            byId[Number(page.id || 0)] = page;
        });

        state.pages.forEach(function (page) {
            var id = Number(page.id || 0);
            var haystack = [page.title, page.slug, page.status, id].join(' ').toLowerCase();

            if (haystack.indexOf(query) === -1) {
                return;
            }

            matchedIds[id] = true;
            visibleIds[id] = true;

            var parentId = Number(page.parentId || 0);
            var guard = 0;

            while (parentId > 0 && byId[parentId] && guard < 100) {
                visibleIds[parentId] = true;
                parentId = Number(byId[parentId].parentId || 0);
                guard++;
            }
        });
    }

    var tree = buildPageTree(state.pages, 0, 0, []);

    if (query) {
        tree = tree.filter(function (item) {
            return !!visibleIds[Number(item.page.id || 0)];
        });
    }

    if (!tree.length) {
        pagesList.innerHTML = '<div class="sb-empty">По запросу ничего не найдено</div>';
        return;
    }

    pagesList.innerHTML = tree.map(function (item) {
        var page = item.page;
        var depth = item.depth;
        var pageId = Number(page.id || 0);
        var active = pageId === state.currentPageId ? ' is-active' : '';
        var matched = query && matchedIds[pageId] ? ' is-search-match' : '';
        var hasChildren = pageHasChildren(page.id);
        var status = String(page.status || 'draft');

        return ''
            + '<div class="sb-editor-page-item' + active + matched + '" data-page-id="' + pageId + '" data-page-search="' + escapeHtml([page.title, page.slug].join(' ').toLowerCase()) + '" style="margin-left:' + (depth * 18) + 'px;">'
            + '  <div class="sb-editor-page-top">'
            + '      <div>'
            + '          <h3 class="sb-editor-page-title">' + escapeHtml(page.title || '') + '</h3>'
            + '          <div class="sb-editor-page-meta">'
            +               '<span class="sb-editor-chip">' + escapeHtml(page.slug || '') + '</span>'
            +               '<span class="sb-editor-chip ' + (status === 'published' ? 'sb-editor-chip--green' : 'sb-editor-chip--yellow') + '">' + escapeHtml(status) + '</span>'
            +               (hasChildren ? '<span class="sb-editor-chip sb-editor-chip--blue">раздел</span>' : '')
            + '          </div>'
            + '      </div>'
            + '  </div>'
            + '</div>';
    }).join('');
}
function updateCanvasHeader() {
    var page = getCurrentPage();
    var pageTitle = document.getElementById('canvasPageTitle');
    var pageMeta = document.getElementById('canvasPageMeta');
    var previewHeading = document.getElementById('pagePreviewHeading');

    if (!page) {
        if (pageTitle) {
            pageTitle.textContent = 'Страница';
        }

        if (pageMeta) {
            pageMeta.textContent = 'Выберите страницу слева';
        }

        if (previewHeading) {
            previewHeading.textContent = 'Выберите страницу';
        }

        if (typeof updatePublicPageLink === 'function') {
            updatePublicPageLink();
        }

        return;
    }

    if (pageTitle) {
        pageTitle.textContent = page.title || 'Страница';
    }

    if (pageMeta) {
        pageMeta.textContent = 'slug: ' + (page.slug || '') + ' · статус: ' + (page.status || 'draft') + ' · блоков: ' + state.blocks.length;
    }

    if (previewHeading) {
        previewHeading.textContent = page.title || 'Страница';
    }

    if (typeof updatePublicPageLink === 'function') {
        updatePublicPageLink();
    }
}

function fillPageForm() {
    var page = getCurrentPage();

    fillPageParentEditorOptions();

    var titleInput = document.getElementById('pageTitleInput');
    var slugInput = document.getElementById('pageSlugInput');
    var statusInput = document.getElementById('pageStatusInput');
    var parentSelect = document.getElementById('pageParentInput');
    var seo = page && page.seo && typeof page.seo === 'object' ? page.seo : {};

    if (titleInput) {
        titleInput.value = page ? (page.title || '') : '';
    }

    if (slugInput) {
        slugInput.value = page ? (page.slug || '') : '';
    }

    if (statusInput) {
        statusInput.value = page ? (page.status || 'draft') : 'draft';
    }

    if (parentSelect) {
        parentSelect.value = page ? String(page.parentId || 0) : '0';
    }

    setInputValue('pageSeoTitleInput', seo.title || '');
    setInputValue('pageSeoDescriptionInput', seo.description || '');
    setInputValue('pageSeoKeywordsInput', seo.keywords || '');
    setInputValue('pageSeoCanonicalInput', seo.canonical || '');
    setInputValue('pageSeoOgTitleInput', seo.ogTitle || '');
    setInputValue('pageSeoOgDescriptionInput', seo.ogDescription || '');
    setInputValue('pageSeoOgImageInput', seo.ogImage || '');
    var indexInput = document.getElementById('pageSeoIndexInput');
    var followInput = document.getElementById('pageSeoFollowInput');
    if (indexInput) indexInput.checked = !Object.prototype.hasOwnProperty.call(seo, 'robotsIndex') || !!seo.robotsIndex;
    if (followInput) followInput.checked = !Object.prototype.hasOwnProperty.call(seo, 'robotsFollow') || !!seo.robotsFollow;
    updateSeoCounters();
}

function updateSeoCounters() {
    var title = getInputValue('pageSeoTitleInput');
    var description = getInputValue('pageSeoDescriptionInput');
    var titleCounter = document.getElementById('pageSeoTitleCounter');
    var descriptionCounter = document.getElementById('pageSeoDescriptionCounter');
    if (titleCounter) {
        titleCounter.textContent = title.length + '/60';
        titleCounter.classList.toggle('is-warning', title.length > 60);
    }
    if (descriptionCounter) {
        descriptionCounter.textContent = description.length + '/160';
        descriptionCounter.classList.toggle('is-warning', description.length > 160);
    }
}

function collectPageSeo() {
    return {
        title: getInputValue('pageSeoTitleInput').trim(),
        description: getInputValue('pageSeoDescriptionInput').trim(),
        keywords: getInputValue('pageSeoKeywordsInput').trim(),
        canonical: getInputValue('pageSeoCanonicalInput').trim(),
        robotsIndex: !!(document.getElementById('pageSeoIndexInput') || {}).checked,
        robotsFollow: !!(document.getElementById('pageSeoFollowInput') || {}).checked,
        ogTitle: getInputValue('pageSeoOgTitleInput').trim(),
        ogDescription: getInputValue('pageSeoOgDescriptionInput').trim(),
        ogImage: getInputValue('pageSeoOgImageInput').trim()
    };
}

async function createPage() {
    var title = getInputValue('newPageTitle').trim();
    var slug = getInputValue('newPageSlug').trim();
    var parentId = Number(getInputValue('newPageParentId') || 0);

    if (!title) {
        alert('Введите название страницы');

        var titleInput = document.getElementById('newPageTitle');
        if (titleInput) {
            titleInput.focus();
        }

        return;
    }

    await api('page.create', {
        siteId: siteId,
        title: title,
        slug: slug,
        parentId: parentId
    });

    var newTitleInput = document.getElementById('newPageTitle');
    var newSlugInput = document.getElementById('newPageSlug');
    var newParentInput = document.getElementById('newPageParentId');

    if (newTitleInput) {
        newTitleInput.value = '';
    }

    if (newSlugInput) {
        newSlugInput.value = '';
    }

    if (newParentInput) {
        newParentInput.value = '0';
    }

    await loadPages();
    await loadBlocks();

    if (typeof showEditorToast === 'function') {
        showEditorToast('Страница создана', 'success');
    }
}

async function savePage() {
    var page = getCurrentPage();
    if (!page) return;

    var res = await api('page.save', {
        id: Number(page.id || 0),
        title: getInputValue('pageTitleInput').trim(),
        slug: getInputValue('pageSlugInput').trim(),
        parentId: Number(getInputValue('pageParentInput') || 0),
        status: getInputValue('pageStatusInput'),
        seo: JSON.stringify(collectPageSeo()),
        expectedVersion: entityVersion(page)
    });

    if (res.page) {
        replaceStatePage(res.page);
    }

    await loadPages();
    await loadBlocks();
}

async function deletePage() {
    if (!state.currentPageId) return;
    if (!confirm('Удалить страницу? Дочерние страницы и блоки этой страницы тоже будут удалены.')) return;

    var idToDelete = state.currentPageId;
    var idsToDelete = {};
    idsToDelete[idToDelete] = true;
    var changed = true;

    while (changed) {
        changed = false;
        state.pages.forEach(function (page) {
            var pageId = Number(page.id || 0);
            var parentId = Number(page.parentId || 0);

            if (!idsToDelete[pageId] && idsToDelete[parentId]) {
                idsToDelete[pageId] = true;
                changed = true;
            }
        });
    }

    var deletingPages = state.pages.filter(function (page) {
        return !!idsToDelete[Number(page.id || 0)];
    });

    await api('page.delete', {
        id: idToDelete,
        expectedVersions: JSON.stringify(buildVersionMap(deletingPages))
    });

    if (state.currentPageId === idToDelete) {
        state.currentPageId = 0;
        state.currentSectionId = 0;
        state.currentColumn = 1;
    }

    await loadPages();
    await loadBlocks();
}

async function movePage(dir) {
    var page = getCurrentPage();
    if (!page) return;

    var siblings = state.pages.filter(function (item) {
        return Number(item.siteId || 0) === Number(page.siteId || 0)
            && Number(item.parentId || 0) === Number(page.parentId || 0);
    }).sort(function (a, b) {
        var sortCmp = Number(a.sort || 0) - Number(b.sort || 0);
        return sortCmp !== 0 ? sortCmp : Number(a.id || 0) - Number(b.id || 0);
    });

    var position = siblings.findIndex(function (item) {
        return Number(item.id || 0) === Number(page.id || 0);
    });
    var targetPosition = dir === 'up' ? position - 1 : position + 1;
    var involved = [page];

    if (siblings[targetPosition]) {
        involved.push(siblings[targetPosition]);
    }

    await api('page.move', {
        id: Number(page.id || 0),
        dir: dir,
        expectedVersions: JSON.stringify(buildVersionMap(involved))
    });

    await loadPages();
}

['pageSeoTitleInput','pageSeoDescriptionInput'].forEach(function (id) {
    var node = document.getElementById(id);
    if (node) node.addEventListener('input', updateSeoCounters);
});
