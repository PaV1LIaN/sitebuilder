(function () {
    window.SB_TABLE_VIEW_LOADED = 'v1-public-pagination';

    function getPageSize(root) {
        var value = Number(root.getAttribute('data-table-view-page-size') || 10);

        if (!Number.isFinite(value) || value < 1) {
            value = 10;
        }

        if (value > 200) {
            value = 200;
        }

        return Math.floor(value);
    }

    function isPaginationEnabled(root) {
        return String(root.getAttribute('data-table-view-pagination') || '') === '1';
    }

    function ensurePaginationBox(root) {
        var box = root.querySelector('[data-table-pagination]');

        if (box) {
            return box;
        }

        box = document.createElement('div');
        box.className = 'sb-public-table-pagination';
        box.setAttribute('data-table-pagination', '');

        var wrap = root.querySelector('.sb-public-table-wrap');

        if (wrap) {
            wrap.appendChild(box);
        } else {
            root.appendChild(box);
        }

        return box;
    }

    function getRows(root) {
        return Array.prototype.slice.call(root.querySelectorAll('tbody tr[data-row-id]'));
    }

    function renderPagination(root) {
        if (root.hasAttribute('data-public-editable-table')) {
            return;
        }

        var enabled = isPaginationEnabled(root);
        var rows = getRows(root);
        var paginationBox = ensurePaginationBox(root);

        if (!enabled || rows.length === 0) {
            paginationBox.innerHTML = '';

            rows.forEach(function (tr) {
                tr.style.display = '';
            });

            return;
        }

        var pageSize = getPageSize(root);
        var totalPages = Math.max(1, Math.ceil(rows.length / pageSize));
        var currentPage = Number(root.getAttribute('data-table-view-current-page') || 1);

        if (!Number.isFinite(currentPage) || currentPage < 1) {
            currentPage = 1;
        }

        if (currentPage > totalPages) {
            currentPage = totalPages;
        }

        root.setAttribute('data-table-view-current-page', String(currentPage));

        var start = (currentPage - 1) * pageSize;
        var end = start + pageSize;

        rows.forEach(function (tr, index) {
            tr.style.display = index >= start && index < end ? '' : 'none';
        });

        paginationBox.innerHTML = '';

        if (totalPages <= 1) {
            return;
        }

        var prevBtn = document.createElement('button');
        prevBtn.type = 'button';
        prevBtn.textContent = 'Назад';
        prevBtn.disabled = currentPage <= 1;
        prevBtn.setAttribute('data-table-view-page-prev', '');

        var info = document.createElement('span');
        info.className = 'sb-public-table-pagination__info';
        info.textContent = 'Страница ' + currentPage + ' из ' + totalPages + ', строк: ' + rows.length;

        var nextBtn = document.createElement('button');
        nextBtn.type = 'button';
        nextBtn.textContent = 'Вперёд';
        nextBtn.disabled = currentPage >= totalPages;
        nextBtn.setAttribute('data-table-view-page-next', '');

        paginationBox.appendChild(prevBtn);
        paginationBox.appendChild(info);
        paginationBox.appendChild(nextBtn);
    }

    function initAll() {
        document.querySelectorAll('[data-public-table-view]').forEach(function (root) {
            renderPagination(root);
        });
    }

    document.addEventListener('click', function (e) {
        var prevBtn = e.target.closest('[data-table-view-page-prev]');
        var nextBtn = e.target.closest('[data-table-view-page-next]');

        if (!prevBtn && !nextBtn) {
            return;
        }

        var root = e.target.closest('[data-public-table-view]');

        if (!root) {
            return;
        }

        e.preventDefault();

        var currentPage = Number(root.getAttribute('data-table-view-current-page') || 1);

        if (!Number.isFinite(currentPage) || currentPage < 1) {
            currentPage = 1;
        }

        if (prevBtn) {
            currentPage -= 1;
        }

        if (nextBtn) {
            currentPage += 1;
        }

        root.setAttribute('data-table-view-current-page', String(currentPage));

        renderPagination(root);
    }, true);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();