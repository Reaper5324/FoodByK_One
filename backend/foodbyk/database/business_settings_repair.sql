-- Railway/MySQL upgrade for the order workflow business settings.
--
-- Check the current schema with business_settings_schema_check.sql first.
-- This is a one-time migration: MySQL has no ADD COLUMN IF NOT EXISTS syntax.
-- Run it only when all seven columns below are absent. If any are already
-- present, remove those ADD COLUMN clauses before executing this file.

ALTER TABLE business_settings
    ADD COLUMN free_delivery_threshold DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN max_item_quantity INT UNSIGNED NOT NULL DEFAULT 6,
    ADD COLUMN max_cart_items INT UNSIGNED NOT NULL DEFAULT 10,
    ADD COLUMN minimum_order_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN ordering_enabled TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN business_phone VARCHAR(30) NOT NULL DEFAULT '081 447 8365',
    ADD COLUMN business_address VARCHAR(255) NOT NULL DEFAULT '8513 Goshawk Street, Lenasia South, Gauteng';

-- Preserve the singleton settings row expected by the API.
INSERT IGNORE INTO business_settings (id) VALUES (1);

-- Ensure weekday settings exist. INSERT IGNORE preserves any schedule already
-- saved by the business and fills only absent weekdays.
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

-- Post-migration verification: expect seven settings rows and seven weekdays.
SELECT COLUMN_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'business_settings'
  AND COLUMN_NAME IN (
      'free_delivery_threshold', 'max_item_quantity', 'max_cart_items',
      'minimum_order_amount', 'ordering_enabled', 'business_phone', 'business_address'
  )
ORDER BY COLUMN_NAME;

SELECT weekday, is_open, open_time, close_time
FROM business_trading_hours
ORDER BY weekday;
