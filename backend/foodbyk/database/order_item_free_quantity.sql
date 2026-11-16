-- Run once on an existing database before deploying the free_quantity model.
-- The previous BOGO rule granted at most one free unit per order; preserve that
-- snapshot for existing matching BOGO orders so later staff edits do not guess.
ALTER TABLE order_items
    ADD COLUMN free_quantity INT UNSIGNED NOT NULL DEFAULT 0 AFTER quantity,
    ADD CONSTRAINT chk_order_items_free_quantity CHECK (free_quantity <= quantity);

CREATE TEMPORARY TABLE legacy_bogo_free_units AS
SELECT
    oi.id,
    o.locked_discount,
    oi.unit_price,
    ROW_NUMBER() OVER (
        PARTITION BY oi.order_id
        ORDER BY oi.unit_price ASC, oi.id ASC
    ) AS item_rank
FROM order_items oi
JOIN orders o ON o.id = oi.order_id
JOIN promotions p ON p.id = o.promotion_id
JOIN promotion_products pp
  ON pp.promotion_id = p.id
 AND pp.product_id = oi.product_id
WHERE p.discount_type = 'buy_one_get_one'
  AND oi.quantity >= 2;

UPDATE order_items oi
JOIN legacy_bogo_free_units ranked ON ranked.id = oi.id
SET oi.free_quantity = 1
WHERE ranked.item_rank = 1
  AND ROUND(ranked.locked_discount, 2) = ROUND(ranked.unit_price, 2);

DROP TEMPORARY TABLE legacy_bogo_free_units;
