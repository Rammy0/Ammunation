</main>

<?php
$adminContextToken = null;
foreach ($_SESSION['auth_contexts'] ?? [] as $contextToken => $authContext) {
    if (
        !empty($authContext['is_admin'])
        && !empty($authContext['user_id'])
        && preg_match('/^[a-f0-9]{64}$/', (string)$contextToken) === 1
    ) {
        $adminContextToken = (string)$contextToken;
        break;
    }
}
$adminLink = $adminContextToken !== null
    ? 'admin/admin.php?_ctx=' . rawurlencode($adminContextToken) . '&new_context=1'
    : withAuthContext('admin/login.php');
?>

<footer class="site-footer">
    <!-- <div class="container d-flex flex-column flex-md-row justify-content-between align-items-center gap-2"> -->
        <center><span>&copy; <?php echo date('Y'); ?> Ammunation|<a class="mt-4 small text-muted" href="<?php echo htmlspecialchars($adminLink, ENT_QUOTES, 'UTF-8'); ?>">Admin</a></span></center>
        <!-- <div> -->
            <!-- <span class="footer-separator">|</span> -->
            
        <!-- </div> -->
    <!-- </div> -->
</footer>

<script src="assets/css/bootstrap-5.3.8-dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/jquery-4.0.0.min.js"></script>
<script src="assets/js/site.js?v=20261011"></script>
</body>
</html>
