<?php

/** Applies additive schema updates needed by customer checkout and order tracking. */
function ensureOrderSchema(PDO $pdo): void
{
    if (!$pdo->query("SHOW TABLES LIKE 'requests'")->fetchColumn()
        || !$pdo->query("SHOW TABLES LIKE 'request_items'")->fetchColumn()) {
        throw new RuntimeException('The requests and request_items tables are required.');
    }

    $productColumns = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('stock', $productColumns, true)) {
        $pdo->exec('ALTER TABLE products ADD COLUMN stock INT NOT NULL DEFAULT 0 AFTER price');
    }

    $requestColumns = $pdo->query('SHOW COLUMNS FROM requests')->fetchAll(PDO::FETCH_COLUMN);
    foreach ([
        'customer_name' => 'ALTER TABLE requests ADD COLUMN customer_name VARCHAR(100) NULL',
        'contact_number' => 'ALTER TABLE requests ADD COLUMN contact_number VARCHAR(30) NULL',
        'payment_method' => "ALTER TABLE requests ADD COLUMN payment_method ENUM('cash_on_delivery', 'credit_card') NULL",
        'cancellation_status' => "ALTER TABLE requests ADD COLUMN cancellation_status ENUM('none', 'requested', 'rejected', 'approved') NOT NULL DEFAULT 'none' AFTER status",
        'cancellation_requested_at' => 'ALTER TABLE requests ADD COLUMN cancellation_requested_at DATETIME NULL',
        'cancellation_reviewed_at' => 'ALTER TABLE requests ADD COLUMN cancellation_reviewed_at DATETIME NULL',
    ] as $column => $alterSql) {
        if (!in_array($column, $requestColumns, true)) {
            $pdo->exec($alterSql);
        }
    }

    $statusColumn = $pdo->query("SHOW COLUMNS FROM requests LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    $neededStatuses = ['processing', 'shipped'];
    if (!$statusColumn || array_filter($neededStatuses, static function (string $status) use ($statusColumn): bool {
        return strpos((string)$statusColumn['Type'], "'" . $status . "'") === false;
    })) {
        $pdo->exec(
            "ALTER TABLE requests MODIFY status
             ENUM('pending', 'approved', 'processing', 'shipped', 'completed', 'cancelled')
             NOT NULL DEFAULT 'pending'"
        );
    }

    $itemColumns = $pdo->query('SHOW COLUMNS FROM request_items')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('product_name', $itemColumns, true)) {
        $pdo->exec('ALTER TABLE request_items ADD COLUMN product_name VARCHAR(255) NULL AFTER product_id');
    }

    ensureProductPriceHistoryTable($pdo);
    removeLegacyStoreTriggers($pdo);
}

