-- Apply once if loyalty_rewards_migration.sql was already run with the old
-- one-point-per-rand historical backfill. Safe to rerun: corrected order
-- earnings produce a zero adjustment on subsequent runs.
START TRANSACTION;

DROP TEMPORARY TABLE IF EXISTS loyalty_rate_correction;
CREATE TEMPORARY TABLE loyalty_rate_correction (
    customer_id INT UNSIGNED NOT NULL PRIMARY KEY,
    points_to_remove DECIMAL(12,2) UNSIGNED NOT NULL
) ENGINE=InnoDB;

INSERT INTO loyalty_rate_correction (customer_id, points_to_remove)
SELECT o.customer_id,
       ROUND(SUM(GREATEST(0, o.loyalty_points_earned - ROUND(p.amount * 0.01, 2))), 2)
FROM orders AS o
JOIN payments AS p ON p.order_id = o.id
WHERE o.status IN ('paid', 'preparing', 'ready', 'completed')
GROUP BY o.customer_id
HAVING SUM(GREATEST(0, o.loyalty_points_earned - ROUND(p.amount * 0.01, 2))) > 0;

-- Remove only the unspent excess from the account balance. Any manual awards
-- remain in place; balances cannot become negative if excess points were used.
UPDATE users AS u
JOIN loyalty_rate_correction AS c ON c.customer_id = u.id
SET u.loyalty_points = GREATEST(0.00, u.loyalty_points - c.points_to_remove);

UPDATE orders AS o
JOIN payments AS p ON p.order_id = o.id
SET o.loyalty_points_earned = ROUND(p.amount * 0.01, 2)
WHERE o.status IN ('paid', 'preparing', 'ready', 'completed');

DROP TEMPORARY TABLE loyalty_rate_correction;
COMMIT;
