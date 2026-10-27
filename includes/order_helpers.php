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

    ensureStoreTriggers($pdo);
}

/** Installs inventory and price-history triggers used by checkout and product edits. */
function ensureStoreTriggers(PDO $pdo): void
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

    $stmt = $pdo->query(
        "SELECT TRIGGER_NAME, ACTION_STATEMENT
         FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = DATABASE()
           AND TRIGGER_NAME IN (
               'trg_request_items_before_insert',
               'trg_request_items_after_insert',
               'trg_products_after_update_price'
           )"
    );
    $existingTriggers = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $trigger) {
        $existingTriggers[$trigger['TRIGGER_NAME']] = $trigger['ACTION_STATEMENT'];
    }

    foreach (['trg_request_items_before_insert', 'trg_request_items_after_insert'] as $triggerName) {
        if (isset($existingTriggers[$triggerName]) && stripos($existingTriggers[$triggerName], 'stock') === false) {
            $pdo->exec('DROP TRIGGER `' . $triggerName . '`');
            unset($existingTriggers[$triggerName]);
        }
    }

    if (!isset($existingTriggers['trg_request_items_before_insert'])) {
        $pdo->exec(
            "CREATE TRIGGER trg_request_items_before_insert
             BEFORE INSERT ON request_items
             FOR EACH ROW
             BEGIN
                 DECLARE available_stock INT DEFAULT NULL;
                 DECLARE CONTINUE HANDLER FOR NOT FOUND SET available_stock = NULL;

                 IF NEW.quantity <= 0 THEN
                     SIGNAL SQLSTATE '45000'
                         SET MESSAGE_TEXT = 'Order quantity must be at least 1';
                 END IF;

                 SELECT stock INTO available_stock
                 FROM products
                 WHERE id = NEW.product_id
                 FOR UPDATE;

                 IF available_stock IS NULL OR available_stock < NEW.quantity THEN
                     SIGNAL SQLSTATE '45000'
                         SET MESSAGE_TEXT = 'Not enough stock for this product';
                 END IF;
             END"
        );
    }

    if (!isset($existingTriggers['trg_request_items_after_insert'])) {
        $pdo->exec(
            "CREATE TRIGGER trg_request_items_after_insert
             AFTER INSERT ON request_items
             FOR EACH ROW
             UPDATE products
             SET stock = stock - NEW.quantity
             WHERE id = NEW.product_id"
        );
    }

    if (!isset($existingTriggers['trg_products_after_update_price'])) {
        $pdo->exec(
            "CREATE TRIGGER trg_products_after_update_price
             AFTER UPDATE ON products
             FOR EACH ROW
             BEGIN
                 IF NEW.price <> OLD.price THEN
                     INSERT INTO product_price_history (product_id, old_price, new_price)
                     VALUES (OLD.id, OLD.price, NEW.price);
                 END IF;
             END"
        );
    }
}
