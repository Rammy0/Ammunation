<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/store_helpers.php';
require_once __DIR__ . '/includes/order_helpers.php';
requireLogin();

if (isAdmin()) {
    header('Location: ' . withAuthContext('admin/orders.php'));
    exit;
}

$userId = (int)$_SESSION['user_id'];
$errors = [];
$success = '';
$schemaReady = false;
try {
    ensureOrderSchema($pdo);
    $schemaReady = true;
} catch (Throwable $e) {
    error_log('Unable to prepare customer order history: ' . $e->getMessage());
    $errors[] = 'Order history is currently unavailable.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $schemaReady) {
    $orderId = (int)($_POST['order_id'] ?? 0);
    if (!validateCsrfToken((string)($_POST['csrf_token'] ?? ''), 'cancel-order')) {
        http_response_code(400);
        $errors[] = 'This request expired. Reload the page and try again.';
    } elseif ($orderId <= 0) {
        $errors[] = 'Invalid order.';
    } else {
        try {
            requestOrderCancellation($pdo, $orderId, $userId);
            $success = 'Your cancellation request was sent to the admin for review.';
        } catch (DomainException $e) {
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            error_log('Unable to request customer order cancellation: ' . $e->getMessage());
            $errors[] = 'Unable to request cancellation right now.';
        }
    }
}

try {
    if (!$schemaReady) {
        $orders = [];
        $itemsByOrder = [];
    } else {
        $stmt = $pdo->prepare(
            'SELECT id, customer_name, contact_number, payment_method, total_amount,
                    delivery_address, status, cancellation_status, cancellation_requested_at, created_at
             FROM requests WHERE user_id = :user_id ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute(['user_id' => $userId]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $itemStmt = $pdo->prepare(
            'SELECT ri.request_id, ri.product_id,
                    COALESCE(ri.product_name, p.name, "Product") AS product_name,
                    p.image, ri.quantity, ri.price
             FROM request_items ri
             LEFT JOIN products p ON p.id = ri.product_id
             JOIN requests r ON r.id = ri.request_id
             WHERE r.user_id = :user_id
             ORDER BY ri.id'
        );
        $itemStmt->execute(['user_id' => $userId]);
        $itemsByOrder = [];
        foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $itemsByOrder[(int)$item['request_id']][] = $item;
        }
    }
} catch (Throwable $e) {
    error_log('Unable to load customer order history: ' . $e->getMessage());
    $orders = [];
    $itemsByOrder = [];
    $errors[] = 'Unable to load your orders right now.';
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container page-panel order-history-page mt-4 mb-4">
    <div class="text-center mb-4">
        <h1 class="mb-1">My orders</h1>
        <p class="mb-0">View your orders and track their status.</p>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endforeach; ?>
    <?php if ($success !== ''): ?>
        <div class="alert alert-success" role="status"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if (!$orders): ?>
        <div class="alert alert-info text-center">You have not placed any orders yet.</div>
        <div class="text-center"><a href="<?php echo htmlspecialchars(withAuthContext('products.php'), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-primary">Browse products</a></div>
    <?php else: ?>
        <div class="order-history-list">
            <?php foreach ($orders as $order): ?>
                <?php
                $orderId = (int)$order['id'];
                $status = (string)$order['status'];
                $cancellationRequested = ($order['cancellation_status'] ?? 'none') === 'requested';
                $displayStatus = $cancellationRequested ? 'Pending Review' : strtoupper($status);
                $statusClass = $cancellationRequested ? 'info' : ($status === 'completed' ? 'success' : ($status === 'cancelled' ? 'danger' : ($status === 'shipped' ? 'primary' : 'warning')));
                ?>
                <article class="card order-history-card mb-3">
                    <header class="order-history-header">
                        <div>
                            <h2 class="h5 mb-1">Order</h2>
                            <time class="small" datetime="<?php echo htmlspecialchars(date(DATE_ATOM, strtotime($order['created_at'])), ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($order['created_at'])), ENT_QUOTES, 'UTF-8'); ?>
                            </time>
                        </div>
                        <span class="badge text-bg-<?php echo $statusClass; ?>"><?php echo htmlspecialchars($displayStatus, ENT_QUOTES, 'UTF-8'); ?></span>
                    </header>
                    <div class="card-body">
                        <div class="order-history-items">
                            <?php foreach ($itemsByOrder[$orderId] ?? [] as $item): ?>
                                <div class="order-history-item">
                                    <img src="<?php echo htmlspecialchars(storeProductImageUrl($item), ENT_QUOTES, 'UTF-8'); ?>" alt="">
                                    <strong><?php echo htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <span><?php echo (int)$item['quantity']; ?> × ₱<?php echo number_format((float)$item['price'], 2); ?></span>
                                    <strong class="order-item-subtotal">₱<?php echo number_format((float)$item['price'] * (int)$item['quantity'], 2); ?></strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="order-history-footer">
                            <div class="small">
                                <div><strong>Delivery address:</strong> <?php echo htmlspecialchars($order['delivery_address'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <div><strong>Payment:</strong> <?php echo $order['payment_method'] === 'credit_card' ? 'Credit card' : 'Cash on delivery'; ?></div>
                                <div><strong>Contact:</strong> <?php echo htmlspecialchars($order['contact_number'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                            <div class="text-end">
                                <div class="fw-bold mb-2">Total: <span class="order-total">₱<?php echo number_format((float)$order['total_amount'], 2); ?></span></div>
                                <?php if (($order['cancellation_status'] ?? '') === 'rejected'): ?>
                                    <div class="small text-warning mb-2">Cancellation request rejected; your order remains active.</div>
                                <?php endif; ?>
                                <?php if (in_array($status, ['pending', 'approved', 'processing'], true)
                                    && ($order['cancellation_status'] ?? 'none') !== 'requested'): ?>
                                    <form method="post" action="<?php echo htmlspecialchars(withAuthContext('my_orders.php'), ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo csrfInputField('cancel-order'); ?>
                                        <?php echo authContextInputField(); ?>
                                        <input type="hidden" name="order_id" value="<?php echo $orderId; ?>">
                                        <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancellationConfirmModal">Request cancellation</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="cancellationConfirmModal" tabindex="-1" aria-labelledby="cancellationConfirmTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="cancellationConfirmTitle">Request order cancellation?</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                Your order will remain active until an admin reviews your request.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Keep order</button>
                <button type="button" class="btn btn-danger" id="confirmCancellationRequest">Send request</button>
            </div>
        </div>
    </div>
</div>
<script>
    (() => {
        const modal = document.getElementById('cancellationConfirmModal');
        const confirmButton = document.getElementById('confirmCancellationRequest');
        let cancellationForm = null;

        modal.addEventListener('show.bs.modal', (event) => {
            cancellationForm = event.relatedTarget.closest('form');
        });

        confirmButton.addEventListener('click', () => {
            if (cancellationForm) {
                cancellationForm.requestSubmit();
            }
        });
    })();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
