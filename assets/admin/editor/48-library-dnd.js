/* =========================================================
   SITEBUILDER DRAG & DROP 2.0 / STAGE 2
   Drag new components from the block library directly to
   section / column / insertion position.
   No autosave. Existing click-to-create behavior is preserved.
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

    var libraryModal = document.getElementById(
        'blockLibraryModal'
    );
    var libraryGrid = document.getElementById(
        'blockLibraryGrid'
    );
    var blocksList = document.getElementById(
        'blocksList'
    );

    if (!libraryModal || !libraryGrid || !blocksList) {
        return;
    }

    var originalCreateBlock = window.createBlock;
    var draggedLibraryType = '';
    var draggedLibraryTitle = '';
    var suppressClickUntil = 0;
    var isCreating = false;

    if (typeof originalCreateBlock !== 'function') {
        return;
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

    function blockMeta(type) {
        if (typeof blockTypeMeta === 'function') {
            return blockTypeMeta(type);
        }

        return {
            title: String(type || 'Блок'),
            icon: '◆'
        };
    }

    function currentBlocksSorted() {
        return (state.blocks || [])
            .slice()
            .sort(function (a, b) {
                var bySort =
                    Number(a.sort || 0)
                    - Number(b.sort || 0);

                if (bySort !== 0) {
                    return bySort;
                }

                return Number(a.id || 0)
                    - Number(b.id || 0);
            });
    }

    function localVersionMap(items) {
        var result = {};

        (items || []).forEach(function (item) {
            var id = Number(item.id || 0);
            var version = Number(item.version || 1);

            if (id > 0 && version > 0) {
                result[id] = version;
            }
        });

        return result;
    }

    function normalizePlacement(placement) {
        placement = placement
            && typeof placement === 'object'
                ? placement
                : null;

        if (!placement) {
            return null;
        }

        var sectionId = Number(
            placement.sectionId || 0
        );
        var column = Math.max(
            1,
            Number(placement.column || 1)
        );
        var beforeBlockId = Number(
            placement.beforeBlockId || 0
        );

        if (state.pageSections && state.pageSections.length) {
            var section = (state.pageSections || [])
                .find(function (item) {
                    return Number(item.id || 0)
                        === sectionId;
                });

            if (!section) {
                sectionId =
                    typeof getDefaultSectionId === 'function'
                        ? Number(
                            getDefaultSectionId() || 0
                        )
                        : Number(
                            state.currentSectionId || 0
                        );
            }

            var columns =
                typeof getSectionColumns === 'function'
                    ? Number(
                        getSectionColumns(sectionId) || 1
                    )
                    : 1;

            column = Math.max(
                1,
                Math.min(columns, column)
            );
        } else {
            sectionId = 0;
            column = 1;
        }

        if (beforeBlockId > 0) {
            var beforeBlock = (state.blocks || [])
                .find(function (block) {
                    return Number(block.id || 0)
                        === beforeBlockId;
                });

            if (!beforeBlock) {
                beforeBlockId = 0;
            } else if (
                sectionId > 0
                && typeof getBlockSectionId === 'function'
                && typeof getBlockColumn === 'function'
                && (
                    Number(
                        getBlockSectionId(beforeBlock) || 0
                    ) !== sectionId
                    || Number(
                        getBlockColumn(beforeBlock) || 1
                    ) !== column
                )
            ) {
                beforeBlockId = 0;
            }
        }

        return {
            sectionId: sectionId,
            column: column,
            beforeBlockId: beforeBlockId
        };
    }

    function findCreatedBlock(beforeIds) {
        var currentId = Number(
            state.currentBlockId || 0
        );

        if (
            currentId > 0
            && !beforeIds[currentId]
        ) {
            return currentId;
        }

        var fresh = currentBlocksSorted()
            .filter(function (block) {
                return !beforeIds[
                    Number(block.id || 0)
                ];
            });

        return fresh.length
            ? Number(
                fresh[fresh.length - 1].id || 0
            )
            : 0;
    }

    async function reorderCreatedBlock(
        createdBlockId,
        beforeBlockId
    ) {
        createdBlockId = Number(
            createdBlockId || 0
        );
        beforeBlockId = Number(
            beforeBlockId || 0
        );

        if (
            createdBlockId <= 0
            || beforeBlockId <= 0
            || createdBlockId === beforeBlockId
        ) {
            return;
        }

        var order = currentBlocksSorted()
            .map(function (block) {
                return Number(block.id || 0);
            })
            .filter(function (id) {
                return id > 0
                    && id !== createdBlockId;
            });

        var insertAt = order.indexOf(
            beforeBlockId
        );

        if (insertAt < 0) {
            return;
        }

        order.splice(
            insertAt,
            0,
            createdBlockId
        );

        await api('block.reorder', {
            pageId: Number(
                state.currentPageId || 0
            ),
            order: JSON.stringify(order),
            expectedVersions: JSON.stringify(
                localVersionMap(state.blocks)
            )
        });

        if (typeof loadBlocks === 'function') {
            await loadBlocks();
        }
    }

    /*
     * Extends the final createBlock wrapper installed by
     * visual/business modules. A normal click calls createBlock(type)
     * and follows the old path. Library DnD passes placement as the
     * second argument.
     */
    window.createBlock = async function (
        type,
        placement
    ) {
        placement = normalizePlacement(
            placement
        );

        if (!placement) {
            return originalCreateBlock.apply(
                this,
                arguments
            );
        }

        if (!state.currentPageId) {
            if (typeof showEditorToast === 'function') {
                showEditorToast(
                    'Сначала выберите страницу',
                    'error'
                );
            }
            return null;
        }

        var beforeIds = {};

        (state.blocks || []).forEach(
            function (block) {
                var id = Number(block.id || 0);

                if (id > 0) {
                    beforeIds[id] = true;
                }
            }
        );

        var previousSectionId = Number(
            state.currentSectionId || 0
        );
        var previousColumn = Number(
            state.currentColumn || 1
        );

        /*
         * Existing createBlock implementations use
         * getDefaultSectionId/getDefaultColumn. Setting the target here
         * means block.create receives target placement immediately.
         */
        state.currentSectionId =
            placement.sectionId;
        state.currentColumn =
            placement.column;

        try {
            var result =
                await originalCreateBlock.call(
                    this,
                    type
                );

            var createdBlockId =
                findCreatedBlock(beforeIds);

            if (createdBlockId <= 0) {
                throw new Error(
                    'CREATED_BLOCK_NOT_FOUND'
                );
            }

            if (placement.beforeBlockId > 0) {
                await reorderCreatedBlock(
                    createdBlockId,
                    placement.beforeBlockId
                );
            }

            state.currentBlockId =
                createdBlockId;
            state.currentSectionId =
                placement.sectionId;
            state.currentColumn =
                placement.column;

            if (
                typeof fillBlockForm
                === 'function'
            ) {
                fillBlockForm();
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
                typeof setInspectorTab
                === 'function'
            ) {
                setInspectorTab('block');
            }

            return result;
        } catch (error) {
            state.currentSectionId =
                previousSectionId;
            state.currentColumn =
                previousColumn;

            throw error;
        }
    };

    function directChildren(
        container,
        selector
    ) {
        if (!container) {
            return [];
        }

        return Array.prototype.slice.call(
            container.querySelectorAll(selector)
        ).filter(function (item) {
            return item.parentElement
                === container;
        });
    }

    function nearestSlot(slots, clientY) {
        if (!slots || !slots.length) {
            return null;
        }

        var best = null;
        var bestDistance = Infinity;

        slots.forEach(function (slot) {
            var rect =
                slot.getBoundingClientRect();
            var center =
                rect.top + rect.height / 2;
            var distance = Math.abs(
                Number(clientY || 0)
                - center
            );

            if (distance < bestDistance) {
                best = slot;
                bestDistance = distance;
            }
        });

        return best;
    }

    function resolveBlockSlot(event) {
        if (
            !event
            || !event.target
            || !blocksList
        ) {
            return null;
        }

        var direct = event.target.closest(
            '[data-block-drop-slot]'
        );

        if (
            direct
            && blocksList.contains(direct)
        ) {
            return direct;
        }

        var column = event.target.closest(
            '.sb-editor-section-preview__column'
            + '[data-section-id][data-column]'
        );

        if (
            column
            && blocksList.contains(column)
        ) {
            return nearestSlot(
                directChildren(
                    column,
                    '[data-block-drop-slot]'
                ),
                event.clientY
            );
        }

        if (
            !(state.pageSections || []).length
            && blocksList.contains(event.target)
        ) {
            return nearestSlot(
                directChildren(
                    blocksList,
                    '[data-block-drop-slot]'
                ),
                event.clientY
            );
        }

        return null;
    }

    function clearDropTarget() {
        blocksList.querySelectorAll(
            '.is-drag-over,'
            + '.is-dnd2-target,'
            + '.is-dnd2-target-section,'
            + '.is-dnd2-library-target'
        ).forEach(function (item) {
            item.classList.remove(
                'is-drag-over',
                'is-dnd2-target',
                'is-dnd2-target-section',
                'is-dnd2-library-target'
            );
        });

        blocksList.querySelectorAll(
            '[data-dnd2-label]'
        ).forEach(function (item) {
            item.removeAttribute(
                'data-dnd2-label'
            );
        });

        var hud =
            document.getElementById(
                'sbDnd2Hud'
            );

        if (hud) {
            hud.hidden = true;
        }
    }

    function hud() {
        var result =
            document.getElementById(
                'sbDnd2Hud'
            );

        if (result) {
            return result;
        }

        result =
            document.createElement('div');
        result.id = 'sbDnd2Hud';
        result.className = 'sb-dnd2-hud';
        result.hidden = true;
        result.setAttribute(
            'aria-hidden',
            'true'
        );
        document.body.appendChild(result);

        return result;
    }

    function showHud(text, event) {
        var result = hud();

        result.textContent =
            String(text || '');
        result.hidden = false;

        result.style.left =
            (
                Number(
                    event.clientX || 0
                ) + 18
            ) + 'px';
        result.style.top =
            (
                Number(
                    event.clientY || 0
                ) + 18
            ) + 'px';

        var rect =
            result.getBoundingClientRect();

        if (
            rect.right
            > window.innerWidth - 10
        ) {
            result.style.left =
                Math.max(
                    10,
                    window.innerWidth
                        - rect.width
                        - 10
                ) + 'px';
        }

        if (
            rect.bottom
            > window.innerHeight - 10
        ) {
            result.style.top =
                Math.max(
                    10,
                    Number(
                        event.clientY || 0
                    )
                    - rect.height
                    - 18
                ) + 'px';
        }
    }

    function sectionTitle(sectionId) {
        sectionId = Number(
            sectionId || 0
        );

        if (sectionId <= 0) {
            return 'Страница';
        }

        var section =
            (state.pageSections || [])
                .find(function (item) {
                    return Number(
                        item.id || 0
                    ) === sectionId;
                });

        return section
            ? String(
                section.title
                || ('Секция #' + sectionId)
            )
            : 'Секция #' + sectionId;
    }

    function blockTitle(blockId) {
        blockId = Number(
            blockId || 0
        );

        var block =
            (state.blocks || [])
                .find(function (item) {
                    return Number(
                        item.id || 0
                    ) === blockId;
                });

        if (!block) {
            return 'блок';
        }

        return String(
            blockMeta(block.type).title
            || block.type
            || 'блок'
        );
    }

    function markTarget(slot, event) {
        clearDropTarget();

        if (!slot) {
            return;
        }

        var sectionId = Number(
            slot.getAttribute(
                'data-section-id'
            ) || 0
        );
        var column = Number(
            slot.getAttribute(
                'data-column'
            ) || 1
        );
        var beforeBlockId = Number(
            slot.getAttribute(
                'data-before-block-id'
            ) || 0
        );

        var label =
            'Добавить «'
            + draggedLibraryTitle
            + '»';

        slot.classList.add(
            'is-drag-over',
            'is-dnd2-library-target'
        );
        slot.setAttribute(
            'data-dnd2-label',
            label
        );

        var targetColumn = slot.closest(
            '.sb-editor-section-preview__column'
        );

        if (targetColumn) {
            targetColumn.classList.add(
                'is-dnd2-target'
            );

            var targetSection =
                targetColumn.closest(
                    '.sb-editor-section-preview'
                );

            if (targetSection) {
                targetSection.classList.add(
                    'is-dnd2-target-section'
                );
            }
        }

        var position = beforeBlockId > 0
            ? 'перед «'
                + blockTitle(beforeBlockId)
                + '»'
            : 'в конец';

        showHud(
            'Добавить «'
            + draggedLibraryTitle
            + '» · '
            + sectionTitle(sectionId)
            + ' · Колонка '
            + column
            + ' · '
            + position,
            event
        );
    }

    function autoScroll(clientY) {
        var canvas =
            document.getElementById(
                'editorCanvasBody'
            );

        if (!canvas) {
            return;
        }

        var rect =
            canvas.getBoundingClientRect();
        var threshold = Math.min(
            96,
            Math.max(
                54,
                rect.height * 0.14
            )
        );
        var y = Number(clientY || 0);
        var delta = 0;

        if (y < rect.top + threshold) {
            delta = -Math.ceil(
                (
                    rect.top
                    + threshold
                    - y
                )
                / threshold
                * 24
            );
        } else if (
            y > rect.bottom - threshold
        ) {
            delta = Math.ceil(
                (
                    y
                    - (
                        rect.bottom
                        - threshold
                    )
                )
                / threshold
                * 24
            );
        }

        if (delta !== 0) {
            canvas.scrollTop += delta;
        }
    }

    function createDragGhost(
        event,
        type,
        title
    ) {
        if (
            !event.dataTransfer
            || typeof event.dataTransfer
                .setDragImage !== 'function'
        ) {
            return;
        }

        var meta = blockMeta(type);
        var ghost =
            document.createElement('div');

        ghost.className =
            'sb-dnd2-ghost sb-dnd2-library-ghost';
        ghost.innerHTML = ''
            + '<strong>'
            + '<span class="sb-dnd2-library-ghost__icon">'
            + escape(meta.icon || '◆')
            + '</span>'
            + escape(title)
            + '</strong>'
            + '<span>'
            + 'Новый компонент'
            + '</span>';

        document.body.appendChild(ghost);

        try {
            event.dataTransfer.setDragImage(
                ghost,
                26,
                20
            );
        } catch (error) {
            // Browser native drag image is acceptable.
        }

        window.setTimeout(function () {
            if (ghost.parentNode) {
                ghost.parentNode.removeChild(
                    ghost
                );
            }
        }, 0);
    }

    function prepareLibraryCards(root) {
        root = root || libraryGrid;

        root.querySelectorAll(
            '[data-library-block]'
        ).forEach(function (card) {
            card.setAttribute(
                'draggable',
                'true'
            );
            card.setAttribute(
                'data-dnd2-library-ready',
                '1'
            );

            if (!card.title) {
                card.title =
                    'Нажмите для добавления'
                    + ' или перетащите на холст';
            }
        });
    }

    function startLibraryDrag(
        card,
        event
    ) {
        var type = String(
            card.getAttribute(
                'data-library-block'
            ) || ''
        );

        if (!type || isCreating) {
            event.preventDefault();
            return;
        }

        var meta = blockMeta(type);

        draggedLibraryType = type;
        draggedLibraryTitle =
            String(
                meta.title
                || type
                || 'Блок'
            );

        suppressClickUntil =
            Date.now() + 700;

        document.body.classList.add(
            'sb-dnd2-active',
            'sb-dnd2-block-dragging',
            'sb-dnd2-library-dragging'
        );

        libraryModal.classList.add(
            'is-dnd2-library-dragging'
        );

        card.classList.add(
            'is-dnd2-library-source'
        );

        if (event.dataTransfer) {
            event.dataTransfer.effectAllowed =
                'copy';

            event.dataTransfer.setData(
                'text/plain',
                'library:'
                + draggedLibraryType
            );

            try {
                event.dataTransfer.setData(
                    'application/x-sitebuilder-block',
                    draggedLibraryType
                );
            } catch (error) {
                // Custom MIME types may be blocked.
            }

            createDragGhost(
                event,
                draggedLibraryType,
                draggedLibraryTitle
            );
        }
    }

    function finishLibraryDrag() {
        clearDropTarget();

        libraryModal.classList.remove(
            'is-dnd2-library-dragging'
        );

        libraryModal.querySelectorAll(
            '.is-dnd2-library-source'
        ).forEach(function (card) {
            card.classList.remove(
                'is-dnd2-library-source'
            );
        });

        document.body.classList.remove(
            'sb-dnd2-active',
            'sb-dnd2-block-dragging',
            'sb-dnd2-library-dragging'
        );

        draggedLibraryType = '';
        draggedLibraryTitle = '';
    }

    async function createFromDrop(
        type,
        slot
    ) {
        if (isCreating || !type || !slot) {
            return;
        }

        var placement =
            normalizePlacement({
                sectionId: Number(
                    slot.getAttribute(
                        'data-section-id'
                    ) || 0
                ),
                column: Number(
                    slot.getAttribute(
                        'data-column'
                    ) || 1
                ),
                beforeBlockId: Number(
                    slot.getAttribute(
                        'data-before-block-id'
                    ) || 0
                )
            });

        if (!placement) {
            return;
        }

        isCreating = true;

        if (
            typeof setEditorStatus
            === 'function'
        ) {
            setEditorStatus(
                'working',
                'Добавляю блок…'
            );
        }

        try {
            if (
                typeof closeBlockLibrary
                === 'function'
            ) {
                closeBlockLibrary();
            } else {
                libraryModal.hidden = true;
                document.body.classList.remove(
                    'sb-modal-open'
                );
            }

            await window.createBlock(
                type,
                placement
            );

            if (
                typeof setEditorStatus
                === 'function'
            ) {
                setEditorStatus(
                    'ready',
                    'Блок добавлен'
                );
            }

            if (
                typeof showEditorToast
                === 'function'
            ) {
                showEditorToast(
                    '«'
                    + blockMeta(type).title
                    + '» добавлен в выбранную позицию',
                    'success'
                );
            }
        } catch (error) {
            console.error(error);

            if (
                typeof setEditorStatus
                === 'function'
            ) {
                setEditorStatus(
                    'error',
                    'Ошибка добавления'
                );
            }

            if (
                typeof showEditorToast
                === 'function'
            ) {
                showEditorToast(
                    'Не удалось добавить блок',
                    'error'
                );
            }
        } finally {
            isCreating = false;
        }
    }

    /*
     * Business block cards are injected after 25-visual-builder.js.
     * Observe the library so every current/future card becomes draggable.
     */
    prepareLibraryCards();

    if ('MutationObserver' in window) {
        var observer =
            new MutationObserver(
                function (mutations) {
                    mutations.forEach(
                        function (mutation) {
                            Array.prototype.slice
                                .call(
                                    mutation.addedNodes
                                    || []
                                )
                                .forEach(
                                    function (added) {
                                        if (
                                            !added
                                            || added.nodeType !== 1
                                        ) {
                                            return;
                                        }

                                        if (
                                            added.matches
                                            && added.matches(
                                                '[data-library-block]'
                                            )
                                        ) {
                                            prepareLibraryCards(
                                                added.parentElement
                                                || libraryGrid
                                            );
                                        } else if (
                                            added.querySelectorAll
                                        ) {
                                            prepareLibraryCards(
                                                added
                                            );
                                        }
                                    }
                                );
                        }
                    );
                }
            );

        observer.observe(
            libraryGrid,
            {
                childList: true,
                subtree: true
            }
        );
    }

    document.addEventListener(
        'dragstart',
        function (event) {
            var card =
                event.target
                && event.target.closest
                    ? event.target.closest(
                        '[data-library-block]'
                    )
                    : null;

            if (
                !card
                || !libraryModal.contains(card)
            ) {
                return;
            }

            startLibraryDrag(
                card,
                event
            );
        },
        true
    );

    document.addEventListener(
        'dragover',
        function (event) {
            if (!draggedLibraryType) {
                return;
            }

            var slot =
                resolveBlockSlot(event);

            if (!slot) {
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();

            if (event.dataTransfer) {
                event.dataTransfer.dropEffect =
                    'copy';
            }

            autoScroll(event.clientY);
            markTarget(slot, event);
        },
        true
    );

    document.addEventListener(
        'drop',
        function (event) {
            if (!draggedLibraryType) {
                return;
            }

            var slot =
                resolveBlockSlot(event);

            if (!slot) {
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();

            var type =
                draggedLibraryType;

            finishLibraryDrag();

            createFromDrop(
                type,
                slot
            );
        },
        true
    );

    document.addEventListener(
        'dragend',
        function () {
            if (
                draggedLibraryType
                || document.body.classList.contains(
                    'sb-dnd2-library-dragging'
                )
            ) {
                finishLibraryDrag();
            }
        },
        true
    );

    /*
     * Browsers can emit click immediately after an HTML5 drag.
     * Capture it before the old library click handler so DnD never
     * creates a second block at the default target.
     */
    document.addEventListener(
        'click',
        function (event) {
            var card =
                event.target
                && event.target.closest
                    ? event.target.closest(
                        '[data-library-block]'
                    )
                    : null;

            if (
                !card
                || !libraryModal.contains(card)
                || Date.now()
                    >= suppressClickUntil
            ) {
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();
        },
        true
    );

    document.addEventListener(
        'keydown',
        function (event) {
            if (
                event.key === 'Escape'
                && draggedLibraryType
            ) {
                finishLibraryDrag();
            }
        },
        true
    );
})();
