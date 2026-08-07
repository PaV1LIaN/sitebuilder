document.addEventListener('click', function (e) {
    var tableAction = e.target.closest('[data-table-action]');

    if (!tableAction) {
        return;
    }

    var action = tableAction.getAttribute('data-table-action');

    if (action === 'open-data-modal') {
        openTableDataModal();
        return;
    }

    if (action === 'close-data-modal') {
        closeTableDataModal();
        return;
    }

    if (action === 'apply-data-modal') {
        applyTableDataModal();
        return;
    }

    if (action === 'add-column') {
        addTableColumn();
        return;
    }

    if (action === 'add-row') {
        addTableRow();
        return;
    }

    if (action === 'delete-column') {
        var columnNode = tableAction.closest('[data-table-column-id]');
        var columnId = columnNode ? String(columnNode.getAttribute('data-table-column-id') || '') : '';

        if (columnId) {
            deleteTableColumn(columnId);
        }

        return;
    }

    if (action === 'delete-row') {
        var rowNode = tableAction.closest('[data-table-row-id]');
        var rowId = rowNode ? String(rowNode.getAttribute('data-table-row-id') || '') : '';

        if (rowId) {
            deleteTableRow(rowId);
        }

        return;
    }

    if (action === 'modal-add-column') {
        addTableColumnInModal();
        return;
    }

    if (action === 'modal-add-row') {
        addTableRowInModal();
        return;
    }

    if (action === 'modal-delete-column') {
        var modalColumnId = String(tableAction.getAttribute('data-column-id') || '');

        if (modalColumnId) {
            deleteTableColumnInModal(modalColumnId);
        }

        return;
    }

    if (action === 'modal-delete-row') {
        var modalRowId = String(tableAction.getAttribute('data-row-id') || '');

        if (modalRowId) {
            deleteTableRowInModal(modalRowId);
        }

        return;
    }
});

if (pagesList) {
    pagesList.addEventListener('click', async function (e) {
        var item = e.target.closest('[data-page-id]');
        if (!item) return;

        state.currentPageId = Number(item.getAttribute('data-page-id') || 0);
        state.currentBlockId = 0;
        state.currentSectionId = 0;
        state.currentColumn = 1;

        renderPages();
        fillPageForm();

        if (typeof setInspectorTab === 'function') {
            setInspectorTab('page');
        }

        await loadBlocks();
    });
}

if (blocksList) {
    /* SiteBuilder contextual entity selection v1 */
    blocksList.addEventListener('click', function (e) {
        var blockItem = e.target.closest('[data-block-id]');

        if (blockItem) {
            state.currentBlockId = Number(
                blockItem.getAttribute('data-block-id') || 0
            );

            var selectedBlock = getCurrentBlock();

            if (selectedBlock) {
                var selectedSectionId =
                    getBlockSectionId(selectedBlock);
                var selectedColumn =
                    getBlockColumn(selectedBlock);

                if (selectedSectionId > 0) {
                    state.currentSectionId =
                        selectedSectionId;
                }

                state.currentColumn =
                    selectedColumn > 0
                        ? selectedColumn
                        : 1;
            }

            renderPageSectionsPanel();
            renderBlocks();
            fillBlockForm();

            if (typeof setInspectorTab === 'function') {
                setInspectorTab('block');
            }

            return;
        }

        var sectionItem = e.target.closest(
            '[data-editor-section-id]'
        );

        if (sectionItem) {
            var sectionId = Number(
                sectionItem.getAttribute(
                    'data-editor-section-id'
                ) || 0
            );

            var columnItem = e.target.closest(
                '.sb-editor-section-preview__column[data-column]'
            );

            if (sectionId > 0) {
                state.currentBlockId = 0;
                state.currentSectionId = sectionId;
                state.currentColumn = columnItem
                    ? Math.max(
                        1,
                        Number(
                            columnItem.getAttribute(
                                'data-column'
                            ) || 1
                        )
                    )
                    : 1;

                renderPageSectionsPanel();
                renderBlocks();
                fillBlockForm();

                if (
                    typeof setInspectorTab
                    === 'function'
                ) {
                    setInspectorTab('section');
                }
            }

            return;
        }
    });

OLD,
    'контекстный click блок/секция'
);

