/* =========================================================
   SITEBUILDER DRAG & DROP 2.0 / STAGE 2 HOTFIX v2
   Reliable pointer-driven drag from component library.
   Native HTML5 drag on <button> is intentionally not used.
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

    if (
        !libraryModal
        || !libraryGrid
        || !blocksList
        || typeof window.createBlock !== 'function'
    ) {
        return;
    }

    var originalCreateBlock = window.createBlock;
    var pointerCandidate = null;
    var pointerDrag = null;
    var pointerTargetSlot = null;
    var suppressClickUntil = 0;
    var isCreating = false;
    var DRAG_THRESHOLD = 7;

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

                return bySort !== 0
                    ? bySort
                    : Number(a.id || 0)
                        - Number(b.id || 0);
            });
    }

    function localVersionMap(items) {
        var result = {};

        (items || []).forEach(function (item) {
            var id = Number(item.id || 0);
            var version = Number(
                item.version || 1
            );

            if (id > 0 && version > 0) {
                result[id] = version;
            }
        });

        return result;
    }

    function normalizePlacement(placement) {
        if (
            !placement
            || typeof placement !== 'object'
        ) {
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

        if ((state.pageSections || []).length) {
            var section =
                (state.pageSections || [])
                    .find(function (item) {
                        return Number(
                            item.id || 0
                        ) === sectionId;
                    });

            if (!section) {
                sectionId =
                    typeof getDefaultSectionId
                    === 'function'
                        ? Number(
                            getDefaultSectionId()
                            || 0
                        )
                        : Number(
                            state.currentSectionId
                            || 0
                        );
            }

            var columns =
                typeof getSectionColumns
                === 'function'
                    ? Number(
                        getSectionColumns(
                            sectionId
                        ) || 1
                    )
                    : 1;

            column = Math.max(
                1,
                Math.min(
                    columns,
                    column
                )
            );
        } else {
            sectionId = 0;
            column = 1;
        }

        if (beforeBlockId > 0) {
            var beforeBlock =
                (state.blocks || [])
                    .find(function (block) {
                        return Number(
                            block.id || 0
                        ) === beforeBlockId;
                    });

            if (!beforeBlock) {
                beforeBlockId = 0;
            } else if (
                sectionId > 0
                && typeof getBlockSectionId
                    === 'function'
                && typeof getBlockColumn
                    === 'function'
                && (
                    Number(
                        getBlockSectionId(
                            beforeBlock
                        ) || 0
                    ) !== sectionId
                    || Number(
                        getBlockColumn(
                            beforeBlock
                        ) || 1
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
        var selected = Number(
            state.currentBlockId || 0
        );

        if (
            selected > 0
            && !beforeIds[selected]
        ) {
            return selected;
        }

        var fresh =
            currentBlocksSorted()
                .filter(function (block) {
                    return !beforeIds[
                        Number(block.id || 0)
                    ];
                });

        return fresh.length
            ? Number(
                fresh[
                    fresh.length - 1
                ].id || 0
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
            || createdBlockId
                === beforeBlockId
        ) {
            return;
        }

        var order =
            currentBlocksSorted()
                .map(function (block) {
                    return Number(
                        block.id || 0
                    );
                })
                .filter(function (id) {
                    return id > 0
                        && id
                            !== createdBlockId;
                });

        var insertAt =
            order.indexOf(
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
            expectedVersions:
                JSON.stringify(
                    localVersionMap(
                        state.blocks
                    )
                )
        });

        if (
            typeof loadBlocks
            === 'function'
        ) {
            await loadBlocks();
        }
    }

    /*
     * Existing createBlock wrappers already know how to generate the
     * correct default content for basic, visual and business blocks.
     * We only temporarily set their default placement.
     */
    window.createBlock = async function (
        type,
        placement
    ) {
        placement =
            normalizePlacement(
                placement
            );

        if (!placement) {
            return originalCreateBlock.apply(
                this,
                arguments
            );
        }

        if (!state.currentPageId) {
            if (
                typeof showEditorToast
                === 'function'
            ) {
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
                var id = Number(
                    block.id || 0
                );

                if (id > 0) {
                    beforeIds[id] = true;
                }
            }
        );

        var previousSectionId =
            Number(
                state.currentSectionId || 0
            );
        var previousColumn =
            Number(
                state.currentColumn || 1
            );

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

            if (
                placement.beforeBlockId > 0
            ) {
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

        return Array.prototype.slice
            .call(
                container.querySelectorAll(
                    selector
                )
            )
            .filter(function (item) {
                return item.parentElement
                    === container;
            });
    }

    function nearestSlot(
        slots,
        clientY
    ) {
        if (!slots || !slots.length) {
            return null;
        }

        var best = null;
        var bestDistance = Infinity;

        slots.forEach(function (slot) {
            var rect =
                slot.getBoundingClientRect();
            var center =
                rect.top
                + rect.height / 2;
            var distance = Math.abs(
                Number(clientY || 0)
                - center
            );

            if (
                distance < bestDistance
            ) {
                best = slot;
                bestDistance = distance;
            }
        });

        return best;
    }

    function resolveBlockSlotFromElement(
        element,
        clientY
    ) {
        if (!element || !blocksList) {
            return null;
        }

        var direct =
            element.closest
                ? element.closest(
                    '[data-block-drop-slot]'
                )
                : null;

        if (
            direct
            && blocksList.contains(direct)
        ) {
            return direct;
        }

        var column =
            element.closest
                ? element.closest(
                    '.sb-editor-section-preview__column'
                    + '[data-section-id][data-column]'
                )
                : null;

        if (
            column
            && blocksList.contains(column)
        ) {
            return nearestSlot(
                directChildren(
                    column,
                    '[data-block-drop-slot]'
                ),
                clientY
            );
        }

        if (
            !(state.pageSections || []).length
            && blocksList.contains(element)
        ) {
            return nearestSlot(
                directChildren(
                    blocksList,
                    '[data-block-drop-slot]'
                ),
                clientY
            );
        }

        return null;
    }

    function resolveBlockSlotAtPoint(
        clientX,
        clientY
    ) {
        var element =
            document.elementFromPoint(
                Number(clientX || 0),
                Number(clientY || 0)
            );

        return resolveBlockSlotFromElement(
            element,
            clientY
        );
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
        result.className =
            'sb-dnd2-hud';
        result.hidden = true;
        result.setAttribute(
            'aria-hidden',
            'true'
        );

        document.body.appendChild(
            result
        );

        return result;
    }

    function showHud(
        text,
        clientX,
        clientY
    ) {
        var result = hud();

        result.textContent =
            String(text || '');
        result.hidden = false;
        result.style.left =
            (
                Number(clientX || 0)
                + 18
            ) + 'px';
        result.style.top =
            (
                Number(clientY || 0)
                + 18
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
                    Number(clientY || 0)
                    - rect.height
                    - 18
                ) + 'px';
        }
    }

    function sectionTitle(sectionId) {
        sectionId =
            Number(sectionId || 0);

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
                || (
                    'Секция #'
                    + sectionId
                )
            )
            : 'Секция #' + sectionId;
    }

    function blockTitle(blockId) {
        blockId =
            Number(blockId || 0);

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

    function markTarget(
        slot,
        clientX,
        clientY
    ) {
        if (pointerTargetSlot !== slot) {
            clearDropTarget();
            pointerTargetSlot = slot;
        }

        if (!slot || !pointerDrag) {
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

        slot.classList.add(
            'is-drag-over',
            'is-dnd2-library-target'
        );
        slot.setAttribute(
            'data-dnd2-label',
            'Добавить «'
            + pointerDrag.title
            + '»'
        );

        var targetColumn =
            slot.closest(
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

        var position =
            beforeBlockId > 0
                ? 'перед «'
                    + blockTitle(
                        beforeBlockId
                    )
                    + '»'
                : 'в конец';

        showHud(
            'Добавить «'
            + pointerDrag.title
            + '» · '
            + sectionTitle(sectionId)
            + ' · Колонка '
            + column
            + ' · '
            + position,
            clientX,
            clientY
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
        var y =
            Number(clientY || 0);
        var delta = 0;

        if (
            y < rect.top + threshold
        ) {
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

    function pointerGhost() {
        var ghost =
            document.getElementById(
                'sbDnd2LibraryPointerGhost'
            );

        if (ghost) {
            return ghost;
        }

        ghost =
            document.createElement('div');
        ghost.id =
            'sbDnd2LibraryPointerGhost';
        ghost.className =
            'sb-dnd2-library-pointer-ghost';
        ghost.hidden = true;
        document.body.appendChild(ghost);

        return ghost;
    }

    function renderPointerGhost(
        clientX,
        clientY
    ) {
        if (!pointerDrag) {
            return;
        }

        var meta =
            blockMeta(pointerDrag.type);
        var ghost = pointerGhost();

        ghost.innerHTML = ''
            + '<span class="sb-dnd2-library-pointer-ghost__icon">'
            + escape(meta.icon || '◆')
            + '</span>'
            + '<span class="sb-dnd2-library-pointer-ghost__copy">'
            + '<strong>'
            + escape(pointerDrag.title)
            + '</strong>'
            + '<small>Новый компонент</small>'
            + '</span>';

        ghost.hidden = false;
        ghost.style.left =
            (
                Number(clientX || 0)
                + 16
            ) + 'px';
        ghost.style.top =
            (
                Number(clientY || 0)
                + 16
            ) + 'px';
    }

    function hidePointerGhost() {
        var ghost =
            document.getElementById(
                'sbDnd2LibraryPointerGhost'
            );

        if (ghost) {
            ghost.hidden = true;
        }
    }

    function prepareLibraryCards(root) {
        root = root || libraryGrid;

        root.querySelectorAll(
            '[data-library-block]'
        ).forEach(function (card) {
            /*
             * Disable the unreliable native draggable behavior on
             * <button>. Pointer events below own the gesture.
             */
            card.draggable = false;
            card.removeAttribute(
                'draggable'
            );
            card.setAttribute(
                'data-dnd2-library-ready',
                '2'
            );

            if (!card.title) {
                card.title =
                    'Нажмите для добавления'
                    + ' или зажмите и перетащите на холст';
            }

            if (
                !card.querySelector(
                    '.sb-dnd2-library-card-grip'
                )
            ) {
                var grip =
                    document.createElement(
                        'span'
                    );
                grip.className =
                    'sb-dnd2-library-card-grip';
                grip.setAttribute(
                    'aria-hidden',
                    'true'
                );
                grip.textContent = '⋮⋮';
                card.appendChild(grip);
            }
        });
    }

    function beginPointerCandidate(
        card,
        event
    ) {
        if (
            isCreating
            || !event.isPrimary
            || (
                event.pointerType
                    === 'mouse'
                && event.button !== 0
            )
        ) {
            return;
        }

        var type = String(
            card.getAttribute(
                'data-library-block'
            ) || ''
        );

        if (!type) {
            return;
        }

        var meta = blockMeta(type);

        pointerCandidate = {
            pointerId: event.pointerId,
            card: card,
            type: type,
            title: String(
                meta.title
                || type
                || 'Блок'
            ),
            startX: Number(
                event.clientX || 0
            ),
            startY: Number(
                event.clientY || 0
            )
        };

        try {
            card.setPointerCapture(
                event.pointerId
            );
        } catch (error) {
            // Capture is a convenience, not a requirement.
        }
    }

    function activatePointerDrag(event) {
        if (
            !pointerCandidate
            || pointerDrag
        ) {
            return;
        }

        pointerDrag =
            pointerCandidate;
        pointerCandidate = null;
        pointerTargetSlot = null;
        suppressClickUntil =
            Date.now() + 900;

        document.body.classList.add(
            'sb-dnd2-active',
            'sb-dnd2-block-dragging',
            'sb-dnd2-library-dragging',
            'sb-dnd2-library-pointer-dragging'
        );

        libraryModal.classList.add(
            'is-dnd2-library-dragging'
        );

        pointerDrag.card.classList.add(
            'is-dnd2-library-source'
        );

        renderPointerGhost(
            event.clientX,
            event.clientY
        );
    }

    function finishPointerVisuals() {
        clearDropTarget();
        pointerTargetSlot = null;

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
            'sb-dnd2-library-dragging',
            'sb-dnd2-library-pointer-dragging'
        );

        hidePointerGhost();
    }

    function cancelPointerGesture() {
        if (pointerCandidate) {
            try {
                pointerCandidate.card
                    .releasePointerCapture(
                        pointerCandidate.pointerId
                    );
            } catch (error) {
                // Ignore missing capture.
            }
        }

        if (pointerDrag) {
            try {
                pointerDrag.card
                    .releasePointerCapture(
                        pointerDrag.pointerId
                    );
            } catch (error) {
                // Ignore missing capture.
            }
        }

        pointerCandidate = null;
        pointerDrag = null;
        finishPointerVisuals();
    }

    async function createFromDrop(
        type,
        slot
    ) {
        if (
            isCreating
            || !type
            || !slot
        ) {
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
                                            || added.nodeType
                                                !== 1
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

    /*
     * Capture phase lets us detect the gesture before the legacy
     * click-to-create handler. A simple press/release is untouched.
     */
    document.addEventListener(
        'pointerdown',
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

            beginPointerCandidate(
                card,
                event
            );
        },
        true
    );

    document.addEventListener(
        'pointermove',
        function (event) {
            var active =
                pointerDrag
                && pointerDrag.pointerId
                    === event.pointerId;

            var candidate =
                pointerCandidate
                && pointerCandidate.pointerId
                    === event.pointerId;

            if (!active && !candidate) {
                return;
            }

            if (!pointerDrag) {
                var dx =
                    Number(event.clientX || 0)
                    - pointerCandidate.startX;
                var dy =
                    Number(event.clientY || 0)
                    - pointerCandidate.startY;

                if (
                    Math.sqrt(
                        dx * dx + dy * dy
                    ) < DRAG_THRESHOLD
                ) {
                    return;
                }

                activatePointerDrag(
                    event
                );
            }

            if (!pointerDrag) {
                return;
            }

            event.preventDefault();

            renderPointerGhost(
                event.clientX,
                event.clientY
            );

            autoScroll(
                event.clientY
            );

            /*
             * The modal is pointer-events:none while dragging, so
             * elementFromPoint sees the real canvas beneath it.
             */
            var slot =
                resolveBlockSlotAtPoint(
                    event.clientX,
                    event.clientY
                );

            if (slot) {
                markTarget(
                    slot,
                    event.clientX,
                    event.clientY
                );
            } else {
                clearDropTarget();
                pointerTargetSlot = null;

                showHud(
                    'Перетащите «'
                    + pointerDrag.title
                    + '» на секцию или колонку',
                    event.clientX,
                    event.clientY
                );
            }
        },
        true
    );

    document.addEventListener(
        'pointerup',
        function (event) {
            if (
                pointerCandidate
                && pointerCandidate.pointerId
                    === event.pointerId
                && !pointerDrag
            ) {
                try {
                    pointerCandidate.card
                        .releasePointerCapture(
                            event.pointerId
                        );
                } catch (error) {
                    // Ignore.
                }

                pointerCandidate = null;
                return;
            }

            if (
                !pointerDrag
                || pointerDrag.pointerId
                    !== event.pointerId
            ) {
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();

            var type =
                pointerDrag.type;
            var slot =
                pointerTargetSlot
                || resolveBlockSlotAtPoint(
                    event.clientX,
                    event.clientY
                );

            try {
                pointerDrag.card
                    .releasePointerCapture(
                        event.pointerId
                    );
            } catch (error) {
                // Ignore.
            }

            pointerDrag = null;
            pointerCandidate = null;
            finishPointerVisuals();

            if (slot) {
                createFromDrop(
                    type,
                    slot
                );
            }
        },
        true
    );

    document.addEventListener(
        'pointercancel',
        function (event) {
            var matches =
                (
                    pointerCandidate
                    && pointerCandidate.pointerId
                        === event.pointerId
                )
                || (
                    pointerDrag
                    && pointerDrag.pointerId
                        === event.pointerId
                );

            if (matches) {
                suppressClickUntil =
                    Date.now() + 400;
                cancelPointerGesture();
            }
        },
        true
    );

    /*
     * Prevent the click that browsers emit after a completed drag.
     * Normal click without movement still reaches the original handler.
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
                && (
                    pointerCandidate
                    || pointerDrag
                )
            ) {
                suppressClickUntil =
                    Date.now() + 400;
                cancelPointerGesture();
            }
        },
        true
    );
})();
