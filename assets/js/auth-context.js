(function () {
    var body = document.body;
    var token = body ? body.getAttribute('data-auth-context') : '';
    if (!token) return;

    function addContext(value) {
        try {
            var url = new URL(value, window.location.href);
            if (url.origin !== window.location.origin || url.hash && !url.pathname) return value;
            if (url.searchParams.has('new_context')) return url.href;
            if (url.searchParams.has('_ctx')) return url.href;
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

    var logoutDialog;
    var pendingLogoutUrl;

    function createLogoutDialog() {
        if (logoutDialog) return logoutDialog;

        logoutDialog = document.createElement('dialog');
        logoutDialog.className = 'logout-confirm-dialog';
        logoutDialog.setAttribute('aria-labelledby', 'logoutConfirmTitle');
        logoutDialog.innerHTML =
            '<div class="logout-confirm-content">' +
                '<h2 id="logoutConfirmTitle">Log out?</h2>' +
                '<p>Are you sure you want to log out?</p>' +
                '<div class="logout-confirm-actions">' +
                    '<button type="button" class="btn btn-outline-light" data-logout-cancel>Stay logged in</button>' +
                    '<button type="button" class="btn btn-danger" data-logout-confirm>Log out</button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(logoutDialog);

        logoutDialog.addEventListener('click', function (event) {
            if (event.target === logoutDialog || event.target.closest('[data-logout-cancel]')) {
                logoutDialog.close();
                return;
            }
            if (event.target.closest('[data-logout-confirm]') && pendingLogoutUrl) {
                window.location.assign(pendingLogoutUrl);
            }
        });
        logoutDialog.addEventListener('close', function () {
            pendingLogoutUrl = null;
        });

        return logoutDialog;
    }

    document.addEventListener('click', function (event) {
        var logoutLink = event.target.closest('[data-confirm-logout]');
        if (!logoutLink) return;

        event.preventDefault();
        pendingLogoutUrl = logoutLink.href;
        createLogoutDialog().showModal();
    });
})();