$events = sbReplaceOnce(
    $events,
    <<<'OLD'
    blocksList.addEventListener('drop', async function (e) {
OLD,
    <<<'NEW'
    var editorViewport =
        document.getElementById('editorViewport');

    if (editorViewport) {
        editorViewport.addEventListener(
            'click',
            function (e) {
                if (
                    e.target.closest(
                        '.sb-editor-block,'
                        + '[data-editor-section-id],'
                        + '.sb-editor-addbar,'
                        + 'button,a,input,textarea,select,'
                        + '[contenteditable="true"]'
                    )
                ) {
                    return;
                }

                if (!e.target.closest('.sb-editor-page')) {
                    return;
                }

                state.currentBlockId = 0;
                fillBlockForm();
                renderBlocks();

                if (
                    typeof setInspectorTab
                    === 'function'
                ) {
                    setInspectorTab('page');
                }
            }
        );
    }

    blocksList.addEventListener('drop', async function (e) {
    blocksList.addEventListener('dragstart', function (e) {
        var blockNode = e.target.closest('.sb-editor-block[data-block-id]');
        if (!blockNode) {
            return;
        }

        var blockId = Number(blockNode.getAttribute('data-block-id') || 0);

        if (blockId <= 0) {
            return;
        }

        state.draggedBlockId = blockId;
        blockNode.classList.add('is-dragging');

        if (e.dataTransfer) {
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', String(blockId));
        }
    });

    blocksList.addEventListener('dragend', function (e) {
        var blockNode = e.target.closest('.sb-editor-block[data-block-id]');
        if (blockNode) {
            blockNode.classList.remove('is-dragging');
        }

        state.draggedBlockId = 0;

        blocksList.querySelectorAll('.sb-editor-section-preview__column.is-drag-over').forEach(function (columnNode) {
            columnNode.classList.remove('is-drag-over');
        });
    });

    blocksList.addEventListener('dragover', function (e) {
        var columnNode = e.target.closest('.sb-editor-section-preview__column[data-section-id][data-column]');
        if (!columnNode) {
            return;
        }

        e.preventDefault();

        if (e.dataTransfer) {
            e.dataTransfer.dropEffect = 'move';
        }

        blocksList.querySelectorAll('.sb-editor-section-preview__column.is-drag-over').forEach(function (node) {
            if (node !== columnNode) {
                node.classList.remove('is-drag-over');
            }
        });

        columnNode.classList.add('is-drag-over');
    });

    blocksList.addEventListener('dragleave', function (e) {
        var columnNode = e.target.closest('.sb-editor-section-preview__column[data-section-id][data-column]');
        if (!columnNode) {
            return;
        }

        var related = e.relatedTarget;

        if (related && columnNode.contains(related)) {
            return;
        }

        columnNode.classList.remove('is-drag-over');
    });

    blocksList.addEventListener('drop', async function (e) {
        var columnNode = e.target.closest('.sb-editor-section-preview__column[data-section-id][data-column]');
        if (!columnNode) {
            return;
        }

        e.preventDefault();

        columnNode.classList.remove('is-drag-over');

        var blockId = Number(state.draggedBlockId || 0);

        if (!blockId && e.dataTransfer) {
            blockId = Number(e.dataTransfer.getData('text/plain') || 0);
        }

        var sectionId = Number(columnNode.getAttribute('data-section-id') || 0);
        var column = Number(columnNode.getAttribute('data-column') || 1);

        if (blockId <= 0 || sectionId <= 0) {
            return;
        }

        try {
            await assignBlockToSection(blockId, sectionId, column);

            state.currentBlockId = blockId;
            state.currentSectionId = sectionId;
            state.currentColumn = column;

            await loadBlocks();

            setPageSectionsMessage('Блок перенесён в секцию #' + sectionId + ', колонку ' + column, 'success');
        } catch (err) {
            console.error(err);
            setPageSectionsMessage('Не удалось перенести блок', 'error');
        }
    });
}

var addPageSectionBtn = document.getElementById('addPageSectionBtn');
if (addPageSectionBtn) {
    addPageSectionBtn.addEventListener('click', createPageSection);
}

document.addEventListener('click', function (e) {
    var addTargetBtn = e.target.closest('[data-set-add-target]');
    if (addTargetBtn) {
        var targetSectionId = Number(addTargetBtn.getAttribute('data-set-add-target') || 0);
        var targetColumn = Number(addTargetBtn.getAttribute('data-column') || 1);

        if (targetSectionId > 0) {
            state.currentBlockId = 0;
            state.currentSectionId = targetSectionId;
            state.currentColumn = targetColumn > 0 ? targetColumn : 1;

            renderPageSectionsPanel();
            renderBlocks();
            fillBlockForm();

            if (typeof setInspectorTab === 'function') {
                setInspectorTab('section');
            }

            setPageSectionsMessage(
                'Новые компоненты будут добавляться в секцию #' + targetSectionId + ', колонку ' + state.currentColumn,
                'success'
            );
        }

        return;
    }

    var selectSection = e.target.closest('[data-page-section-select], [data-add-block-to-section]');

    if (selectSection) {
        var sectionId = Number(
            selectSection.getAttribute('data-page-section-select') ||
            selectSection.getAttribute('data-add-block-to-section') ||
            0
        );

        if (sectionId > 0) {
            state.currentBlockId = 0;
            state.currentSectionId = sectionId;
            state.currentColumn = 1;
            renderPageSectionsPanel();
            renderBlocks();
            fillBlockForm();

            if (typeof setInspectorTab === 'function') {
                setInspectorTab('section');
            }
        }

        return;
    }

    var sectionBtn = e.target.closest('[data-section-action]');

    if (!sectionBtn) {
        return;
    }

    var action = sectionBtn.getAttribute('data-section-action');
    var sectionActionId = Number(sectionBtn.getAttribute('data-section-id') || 0);

    if (action === 'move-up') {
        movePageSection(sectionActionId, 'up');
        return;
    }

    if (action === 'move-down') {
        movePageSection(sectionActionId, 'down');
        return;
    }

    if (action === 'save') {
        savePageSection(sectionActionId);
        return;
    }

    if (action === 'delete') {
        deletePageSection(sectionActionId);
    }
});

document.addEventListener('change', function (e) {
    var sectionField = e.target.closest('[data-section-field="columns"], [data-section-field="container"]');

    if (sectionField) {
        var sectionId = Number(sectionField.getAttribute('data-section-id') || 0);

        if (sectionId > 0) {
            savePageSection(sectionId);
        }

        return;
    }

    if (e.target && e.target.id === 'blockSectionInput') {
        var block = getCurrentBlock();
        var newSectionId = Number(e.target.value || 0);

        if (block) {
            state.currentSectionId = newSectionId;
            state.currentColumn = 1;

            fillBlockPlacementForm(Object.assign({}, block, {
                sectionId: newSectionId,
                column: 1,
                props: Object.assign({}, block.props || {}, {
                    sectionId: newSectionId,
                    column: 1,
                    _placement: {
                        sectionId: newSectionId,
                        column: 1
                    }
                })
            }));

            renderPageSectionsPanel();
            renderBlocks();
        }

        return;
    }

    if (e.target && e.target.id === 'blockColumnInput') {
        var columnValue = Number(e.target.value || 1);

        state.currentColumn = columnValue > 0 ? columnValue : 1;

        renderBlocks();
    }
});

var createPageBtn = document.getElementById('createPageBtn');
if (createPageBtn) {
    createPageBtn.addEventListener('click', createPage);
}

var savePageBtn = document.getElementById('savePageBtn');
if (savePageBtn) {
    savePageBtn.addEventListener('click', savePage);
}

var deletePageBtn = document.getElementById('deletePageBtn');
if (deletePageBtn) {
    deletePageBtn.addEventListener('click', deletePage);
}

var movePageUpBtn = document.getElementById('movePageUpBtn');
if (movePageUpBtn) {
    movePageUpBtn.addEventListener('click', function () {
        movePage('up');
    });
}

var movePageDownBtn = document.getElementById('movePageDownBtn');
if (movePageDownBtn) {
    movePageDownBtn.addEventListener('click', function () {
        movePage('down');
    });
}

var publishPageBtn = document.getElementById('publishPageBtn');
if (publishPageBtn) {
    publishPageBtn.addEventListener('click', async function () {
        var page = getCurrentPage();
        if (!page) return;

        await api('page.setStatus', {
            id: Number(page.id || 0),
            status: 'published',
            expectedVersion: entityVersion(page)
        });

        await loadPages();
    });
}

document.querySelectorAll('[data-add-block]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        createBlock(btn.getAttribute('data-add-block'));
    });
});

var saveBlockBtn = document.getElementById('saveBlockBtn');
if (saveBlockBtn) {
    saveBlockBtn.addEventListener('click', saveBlock);
}

var duplicateBlockBtn = document.getElementById('duplicateBlockBtn');
if (duplicateBlockBtn) {
    duplicateBlockBtn.addEventListener('click', duplicateBlock);
}

var deleteBlockBtn = document.getElementById('deleteBlockBtn');
if (deleteBlockBtn) {
    deleteBlockBtn.addEventListener('click', deleteBlock);
}

var moveBlockUpBtn = document.getElementById('moveBlockUpBtn');
if (moveBlockUpBtn) {
    moveBlockUpBtn.addEventListener('click', function () {
        moveBlock('up');
    });
}

var moveBlockDownBtn = document.getElementById('moveBlockDownBtn');
if (moveBlockDownBtn) {
    moveBlockDownBtn.addEventListener('click', function () {
        moveBlock('down');
    });
}

var deleteSiteBtn = document.getElementById('deleteSiteBtn');
if (deleteSiteBtn) {
    deleteSiteBtn.addEventListener('click', deleteSite);
}

var saveAsTemplateBtn = document.getElementById('saveAsTemplateBtn');
if (saveAsTemplateBtn) {
    saveAsTemplateBtn.addEventListener('click', openTemplateModal);
}

var createTemplateBtn = document.getElementById('createTemplateBtn');
if (createTemplateBtn) {
    createTemplateBtn.addEventListener('click', createTemplateFromSite);
}

document.querySelectorAll('[data-close-template-modal]').forEach(function (btn) {
    btn.addEventListener('click', closeTemplateModal);
});

var syncAccessBtn = document.getElementById('syncAccessBtn');
if (syncAccessBtn) {
    syncAccessBtn.addEventListener('click', syncAccess);
}

var ensureBitrixGroupBtn = document.getElementById('ensureBitrixGroupBtn');
if (ensureBitrixGroupBtn) {
    ensureBitrixGroupBtn.addEventListener('click', ensureBitrixGroup);
}

var grantAccessBtn = document.getElementById('grantAccessBtn');
if (grantAccessBtn) {
    grantAccessBtn.addEventListener('click', grantAccessRole);
}

var reloadAccessBtn = document.getElementById('reloadAccessBtn');
if (reloadAccessBtn) {
    reloadAccessBtn.addEventListener('click', loadAccessList);
}

var savePageAccessBtn = document.getElementById('savePageAccessBtn');
if (savePageAccessBtn) {
    savePageAccessBtn.addEventListener('click', savePageAccess);
}

var reloadPageAccessBtn = document.getElementById('reloadPageAccessBtn');
if (reloadPageAccessBtn) {
    reloadPageAccessBtn.addEventListener('click', function () {
        state.pageAccessLoadedPageId = 0;
        loadPageAccessList(true);
    });
}

var accessUserSearchInput = document.getElementById('accessUserSearchInput');
if (accessUserSearchInput) {
    accessUserSearchInput.addEventListener('input', function () {
        clearTimeout(state.userSearchTimer);

        state.userSearchTimer = setTimeout(function () {
            searchAccessUsers();
        }, 300);
    });

    accessUserSearchInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();

            if (state.userSearchResults.length) {
                selectAccessUser(state.userSearchResults[0]);
            }
        }
    });
}

