-- Apply once to an existing Food by K database.
-- Existing customer balances are preserved; new earnings use the R100 rate.
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

-- Before this change, the payment webhook awarded one whole point per rand.
-- Carry that recorded historical earning rule into the order history display.
UPDATE orders
SET loyalty_points_earned = ROUND(GREATEST(0, subtotal - locked_discount) + delivery_fee, 0)
WHERE status IN ('paid', 'preparing', 'ready', 'completed');
