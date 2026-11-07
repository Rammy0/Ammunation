SET @product_stock_exists = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'products'
    AND COLUMN_NAME = 'stock'
);
SET @product_stock_migration = IF(
  @product_stock_exists = 0,
  'ALTER TABLE `products` ADD COLUMN `stock` INT NOT NULL DEFAULT 0 AFTER `price`',
  'SELECT 1'
);
PREPARE product_stock_stmt FROM @product_stock_migration;
EXECUTE product_stock_stmt;
DEALLOCATE PREPARE product_stock_stmt;

CREATE TABLE IF NOT EXISTS `product_price_history` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` INT UNSIGNED NOT NULL,
  `old_price` DECIMAL(12,2) NOT NULL,
  `new_price` DECIMAL(12,2) NOT NULL,
  `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_product_price_history_product` (`product_id`),
  KEY `idx_product_price_history_changed` (`changed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TRIGGER IF EXISTS `trg_request_items_before_insert`;
DROP TRIGGER IF EXISTS `trg_request_items_after_insert`;
DROP TRIGGER IF EXISTS `trg_products_after_update_price`;

DELIMITER $$

CREATE TRIGGER `trg_request_items_before_insert`
BEFORE INSERT ON `request_items`
FOR EACH ROW
BEGIN
  DECLARE available_stock INT DEFAULT NULL;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET available_stock = NULL;

  IF NEW.quantity <= 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Order quantity must be at least 1';
  END IF;

  SELECT `stock` INTO available_stock
  FROM `products`
  WHERE `id` = NEW.product_id
  FOR UPDATE;

  IF available_stock IS NULL OR available_stock < NEW.quantity THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Not enough stock for this product';
  END IF;
END$$

CREATE TRIGGER `trg_request_items_after_insert`
AFTER INSERT ON `request_items`
FOR EACH ROW
UPDATE `products`
SET `stock` = `stock` - NEW.quantity
WHERE `id` = NEW.product_id$$

CREATE TRIGGER `trg_products_after_update_price`
AFTER UPDATE ON `products`
FOR EACH ROW
BEGIN
  IF NEW.price <> OLD.price THEN
    INSERT INTO `product_price_history` (`product_id`, `old_price`, `new_price`)
    VALUES (OLD.id, OLD.price, NEW.price);
  END IF;
END$$

DELIMITER ;