var pageAccessUserSearchInput = document.getElementById('pageAccessUserSearchInput');
if (pageAccessUserSearchInput) {
    pageAccessUserSearchInput.addEventListener('input', function () {
        clearTimeout(state.pageAccessUserSearchTimer);

        state.pageAccessUserSearchTimer = setTimeout(function () {
            searchPageAccessUsers();
        }, 300);
    });

    pageAccessUserSearchInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();

            if (state.pageAccessUserSearchResults.length) {
                selectPageAccessUser(state.pageAccessUserSearchResults[0]);
            }
        }
    });
}

[
    'pageAccessCanView',
    'pageAccessCanEdit',
    'pageAccessCanDiskView',
    'pageAccessCanDiskEdit'
].forEach(function (id) {
    var input = document.getElementById(id);
    if (input) {
        input.addEventListener('change', function () {
            syncPageAccessPermissionInputs(id);
        });
    }
});

document.addEventListener('click', function (e) {
    var selectPageAccessBtn = e.target.closest('[data-select-page-access-user]');
    if (selectPageAccessBtn) {
        var pageAccessUserId = Number(selectPageAccessBtn.getAttribute('data-select-page-access-user') || 0);
        var pageAccessUser = (state.pageAccessUserSearchResults || []).find(function (item) {
            return Number(item.id || 0) === pageAccessUserId;
        });

        if (pageAccessUser) {
            selectPageAccessUser(pageAccessUser);
        }

        return;
    }

    var clearPageAccessBtn = e.target.closest('[data-clear-page-access-user]');
    if (clearPageAccessBtn) {
        clearSelectedPageAccessUser();
        return;
    }

    var editPageAccessBtn = e.target.closest('[data-page-access-edit-id]');
    if (editPageAccessBtn) {
        editPageAccessItem(Number(editPageAccessBtn.getAttribute('data-page-access-edit-id') || 0));
        return;
    }

    var deletePageAccessBtn = e.target.closest('[data-page-access-delete-id]');
    if (deletePageAccessBtn) {
        deletePageAccessItem(Number(deletePageAccessBtn.getAttribute('data-page-access-delete-id') || 0));
        return;
    }

    var selectBtn = e.target.closest('[data-select-access-user]');
    if (selectBtn) {
        var userId = Number(selectBtn.getAttribute('data-select-access-user') || 0);
        var user = (state.userSearchResults || []).find(function (item) {
            return Number(item.id || 0) === userId;
        });

        if (user) {
            selectAccessUser(user);
        }

        return;
    }

    var clearBtn = e.target.closest('[data-clear-access-user]');
    if (clearBtn) {
        clearSelectedAccessUser();
        return;
    }

    var removeBtn = e.target.closest('[data-access-remove-user]');
    if (removeBtn) {
        removeAccessRole(Number(removeBtn.getAttribute('data-access-remove-user') || 0));
        return;
    }
});

document.addEventListener('mousedown', function (e) {
    if (!e.target.closest('#siteAccessPanel .sb-access-search-wrap')) {
        renderAccessUserSearchResults([]);
    }

    if (!e.target.closest('#pageAccessPanel .sb-access-search-wrap')) {
        renderPageAccessUserSearchResults([]);
    }
});

window.onerror = function (message, source, lineno, colno, error) {
    print({
        jsError: true,
        message: message,
        source: source,
        line: lineno,
        column: colno,
        stack: error && error.stack ? error.stack : null
    });
};

(async function init() {
    try {
        setManagementPanelsVisible(false);

        await loadSite();
        await loadPages();
        await loadBlocks();
        await loadAccessList();
    } catch (e) {
        print(e);
        alert('Не удалось загрузить редактор');
    }
})();
