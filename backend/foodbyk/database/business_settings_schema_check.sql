-- Read-only preflight for business_settings_repair.sql.
-- Run this first in Railway's MySQL query console, against the same database
-- configured in the backend's DB_NAME setting.

SELECT DATABASE() AS selected_database;

SELECT expected.expected_column AS column_name,
       CASE WHEN actual.COLUMN_NAME IS NULL THEN 'MISSING' ELSE 'PRESENT' END AS status
FROM (
    SELECT 'free_delivery_threshold' AS expected_column UNION ALL
    SELECT 'minimum_order_amount' UNION ALL
    SELECT 'enforce_minimum_order' UNION ALL
    SELECT 'ordering_enabled' UNION ALL
    SELECT 'business_phone' UNION ALL
    SELECT 'business_address' UNION ALL
    SELECT 'max_item_quantity' UNION ALL
    SELECT 'max_cart_items'
) AS expected
LEFT JOIN information_schema.COLUMNS AS actual
       ON actual.TABLE_SCHEMA = DATABASE()
      AND actual.TABLE_NAME = 'business_settings'
      AND actual.COLUMN_NAME = expected.expected_column
ORDER BY expected.expected_column;

SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'business_trading_hours';
