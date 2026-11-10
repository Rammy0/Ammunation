(function () {
    var bars = Array.prototype.slice.call(document.querySelectorAll('.report-chart-bar[data-chart-width]'));
    var donuts = Array.prototype.slice.call(document.querySelectorAll('.report-chart-donut'));
    var reduceMotion = window.matchMedia
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (reduceMotion) {
        bars.forEach(function (bar) {
            bar.classList.add('is-visible');
        });
        donuts.forEach(function (donut) {
            donut.classList.add('is-visible');
        });
        return;
    }

    donuts.forEach(function (donut, index) {
        donut.style.setProperty('--chart-delay', (index * 100) + 'ms');
        donut.classList.add('is-visible');
    });

    function reveal(bar) {
        var width = Number(bar.getAttribute('data-chart-width'));
        if (!Number.isFinite(width)) return;
        bar.style.setProperty('--chart-width', Math.max(0, Math.min(100, width)) + '%');
        requestAnimationFrame(function () {
            bar.classList.add('is-visible');
        });
    }

    if (!('IntersectionObserver' in window)) {
        bars.forEach(reveal);
        return;
    }

    var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                reveal(entry.target);
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.15 });

    bars.forEach(function (bar) {
        observer.observe(bar);
    });
})();
