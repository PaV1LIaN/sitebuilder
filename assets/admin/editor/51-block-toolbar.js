/* =========================================================
   SITEBUILDER / BLOCK TOOLBAR 2.0 / STAGE 1
   Floating toolbar for the selected block.
   Reuses existing DnD, block APIs, inspector modes and globals.
   No autosave and no backend changes.
   ========================================================= */
(function () {
    'use strict';

    if (
        !window.SB_EDITOR_CONFIG
        || typeof state === 'undefined'
        || typeof api !== 'function'
    ) {
        return;
    }

    var blocksList =
        document.getElementById('blocksList');
    var libraryModal =
        document.getElementById('blockLibraryModal');

    if (
        !blocksList
        || typeof window.renderBlocks !== 'function'
        || typeof window.createBlock !== 'function'
    ) {
        return;
    }

    var TYPE_META = {
        heading: ['H', 'Заголовок'],
        text: ['¶', 'Текст'],
        button: ['↗', 'Кнопка'],
        image: ['▧', 'Изображение'],
        hero: ['★', 'Первый экран'],
        cards: ['▦', 'Карточки'],
        quote: ['“', 'Цитата'],
        stats: ['№', 'Показатели'],
        divider: ['—', 'Разделитель'],
        spacer: ['↕', 'Отступ'],
        table: ['▤', 'Таблица'],
        disk: ['◫', 'Битрикс.Диск'],
        html: ['</>', 'HTML'],
        global: ['∞', 'Глобальный блок'],
        faq: ['?', 'FAQ'],
        video: ['▶', 'Видео'],
        pricing: ['₽', 'Тарифы'],
        form: ['✉', 'Форма'],
        gallery: ['▦', 'Галерея'],
        navigation: ['☰', 'Навигация'],
        footer: ['▰', 'Подвал']
    };

    var pendingInsert = null;
    var actionBusy = false;
    var originalCreateBlock =
        window.createBlock;

    function escape(value) {
        if (typeof escapeHtml === 'function') {
            return escapeHtml(value);
        }

        return String(value == null ? '' : value)
            .replace(/[&<>"']/g, function (char) {
                return {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;'
                }[char];
            });
    }

    function blockById(blockId) {
        blockId = Number(blockId || 0);

        return (state.blocks || [])
            .find(function (block) {
                return Number(block.id || 0)
                    === blockId;
            }) || null;
    }

    function currentBlock() {
        if (typeof getCurrentBlock === 'function') {
            return getCurrentBlock();
        }

        return blockById(
            Number(state.currentBlockId || 0)
        );
    }

    function blockSectionId(block) {
        if (
            block
            && typeof getBlockSectionId
                === 'function'
        ) {
            return Number(
                getBlockSectionId(block) || 0
            );
        }

        var props =
            block
            && block.props
            && typeof block.props === 'object'
                ? block.props
                : {};

        var placement =
            props._placement
            && typeof props._placement
                === 'object'
                ? props._placement
                : {};

        return Number(
            placement.sectionId
            || props.sectionId
            || 0
        );
    }

    function blockColumn(block) {
        if (
            block
            && typeof getBlockColumn
                === 'function'
        ) {
            return Math.max(
                1,
                Number(
                    getBlockColumn(block) || 1
                )
            );
        }

        var props =
            block
            && block.props
            && typeof block.props === 'object'
                ? block.props
                : {};

        var placement =
            props._placement
            && typeof props._placement
                === 'object'
                ? props._placement
                : {};

        return Math.max(
            1,
            Number(
                placement.column
                || props.column
                || 1
            )
        );
    }

    function entityVer(entity) {
        if (
            typeof entityVersion
            === 'function'
        ) {
            return entityVersion(entity);
        }

        return Math.max(
            1,
            Number(
                entity
                && entity.version
                || 1
            )
        );
    }

    function versionMap(items) {
        var result = {};

        (items || []).forEach(function (item) {
            var id = Number(item.id || 0);

            if (id > 0) {
                result[id] =
                    entityVer(item);
            }
        });

        return result;
    }

    function sortedBlocks(items) {
        return (items || [])
            .slice()
            .sort(function (a, b) {
                var bySort =
                    Number(a.sort || 0)
                    - Number(b.sort || 0);

                return bySort !== 0
                    ? bySort
                    : Number(a.id || 0)
                        - Number(b.id || 0);
            });
    }

    function typeMeta(type) {
        type = String(type || '');

        if (TYPE_META[type]) {
            return {
                icon: TYPE_META[type][0],
                title: TYPE_META[type][1]
            };
        }

        if (
            typeof blockTypeMeta
            === 'function'
        ) {
            var meta = blockTypeMeta(type);

            return {
                icon:
                    String(
                        meta.icon || '◆'
                    ),
                title:
                    String(
                        meta.title
                        || type
                        || 'Блок'
                    )
            };
        }

        return {
            icon: '◆',
            title: type || 'Блок'
        };
    }

    function setStatus(name, text) {
        if (
            typeof setEditorStatus
            === 'function'
        ) {
            setEditorStatus(name, text);
        }
    }

    function toast(text, type) {
        if (
            typeof showEditorToast
            === 'function'
        ) {
            showEditorToast(
                text,
                type || 'success'
            );
        }
    }

    function syncSelection(blockId) {
        blockId = Number(blockId || 0);
        var block = blockById(blockId);

        state.currentBlockId =
            block ? blockId : 0;

        if (block) {
            var sectionId =
                blockSectionId(block);
            var column =
                blockColumn(block);

            if (sectionId > 0) {
                state.currentSectionId =
                    sectionId;
            }

            state.currentColumn =
                Math.max(1, column);
        }

        if (
            typeof renderPageSectionsPanel
            === 'function'
        ) {
            renderPageSectionsPanel();
        }

        if (
            typeof window.renderBlocks
            === 'function'
        ) {
            window.renderBlocks();
        }

        if (
            typeof fillBlockForm
            === 'function'
        ) {
            fillBlockForm();
        }

        if (
            block
            && typeof setInspectorTab
                === 'function'
        ) {
            setInspectorTab('block');
        }
    }

    function ensureInspectorVisible() {
        var body = document.body;

        if (!body) return;

        if (
            body.classList.contains(
                'sb-editor-focus-mode'
            )
        ) {
            var focus =
                document.getElementById(
                    'toggleFocusModeBtn'
                );

            if (focus) {
                focus.click();
            }
        }

        if (
            body.classList.contains(
                'sb-editor-right-collapsed'
            )
        ) {
            var inspector =
                document.getElementById(
                    'toggleInspectorPanelBtn'
                );

            if (inspector) {
                inspector.click();
            }
        }
    }

    function openInspectorMode(mode) {
        ensureInspectorVisible();

        if (
            typeof setInspectorTab
            === 'function'
        ) {
            setInspectorTab('block');
        }

        if (
            window.SBBlockInspectorModes
            && typeof window
                .SBBlockInspectorModes
                .setMode === 'function'
        ) {
            window.SBBlockInspectorModes
                .setMode(mode);
        }

        var inspector =
            document.getElementById(
                'blockInspector'
            );

        if (
            inspector
            && typeof inspector.scrollIntoView
                === 'function'
        ) {
            window.setTimeout(function () {
                inspector.scrollIntoView({
                    block: 'nearest'
                });
            }, 0);
        }
    }

    function samePlacementBlocks(block) {
        if (!block) return [];

        var sectionId =
            blockSectionId(block);
        var column =
            blockColumn(block);

        return sortedBlocks(
            state.blocks
        ).filter(function (item) {
            return (
                blockSectionId(item)
                    === sectionId
                && blockColumn(item)
                    === column
            );
        });
    }

    function insertionPlacement(
        block,
        direction
    ) {
        if (!block) {
            return null;
        }

        var siblings =
            samePlacementBlocks(block);
        var blockId =
            Number(block.id || 0);
        var index =
            siblings.findIndex(
                function (item) {
                    return Number(
                        item.id || 0
                    ) === blockId;
                }
            );

        if (index < 0) {
            return null;
        }

        var beforeBlockId = blockId;

        if (direction === 'after') {
            var next =
                siblings[index + 1]
                || null;

            beforeBlockId = next
                ? Number(next.id || 0)
                : 0;
        }

        return {
            sectionId:
                blockSectionId(block),
            column:
                blockColumn(block),
            beforeBlockId:
                beforeBlockId
        };
    }

    function libraryTitle(text) {
        var title =
            document.getElementById(
                'blockLibraryTitle'
            );

        if (title) {
            title.textContent =
                text || 'Добавить блок';
        }
    }

    function clearPendingInsert() {
        pendingInsert = null;
        libraryTitle('Добавить блок');
    }

    function forceComponentsPane() {
        var tab =
            document.querySelector(
                '[data-library-view="blocks"]'
            );

        if (
            tab
            && !tab.classList.contains(
                'is-active'
            )
        ) {
            tab.click();
        }
    }

    function openInsertLibrary(
        blockId,
        direction
    ) {
        var block =
            blockById(blockId);

        if (!block) {
            toast(
                'Блок не найден',
                'error'
            );
            return;
        }

        var placement =
            insertionPlacement(
                block,
                direction
            );

        if (!placement) {
            toast(
                'Не удалось определить позицию',
                'error'
            );
            return;
        }

        var meta =
            typeMeta(block.type);

        pendingInsert = {
            placement: placement,
            sourceBlockId:
                Number(block.id || 0),
            direction: direction
        };

        closeInsertMenus();
        forceComponentsPane();

        libraryTitle(
            direction === 'before'
                ? 'Добавить перед «'
                    + meta.title
                    + '»'
                : 'Добавить после «'
                    + meta.title
                    + '»'
        );

        if (
            typeof openBlockLibrary
            === 'function'
        ) {
            openBlockLibrary();
        }
    }

    /*
     * The DnD Stage 2 wrapper already accepts createBlock(type, placement)
     * and knows how to place a newly created component before a target
     * block. Toolbar insertion simply feeds that existing path.
     */
    window.createBlock =
        async function (
            type,
            placement
        ) {
            if (
                placement
                || !pendingInsert
            ) {
                return originalCreateBlock
                    .apply(
                        this,
                        arguments
                    );
            }

            var target =
                pendingInsert.placement;

            try {
                return await originalCreateBlock
                    .call(
                        this,
                        type,
                        target
                    );
            } finally {
                clearPendingInsert();
            }
        };

    async function duplicateSelected(
        blockId
    ) {
        if (actionBusy) return;

        var block =
            blockById(blockId);

        if (!block) return;

        actionBusy = true;
        setStatus(
            'working',
            'Дублирую блок…'
        );

        try {
            var response =
                await api(
                    'block.duplicate',
                    {
                        id:
                            Number(
                                block.id || 0
                            ),
                        expectedVersion:
                            entityVer(block),
                        expectedVersions:
                            JSON.stringify(
                                versionMap(
                                    state.blocks
                                )
                            )
                    }
                );

            var data =
                typeof apiData
                === 'function'
                    ? apiData(response)
                    : response;

            var copy =
                data.block
                || response.block
                || null;
            var copyId =
                Number(
                    copy
                    && copy.id
                    || 0
                );

            if (
                typeof loadBlocks
                === 'function'
            ) {
                await loadBlocks();
            }

            if (copyId > 0) {
                syncSelection(copyId);
            } else {
                syncSelection(blockId);
            }

            setStatus(
                'ready',
                'Блок продублирован'
            );

            toast(
                'Блок продублирован',
                'success'
            );
        } catch (error) {
            console.error(error);

            if (
                error
                && error.error
                    === 'VERSION_CONFLICT'
                && typeof loadBlocks
                    === 'function'
            ) {
                try {
                    await loadBlocks();
                } catch (refreshError) {
                    console.error(
                        refreshError
                    );
                }
            }

            setStatus(
                'error',
                'Ошибка дублирования'
            );

            toast(
                'Не удалось продублировать блок',
                'error'
            );
        } finally {
            actionBusy = false;
        }
    }

    async function moveInsidePlacement(
        blockId,
        direction
    ) {
        if (actionBusy) return;

        var block =
            blockById(blockId);

        if (!block) return;

        var siblings =
            samePlacementBlocks(block);
        var index =
            siblings.findIndex(
                function (item) {
                    return Number(
                        item.id || 0
                    ) === Number(blockId);
                }
            );

        var targetIndex =
            direction === 'up'
                ? index - 1
                : index + 1;

        if (
            index < 0
            || !siblings[targetIndex]
        ) {
            toast(
                direction === 'up'
                    ? 'Блок уже первый в колонке'
                    : 'Блок уже последний в колонке',
                'info'
            );
            return;
        }

        var targetId =
            Number(
                siblings[targetIndex].id
                || 0
            );

        var order =
            sortedBlocks(state.blocks)
                .map(function (item) {
                    return Number(
                        item.id || 0
                    );
                });

        var first =
            order.indexOf(
                Number(blockId)
            );
        var second =
            order.indexOf(targetId);

        if (
            first < 0
            || second < 0
        ) {
            return;
        }

        var tmp = order[first];
        order[first] = order[second];
        order[second] = tmp;

        actionBusy = true;
        setStatus(
            'working',
            'Перемещаю блок…'
        );

        try {
            await api(
                'block.reorder',
                {
                    pageId:
                        Number(
                            state.currentPageId
                            || 0
                        ),
                    order:
                        JSON.stringify(order),
                    expectedVersions:
                        JSON.stringify(
                            versionMap(
                                state.blocks
                            )
                        )
                }
            );

            if (
                typeof loadBlocks
                === 'function'
            ) {
                await loadBlocks();
            }

            syncSelection(blockId);

            setStatus(
                'ready',
                'Блок перемещён'
            );
        } catch (error) {
            console.error(error);

            if (
                error
                && error.error
                    === 'VERSION_CONFLICT'
                && typeof loadBlocks
                    === 'function'
            ) {
                try {
                    await loadBlocks();
                } catch (refreshError) {
                    console.error(
                        refreshError
                    );
                }
            }

            setStatus(
                'error',
                'Ошибка перемещения'
            );

            toast(
                'Не удалось переместить блок',
                'error'
            );
        } finally {
            actionBusy = false;
        }
    }

    async function deleteSelected(
        blockId
    ) {
        if (actionBusy) return;

        var block =
            blockById(blockId);

        if (!block) return;

        state.currentBlockId =
            Number(blockId);

        if (
            typeof window.deleteBlock
            !== 'function'
        ) {
            return;
        }

        actionBusy = true;

        try {
            await window.deleteBlock();
        } catch (error) {
            console.error(error);

            toast(
                'Не удалось удалить блок',
                'error'
            );
        } finally {
            actionBusy = false;
        }
    }

    function globalAction(blockId) {
        var block =
            blockById(blockId);

        if (!block) return;

        state.currentBlockId =
            Number(blockId);

        if (
            typeof fillBlockForm
            === 'function'
        ) {
            fillBlockForm();
        }

        if (
            String(block.type || '')
            === 'global'
        ) {
            if (
                window.SBGlobalBlocks
                && typeof window
                    .SBGlobalBlocks.open
                    === 'function'
            ) {
                window.SBGlobalBlocks.open();
            }

            return;
        }

        var button =
            document.getElementById(
                'saveGlobalBlockBtn'
            );

        if (button && !button.disabled) {
            button.click();
            return;
        }

        toast(
            'Сохранение глобального блока недоступно',
            'error'
        );
    }

    function toolbarHtml(block) {
        var id =
            Number(block.id || 0);
        var meta =
            typeMeta(block.type);

        return ''
            + '<div'
            + ' class="sb-bt-toolbar"'
            + ' data-bt-toolbar="'
            + id
            + '"'
            + ' data-vb-action="toolbar"'
            + ' role="toolbar"'
            + ' aria-label="Действия с блоком">'
            + '  <span'
            + ' class="sb-bt-drag"'
            + ' draggable="true"'
            + ' data-block-drag-handle="'
            + id
            + '"'
            + ' title="Перетащить блок"'
            + ' aria-label="Перетащить блок">'
            + '⋮⋮'
            + '  </span>'
            + '  <span class="sb-bt-type"'
            + ' title="'
            + escape(meta.title)
            + '">'
            + '    <i>'
            + escape(meta.icon)
            + '</i>'
            + '    <strong>'
            + escape(meta.title)
            + '</strong>'
            + '  </span>'
            + '  <span class="sb-bt-separator"></span>'
            + '  <span class="sb-bt-insert-wrap">'
            + '    <button'
            + ' type="button"'
            + ' data-bt-action="insert-menu"'
            + ' data-block-id="'
            + id
            + '"'
            + ' title="Добавить блок рядом"'
            + ' aria-label="Добавить блок рядом"'
            + ' aria-expanded="false">'
            + '＋'
            + '    </button>'
            + '    <span'
            + ' class="sb-bt-insert-menu"'
            + ' data-bt-insert-menu="'
            + id
            + '" hidden>'
            + '      <button'
            + ' type="button"'
            + ' data-bt-action="insert-before"'
            + ' data-block-id="'
            + id
            + '">'
            + '        <span>↑</span>'
            + '        <strong>Добавить перед</strong>'
            + '      </button>'
            + '      <button'
            + ' type="button"'
            + ' data-bt-action="insert-after"'
            + ' data-block-id="'
            + id
            + '">'
            + '        <span>↓</span>'
            + '        <strong>Добавить после</strong>'
            + '      </button>'
            + '    </span>'
            + '  </span>'
            + '  <button'
            + ' type="button"'
            + ' data-bt-action="duplicate"'
            + ' data-block-id="'
            + id
            + '"'
            + ' title="Дублировать"'
            + ' aria-label="Дублировать блок">'
            + '⧉'
            + '  </button>'
            + '  <button'
            + ' type="button"'
            + ' data-bt-action="up"'
            + ' data-block-id="'
            + id
            + '"'
            + ' title="Выше в этой колонке"'
            + ' aria-label="Переместить выше">'
            + '↑'
            + '  </button>'
            + '  <button'
            + ' type="button"'
            + ' data-bt-action="down"'
            + ' data-block-id="'
            + id
            + '"'
            + ' title="Ниже в этой колонке"'
            + ' aria-label="Переместить ниже">'
            + '↓'
            + '  </button>'
            + '  <span class="sb-bt-separator"></span>'
            + '  <button'
            + ' type="button"'
            + ' class="sb-bt-mode"'
            + ' data-bt-action="content"'
            + ' data-block-id="'
            + id
            + '"'
            + ' title="Контент"'
            + ' aria-label="Открыть Контент">'
            + '◉'
            + '  </button>'
            + '  <button'
            + ' type="button"'
            + ' class="sb-bt-mode"'
            + ' data-bt-action="appearance"'
            + ' data-block-id="'
            + id
            + '"'
            + ' title="Оформление"'
            + ' aria-label="Открыть Оформление">'
            + '◐'
            + '  </button>'
            + '  <button'
            + ' type="button"'
            + ' class="sb-bt-mode"'
            + ' data-bt-action="adaptive"'
            + ' data-block-id="'
            + id
            + '"'
            + ' title="Адаптивность"'
            + ' aria-label="Открыть Адаптивность">'
            + '▣'
            + '  </button>'
            + '  <span class="sb-bt-separator"></span>'
            + '  <button'
            + ' type="button"'
            + ' data-bt-action="global"'
            + ' data-block-id="'
            + id
            + '"'
            + ' title="Глобальный блок"'
            + ' aria-label="Глобальный блок">'
            + '∞'
            + '  </button>'
            + '  <button'
            + ' type="button"'
            + ' class="sb-bt-danger"'
            + ' data-bt-action="delete"'
            + ' data-block-id="'
            + id
            + '"'
            + ' title="Удалить"'
            + ' aria-label="Удалить блок">'
            + '×'
            + '  </button>'
            + '</div>';
    }

    function decorateToolbar() {
        var block =
            currentBlock();

        blocksList
            .querySelectorAll(
                '.sb-bt-toolbar'
            )
            .forEach(function (toolbar) {
                toolbar.remove();
            });

        blocksList
            .querySelectorAll(
                '.sb-bt-enhanced'
            )
            .forEach(function (node) {
                node.classList.remove(
                    'sb-bt-enhanced'
                );
            });

        if (!block) return;

        var id =
            Number(block.id || 0);

        if (id <= 0) return;

        var card =
            blocksList.querySelector(
                '.sb-editor-block'
                + '[data-block-id="'
                + id
                + '"]'
            );

        if (!card) return;

        card.classList.add(
            'sb-bt-enhanced'
        );

        card.insertAdjacentHTML(
            'afterbegin',
            toolbarHtml(block)
        );
    }

    var originalRenderBlocks =
        window.renderBlocks;

    window.renderBlocks =
        function () {
            var result =
                originalRenderBlocks
                    .apply(
                        this,
                        arguments
                    );

            decorateToolbar();

            return result;
        };

    function closeInsertMenus(
        exceptId
    ) {
        blocksList.querySelectorAll(
            '[data-bt-insert-menu]'
        ).forEach(function (menu) {
            var id =
                Number(
                    menu.getAttribute(
                        'data-bt-insert-menu'
                    ) || 0
                );
            var keep =
                Number(exceptId || 0)
                === id;

            menu.hidden = !keep;

            var button =
                menu.parentElement
                && menu.parentElement
                    .querySelector(
                        '[data-bt-action="insert-menu"]'
                    );

            if (button) {
                button.setAttribute(
                    'aria-expanded',
                    keep ? 'true' : 'false'
                );
            }
        });
    }

    function toggleInsertMenu(blockId) {
        var menu =
            blocksList.querySelector(
                '[data-bt-insert-menu="'
                + Number(blockId || 0)
                + '"]'
            );

        if (!menu) return;

        var open = menu.hidden;

        closeInsertMenus(
            open ? blockId : 0
        );
    }

    document.addEventListener(
        'click',
        function (event) {
            var actionButton =
                event.target.closest(
                    '[data-bt-action]'
                );

            if (!actionButton) {
                if (
                    !event.target.closest(
                        '.sb-bt-toolbar'
                    )
                ) {
                    closeInsertMenus();
                }

                return;
            }

            var toolbar =
                actionButton.closest(
                    '.sb-bt-toolbar'
                );

            if (!toolbar) return;

            event.preventDefault();
            event.stopImmediatePropagation();

            var action =
                String(
                    actionButton.getAttribute(
                        'data-bt-action'
                    ) || ''
                );
            var blockId =
                Number(
                    actionButton.getAttribute(
                        'data-block-id'
                    )
                    || toolbar.getAttribute(
                        'data-bt-toolbar'
                    )
                    || 0
                );

            if (blockId <= 0) {
                return;
            }

            if (action === 'insert-menu') {
                toggleInsertMenu(blockId);
                return;
            }

            if (action === 'insert-before') {
                openInsertLibrary(
                    blockId,
                    'before'
                );
                return;
            }

            if (action === 'insert-after') {
                openInsertLibrary(
                    blockId,
                    'after'
                );
                return;
            }

            closeInsertMenus();

            if (action === 'duplicate') {
                duplicateSelected(blockId);
                return;
            }

            if (action === 'up') {
                moveInsidePlacement(
                    blockId,
                    'up'
                );
                return;
            }

            if (action === 'down') {
                moveInsidePlacement(
                    blockId,
                    'down'
                );
                return;
            }

            if (
                action === 'content'
                || action === 'appearance'
                || action === 'adaptive'
            ) {
                state.currentBlockId =
                    blockId;

                if (
                    typeof fillBlockForm
                    === 'function'
                ) {
                    fillBlockForm();
                }

                openInspectorMode(action);
                return;
            }

            if (action === 'global') {
                globalAction(blockId);
                return;
            }

            if (action === 'delete') {
                deleteSelected(blockId);
            }
        },
        true
    );

    /*
     * Cancel a toolbar insertion target when the component library is
     * closed or switched to a non-component flow.
     */
    document.addEventListener(
        'click',
        function (event) {
            if (!pendingInsert) {
                return;
            }

            if (
                event.target.closest(
                    '[data-close-block-library],'
                    + '[data-section-preset],'
                    + '[data-open-global-blocks-library]'
                )
            ) {
                clearPendingInsert();
                return;
            }

            var view =
                event.target.closest(
                    '[data-library-view]'
                );

            if (
                view
                && view.getAttribute(
                    'data-library-view'
                ) !== 'blocks'
            ) {
                clearPendingInsert();
            }
        },
        true
    );

    document.addEventListener(
        'keydown',
        function (event) {
            if (event.key !== 'Escape') {
                return;
            }

            closeInsertMenus();

            if (
                pendingInsert
                && libraryModal
                && !libraryModal.hidden
            ) {
                clearPendingInsert();
            }
        },
        true
    );

    decorateToolbar();

    window.SBBlockToolbar = {
        refresh: decorateToolbar,
        clearInsertTarget:
            clearPendingInsert
    };
})();
