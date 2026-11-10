(function () {
    var body = document.body;
    var token = body ? body.getAttribute('data-auth-context') : '';
    if (!token) return;

    var timeoutMs = 5 * 60 * 1000;
    var heartbeatIntervalMs = 30 * 1000;
    var storageKey = 'ammunation-admin-activity-' + token;
    var heartbeatUrl = new URL('heartbeat.php', window.location.href);
    var logoutUrl = new URL('../logout.php', window.location.href);
    var lastActivity = Date.now();
    var redirecting = false;
    var timeoutId;

    heartbeatUrl.searchParams.set('_ctx', token);
    logoutUrl.searchParams.set('_ctx', token);
    logoutUrl.searchParams.set('reason', 'idle');

    function readStoredActivity() {
        try {
            return Number(localStorage.getItem(storageKey)) || 0;
        } catch (error) {
            return 0;
        }
    }

    function storeActivity(timestamp) {
        try {
            localStorage.setItem(storageKey, String(timestamp));
        } catch (error) {
            // The in-memory timeout still works when storage is unavailable.
        }
    }

    function logOut() {
        if (redirecting) return;
        redirecting = true;
        window.location.replace(logoutUrl.href);
    }

    function scheduleTimeout() {
        window.clearTimeout(timeoutId);
        var storedActivity = readStoredActivity();
        if (storedActivity > lastActivity) {
            lastActivity = storedActivity;
        }
        var remaining = timeoutMs - (Date.now() - lastActivity);
        if (remaining <= 0) {
            logOut();
            return;
        }
        timeoutId = window.setTimeout(logOut, remaining);
    }

    function markActivity() {
        if (redirecting) return;
        var now = Date.now();
        if (now - Math.max(lastActivity, readStoredActivity()) >= timeoutMs) {
            logOut();
            return;
        }
        if (now - lastActivity < 1000) return;
        lastActivity = now;
        storeActivity(now);
        scheduleTimeout();
    }

    function sendHeartbeat() {
        if (redirecting) return;
        if (Date.now() - Math.max(lastActivity, readStoredActivity()) >= timeoutMs) {
            logOut();
            return;
        }
        fetch(heartbeatUrl.href, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(function (response) {
            if (response.status === 401) {
                logOut();
            }
        }).catch(function (error) {
            console.error('Unable to refresh admin session activity:', error);
        });
    }

    lastActivity = Date.now();
    storeActivity(lastActivity);
    scheduleTimeout();

    ['click', 'keydown', 'mousemove', 'pointerdown', 'scroll', 'touchstart', 'input'].forEach(function (eventName) {
        document.addEventListener(eventName, markActivity, { passive: true });
    });

    window.addEventListener('storage', function (event) {
        if (event.key === storageKey && event.newValue) {
            lastActivity = Math.max(lastActivity, Number(event.newValue) || 0);
            scheduleTimeout();
        }
    });

    window.setInterval(function () {
        var sharedActivity = readStoredActivity();
        if (sharedActivity > lastActivity) {
            lastActivity = sharedActivity;
            scheduleTimeout();
        }
        if (Date.now() - lastActivity < heartbeatIntervalMs) {
            sendHeartbeat();
        } else {
            scheduleTimeout();
        }
    }, heartbeatIntervalMs);
})();
