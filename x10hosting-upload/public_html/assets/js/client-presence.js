(function () {
    var body = document.body;
    var token = body ? body.getAttribute('data-auth-context') : '';
    if (!token) return;

    var heartbeatUrl = new URL('client_heartbeat.php', window.location.href);
    heartbeatUrl.searchParams.set('_ctx', token);

    function sendHeartbeat() {
        fetch(heartbeatUrl.href, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).catch(function (error) {
            console.error('Unable to update client presence:', error);
        });
    }

    sendHeartbeat();
    window.setInterval(sendHeartbeat, 30 * 1000);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) sendHeartbeat();
    });
})();
