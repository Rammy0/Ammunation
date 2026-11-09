<?php
require_once __DIR__ . '/includes/auth.php';
$timedOutAdmin = ($_GET['reason'] ?? '') === 'idle' && !empty($_SESSION['is_admin']);
$destination = $timedOutAdmin ? 'admin/login.php?session_expired=1' : 'index.php';
$homeUrl = withAuthContext($destination);
logout();
header('Location: ' . $homeUrl);
exit;
?>