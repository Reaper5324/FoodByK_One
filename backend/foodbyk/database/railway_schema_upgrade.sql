-- Food by K existing Railway MySQL database upgrade.
-- Select the same database used by the backend before applying this file.
--
-- MANUAL ONE-TIME MIGRATION: this file uses ordinary SQL only. It contains no
-- prepared statements, dynamic SQL, procedures, or DELIMITER commands.
--
-- Before running: check business_settings columns with
-- business_settings_schema_check.sql. If a column below is already PRESENT,
-- comment out that column's entire ALTER TABLE statement before execution.
-- Also check order_items.free_quantity and the named CHECK constraint; remove
-- the corresponding ALTER statement if that structure already exists.
-- CREATE TABLE IF NOT EXISTS and INSERT IGNORE preserve existing tables/data.

-- Business settings for ordering, fulfilment, cart limits, and contact details.
ALTER TABLE business_settings
    ADD COLUMN free_delivery_threshold DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER delivery_fee;
ALTER TABLE business_settings
    ADD COLUMN max_item_quantity INT UNSIGNED NOT NULL DEFAULT 6 AFTER max_orders_per_slot;
ALTER TABLE business_settings
    ADD COLUMN max_cart_items INT UNSIGNED NOT NULL DEFAULT 10 AFTER max_item_quantity;
ALTER TABLE business_settings
    ADD COLUMN minimum_order_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER max_cart_items;
ALTER TABLE business_settings
    ADD COLUMN enforce_minimum_order TINYINT(1) NOT NULL DEFAULT 0 AFTER minimum_order_amount;
ALTER TABLE business_settings
    ADD COLUMN ordering_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER enforce_minimum_order;
ALTER TABLE business_settings
    ADD COLUMN business_phone VARCHAR(30) NOT NULL DEFAULT '081 447 8365' AFTER ordering_enabled;
ALTER TABLE business_settings
    ADD COLUMN business_address VARCHAR(255) NOT NULL DEFAULT '8513 Goshawk Street, Lenasia South, Gauteng' AFTER business_phone;

INSERT IGNORE INTO business_settings (id) VALUES (1);

-- Weekly trading hours. Existing days are preserved by INSERT IGNORE.
CREATE TABLE IF NOT EXISTS business_trading_hours (
    weekday    TINYINT UNSIGNED PRIMARY KEY,
    is_open    TINYINT(1) NOT NULL DEFAULT 1,
    open_time  TIME NOT NULL DEFAULT '11:00:00',
    close_time TIME NOT NULL DEFAULT '18:00:00',
    CONSTRAINT chk_trading_hours_weekday CHECK (weekday BETWEEN 1 AND 7),
    CONSTRAINT chk_trading_hours_order CHECK (close_time > open_time)
) ENGINE=InnoDB;

INSERT IGNORE INTO business_trading_hours (weekday, is_open, open_time, close_time)
SELECT days.weekday,
       days.weekday < 7,
       COALESCE(settings.trading_hours_start, '09:00:00'),
       COALESCE(settings.trading_hours_end, '20:00:00')
FROM business_settings AS settings
CROSS JOIN (
    SELECT 1 AS weekday UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL
    SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7
) AS days
WHERE settings.id = 1;

-- Promotion-to-product targeting.
CREATE TABLE IF NOT EXISTS promotion_products (
    promotion_id INT UNSIGNED NOT NULL,
    product_id   INT UNSIGNED NOT NULL,
    PRIMARY KEY (promotion_id, product_id),
    CONSTRAINT fk_promotion_products_promotion FOREIGN KEY (promotion_id) REFERENCES promotions(id) ON DELETE CASCADE,
    CONSTRAINT fk_promotion_products_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_promotion_products_product (product_id)
) ENGINE=InnoDB;

-- Explicit BOGO free-quantity snapshot for order items.
ALTER TABLE order_items
    ADD COLUMN free_quantity INT UNSIGNED NOT NULL DEFAULT 0 AFTER quantity;

ALTER TABLE order_items
    ADD CONSTRAINT chk_order_items_free_quantity CHECK (free_quantity <= quantity);

-- Preserve the previous one-free-unit BOGO calculation on matching historical
-- orders. New rows with an explicit free_quantity are left untouched.
DROP TEMPORARY TABLE IF EXISTS foodbyk_legacy_bogo_free_units;
CREATE TEMPORARY TABLE foodbyk_legacy_bogo_free_units AS
SELECT oi.id,
       o.locked_discount,
       oi.unit_price,
       ROW_NUMBER() OVER (PARTITION BY oi.order_id ORDER BY oi.unit_price ASC, oi.id ASC) AS item_rank
FROM order_items AS oi
JOIN orders AS o ON o.id = oi.order_id
JOIN promotions AS p ON p.id = o.promotion_id
JOIN promotion_products AS pp ON pp.promotion_id = p.id AND pp.product_id = oi.product_id
WHERE p.discount_type = 'buy_one_get_one'
  AND oi.quantity >= 2
  AND oi.free_quantity = 0;

UPDATE order_items AS oi
JOIN foodbyk_legacy_bogo_free_units AS ranked ON ranked.id = oi.id
SET oi.free_quantity = 1
WHERE ranked.item_rank = 1
  AND ROUND(ranked.locked_discount, 2) = ROUND(ranked.unit_price, 2);

DROP TEMPORARY TABLE foodbyk_legacy_bogo_free_units;

-- Verification: expect eight settings columns, both tables, free_quantity,
-- and seven trading-hour rows.
SELECT COLUMN_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'business_settings'
  AND COLUMN_NAME IN (
      'free_delivery_threshold', 'max_item_quantity', 'max_cart_items',
      'minimum_order_amount', 'enforce_minimum_order', 'ordering_enabled',
      'business_phone', 'business_address'
  )
ORDER BY COLUMN_NAME;

SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('business_trading_hours', 'promotion_products');

SELECT COLUMN_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'order_items'
  AND COLUMN_NAME = 'free_quantity';

SELECT weekday, is_open, open_time, close_time
FROM business_trading_hours
ORDER BY weekday;
