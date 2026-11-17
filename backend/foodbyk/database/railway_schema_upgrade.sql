-- Food by K existing Railway MySQL database upgrade.
-- Select the same database used by the backend before applying this file.
--
-- MANUAL ONE-TIME MIGRATION: this file uses ordinary SQL only. It contains no
-- prepared statements, dynamic SQL, procedures, or DELIMITER commands.
--
-- This version assumes the earlier business-settings and weekly-hours schema
-- has already been applied. It adds the minimum-enforcement flag and booking
-- window setting introduced by the current schema.
-- Before running: if enforce_minimum_order or max_advance_booking_days is
-- already in business_settings, comment out its corresponding ALTER statement.
-- Also check order_items.free_quantity
-- and its named CHECK constraint and skip those statements if already present.
-- CREATE TABLE IF NOT EXISTS and INSERT IGNORE preserve existing data.

ALTER TABLE business_settings
    ADD COLUMN enforce_minimum_order TINYINT(1) NOT NULL DEFAULT 0 AFTER minimum_order_amount;

-- Maximum inclusive booking window; skip this ALTER if already present.
ALTER TABLE business_settings
    ADD COLUMN max_advance_booking_days INT UNSIGNED NOT NULL DEFAULT 30 AFTER max_orders_per_slot;

-- Ensure the already-installed hours table has all weekday rows. Existing
-- schedules are preserved by INSERT IGNORE.
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

-- Verification: expect enforce_minimum_order, both tables, free_quantity,
-- max_advance_booking_days, and seven trading-hour rows.
SELECT COLUMN_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'business_settings'
  AND COLUMN_NAME = 'enforce_minimum_order'
ORDER BY COLUMN_NAME;

SELECT COLUMN_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'business_settings'
  AND COLUMN_NAME = 'max_advance_booking_days';

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
