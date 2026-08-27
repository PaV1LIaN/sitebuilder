(function () {
    'use strict';

    /* SiteBuilder public shell interactions v3 */

    function initMotion() {
        var items = Array.prototype.slice.call(
            document.querySelectorAll('[data-sb-animate]')
        );

        if (!items.length) {
            return;
        }

        var reduceMotion =
            window.matchMedia
            && window.matchMedia(
                '(prefers-reduced-motion: reduce)'
            ).matches;

        if (
            reduceMotion
            || !('IntersectionObserver' in window)
        ) {
            items.forEach(function (node) {
                node.classList.add('is-visible');
            });
            return;
        }

        document.documentElement.classList.add(
            'sb-motion-ready'
        );
        document.body.classList.add('sb-motion-ready');

        var observer = new IntersectionObserver(
            function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) {
                        return;
                    }

                    entry.target.classList.add('is-visible');
                    observer.unobserve(entry.target);
                });
            },
            {
                root: null,
                rootMargin: '0px 0px -8% 0px',
                threshold: 0.08
            }
        );

        items.forEach(function (node) {
            observer.observe(node);
        });
    }

    function pageIdFromLink(link) {
        if (!link) {
            return '';
        }

        try {
            return String(
                new URL(link.href, window.location.href)
                    .searchParams
                    .get('pageId') || ''
            );
        } catch (error) {
            return '';
        }
    }

    function nodePageId(node) {
        return pageIdFromLink(
            node.querySelector(
                ':scope > .sb-tree-node__row '
                + '.sb-section-nav__link'
            )
        );
    }

    function readStoredIds(key) {
        try {
            var raw = window.localStorage.getItem(key);

            if (raw === null) {
                return null;
            }

            var parsed = JSON.parse(raw);

            return Array.isArray(parsed)
                ? parsed.map(String)
                : null;
        } catch (error) {
            return null;
        }
    }

    function writeStoredIds(key, ids) {
        try {
            window.localStorage.setItem(
                key,
                JSON.stringify(ids)
            );
        } catch (error) {
            /* localStorage может быть отключён. */
        }
    }

    function directToggle(node) {
        return node.querySelector(
            ':scope > .sb-tree-node__row '
            + '[data-role="toggle"]'
        );
    }

    function syncToggle(node) {
        var toggle = directToggle(node);

        if (toggle) {
            toggle.setAttribute(
                'aria-expanded',
                node.classList.contains('is-open')
                    ? 'true'
                    : 'false'
            );
        }
    }

    function openActiveBranch(nav) {
        var activeLink = nav.querySelector(
            '.sb-section-nav__link.is-active'
        );

        if (!activeLink) {
            return;
        }

        var node = activeLink.closest('.sb-tree-node');

        while (node) {
            node.classList.add('is-open');
            syncToggle(node);

            var parent = node.parentElement;

            node = parent
                ? parent.closest('.sb-tree-node')
                : null;
        }
    }

    function initSectionTrees() {
        document.querySelectorAll('.sb-section-nav')
            .forEach(function (nav) {
                var rootLink = nav.querySelector(
                    '.sb-section-nav__root-link'
                );
                var siteId = String(
                    new URL(
                        window.location.href
                    ).searchParams.get('siteId') || '0'
                );
                var rootId =
                    pageIdFromLink(rootLink) || '0';
                var storageKey =
                    'sitebuilder:section-nav:'
                    + siteId
                    + ':'
                    + rootId;

                var nodes = Array.prototype.slice.call(
                    nav.querySelectorAll('.sb-tree-node')
                );
                var storedIds = readStoredIds(storageKey);

                if (storedIds !== null) {
                    nodes.forEach(function (node) {
                        var id = nodePageId(node);

                        node.classList.toggle(
                            'is-open',
                            id !== ''
                            && storedIds.indexOf(id) !== -1
                        );

                        syncToggle(node);
                    });
                }

                openActiveBranch(nav);

                function saveState() {
                    var ids = nodes
                        .filter(function (node) {
                            return node.classList.contains(
                                'is-open'
                            );
                        })
                        .map(nodePageId)
                        .filter(Boolean);

                    writeStoredIds(storageKey, ids);
                }

                nav.addEventListener(
                    'click',
                    function (event) {
                        var toggle = event.target.closest(
                            '[data-role="toggle"]'
                        );

                        if (!toggle || !nav.contains(toggle)) {
                            return;
                        }

                        event.preventDefault();
                        event.stopPropagation();

                        var node = toggle.closest(
                            '.sb-tree-node'
                        );

                        if (!node) {
                            return;
                        }

                        node.classList.toggle('is-open');
                        syncToggle(node);
                        saveState();
                    }
                );
            });
    }

    function initStickyHeader() {
        var header = document.querySelector(
            '[data-role="public-header"]'
        );

        if (!header) {
            document.documentElement.style.setProperty(
                '--sb-public-header-height',
                '0px'
            );
            return;
        }

        function syncHeight() {
            document.documentElement.style.setProperty(
                '--sb-public-header-height',
                Math.ceil(
                    header.getBoundingClientRect().height
                ) + 'px'
            );
        }

        function syncScrolled() {
            header.classList.toggle(
                'is-scrolled',
                window.scrollY > 6
            );
        }

        syncHeight();
        syncScrolled();

        window.addEventListener(
            'resize',
            syncHeight,
            {passive: true}
        );

        window.addEventListener(
            'scroll',
            syncScrolled,
            {passive: true}
        );

        if ('ResizeObserver' in window) {
            var observer = new ResizeObserver(syncHeight);
            observer.observe(header);
        }
    }

    function initMobileDrawer() {
        var drawer = document.querySelector(
            '[data-role="section-nav-drawer"]'
        );
        var openButton = document.querySelector(
            '[data-action="open-section-nav"]'
        );
        var backdrop = document.querySelector(
            '.sb-public-nav-backdrop'
        );

        if (!drawer || !openButton || !backdrop) {
            return;
        }

        var media = window.matchMedia(
            '(max-width: 1000px)'
        );
        var lastFocused = null;

        function focusableItems() {
            return Array.prototype.slice.call(
                drawer.querySelectorAll(
                    'a[href], button:not([disabled]), '
                    + '[tabindex]:not([tabindex="-1"])'
                )
            ).filter(function (node) {
                return !node.hidden
                    && node.offsetParent !== null;
            });
        }

        function syncAria() {
            drawer.setAttribute(
                'aria-hidden',
                media.matches
                && !drawer.classList.contains(
                    'is-mobile-open'
                )
                    ? 'true'
                    : 'false'
            );
        }

        function openDrawer() {
            if (!media.matches) {
                return;
            }

            lastFocused = document.activeElement;

            drawer.classList.add('is-mobile-open');
            backdrop.hidden = false;
            document.body.classList.add(
                'sb-section-nav-open'
            );
            openButton.setAttribute(
                'aria-expanded',
                'true'
            );

            syncAria();

            window.setTimeout(function () {
                var active = drawer.querySelector(
                    '.sb-section-nav__link.is-active'
                );
                var close = drawer.querySelector(
                    '[data-action="close-section-nav"]'
                );

                if (active) {
                    active.scrollIntoView({
                        block: 'center',
                        behavior: 'smooth'
                    });
                }

                if (close) {
                    close.focus();
                }
            }, 40);
        }

        function closeDrawer(restoreFocus) {
            drawer.classList.remove('is-mobile-open');
            backdrop.hidden = true;
            document.body.classList.remove(
                'sb-section-nav-open'
            );
            openButton.setAttribute(
                'aria-expanded',
                'false'
            );

            syncAria();

            if (
                restoreFocus
                && lastFocused
                && typeof lastFocused.focus === 'function'
            ) {
                lastFocused.focus();
            }
        }

        openButton.addEventListener('click', openDrawer);

        document.querySelectorAll(
            '[data-action="close-section-nav"]'
        ).forEach(function (button) {
            button.addEventListener(
                'click',
                function () {
                    closeDrawer(true);
                }
            );
        });

        drawer.addEventListener(
            'click',
            function (event) {
                if (
                    media.matches
                    && event.target.closest('a[href]')
                ) {
                    closeDrawer(false);
                }
            }
        );

        document.addEventListener(
            'keydown',
            function (event) {
                if (
                    event.key === 'Escape'
                    && drawer.classList.contains(
                        'is-mobile-open'
                    )
                ) {
                    event.preventDefault();
                    closeDrawer(true);
                    return;
                }

                if (
                    event.key !== 'Tab'
                    || !drawer.classList.contains(
                        'is-mobile-open'
                    )
                ) {
                    return;
                }

                var items = focusableItems();

                if (!items.length) {
                    event.preventDefault();
                    return;
                }

                var first = items[0];
                var last = items[items.length - 1];

                if (
                    event.shiftKey
                    && document.activeElement === first
                ) {
                    event.preventDefault();
                    last.focus();
                } else if (
                    !event.shiftKey
                    && document.activeElement === last
                ) {
                    event.preventDefault();
                    first.focus();
                }
            }
        );

        function onMediaChange() {
            if (!media.matches) {
                closeDrawer(false);
            } else {
                syncAria();
            }
        }

        if (typeof media.addEventListener === 'function') {
            media.addEventListener(
                'change',
                onMediaChange
            );
        } else if (typeof media.addListener === 'function') {
            media.addListener(onMediaChange);
        }

        syncAria();
    }

    initMotion();
    initSectionTrees();
    initStickyHeader();
    initMobileDrawer();
})();