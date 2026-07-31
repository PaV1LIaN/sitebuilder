var config = window.SB_EDITOR_CONFIG || {};

var BASE_PATH = config.basePath || '';
var API_URL = config.apiUrl || (BASE_PATH + '/api.php');
var siteId = Number(config.siteId || 0);
var IS_BITRIX_ADMIN = !!config.isBitrixAdmin;

var state = {
    site: null,
    pages: [],
    currentPageId: 0,
    blocks: [],
    currentBlockId: 0,
    pageSections: [],
    currentSectionId: 0,
    currentColumn: 1,
    draggedBlockId: 0,
    accessItems: [],
    userSearchResults: [],
    selectedAccessUser: null,
    userSearchTimer: null,
    historyItems: [],
    historyUsers: {},
    historyEntityType: '',
    historyEntityId: 0,
    inspectorTab: 'page',
    previewDevice: 'desktop',
    pageSearch: '',
    globalBlocks: []
};

var output = document.getElementById('output') || document.getElementById('outputFallback');
var pagesList = document.getElementById('pagesList');
var blocksList = document.getElementById('blocksList');
var newPageParentId = document.getElementById('newPageParentId');

function print(data) {
    if (!output) return;

    try {
        output.textContent = typeof data === 'string' ? data : JSON.stringify(data, null, 2);
    } catch (e) {
        output.textContent = String(data);
    }
}

