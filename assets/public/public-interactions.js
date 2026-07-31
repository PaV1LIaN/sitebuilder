(function () {
    'use strict';

    var items = Array.prototype.slice.call(document.querySelectorAll('[data-sb-animate]'));
    if (!items.length) return;

    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduceMotion || !('IntersectionObserver' in window)) {
        items.forEach(function (node) { node.classList.add('is-visible'); });
        return;
    }

    document.documentElement.classList.add('sb-motion-ready');
    document.body.classList.add('sb-motion-ready');

    var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (!entry.isIntersecting) return;
            entry.target.classList.add('is-visible');
            observer.unobserve(entry.target);
        });
    }, {
        root: null,
        rootMargin: '0px 0px -8% 0px',
        threshold: 0.08
    });

    items.forEach(function (node) {
        observer.observe(node);
    });
})();