/** Creates the table used to record product price changes from PHP. */
function ensureProductPriceHistoryTable(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS product_price_history (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            product_id INT UNSIGNED NOT NULL,
            old_price DECIMAL(12,2) NOT NULL,
            new_price DECIMAL(12,2) NOT NULL,
            changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_product_price_history_product (product_id),
            KEY idx_product_price_history_changed (changed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

}

/** Removes the database triggers from the former SQL-based implementation. */
function removeLegacyStoreTriggers(PDO $pdo): void
{
    $stmt = $pdo->query(
        "SELECT TRIGGER_NAME
         FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = DATABASE()
           AND TRIGGER_NAME IN (
               'trg_request_items_before_insert',
               'trg_request_items_after_insert',
               'trg_products_after_update_price'
           )"
    );
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $triggerName) {
        $pdo->exec('DROP TRIGGER `' . $triggerName . '`');
    }
}

/** Validates and decrements product stock while holding a row lock in the order transaction. */
function decrementProductStock(PDO $pdo, int $productId, int $quantity): void
{
    if ($quantity <= 0) {
        throw new DomainException('Order quantity must be at least 1.');
    }

    $stmt = $pdo->prepare('SELECT stock FROM products WHERE id = :id FOR UPDATE');
    $stmt->execute(['id' => $productId]);
    $stock = $stmt->fetchColumn();
    if ($stock === false || (int)$stock < $quantity) {
        throw new DomainException('Not enough stock for this product.');
    }

    $stmt = $pdo->prepare('UPDATE products SET stock = stock - :quantity WHERE id = :id');
    $stmt->execute(['quantity' => $quantity, 'id' => $productId]);
}

/** Records a customer cancellation request without changing the current order status. */
function requestOrderCancellation(PDO $pdo, int $orderId, int $userId): void
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT status, cancellation_status
             FROM requests
             WHERE id = :id AND user_id = :user_id
             FOR UPDATE'
        );
        $stmt->execute(['id' => $orderId, 'user_id' => $userId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            throw new DomainException('Order not found.');
        }
        if (!in_array($order['status'], ['pending', 'approved', 'processing'], true)) {
            throw new DomainException('This order can no longer be cancelled.');
        }
        if ($order['cancellation_status'] === 'requested') {
            throw new DomainException('A cancellation request is already awaiting review.');
        }

        $stmt = $pdo->prepare(
            "UPDATE requests
             SET cancellation_status = 'requested',
                 cancellation_requested_at = UTC_TIMESTAMP(),
                 cancellation_reviewed_at = NULL
             WHERE id = :id"
        );
        $stmt->execute(['id' => $orderId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Approves or rejects a pending cancellation; approval also restores ordered stock. */
function reviewOrderCancellation(PDO $pdo, int $orderId, string $decision): void
{
    if (!in_array($decision, ['approve', 'reject'], true)) {
        throw new InvalidArgumentException('Invalid cancellation decision.');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT status, cancellation_status
             FROM requests
             WHERE id = :id
             FOR UPDATE'
        );
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order || $order['cancellation_status'] !== 'requested') {
            throw new DomainException('This cancellation request is no longer awaiting review.');
        }

        if ($decision === 'approve') {
            if (!in_array($order['status'], ['pending', 'approved', 'processing'], true)) {
                throw new DomainException('This order can no longer be cancelled.');
            }

            $itemStmt = $pdo->prepare(
                'SELECT product_id, SUM(quantity) AS quantity
                 FROM request_items
                 WHERE request_id = :request_id
                 GROUP BY product_id
                 ORDER BY product_id'
            );
            $itemStmt->execute(['request_id' => $orderId]);
            $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$items) {
                throw new RuntimeException('Cannot restore stock because the order has no items.');
            }
            $stockStmt = $pdo->prepare('UPDATE products SET stock = stock + :quantity WHERE id = :id');
            foreach ($items as $item) {
                if ((int)$item['quantity'] <= 0) {
                    throw new RuntimeException('Cannot restore stock for an invalid order quantity.');
                }
                $stockStmt->execute([
                    'quantity' => (int)$item['quantity'],
                    'id' => (int)$item['product_id'],
                ]);
                if ($stockStmt->rowCount() !== 1) {
                    throw new RuntimeException('Unable to restore stock for a cancelled order.');
                }
            }

            $stmt = $pdo->prepare(
                "UPDATE requests
                 SET status = 'cancelled',
                     cancellation_status = 'approved',
                     cancellation_reviewed_at = UTC_TIMESTAMP()
                 WHERE id = :id"
            );
        } else {
            $stmt = $pdo->prepare(
                "UPDATE requests
                 SET cancellation_status = 'rejected',
                     cancellation_reviewed_at = UTC_TIMESTAMP()
                 WHERE id = :id"
            );
        }
        $stmt->execute(['id' => $orderId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Records a price change within the caller's product-update transaction. */
function recordProductPriceChange(PDO $pdo, int $productId, $oldPrice, $newPrice): void
{
    if (number_format((float)$oldPrice, 2, '.', '') === number_format((float)$newPrice, 2, '.', '')) {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO product_price_history (product_id, old_price, new_price)
         VALUES (:product_id, :old_price, :new_price)'
    );
    $stmt->execute([
        'product_id' => $productId,
        'old_price' => $oldPrice,
        'new_price' => $newPrice,
    ]);
}