function escapeHtml(value) {
    return String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function userAvatarHtml(user, className) {
    user = user || {};
    className = className || '';

    var avatar = user.avatarUrl || user.avatar || user.photoUrl || user.userAvatarUrl || '';
    var title = user.title || user.name || user.userName || '';
    var initials = 'U';

    if (title) {
        var parts = String(title).trim().split(/\s+/).filter(Boolean);

        if (parts.length === 1) {
            initials = parts[0].substring(0, 1).toUpperCase();
        } else if (parts.length >= 2) {
            initials = (parts[0].substring(0, 1) + parts[1].substring(0, 1)).toUpperCase();
        }
    }

    var size = '32px';

    if (className.indexOf('selected') !== -1) {
        size = '42px';
    }

    var wrapStyle = [
        'width:' + size,
        'height:' + size,
        'min-width:' + size,
        'max-width:' + size,
        'min-height:' + size,
        'max-height:' + size,
        'border-radius:50%',
        'overflow:hidden',
        'display:flex',
        'align-items:center',
        'justify-content:center',
        'background:#eef2ff',
        'color:#3730a3',
        'font-size:11px',
        'font-weight:700',
        'line-height:1'
    ].join(';');

    if (avatar) {
        return ''
            + '<div class="' + className + '" style="' + wrapStyle + '">'
            + '  <img src="' + escapeHtml(avatar) + '" alt="" style="width:' + size + ';height:' + size + ';min-width:' + size + ';max-width:' + size + ';min-height:' + size + ';max-height:' + size + ';object-fit:cover;display:block;">'
            + '</div>';
    }

    return ''
        + '<div class="' + className + '" style="' + wrapStyle + '">'
        + escapeHtml(initials)
        + '</div>';
}

function getSessid() {
    if (window.BX && typeof BX.bitrix_sessid === 'function') {
        return BX.bitrix_sessid();
    }

    return config.sessid || '';
}

function api(action, data) {
    var actionName = String(action || '');
    var isReadOnly = /\.(list|get|search|status|health|check)$/i.test(actionName)
        || actionName === 'common.site'
        || actionName === 'common.bootstrap';

    if (typeof setEditorStatus === 'function') {
        setEditorStatus('working', isReadOnly ? 'Загрузка…' : 'Сохранение…');
    }

    return new Promise(function (resolve, reject) {
        BX.ajax({
            url: API_URL,
            method: 'POST',
            dataType: 'json',
            timeout: 60,
            data: Object.assign({
                action: action,
                sessid: getSessid()
            }, data || {}),
            onsuccess: function (res) {
                print(res);

                if (res && res.ok) {
                    if (typeof setEditorStatus === 'function') {
                        setEditorStatus('ready', isReadOnly ? 'Готово' : 'Сохранено');
                    }
                    resolve(res);
                } else {
                    if (typeof setEditorStatus === 'function') {
                        setEditorStatus('error', 'Не сохранено');
                    }
                    if (res && res.error === 'VERSION_CONFLICT') {
                        handleVersionConflict(res);
                    }

                    reject(res || {error: 'UNKNOWN'});
                }
            },
            onfailure: function (err) {
                if (typeof setEditorStatus === 'function') {
                    setEditorStatus('error', 'Ошибка соединения');
                }
                print({
                    ok: false,
                    error: 'AJAX_ERROR',
                    detail: err
                });

                reject(err);
            }
        });
    });
}

function apiData(res) {
    return res && res.data ? res.data : res;
}


function entityVersion(entity) {
    return Math.max(1, Number((entity && entity.version) || 1));
}

function buildVersionMap(items) {
    var result = {};

    (items || []).forEach(function (item) {
        var id = Number((item && item.id) || 0);
        if (id > 0) {
            result[id] = entityVersion(item);
        }
    });

    return result;
}

function replaceStatePage(page) {
    if (!page) return;

    var id = Number(page.id || 0);
    state.pages = state.pages.map(function (current) {
        return Number(current.id || 0) === id ? page : current;
    });
}

function replaceStateBlock(block) {
    if (!block) return;

    var id = Number(block.id || 0);
    state.blocks = state.blocks.map(function (current) {
        return Number(current.id || 0) === id ? block : current;
    });
}

function handleVersionConflict(error) {
    var currentVersion = Number((error && error.currentVersion) || 0);
    var message = 'Объект уже изменён другим пользователем. Данные будут обновлены с сервера.';

    if (currentVersion > 0) {
        message += '\nАктуальная версия: ' + currentVersion + '.';
    }

    alert(message);

    window.setTimeout(async function () {
        try {
            if (typeof loadPages === 'function') {
                await loadPages();
            }

            if (typeof loadBlocks === 'function') {
                await loadBlocks();
            }
        } catch (reloadError) {
            console.error(reloadError);
        }
    }, 0);
}

function getInputValue(id) {
    var el = document.getElementById(id);
    return el ? String(el.value || '') : '';
}

function setInputValue(id, value) {
    var el = document.getElementById(id);

    if (el) {
        el.value = value == null ? '' : String(value);
    }
}

function getChecked(id) {
    var el = document.getElementById(id);
    return !!(el && el.checked);
}

function getCurrentPage() {
    return state.pages.find(function (page) {
        return Number(page.id || 0) === state.currentPageId;
    }) || null;
}

function getCurrentBlock() {
    return state.blocks.find(function (block) {
        return Number(block.id || 0) === state.currentBlockId;
    }) || null;
}

function getBlockSectionId(block) {
    block = block || {};

    var props = block.props || {};
    var placement = props._placement || {};

    return Number(
        block.sectionId ||
        props.sectionId ||
        placement.sectionId ||
        0
    );
}

function getBlockColumn(block) {
    block = block || {};

    var props = block.props || {};
    var placement = props._placement || {};

    return Number(
        block.column ||
        props.column ||
        placement.column ||
        1
    );
}

function pageHasChildren(pageId) {
    return state.pages.some(function (page) {
        return Number(page.parentId || 0) === Number(pageId || 0);
    });
}

function buildPageTree(pages, parentId, depth, result) {
    result = result || [];
    depth = depth || 0;

    var branch = pages
        .filter(function (page) {
            return Number(page.parentId || 0) === Number(parentId || 0);
        })
        .sort(function (a, b) {
            var sortCmp = Number(a.sort || 0) - Number(b.sort || 0);
            if (sortCmp !== 0) return sortCmp;
            return Number(a.id || 0) - Number(b.id || 0);
        });

    branch.forEach(function (page) {
        result.push({
            page: page,
            depth: depth
        });

        buildPageTree(pages, Number(page.id || 0), depth + 1, result);
    });

    return result;
}