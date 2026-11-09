(function () {
    var pendingLogoutUrl = '';
    var dialog = document.createElement('dialog');
    dialog.className = 'logout-confirm-dialog';
    dialog.setAttribute('aria-labelledby', 'logoutConfirmTitle');
    dialog.innerHTML =
        '<div class="logout-confirm-content">' +
            '<h2 id="logoutConfirmTitle">Log out?</h2>' +
            '<p>Are you sure you want to log out of your account?</p>' +
            '<div class="logout-confirm-actions">' +
                '<button type="button" class="btn btn-outline-light" data-logout-cancel>Stay logged in</button>' +
                '<button type="button" class="btn btn-danger" data-logout-confirm>Log out</button>' +
            '</div>' +
        '</div>';
    document.body.appendChild(dialog);

    document.addEventListener('click', function (event) {
        var link = event.target.closest('a[href]');
        if (!link) return;

        var url;
        try {
            url = new URL(link.href, window.location.href);
        } catch (error) {
            return;
        }
        if (!/(^|\/)logout\.php$/.test(url.pathname)) return;

        event.preventDefault();
        pendingLogoutUrl = url.href;
        dialog.showModal();
    });

    dialog.querySelector('[data-logout-cancel]').addEventListener('click', function () {
        dialog.close();
        pendingLogoutUrl = '';
    });

    dialog.querySelector('[data-logout-confirm]').addEventListener('click', function () {
        if (!pendingLogoutUrl) return;
        window.location.assign(pendingLogoutUrl);
    });

    dialog.addEventListener('close', function () {
        pendingLogoutUrl = '';
    });
})();
