/* =========================================================
   SITEBUILDER EDITOR WORKSPACE / STAGE 21
   Canvas zoom and fit controls.
   ========================================================= */
(function () {
    'use strict';

    if (!window.SB_EDITOR_CONFIG) {
        return;
    }

    var viewport = document.getElementById('editorViewport');
    var canvasBody = document.getElementById('editorCanvasBody');
    var zoomValue = document.getElementById('editorZoomValue');
    var zoomOut = document.getElementById('editorZoomOutBtn');
    var zoomIn = document.getElementById('editorZoomInBtn');
    var zoomReset = document.getElementById('editorZoomResetBtn');
    var zoomFit = document.getElementById('editorZoomFitBtn');

    if (
        !viewport
        || !canvasBody
        || !zoomValue
        || !zoomOut
        || !zoomIn
        || !zoomReset
        || !zoomFit
    ) {
        return;
    }

    var siteId = Number(
        window.SB_EDITOR_CONFIG.siteId || 0
    );
    var storageKey =
        'sitebuilder.editor.workspace.zoom.' + siteId;

    var state = {
        scale: 1,
        fit: false
    };

    function clamp(value, min, max) {
        return Math.max(
            min,
            Math.min(max, Number(value || 0))
        );
    }

    function readState() {
        try {
            var parsed = JSON.parse(
                window.localStorage.getItem(storageKey)
                || '{}'
            );

            if (parsed && typeof parsed === 'object') {
                state.scale = clamp(
                    parsed.scale || 1,
                    .5,
                    1.25
                );
                state.fit = !!parsed.fit;
            }
        } catch (error) {
            state.scale = 1;
            state.fit = false;
        }
    }

    function saveState() {
        try {
            window.localStorage.setItem(
                storageKey,
                JSON.stringify(state)
            );
        } catch (error) {
            // localStorage may be unavailable.
        }
    }

    function render() {
        var percent = Math.round(state.scale * 100);

        document.documentElement.style.setProperty(
            '--sb-editor-canvas-zoom',
            String(state.scale)
        );

        zoomValue.textContent = percent + '%';
        zoomValue.setAttribute(
            'aria-label',
            'Масштаб холста ' + percent + '%'
        );

        zoomFit.classList.toggle(
            'is-active',
            state.fit
        );
        zoomFit.setAttribute(
            'aria-pressed',
            state.fit ? 'true' : 'false'
        );

        zoomOut.disabled = state.scale <= .5;
        zoomIn.disabled = state.scale >= 1.25;
    }

    function currentNaturalWidth() {
        var rect = viewport.getBoundingClientRect();
        var scale = state.scale || 1;

        return Math.max(
            1,
            rect.width / scale
        );
    }

    function applyFit(save) {
        var available = Math.max(
            280,
            canvasBody.clientWidth - 64
        );
        var natural = currentNaturalWidth();

        state.scale = clamp(
            available / natural,
            .5,
            1
        );
        state.fit = true;

        render();

        if (save !== false) {
            saveState();
        }
    }

    function setScale(nextScale) {
        state.scale = clamp(nextScale, .5, 1.25);
        state.fit = false;
        render();
        saveState();
    }

    zoomOut.addEventListener('click', function () {
        setScale(state.scale - .1);
    });

    zoomIn.addEventListener('click', function () {
        setScale(state.scale + .1);
    });

    zoomReset.addEventListener('click', function () {
        setScale(1);
    });

    zoomValue.addEventListener('click', function () {
        setScale(1);
    });

    zoomFit.addEventListener('click', function () {
        applyFit(true);
    });

    document.querySelectorAll(
        '[data-preview-device]'
    ).forEach(function (button) {
        button.addEventListener('click', function () {
            if (!state.fit) {
                return;
            }

            window.setTimeout(function () {
                applyFit(true);
            }, 40);
        });
    });

    var resizeTimer = 0;

    window.addEventListener(
        'resize',
        function () {
            if (!state.fit) {
                return;
            }

            window.clearTimeout(resizeTimer);
            resizeTimer = window.setTimeout(
                function () {
                    applyFit(false);
                },
                80
            );
        },
        {passive: true}
    );

    if ('ResizeObserver' in window) {
        var observer = new ResizeObserver(function () {
            if (!state.fit) {
                return;
            }

            window.clearTimeout(resizeTimer);
            resizeTimer = window.setTimeout(
                function () {
                    applyFit(false);
                },
                60
            );
        });

        observer.observe(canvasBody);
    }

    readState();
    render();

    if (state.fit) {
        window.setTimeout(function () {
            applyFit(false);
        }, 60);
    }
})();
