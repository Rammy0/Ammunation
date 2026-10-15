<?php

/** Applies additive schema updates needed by customer checkout and order tracking. */
function ensureOrderSchema(PDO $pdo): void
{
    if (!$pdo->query("SHOW TABLES LIKE 'requests'")->fetchColumn()
        || !$pdo->query("SHOW TABLES LIKE 'request_items'")->fetchColumn()) {
        throw new RuntimeException('The requests and request_items tables are required.');
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
}
