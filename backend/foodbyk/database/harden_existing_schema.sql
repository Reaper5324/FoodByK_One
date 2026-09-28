-- Apply once to an existing database after reviewing the data checks below.
-- Back up the database first. This migration intentionally fails if existing
-- rows violate the new constraints so bad data is not silently rewritten.

ALTER TABLE addresses
    ADD CONSTRAINT chk_addresses_coordinate_pair
        CHECK ((latitude IS NULL AND longitude IS NULL) OR (latitude IS NOT NULL AND longitude IS NOT NULL)),
    ADD CONSTRAINT chk_addresses_latitude CHECK (latitude IS NULL OR latitude BETWEEN -90 AND 90),
    ADD CONSTRAINT chk_addresses_longitude CHECK (longitude IS NULL OR longitude BETWEEN -180 AND 180);

ALTER TABLE products
    ADD CONSTRAINT chk_products_price_nonnegative CHECK (price >= 0);

ALTER TABLE cart_items
    ADD CONSTRAINT chk_cart_quantity_positive CHECK (quantity > 0),
    ADD CONSTRAINT chk_cart_unit_price_nonnegative CHECK (unit_price IS NULL OR unit_price >= 0);

ALTER TABLE orders
    ADD CONSTRAINT chk_orders_requested_window CHECK (requested_window_end > requested_window_start),
    ADD CONSTRAINT chk_orders_confirmed_window CHECK (
        confirmed_window_start IS NULL OR confirmed_window_end IS NULL OR confirmed_window_end > confirmed_window_start
    ),
    ADD CONSTRAINT chk_orders_amounts_nonnegative CHECK (subtotal >= 0 AND locked_discount >= 0 AND delivery_fee >= 0),
    ADD CONSTRAINT chk_orders_distance_nonnegative CHECK (distance_km >= 0);

ALTER TABLE order_items
    ADD CONSTRAINT chk_order_items_quantity_positive CHECK (quantity > 0),
    ADD CONSTRAINT chk_order_items_price_nonnegative CHECK (unit_price >= 0);

ALTER TABLE payments
    MODIFY gateway_token VARCHAR(512) DEFAULT NULL,
    ADD CONSTRAINT chk_payments_amount_nonnegative CHECK (amount >= 0);

ALTER TABLE promotions
    ADD CONSTRAINT chk_promotions_discount_nonnegative CHECK (discount_value >= 0),
    ADD CONSTRAINT chk_promotions_date_range CHECK (start_date IS NULL OR end_date IS NULL OR end_date >= start_date);

ALTER TABLE login_attempts
    ADD INDEX idx_login_attempts_time (attempted_at);
