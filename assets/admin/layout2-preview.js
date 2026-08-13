/* =========================================================
   SITEBUILDER / LAYOUT 2.0 / STAGE 3
   Real public preview inside the layout editor.
   ========================================================= */
(function () {
    'use strict';

    var config =
        window.SB_LAYOUT_CONFIG
        || {};

    var panel =
        document.getElementById(
            'layoutRealPreviewPanel'
        );

    if (
        !panel
        || !config.apiUrl
        || !config.siteId
    ) {
        return;
    }

    var SITE_ID =
        Number(
            config.siteId || 0
        );

    var DEVICE_WIDTHS = {
        desktop: 1280,
        tablet: 768,
        mobile: 390
    };

    var state = {
        pages: [],
        pageId: 0,
        device: 'desktop',
        timer: 0,
        serial: 0,
        loadedUrl: '',
        loadingPages: false
    };

    function node(id) {
        return document.getElementById(
            id
        );
    }

    function clamp(
        value,
        min,
        max
    ) {
        value =
            Number(value);

        if (!isFinite(value)) {
            value = min;
        }

        return Math.max(
            min,
            Math.min(
                max,
                value
            )
        );
    }

    function escapeHtml(value) {
        return String(
            value == null
                ? ''
                : value
        ).replace(
            /[&<>"']/g,
            function (char) {
                return {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;'
                }[char];
            }
        );
    }

    function requestBody(
        action,
        data
    ) {
        var body =
            new URLSearchParams();

        body.set(
            'action',
            action
        );

        body.set(
            'sessid',
            String(
                config.sessid || ''
            )
        );

        Object.keys(
            data || {}
        ).forEach(
            function (key) {
                var value =
                    data[key];

                if (
                    value === undefined
                    || value === null
                ) {
                    return;
                }

                body.set(
                    key,
                    String(value)
                );
            }
        );

        return body;
    }

    async function api(
        action,
        data
    ) {
        var response =
            await fetch(
                config.apiUrl,
                {
                    method: 'POST',
                    credentials:
                        'same-origin',
                    headers: {
                        'Content-Type':
                            'application/x-www-form-urlencoded;charset=UTF-8',
                        'X-Requested-With':
                            'XMLHttpRequest'
                    },
                    body:
                        requestBody(
                            action,
                            data
                        ).toString()
                }
            );

        var payload =
            await response.json();

        if (
            !response.ok
            || !payload
            || payload.ok !== true
        ) {
            throw new Error(
                String(
                    payload
                    && payload.error
                    || 'API_ERROR'
                )
            );
        }

        return payload;
    }

    function pageMap() {
        var map = {};

        state.pages.forEach(
            function (page) {
                var id =
                    Number(
                        page.id || 0
                    );

                if (id > 0) {
                    map[id] = page;
                }
            }
        );

        return map;
    }

    function sortedChildren(
        parentId
    ) {
        return state.pages
            .filter(
                function (page) {
                    return Number(
                        page.parentId
                        || 0
                    ) === Number(
                        parentId || 0
                    );
                }
            )
            .sort(
                function (a, b) {
                    var bySort =
                        Number(
                            a.sort || 500
                        )
                        - Number(
                            b.sort || 500
                        );

                    return bySort !== 0
                        ? bySort
                        : Number(
                            a.id || 0
                        )
                        - Number(
                            b.id || 0
                        );
                }
            );
    }

    function flattenPages() {
        var result = [];
        var visited = {};

        function walk(
            parentId,
            depth
        ) {
            sortedChildren(
                parentId
            ).forEach(
                function (page) {
                    var id =
                        Number(
                            page.id || 0
                        );

                    if (
                        id <= 0
                        || visited[id]
                    ) {
                        return;
                    }

                    visited[id] =
                        true;

                    result.push({
                        page: page,
                        depth:
                            Math.max(
                                0,
                                depth
                            )
                    });

                    walk(
                        id,
                        depth + 1
                    );
                }
            );
        }

        walk(0, 0);

        /*
         * Keep malformed/orphaned historical pages selectable instead of
         * silently dropping them from the preview toolbar.
         */
        state.pages
            .slice()
            .sort(
                function (a, b) {
                    return Number(
                        a.id || 0
                    ) - Number(
                        b.id || 0
                    );
                }
            )
            .forEach(
                function (page) {
                    var id =
                        Number(
                            page.id || 0
                        );

                    if (
                        id > 0
                        && !visited[id]
                    ) {
                        visited[id] =
                            true;

                        result.push({
                            page: page,
                            depth: 0
                        });
                    }
                }
            );

        return result;
    }

    function pageById(pageId) {
        pageId =
            Number(
                pageId || 0
            );

        return state.pages.find(
            function (page) {
                return Number(
                    page.id || 0
                ) === pageId;
            }
        ) || null;
    }

    function savedPageId() {
        try {
            return Number(
                sessionStorage.getItem(
                    'sb-layout-preview-page-'
                    + SITE_ID
                )
                || 0
            );
        } catch (error) {
            return 0;
        }
    }

    function persistPageId(pageId) {
        try {
            sessionStorage.setItem(
                'sb-layout-preview-page-'
                + SITE_ID,
                String(
                    Number(
                        pageId || 0
                    )
                )
            );
        } catch (error) {
            /* Storage is optional. */
        }
    }

    function chooseDefaultPage() {
        var candidates = [
            savedPageId(),
            Number(
                config.homePageId
                || 0
            )
        ];

        for (
            var i = 0;
            i < candidates.length;
            i++
        ) {
            var page =
                pageById(
                    candidates[i]
                );

            if (
                page
                && !page.navigationOnly
            ) {
                return Number(
                    page.id || 0
                );
            }
        }

        var first =
            state.pages.find(
                function (page) {
                    return (
                        Number(
                            page.id || 0
                        ) > 0
                        && !page.navigationOnly
                    );
                }
            );

        return first
            ? Number(
                first.id || 0
            )
            : 0;
    }

    function pageStatusLabel(page) {
        if (!page) {
            return '';
        }

        return String(
            page.status || 'draft'
        ) === 'published'
            ? 'Опубликована'
            : 'Черновик';
    }

    function renderPages() {
        var select =
            node(
                'layoutPreviewPage'
            );

        if (!select) {
            return;
        }

        var flattened =
            flattenPages();

        if (!flattened.length) {
            select.innerHTML =
                '<option value="0">Страниц нет</option>';

            select.disabled =
                true;

            state.pageId = 0;

            updatePageBadge();

            return;
        }

        select.disabled =
            false;

        select.innerHTML =
            flattened
                .map(
                    function (item) {
                        var page =
                            item.page;
                        var id =
                            Number(
                                page.id || 0
                            );
                        var prefix =
                            item.depth > 0
                                ? new Array(
                                    item.depth + 1
                                ).join('— ')
                                : '';
                        var status =
                            pageStatusLabel(
                                page
                            );

                        return ''
                            + '<option value="'
                            + id
                            + '"'
                            + (
                                page.navigationOnly
                                    ? ' disabled'
                                    : ''
                            )
                            + '>'
                            + escapeHtml(
                                prefix
                                + String(
                                    page.title
                                    || (
                                        'Страница #'
                                        + id
                                    )
                                )
                                + ' · '
                                + status
                            )
                            + '</option>';
                    }
                )
                .join('');

        if (
            !pageById(
                state.pageId
            )
            || (
                pageById(
                    state.pageId
                )
                && pageById(
                    state.pageId
                ).navigationOnly
            )
        ) {
            state.pageId =
                chooseDefaultPage();
        }

        if (state.pageId > 0) {
            select.value =
                String(
                    state.pageId
                );
        }

        updatePageBadge();
    }

    function updatePageBadge() {
        var page =
            pageById(
                state.pageId
            );

        var badge =
            node(
                'layoutPreviewPageStatus'
            );

        if (!badge) {
            return;
        }

        if (!page) {
            badge.textContent =
                'Нет страницы';

            badge.setAttribute(
                'data-status',
                'none'
            );

            return;
        }

        var status =
            String(
                page.status
                || 'draft'
            );

        badge.textContent =
            pageStatusLabel(
                page
            );

        badge.setAttribute(
            'data-status',
            status
        );
    }

    function currentSettings() {
        var showHeader =
            node(
                'showHeader'
            );
        var showFooter =
            node(
                'showFooter'
            );
        var showLeft =
            node(
                'showLeft'
            );
        var showRight =
            node(
                'showRight'
            );
        var leftWidth =
            node(
                'leftWidth'
            );
        var rightWidth =
            node(
                'rightWidth'
            );
        var leftMode =
            node(
                'leftMode'
            );

        return {
            showHeader:
                !!(
                    showHeader
                    && showHeader.checked
                ),
            showFooter:
                !!(
                    showFooter
                    && showFooter.checked
                ),
            showLeft:
                !!(
                    showLeft
                    && showLeft.checked
                ),
            showRight:
                !!(
                    showRight
                    && showRight.checked
                ),
            leftWidth:
                clamp(
                    leftWidth
                    ? leftWidth.value
                    : 260,
                    120,
                    800
                ),
            rightWidth:
                clamp(
                    rightWidth
                    ? rightWidth.value
                    : 260,
                    120,
                    800
                ),
            leftMode:
                leftMode
                && String(
                    leftMode.value
                    || ''
                ) === 'menu'
                    ? 'menu'
                    : 'blocks'
        };
    }

    function previewUrl() {
        if (state.pageId <= 0) {
            return '';
        }

        var settings =
            currentSettings();

        var params =
            new URLSearchParams();

        params.set(
            'siteId',
            String(SITE_ID)
        );

        params.set(
            'pageId',
            String(
                state.pageId
            )
        );

        params.set(
            'showHeader',
            settings.showHeader
                ? '1'
                : '0'
        );

        params.set(
            'showFooter',
            settings.showFooter
                ? '1'
                : '0'
        );

        params.set(
            'showLeft',
            settings.showLeft
                ? '1'
                : '0'
        );

        params.set(
            'showRight',
            settings.showRight
                ? '1'
                : '0'
        );

        params.set(
            'leftWidth',
            String(
                settings.leftWidth
            )
        );

        params.set(
            'rightWidth',
            String(
                settings.rightWidth
            )
        );

        params.set(
            'leftMode',
            settings.leftMode
        );

        params.set(
            '_preview',
            String(
                ++state.serial
            )
        );

        return String(
            config.previewUrl
            || (
                String(
                    config.basePath
                    || ''
                )
                + '/layout_preview.php'
            )
        )
            + '?'
            + params.toString();
    }

    function updatePreviewNote() {
        var note =
            node(
                'layoutPreviewNote'
            );

        if (!note) {
            return;
        }

        var saveState =
            node(
                'layoutSaveState'
            );

        var hasDraftSettings =
            !!(
                saveState
                && saveState.getAttribute(
                    'data-state'
                ) === 'dirty'
            );

        var blockState =
            node(
                'layoutBlockState'
            );

        var hasDraftBlock =
            !!(
                blockState
                && blockState.getAttribute(
                    'data-state'
                ) === 'dirty'
            );

        if (
            hasDraftSettings
            && hasDraftBlock
        ) {
            note.textContent =
                'Черновые параметры каркаса показаны сразу. Несохранённые изменения layout-блока появятся после «Сохранить блок».';
        } else if (
            hasDraftSettings
        ) {
            note.textContent =
                'Предпросмотр использует текущие несохранённые параметры каркаса. На сайте они изменятся только после «Сохранить каркас».';
        } else if (
            hasDraftBlock
        ) {
            note.textContent =
                'Предпросмотр показывает сохранённую версию выбранного layout-блока. Сохраните блок, чтобы обновить preview.';
        } else {
            note.textContent =
                'Это тот же публичный рендерер. Ссылки и формы внутри preview отключены.';
        }
    }

    function setLoading(loading) {
        var loadingNode =
            node(
                'layoutPreviewLoading'
            );
        var iframe =
            node(
                'layoutPreviewFrame'
            );

        if (loadingNode) {
            loadingNode.hidden =
                !loading;
        }

        if (iframe) {
            iframe.classList.toggle(
                'is-loading',
                !!loading
            );
        }
    }

    function refreshPreview(
        force
    ) {
        window.clearTimeout(
            state.timer
        );

        if (state.pageId <= 0) {
            var iframe =
                node(
                    'layoutPreviewFrame'
                );

            if (iframe) {
                iframe.hidden =
                    true;
            }

            var empty =
                node(
                    'layoutPreviewEmpty'
                );

            if (empty) {
                empty.hidden =
                    false;
            }

            updatePageBadge();
            updatePreviewNote();

            return;
        }

        var emptyNode =
            node(
                'layoutPreviewEmpty'
            );

        if (emptyNode) {
            emptyNode.hidden =
                true;
        }

        var url =
            previewUrl();

        if (!url) {
            return;
        }

        var iframeNode =
            node(
                'layoutPreviewFrame'
            );

        var openLink =
            node(
                'layoutPreviewOpen'
            );

        if (openLink) {
            openLink.href =
                url;
        }

        if (
            !force
            && state.loadedUrl
            && state.loadedUrl
                .replace(
                    /&_preview=\d+$/,
                    ''
                )
                === url.replace(
                    /&_preview=\d+$/,
                    ''
                )
        ) {
            updatePreviewNote();
            return;
        }

        state.loadedUrl =
            url;

        setLoading(true);

        iframeNode.hidden =
            false;
        iframeNode.src =
            url;

        updatePageBadge();
        updatePreviewNote();
    }

    function schedulePreview(
        delay
    ) {
        window.clearTimeout(
            state.timer
        );

        state.timer =
            window.setTimeout(
                function () {
                    refreshPreview(
                        true
                    );
                },
                Math.max(
                    0,
                    Number(
                        delay || 0
                    )
                )
            );

        updatePreviewNote();
    }

    function setDevice(device) {
        if (
            !Object.prototype
                .hasOwnProperty.call(
                    DEVICE_WIDTHS,
                    device
                )
        ) {
            device =
                'desktop';
        }

        state.device =
            device;

        var viewport =
            node(
                'layoutPreviewViewport'
            );

        var frameShell =
            node(
                'layoutPreviewFrameShell'
            );

        if (viewport) {
            viewport.setAttribute(
                'data-device',
                device
            );
        }

        if (frameShell) {
            frameShell.style.width =
                DEVICE_WIDTHS[
                    device
                ]
                + 'px';
        }

        document
            .querySelectorAll(
                '[data-layout-preview-device]'
            )
            .forEach(
                function (button) {
                    button.classList
                        .toggle(
                            'is-active',
                            button
                                .getAttribute(
                                    'data-layout-preview-device'
                                )
                                === device
                        );
                }
            );

        var size =
            node(
                'layoutPreviewSize'
            );

        if (size) {
            size.textContent =
                DEVICE_WIDTHS[
                    device
                ]
                + ' px';
        }
    }

    async function loadPages() {
        if (state.loadingPages) {
            return;
        }

        state.loadingPages =
            true;

        var select =
            node(
                'layoutPreviewPage'
            );

        if (select) {
            select.disabled =
                true;
        }

        try {
            var response =
                await api(
                    'page.list',
                    {
                        siteId:
                            SITE_ID
                    }
                );

            state.pages =
                Array.isArray(
                    response.pages
                )
                    ? response.pages
                    : [];

            state.pageId =
                chooseDefaultPage();

            renderPages();
            refreshPreview(true);
        } catch (error) {
            console.error(error);

            state.pages = [];
            state.pageId = 0;

            if (select) {
                select.innerHTML =
                    '<option value="0">Не удалось загрузить страницы</option>';
            }

            var empty =
                node(
                    'layoutPreviewEmpty'
                );

            if (empty) {
                empty.hidden =
                    false;
                empty.textContent =
                    'Не удалось загрузить список страниц для предпросмотра.';
            }
        } finally {
            state.loadingPages =
                false;

            if (
                select
                && state.pages.length
            ) {
                select.disabled =
                    false;
            }
        }
    }

    node(
        'layoutPreviewFrame'
    ).addEventListener(
        'load',
        function () {
            setLoading(false);

            var loaded =
                node(
                    'layoutPreviewLoaded'
                );

            if (loaded) {
                loaded.textContent =
                    'Обновлено '
                    + new Date()
                        .toLocaleTimeString(
                            'ru-RU',
                            {
                                hour: '2-digit',
                                minute:
                                    '2-digit',
                                second:
                                    '2-digit'
                            }
                        );
            }
        }
    );

    node(
        'layoutPreviewPage'
    ).addEventListener(
        'change',
        function () {
            state.pageId =
                Number(
                    this.value || 0
                );

            persistPageId(
                state.pageId
            );

            updatePageBadge();
            refreshPreview(true);
        }
    );

    node(
        'layoutPreviewReload'
    ).addEventListener(
        'click',
        function () {
            refreshPreview(true);
        }
    );

    document.addEventListener(
        'click',
        function (event) {
            var device =
                event.target.closest(
                    '[data-layout-preview-device]'
                );

            if (device) {
                event.preventDefault();

                setDevice(
                    String(
                        device.getAttribute(
                            'data-layout-preview-device'
                        )
                        || 'desktop'
                    )
                );

                return;
            }

            /*
             * The main layout script handles this button first and updates
             * the hidden #leftMode. A short debounce ensures preview reads
             * the resulting value, not the previous one.
             */
            if (
                event.target.closest(
                    '[data-left-mode]'
                )
            ) {
                schedulePreview(220);
            }
        }
    );

    document.addEventListener(
        'change',
        function (event) {
            if (
                event.target.matches(
                    '#showHeader,'
                    + '#showFooter,'
                    + '#showLeft,'
                    + '#showRight,'
                    + '#leftWidth,'
                    + '#rightWidth'
                )
            ) {
                schedulePreview(180);
            }
        }
    );

    document.addEventListener(
        'input',
        function (event) {
            if (
                event.target.matches(
                    '#leftWidthRange,'
                    + '#rightWidthRange'
                )
            ) {
                /*
                 * Slider movement can emit dozens of input events.
                 * Preview reload is deliberately debounced and is not a save.
                 */
                schedulePreview(420);
            }

            if (
                event.target.closest(
                    '#layoutBlockFields,'
                    + '#blockAdvancedContent,'
                    + '#blockAdvancedProps'
                )
            ) {
                updatePreviewNote();
            }
        }
    );

    var versionBadge =
        node(
            'layoutVersionBadge'
        );

    if (
        versionBadge
        && typeof MutationObserver
            !== 'undefined'
    ) {
        new MutationObserver(
            function () {
                /*
                 * Block create/save/delete/DnD and layout settings save all
                 * advance the layout version. Refreshing here keeps Stage 3
                 * independent from private state inside layout2.js.
                 */
                schedulePreview(160);
            }
        ).observe(
            versionBadge,
            {
                childList: true,
                characterData: true,
                subtree: true
            }
        );
    }

    var saveState =
        node(
            'layoutSaveState'
        );

    if (
        saveState
        && typeof MutationObserver
            !== 'undefined'
    ) {
        new MutationObserver(
            updatePreviewNote
        ).observe(
            saveState,
            {
                attributes: true,
                childList: true,
                subtree: true
            }
        );
    }

    var blockState =
        node(
            'layoutBlockState'
        );

    if (
        blockState
        && typeof MutationObserver
            !== 'undefined'
    ) {
        new MutationObserver(
            updatePreviewNote
        ).observe(
            blockState,
            {
                attributes: true,
                childList: true,
                subtree: true
            }
        );
    }

    setDevice('desktop');
    updatePreviewNote();
    loadPages();

    window.SBLayoutPreview = {
        refresh: function () {
            refreshPreview(true);
        },
        setDevice: setDevice
    };
})();
