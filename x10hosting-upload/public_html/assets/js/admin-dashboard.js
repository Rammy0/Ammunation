$(function () {
    $('.admin-report-bar[data-chart-width]').each(function () {
        var $bar = $(this);
        var width = Number($bar.attr('data-chart-width'));

        if (!Number.isFinite(width)) {
            return;
        }

        width = Math.max(0, Math.min(100, width));
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            $bar.css('width', width + '%');
            return;
        }

        $bar.css('width', '0%').animate({ width: width + '%' }, 700);
    });

    $('.best-seller-pie[data-pie-segments]').each(function () {
        var $pie = $(this);
        var segments;

        try {
            segments = JSON.parse($pie.attr('data-pie-segments'));
        } catch (error) {
            return;
        }

        if (!Array.isArray(segments) || segments.length === 0) {
            return;
        }

        var total = segments.reduce(function (sum, segment) {
            return sum + Math.max(0, Number(segment.units) || 0);
        }, 0);
        if (total <= 0) {
            return;
        }

        function gradient(progress) {
            var stops = [];
            var angle = 0;
            segments.forEach(function (segment) {
                var units = Math.max(0, Number(segment.units) || 0);
                var color = /^#[0-9a-f]{6}$/i.test(segment.color) ? segment.color : '#69737f';
                if (units === 0) {
                    return;
                }

                var nextAngle = angle + (units / total) * 360 * progress;
                stops.push(color + ' ' + angle + 'deg ' + nextAngle + 'deg');
                angle = nextAngle;
            });

            if (progress < 1) {
                stops.push('#30291d ' + angle + 'deg 360deg');
            }
            return 'conic-gradient(from -90deg, ' + stops.join(', ') + ')';
        }

        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            $pie.css('background', gradient(1));
            return;
        }

        $pie.css('background', gradient(0)).animate({ chartProgress: 1 }, {
            duration: 700,
            step: function (progress) {
                $pie.css('background', gradient(progress));
            }
        });
    });
});
