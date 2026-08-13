/* =========================================================
   SITEBUILDER / SECTIONS 2.0 / STAGE 1
   Quick section insertion, layout presets, column ratios
   and full section duplication.
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

    if (!blocksList) {
        return;
    }

    var LAYOUTS = [
        {
            key: '1',
            title: '1 колонка',
            subtitle: '100%',
            columns: 1,
            ratio: 'equal',
            parts: [1]
        },
        {
            key: '2-50-50',
            title: '2 колонки',
            subtitle: '50 / 50',
            columns: 2,
            ratio: 'equal',
            parts: [1, 1]
        },
        {
            key: '2-33-67',
            title: '2 колонки',
            subtitle: '33 / 67',
            columns: 2,
            ratio: '33-67',
            parts: [1, 2]
        },
        {
            key: '2-67-33',
            title: '2 колонки',
            subtitle: '67 / 33',
            columns: 2,
            ratio: '67-33',
            parts: [2, 1]
        },
        {
            key: '2-25-75',
            title: '2 колонки',
            subtitle: '25 / 75',
            columns: 2,
            ratio: '25-75',
            parts: [1, 3]
        },
        {
            key: '2-75-25',
            title: '2 колонки',
            subtitle: '75 / 25',
            columns: 2,
            ratio: '75-25',
            parts: [3, 1]
        },
        {
            key: '3',
            title: '3 колонки',
            subtitle: 'равные',
            columns: 3,
            ratio: 'equal',
            parts: [1, 1, 1]
        },
        {
            key: '4',
            title: '4 колонки',
            subtitle: 'равные',
            columns: 4,
            ratio: 'equal',
            parts: [1, 1, 1, 1]
        }
    ];

    var modalState = null;
    var busy = false;

    function deepClone(value) {
        try {
            return JSON.parse(
                JSON.stringify(value)
            );
        } catch (error) {
            return value;
        }
    }

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

    function clamp(value, min, max) {
        return Math.max(
            min,
            Math.min(
                max,
                Number(value || 0)
            )
        );
    }

    function sectionById(sectionId) {
        sectionId = Number(sectionId || 0);

        if (
            typeof getSectionById
            === 'function'
        ) {
            return getSectionById(sectionId);
        }

        return (state.pageSections || [])
            .find(function (section) {
                return Number(section.id || 0)
                    === sectionId;
            }) || null;
    }

    function sectionVersionMap(items) {
        var result = {};

        (items || []).forEach(function (section) {
            var id = Number(section.id || 0);
            var version = Number(
                section.version || 1
            );

            if (id > 0 && version > 0) {
                result[id] = version;
            }
        });

        return result;
    }

    function blockVersion(block) {
        if (
            typeof entityVersion
            === 'function'
        ) {
            return entityVersion(block);
        }

        return Math.max(
            1,
            Number(
                block && block.version || 1
            )
        );
    }

    function getBlockSection(block) {
        if (
            typeof getBlockSectionId
            === 'function'
        ) {
            return Number(
                getBlockSectionId(block) || 0
            );
        }

        var props =
            block && block.props
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

    function getBlockColumnNumber(block) {
        if (
            typeof getBlockColumn
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
            block && block.props
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

    function sortedSections(items) {
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

    function layoutByKey(key) {
        key = String(key || '');

        return LAYOUTS.find(
            function (layout) {
                return layout.key === key;
            }
        ) || LAYOUTS[0];
    }

    function currentLayoutKey(section) {
        var layout =
            section && section.layout
            && typeof section.layout === 'object'
                ? section.layout
                : {};

        var columns = clamp(
            layout.columns || 1,
            1,
            4
        );

        var ratio = String(
            layout.columnRatio || 'equal'
        );

        if (columns === 1) return '1';
        if (columns === 3) return '3';
        if (columns === 4) return '4';

        if (
            [
                '33-67',
                '67-33',
                '25-75',
                '75-25'
            ].indexOf(ratio) !== -1
        ) {
            return '2-' + ratio;
        }

        return '2-50-50';
    }

    function ratioLabel(section) {
        var layout =
            section && section.layout
            && typeof section.layout === 'object'
                ? section.layout
                : {};

        var columns = clamp(
            layout.columns || 1,
            1,
            4
        );

        if (columns !== 2) {
            return columns + ' кол.';
        }

        var ratio = String(
            layout.columnRatio || 'equal'
        );

        var labels = {
            equal: '50 / 50',
            '33-67': '33 / 67',
            '67-33': '67 / 33',
            '25-75': '25 / 75',
            '75-25': '75 / 25'
        };

        return labels[ratio]
            || labels.equal;
    }

    function gridTemplate(layout) {
        layout =
            layout && typeof layout === 'object'
                ? layout
                : {};

        var columns = clamp(
            layout.columns || 1,
            1,
            4
        );

        if (columns !== 2) {
            return 'repeat('
                + columns
                + ', minmax(0, 1fr))';
        }

        var ratio = String(
            layout.columnRatio || 'equal'
        );

        var templates = {
            equal:
                'minmax(0, 1fr) minmax(0, 1fr)',
            '33-67':
                'minmax(0, 1fr) minmax(0, 2fr)',
            '67-33':
                'minmax(0, 2fr) minmax(0, 1fr)',
            '25-75':
                'minmax(0, 1fr) minmax(0, 3fr)',
            '75-25':
                'minmax(0, 3fr) minmax(0, 1fr)'
        };

        return templates[ratio]
            || templates.equal;
    }

    function layoutPayload(
        preset,
        baseLayout
    ) {
        baseLayout =
            baseLayout
            && typeof baseLayout === 'object'
                ? baseLayout
                : {};

        var columns = Number(
            preset.columns || 1
        );

        return Object.assign(
            {},
            deepClone(baseLayout),
            {
                columns: columns,
                tabletColumns:
                    columns >= 3
                        ? 2
                        : columns,
                mobileColumns: 1,
                columnRatio:
                    preset.ratio || 'equal',
                container:
                    String(
                        baseLayout.container
                        || 'default'
                    ),
                gap: clamp(
                    baseLayout.gap == null
                        ? 24
                        : baseLayout.gap,
                    0,
                    120
                ),
                verticalAlign:
                    String(
                        baseLayout.verticalAlign
                        || 'start'
                    )
            }
        );
    }

    function defaultSectionProps() {
        return {
            backgroundColor: '',
            textColor: '',
            backgroundImage: '',
            backgroundPosition: 'center',
            backgroundSize: 'cover',
            paddingTop: 40,
            paddingBottom: 40,
            paddingX: 24,
            minHeight: 0,
            borderRadius: 0,
            shadow: false
        };
    }

    function modal() {
        var existing =
            document.getElementById(
                'sbSections2LayoutModal'
            );

        if (existing) {
            return existing;
        }

        var node =
            document.createElement('div');

        node.id =
            'sbSections2LayoutModal';
        node.className =
            'sb-s2-layout-modal';
        node.hidden = true;

        var cards = LAYOUTS.map(
            function (layout) {
                var wires =
                    layout.parts.map(
                        function (part) {
                            return ''
                                + '<i style="flex:'
                                + Number(part)
                                + '"></i>';
                        }
                    ).join('');

                return ''
                    + '<button'
                    + ' type="button"'
                    + ' class="sb-s2-layout-card"'
                    + ' data-s2-layout-key="'
                    + escape(layout.key)
                    + '">'
                    + '  <span class="sb-s2-layout-card__preview">'
                    + wires
                    + '  </span>'
                    + '  <strong>'
                    + escape(layout.title)
                    + '</strong>'
                    + '  <small>'
                    + escape(layout.subtitle)
                    + '</small>'
                    + '</button>';
            }
        ).join('');

        node.innerHTML = ''
            + '<div class="sb-s2-layout-modal__backdrop"'
            + ' data-s2-modal-close></div>'
            + '<div class="sb-s2-layout-modal__dialog"'
            + ' role="dialog" aria-modal="true"'
            + ' aria-labelledby="sbSections2LayoutTitle">'
            + '  <div class="sb-s2-layout-modal__head">'
            + '    <div>'
            + '      <div class="sb-s2-layout-modal__eyebrow">'
            + 'Sections 2.0'
            + '      </div>'
            + '      <h2 id="sbSections2LayoutTitle">'
            + 'Выберите структуру секции'
            + '      </h2>'
            + '      <p id="sbSections2LayoutHint">'
            + 'Можно изменить позже.'
            + '      </p>'
            + '    </div>'
            + '    <button type="button"'
            + ' class="sb-s2-layout-modal__close"'
            + ' data-s2-modal-close'
            + ' aria-label="Закрыть">×</button>'
            + '  </div>'
            + '  <div class="sb-s2-layout-grid">'
            + cards
            + '  </div>'
            + '</div>';

        document.body.appendChild(node);

        return node;
    }

    function openLayoutModal(config) {
        var node = modal();
        modalState = config || {};

        var title =
            node.querySelector(
                '#sbSections2LayoutTitle'
            );
        var hint =
            node.querySelector(
                '#sbSections2LayoutHint'
            );

        if (modalState.mode === 'edit') {
            if (title) {
                title.textContent =
                    'Изменить раскладку секции';
            }
            if (hint) {
                hint.textContent =
                    'Блоки из исчезающих колонок будут перенесены в последнюю доступную колонку.';
            }
        } else {
            if (title) {
                title.textContent =
                    'Добавить секцию';
            }
            if (hint) {
                hint.textContent =
                    'Выберите структуру — секция появится сразу в этой позиции.';
            }
        }

        var activeKey = '';

        if (
            modalState.mode === 'edit'
            && modalState.sectionId
        ) {
            activeKey = currentLayoutKey(
                sectionById(
                    modalState.sectionId
                )
            );
        }

        node.querySelectorAll(
            '[data-s2-layout-key]'
        ).forEach(function (button) {
            button.classList.toggle(
                'is-active',
                button.getAttribute(
                    'data-s2-layout-key'
                ) === activeKey
            );
        });

        node.hidden = false;
        document.body.classList.add(
            'sb-s2-modal-open'
        );
    }

    function closeLayoutModal() {
        var node =
            document.getElementById(
                'sbSections2LayoutModal'
            );

        if (node) {
            node.hidden = true;
        }

        modalState = null;
        document.body.classList.remove(
            'sb-s2-modal-open'
        );
    }

    function setStatus(stateName, text) {
        if (
            typeof setEditorStatus
            === 'function'
        ) {
            setEditorStatus(
                stateName,
                text
            );
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

    function setSectionState(
        sections,
        sectionId
    ) {
        state.pageSections =
            Array.isArray(sections)
                ? sections
                : state.pageSections;

        state.currentSectionId =
            Number(sectionId || 0);
        state.currentColumn = 1;
        state.currentBlockId = 0;

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
            typeof setInspectorTab
            === 'function'
        ) {
            setInspectorTab('section');
        }
    }

    function orderWithNewSection(
        sections,
        newSectionId,
        beforeSectionId
    ) {
        var ids = sortedSections(sections)
            .map(function (section) {
                return Number(
                    section.id || 0
                );
            })
            .filter(function (id) {
                return id > 0
                    && id !== newSectionId;
            });

        var insertAt =
            beforeSectionId > 0
                ? ids.indexOf(
                    Number(beforeSectionId)
                )
                : ids.length;

        if (insertAt < 0) {
            insertAt = ids.length;
        }

        ids.splice(
            insertAt,
            0,
            Number(newSectionId)
        );

        return ids;
    }

    function orderAfterSource(
        sections,
        newSectionId,
        sourceSectionId
    ) {
        var ids = sortedSections(sections)
            .map(function (section) {
                return Number(
                    section.id || 0
                );
            })
            .filter(function (id) {
                return id > 0
                    && id !== newSectionId;
            });

        var sourceIndex =
            ids.indexOf(
                Number(sourceSectionId)
            );

        var insertAt =
            sourceIndex >= 0
                ? sourceIndex + 1
                : ids.length;

        ids.splice(
            insertAt,
            0,
            Number(newSectionId)
        );

        return ids;
    }

    function sameOrder(
        sections,
        order
    ) {
        var current =
            sortedSections(sections)
                .map(function (section) {
                    return Number(
                        section.id || 0
                    );
                });

        if (current.length !== order.length) {
            return false;
        }

        return current.every(
            function (id, index) {
                return id
                    === Number(
                        order[index] || 0
                    );
            }
        );
    }

    async function reorderSections(
        sections,
        order
    ) {
        if (sameOrder(sections, order)) {
            return sections;
        }

        var response =
            await api(
                'pageSection.reorder',
                {
                    siteId: Number(
                        siteId || 0
                    ),
                    pageId: Number(
                        state.currentPageId
                        || 0
                    ),
                    order:
                        JSON.stringify(order),
                    expectedVersions:
                        JSON.stringify(
                            sectionVersionMap(
                                sections
                            )
                        )
                }
            );

        var data =
            typeof apiData === 'function'
                ? apiData(response)
                : response;

        return Array.isArray(
            data.sections
        )
            ? data.sections
            : sections;
    }

    async function createSectionAt(
        preset,
        beforeSectionId
    ) {
        if (!state.currentPageId || busy) {
            return;
        }

        busy = true;
        setStatus(
            'working',
            'Создаю секцию…'
        );

        try {
            var response =
                await api(
                    'pageSection.create',
                    {
                        siteId:
                            Number(siteId || 0),
                        pageId:
                            Number(
                                state.currentPageId
                                || 0
                            ),
                        title:
                            'Новая секция',
                        layout:
                            JSON.stringify(
                                layoutPayload(
                                    preset,
                                    {}
                                )
                            ),
                        props:
                            JSON.stringify(
                                defaultSectionProps()
                            )
                    }
                );

            var data =
                typeof apiData === 'function'
                    ? apiData(response)
                    : response;

            var section =
                data.section || {};
            var newId =
                Number(section.id || 0);
            var sections =
                Array.isArray(data.sections)
                    ? data.sections
                    : [];

            if (newId <= 0) {
                throw new Error(
                    'SECTION_CREATE_FAILED'
                );
            }

            var order =
                orderWithNewSection(
                    sections,
                    newId,
                    Number(
                        beforeSectionId || 0
                    )
                );

            sections =
                await reorderSections(
                    sections,
                    order
                );

            setSectionState(
                sections,
                newId
            );

            setStatus(
                'ready',
                'Секция создана'
            );

            toast(
                'Секция добавлена',
                'success'
            );
        } catch (error) {
            console.error(error);

            setStatus(
                'error',
                'Ошибка создания секции'
            );

            toast(
                'Не удалось создать секцию',
                'error'
            );
        } finally {
            busy = false;
        }
    }

    async function moveBlocksIntoVisibleColumns(
        sectionId,
        columns
    ) {
        var blocks =
            sortedBlocks(state.blocks)
                .filter(function (block) {
                    return getBlockSection(block)
                        === Number(sectionId);
                });

        for (
            var index = 0;
            index < blocks.length;
            index++
        ) {
            var block = blocks[index];
            var column =
                getBlockColumnNumber(block);

            if (column <= columns) {
                continue;
            }

            var response =
                await api(
                    'pageSection.assignBlock',
                    {
                        blockId:
                            Number(
                                block.id || 0
                            ),
                        sectionId:
                            Number(sectionId),
                        column:
                            Number(columns),
                        expectedVersion:
                            blockVersion(block)
                    }
                );

            var data =
                typeof apiData === 'function'
                    ? apiData(response)
                    : response;

            var updated =
                data.block
                || response.block
                || null;

            if (
                updated
                && typeof replaceStateBlock
                    === 'function'
            ) {
                replaceStateBlock(updated);
            } else if (updated) {
                var id =
                    Number(updated.id || 0);

                state.blocks =
                    (state.blocks || [])
                        .map(function (item) {
                            return Number(
                                item.id || 0
                            ) === id
                                ? updated
                                : item;
                        });
            }
        }
    }

    async function applyLayoutToSection(
        sectionId,
        preset
    ) {
        if (busy) return;

        var section =
            sectionById(sectionId);

        if (!section) {
            toast(
                'Секция не найдена',
                'error'
            );
            return;
        }

        busy = true;
        setStatus(
            'working',
            'Меняю раскладку…'
        );

        try {
            var layout =
                layoutPayload(
                    preset,
                    section.layout || {}
                );

            var response =
                await api(
                    'pageSection.update',
                    {
                        sectionId:
                            Number(sectionId),
                        layout:
                            JSON.stringify(layout),
                        expectedVersion:
                            Math.max(
                                1,
                                Number(
                                    section.version
                                    || 1
                                )
                            )
                    }
                );

            var data =
                typeof apiData === 'function'
                    ? apiData(response)
                    : response;

            state.pageSections =
                Array.isArray(data.sections)
                    ? data.sections
                    : state.pageSections;

            await moveBlocksIntoVisibleColumns(
                sectionId,
                Number(preset.columns || 1)
            );

            state.currentSectionId =
                Number(sectionId);
            state.currentColumn =
                Math.min(
                    Number(
                        state.currentColumn
                        || 1
                    ),
                    Number(
                        preset.columns || 1
                    )
                );
            state.currentBlockId = 0;

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

            setStatus(
                'ready',
                'Раскладка изменена'
            );

            toast(
                'Раскладка секции изменена',
                'success'
            );
        } catch (error) {
            console.error(error);

            if (
                typeof refreshSectionsAfterConflict
                    === 'function'
            ) {
                try {
                    if (
                        await refreshSectionsAfterConflict(
                            error
                        )
                    ) {
                        return;
                    }
                } catch (refreshError) {
                    console.error(
                        refreshError
                    );
                }
            }

            setStatus(
                'error',
                'Ошибка раскладки'
            );

            toast(
                'Не удалось изменить раскладку',
                'error'
            );
        } finally {
            busy = false;
        }
    }

    function blocksForSection(sectionId) {
        var firstSectionId =
            state.pageSections
            && state.pageSections.length
                ? Number(
                    state.pageSections[0].id
                    || 0
                )
                : 0;

        return sortedBlocks(
            state.blocks
        ).filter(function (block) {
            var blockSectionId =
                getBlockSection(block);

            if (
                blockSectionId
                === Number(sectionId)
            ) {
                return true;
            }

            return (
                blockSectionId <= 0
                && Number(sectionId)
                    === firstSectionId
            );
        });
    }

    async function rollbackDuplicate(
        createdBlocks,
        createdSection
    ) {
        for (
            var index =
                createdBlocks.length - 1;
            index >= 0;
            index--
        ) {
            var block =
                createdBlocks[index];

            try {
                await api(
                    'block.delete',
                    {
                        id:
                            Number(
                                block.id || 0
                            ),
                        expectedVersion:
                            blockVersion(block)
                    }
                );
            } catch (error) {
                console.error(
                    'Sections2 rollback block',
                    error
                );
            }
        }

        if (
            createdSection
            && createdSection.id
        ) {
            try {
                await api(
                    'pageSection.delete',
                    {
                        sectionId:
                            Number(
                                createdSection.id
                            ),
                        expectedVersion:
                            Math.max(
                                1,
                                Number(
                                    createdSection.version
                                    || 1
                                )
                            )
                    }
                );
            } catch (error) {
                console.error(
                    'Sections2 rollback section',
                    error
                );
            }
        }
    }

    async function duplicateSection(
        sectionId
    ) {
        if (busy) return;

        var source =
            sectionById(sectionId);

        if (!source) {
            toast(
                'Секция не найдена',
                'error'
            );
            return;
        }

        busy = true;
        setStatus(
            'working',
            'Дублирую секцию…'
        );

        var createdSection = null;
        var createdBlocks = [];

        try {
            var response =
                await api(
                    'pageSection.create',
                    {
                        siteId:
                            Number(siteId || 0),
                        pageId:
                            Number(
                                state.currentPageId
                                || 0
                            ),
                        title:
                            String(
                                source.title
                                || 'Секция'
                            )
                            + ' — копия',
                        layout:
                            JSON.stringify(
                                deepClone(
                                    source.layout || {}
                                )
                            ),
                        props:
                            JSON.stringify(
                                deepClone(
                                    source.props || {}
                                )
                            )
                    }
                );

            var data =
                typeof apiData === 'function'
                    ? apiData(response)
                    : response;

            createdSection =
                data.section || null;

            if (
                !createdSection
                || Number(
                    createdSection.id || 0
                ) <= 0
            ) {
                throw new Error(
                    'SECTION_DUPLICATE_CREATE_FAILED'
                );
            }

            var newSectionId =
                Number(
                    createdSection.id
                );
            var columns =
                clamp(
                    (
                        createdSection.layout
                        || {}
                    ).columns || 1,
                    1,
                    4
                );

            var sourceBlocks =
                blocksForSection(
                    sectionId
                );

            for (
                var index = 0;
                index < sourceBlocks.length;
                index++
            ) {
                var sourceBlock =
                    sourceBlocks[index];

                var content =
                    deepClone(
                        sourceBlock.content
                        || {}
                    );

                var props =
                    deepClone(
                        sourceBlock.props
                        || {}
                    );

                var column =
                    Math.min(
                        columns,
                        getBlockColumnNumber(
                            sourceBlock
                        )
                    );

                props.sectionId =
                    newSectionId;
                props.column = column;
                props._placement = {
                    sectionId:
                        newSectionId,
                    column: column
                };

                var blockResponse =
                    await api(
                        'block.create',
                        {
                            pageId:
                                Number(
                                    state.currentPageId
                                    || 0
                                ),
                            type:
                                String(
                                    sourceBlock.type
                                    || 'text'
                                ),
                            content:
                                JSON.stringify(
                                    content
                                ),
                            props:
                                JSON.stringify(
                                    props
                                ),
                            sectionId:
                                newSectionId,
                            column: column
                        }
                    );

                var blockData =
                    typeof apiData
                    === 'function'
                        ? apiData(
                            blockResponse
                        )
                        : blockResponse;

                var createdBlock =
                    blockData.block
                    || blockResponse.block
                    || null;

                if (
                    !createdBlock
                    || Number(
                        createdBlock.id || 0
                    ) <= 0
                ) {
                    throw new Error(
                        'SECTION_DUPLICATE_BLOCK_FAILED'
                    );
                }

                createdBlocks.push(
                    createdBlock
                );
            }

            var sections =
                Array.isArray(data.sections)
                    ? data.sections
                    : [];

            var order =
                orderAfterSource(
                    sections,
                    newSectionId,
                    Number(sectionId)
                );

            sections =
                await reorderSections(
                    sections,
                    order
                );

            state.pageSections =
                sections;

            if (
                typeof loadBlocks
                === 'function'
            ) {
                await loadBlocks();
            }

            setSectionState(
                sections,
                newSectionId
            );

            setStatus(
                'ready',
                'Секция продублирована'
            );

            toast(
                'Секция и её блоки продублированы',
                'success'
            );
        } catch (error) {
            console.error(error);

            await rollbackDuplicate(
                createdBlocks,
                createdSection
            );

            if (
                typeof loadPageSections
                === 'function'
            ) {
                try {
                    await loadPageSections();
                } catch (refreshError) {
                    console.error(
                        refreshError
                    );
                }
            }

            if (
                typeof loadBlocks
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
                'Не удалось полностью продублировать секцию',
                'error'
            );
        } finally {
            busy = false;
        }
    }

    function decorateCanvas() {
        if (!blocksList) return;

        blocksList.querySelectorAll(
            '[data-section-drop-slot]'
        ).forEach(function (slot) {
            if (
                slot.querySelector(
                    '[data-s2-insert-section]'
                )
            ) {
                return;
            }

            var beforeId =
                Number(
                    slot.getAttribute(
                        'data-before-section-id'
                    ) || 0
                );

            var button =
                document.createElement(
                    'button'
                );

            button.type = 'button';
            button.className =
                'sb-s2-insert-section';
            button.setAttribute(
                'data-s2-insert-section',
                String(beforeId)
            );
            button.innerHTML =
                '<span>＋</span><em>Секция</em>';
            button.title =
                'Добавить секцию в эту позицию';

            slot.appendChild(button);
        });

        blocksList.querySelectorAll(
            '.sb-editor-section-preview'
            + '[data-editor-section-id]'
        ).forEach(function (sectionNode) {
            var sectionId =
                Number(
                    sectionNode.getAttribute(
                        'data-editor-section-id'
                    ) || 0
                );

            if (sectionId <= 0) return;

            var tools =
                sectionNode.querySelector(
                    '.sb-editor-section-preview__tools'
                );

            if (
                tools
                && !tools.querySelector(
                    '[data-s2-layout-section]'
                )
            ) {
                var layoutButton =
                    document.createElement(
                        'button'
                    );

                layoutButton.type =
                    'button';
                layoutButton.setAttribute(
                    'data-s2-layout-section',
                    String(sectionId)
                );
                layoutButton.title =
                    'Изменить раскладку';
                layoutButton.setAttribute(
                    'aria-label',
                    'Изменить раскладку секции'
                );
                layoutButton.textContent =
                    '▦';

                var duplicateButton =
                    document.createElement(
                        'button'
                    );

                duplicateButton.type =
                    'button';
                duplicateButton.setAttribute(
                    'data-s2-duplicate-section',
                    String(sectionId)
                );
                duplicateButton.title =
                    'Дублировать секцию';
                duplicateButton.setAttribute(
                    'aria-label',
                    'Дублировать секцию'
                );
                duplicateButton.textContent =
                    '⧉';

                tools.insertBefore(
                    duplicateButton,
                    tools.firstChild
                );
                tools.insertBefore(
                    layoutButton,
                    tools.firstChild
                );
            }

            var identity =
                sectionNode.querySelector(
                    '.sb-editor-section-preview__identity'
                );

            if (
                identity
                && !identity.querySelector(
                    '.sb-s2-layout-badge'
                )
            ) {
                var section =
                    sectionById(sectionId);

                var badge =
                    document.createElement(
                        'span'
                    );

                badge.className =
                    'sb-s2-layout-badge';
                badge.textContent =
                    ratioLabel(section);

                identity.appendChild(
                    badge
                );
            }
        });
    }

    function decorateSectionsPanel() {
        var list =
            document.getElementById(
                'pageSectionsList'
            );

        if (!list) return;

        list.querySelectorAll(
            '[data-page-section-id]'
        ).forEach(function (card) {
            var sectionId =
                Number(
                    card.getAttribute(
                        'data-page-section-id'
                    ) || 0
                );

            var section =
                sectionById(sectionId);

            if (!section) return;

            var meta =
                card.querySelector(
                    '.sb-page-section-card__meta'
                );

            if (
                meta
                && !meta.querySelector(
                    '.sb-s2-panel-ratio'
                )
            ) {
                var badge =
                    document.createElement(
                        'span'
                    );

                badge.className =
                    'sb-s2-panel-ratio';
                badge.textContent =
                    ratioLabel(section);

                meta.appendChild(badge);
            }
        });
    }

    /*
     * Stage 17 owns the final canvas renderer. Decorate it after every
     * render rather than duplicating the renderer itself.
     */
    var originalRenderBlocks =
        window.renderBlocks;

    if (
        typeof originalRenderBlocks
        === 'function'
    ) {
        window.renderBlocks =
            function () {
                var result =
                    originalRenderBlocks.apply(
                        this,
                        arguments
                    );

                decorateCanvas();

                return result;
            };
    }

    var originalRenderSectionsPanel =
        window.renderPageSectionsPanel;

    if (
        typeof originalRenderSectionsPanel
        === 'function'
    ) {
        window.renderPageSectionsPanel =
            function () {
                var result =
                    originalRenderSectionsPanel.apply(
                        this,
                        arguments
                    );

                decorateSectionsPanel();

                return result;
            };
    }

    /*
     * Stage 17 asks SBVisualBuilder for section inline styles.
     * Extend that single source of truth with the desktop grid template.
     */
    if (
        window.SBVisualBuilder
        && typeof window.SBVisualBuilder
            .sectionStyle === 'function'
    ) {
        var originalSectionStyle =
            window.SBVisualBuilder
                .sectionStyle;

        window.SBVisualBuilder
            .sectionStyle =
            function (section) {
                return originalSectionStyle(
                    section
                )
                + '--sb-preview-grid-template:'
                + gridTemplate(
                    section
                    && section.layout
                        || {}
                )
                + ';';
            };
    }

    document.addEventListener(
        'click',
        function (event) {
            var insertButton =
                event.target.closest(
                    '[data-s2-insert-section]'
                );

            if (insertButton) {
                event.preventDefault();
                event.stopImmediatePropagation();

                openLayoutModal({
                    mode: 'create',
                    beforeSectionId:
                        Number(
                            insertButton
                                .getAttribute(
                                    'data-s2-insert-section'
                                ) || 0
                        )
                });

                return;
            }

            var layoutButton =
                event.target.closest(
                    '[data-s2-layout-section]'
                );

            if (layoutButton) {
                event.preventDefault();
                event.stopImmediatePropagation();

                openLayoutModal({
                    mode: 'edit',
                    sectionId:
                        Number(
                            layoutButton
                                .getAttribute(
                                    'data-s2-layout-section'
                                ) || 0
                        )
                });

                return;
            }

            var duplicateButton =
                event.target.closest(
                    '[data-s2-duplicate-section]'
                );

            if (duplicateButton) {
                event.preventDefault();
                event.stopImmediatePropagation();

                duplicateSection(
                    Number(
                        duplicateButton
                            .getAttribute(
                                'data-s2-duplicate-section'
                            ) || 0
                    )
                );

                return;
            }

            if (
                event.target.closest(
                    '[data-s2-modal-close]'
                )
            ) {
                event.preventDefault();
                closeLayoutModal();
                return;
            }

            var layoutCard =
                event.target.closest(
                    '[data-s2-layout-key]'
                );

            if (
                layoutCard
                && modalState
            ) {
                event.preventDefault();

                var preset =
                    layoutByKey(
                        layoutCard.getAttribute(
                            'data-s2-layout-key'
                        )
                    );

                var action =
                    modalState;

                closeLayoutModal();

                if (
                    action.mode === 'edit'
                ) {
                    applyLayoutToSection(
                        Number(
                            action.sectionId
                            || 0
                        ),
                        preset
                    );
                } else {
                    createSectionAt(
                        preset,
                        Number(
                            action.beforeSectionId
                            || 0
                        )
                    );
                }
            }
        },
        true
    );

    document.addEventListener(
        'keydown',
        function (event) {
            if (
                event.key === 'Escape'
                && modalState
            ) {
                closeLayoutModal();
            }
        },
        true
    );

    /*
     * Initial page may already be rendered before this module loads.
     */
    decorateCanvas();
    decorateSectionsPanel();
})();
