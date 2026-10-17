-- Apply once to an existing Food by K database.
-- Legacy balances are reduced by the old-rate excess for paid orders; any
-- manual points awarded outside orders remain untouched.
ALTER TABLE users
    MODIFY loyalty_points DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0.00;

ALTER TABLE orders
    ADD COLUMN loyalty_points_redeemed DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER locked_discount,
    ADD COLUMN loyalty_points_refunded DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER loyalty_points_redeemed,
    ADD COLUMN loyalty_points_earned DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER loyalty_points_refunded,
    ADD CONSTRAINT chk_orders_loyalty_points CHECK (
        loyalty_points_redeemed >= 0
        AND loyalty_points_refunded >= 0
        AND loyalty_points_refunded <= loyalty_points_redeemed
        AND loyalty_points_earned >= 0
    );

-- Payment.amount is the amount actually submitted for charging and is the
-- authoritative spend value for historical paid orders.
START TRANSACTION;

CREATE TEMPORARY TABLE loyalty_rate_correction (
    customer_id INT UNSIGNED NOT NULL PRIMARY KEY,
    points_to_remove DECIMAL(12,2) UNSIGNED NOT NULL
) ENGINE=InnoDB;

INSERT INTO loyalty_rate_correction (customer_id, points_to_remove)
SELECT o.customer_id,
       ROUND(SUM(GREATEST(0, ROUND(p.amount, 0) - ROUND(p.amount * 0.01, 2))), 2)
FROM orders AS o
JOIN payments AS p ON p.order_id = o.id
WHERE o.status IN ('paid', 'preparing', 'ready', 'completed')
GROUP BY o.customer_id
HAVING SUM(GREATEST(0, ROUND(p.amount, 0) - ROUND(p.amount * 0.01, 2))) > 0;

UPDATE users AS u
JOIN loyalty_rate_correction AS c ON c.customer_id = u.id
SET u.loyalty_points = GREATEST(0.00, u.loyalty_points - c.points_to_remove);

UPDATE orders AS o
JOIN payments AS p ON p.order_id = o.id
SET o.loyalty_points_earned = ROUND(p.amount * 0.01, 2)
WHERE o.status IN ('paid', 'preparing', 'ready', 'completed');

DROP TEMPORARY TABLE loyalty_rate_correction;
COMMIT;
