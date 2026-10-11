(function () {
    var body = document.body;
    var token = body ? body.getAttribute('data-auth-context') : '';
    if (!token) return;

    function addContext(value) {
        try {
            var url = new URL(value, window.location.href);
            if (url.origin !== window.location.origin || url.hash && !url.pathname) return value;
            if (url.searchParams.has('new_context')) return url.href;
            url.searchParams.set('_ctx', token);
            return url.href;
        } catch (error) {
            return value;
        }
    }

    var currentUrl = new URL(window.location.href);
    if (currentUrl.searchParams.get('_ctx') !== token) {
        currentUrl.searchParams.delete('new_context');
        currentUrl.searchParams.set('_ctx', token);
        window.history.replaceState({}, '', currentUrl.href);
    }

    window.applyAuthContext = function (root) {
        root = root || document;
        root.querySelectorAll('a[href]').forEach(function (link) {
            var href = link.getAttribute('href');
            if (!href || href.charAt(0) === '#' || /^(mailto:|tel:|javascript:)/i.test(href)) return;
            link.setAttribute('href', addContext(href));
        });

        root.querySelectorAll('form').forEach(function (form) {
            if (!form.querySelector('input[name="_ctx"]')) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = '_ctx';
                input.value = token;
                form.appendChild(input);
            }

            var action = form.getAttribute('action');
            if (action) form.setAttribute('action', addContext(action));
        });
    };

    window.applyAuthContext(document);
})();
